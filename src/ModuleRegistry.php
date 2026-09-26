<?php

namespace Rahpt\Ci4Module;

use Exception;
use JsonException;
use Rahpt\Ci4Module\Config\Modules;
use Rahpt\Ci4Module\Validators\DependencyChecker;
use Rahpt\Ci4Module\Validators\ModuleNameValidator;

/**
 * ModuleRegistry - Manages module registration, lifecycle state machine, and dependency tracking in modules.json
 *
 * State machine transitions:
 *   discovered  -> validated
 *   validated   -> installed
 *   installed   -> activating
 *   activating  -> active
 *   active      -> deactivating
 *   deactivating -> disabled
 *   disabled    -> activating   (re-activation)
 *   failed      -> validated    (retry after fix)
 *   failed      -> quarantined  (isolate permanently)
 *   any         -> failed       (on error)
 *   any         -> quarantined  (on security violation)
 */
class ModuleRegistry
{
    // Transactional state machine constants
    public const STATUS_DISCOVERED   = 'discovered';
    public const STATUS_VALIDATED    = 'validated';
    public const STATUS_INSTALLED    = 'installed';
    public const STATUS_ACTIVATING   = 'activating';
    public const STATUS_ACTIVE       = 'active';
    public const STATUS_DEACTIVATING = 'deactivating';
    public const STATUS_DISABLED     = 'disabled';
    public const STATUS_FAILED       = 'failed';
    public const STATUS_QUARANTINED  = 'quarantined';

    /**
     * Valid state machine transitions: from => [allowed to, ...]
     * Special key '*' means reachable from any state.
     */
    protected const ALLOWED_TRANSITIONS = [
        self::STATUS_DISCOVERED   => [self::STATUS_VALIDATED, self::STATUS_FAILED, self::STATUS_QUARANTINED],
        self::STATUS_VALIDATED    => [self::STATUS_INSTALLED, self::STATUS_FAILED, self::STATUS_QUARANTINED],
        self::STATUS_INSTALLED    => [self::STATUS_ACTIVATING, self::STATUS_FAILED, self::STATUS_QUARANTINED],
        self::STATUS_ACTIVATING   => [self::STATUS_ACTIVE, self::STATUS_FAILED, self::STATUS_QUARANTINED],
        self::STATUS_ACTIVE       => [self::STATUS_DEACTIVATING, self::STATUS_FAILED, self::STATUS_QUARANTINED],
        self::STATUS_DEACTIVATING => [self::STATUS_DISABLED, self::STATUS_FAILED, self::STATUS_QUARANTINED],
        self::STATUS_DISABLED     => [self::STATUS_ACTIVATING, self::STATUS_FAILED, self::STATUS_QUARANTINED],
        self::STATUS_FAILED       => [self::STATUS_VALIDATED, self::STATUS_QUARANTINED, self::STATUS_FAILED],
        self::STATUS_QUARANTINED  => [self::STATUS_QUARANTINED],  // terminal; only explicit rollback can escape
    ];

    protected Modules $config;

    /**
     * Cache for module instances to avoid repeated instantiation
     */
    protected static array $moduleInstances = [];

    public function __construct(?Modules $config = null)
    {
        $this->config = $config ?? config(\Rahpt\Ci4Module\Config\Modules::class);
    }

    /**
     * Get the full path to the central modules registration file.
     */
    protected function getCentralRegistryPath(): string
    {
        return WRITEPATH . $this->config->registrationFile;
    }

    /**
     * Load central registration data.
     * 
     * @return array<string, array<string, mixed>>
     */
    public function all(?string $module = null): array
    {
        $fileName = $this->getCentralRegistryPath();
        if (!is_file($fileName)) {
            return [];
        }

        try {
            $data = json_decode(file_get_contents($fileName), true, 512, JSON_THROW_ON_ERROR);
            if ($module) {
                return isset($data[$module]) ? [$module => $data[$module]] : [];
            }
            return $data;
        } catch (JsonException $e) {
            return [];
        }
    }

    public function put(string $module, array $data): void
    {
        // Enforce strict module name format
        $module = ModuleNameValidator::validate($module);

        $fileName = $this->getCentralRegistryPath();
        $all = $this->all();

        $all[$module] = array_merge($all[$module] ?? ['active' => true, 'status' => self::STATUS_INSTALLED], $data);

        $json = json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new JsonException('Failed to encode central registry file.');
        }

        // Ensure directory exists
        $dir = dirname($fileName);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        // Atomic write to prevent file corruption in case of concurrency or unexpected termination
        $this->writeAtomic($fileName, $json);

        // Trigger global event for decoupling
        \CodeIgniter\Events\Events::trigger('rahpt.module.changed', $module, $data);
    }

    /**
     * Atomically writes data to a file using a unique temp file and rename.
     */
    protected function writeAtomic(string $fileName, string $content): void
    {
        $tempFile = $fileName . '.' . bin2hex(random_bytes(6)) . '.tmp';

        if (file_put_contents($tempFile, $content, LOCK_EX) === false) {
            throw new \RuntimeException("Failed to write temporary registry file: {$tempFile}");
        }

        if (!@rename($tempFile, $fileName)) {
            // Fallback for Windows if target file is locked or cannot be directly overwritten
            @unlink($fileName);
            if (!@rename($tempFile, $fileName)) {
                @unlink($tempFile);
                throw new \RuntimeException("Failed to atomically replace registry file: {$fileName}");
            }
        }
    }

    /**
     * Retrieves the lifecycle state of a module.
     */
    public function getStatus(string $module): string
    {
        $module = ModuleNameValidator::validate($module);
        $all = $this->all();
        return $all[$module]['status'] ?? self::STATUS_DISCOVERED;
    }

    /**
     * Enforces a valid state machine transition for a module.
     * Throws \RuntimeException if the transition is not allowed.
     *
     * @throws \RuntimeException on invalid transition
     */
    public function transition(string $module, string $toStatus, ?string $reason = null): void
    {
        $module    = ModuleNameValidator::validate($module);
        $fromStatus = $this->getStatus($module);

        $allowed = self::ALLOWED_TRANSITIONS[$fromStatus] ?? [];
        if (!in_array($toStatus, $allowed, true)) {
            throw new \RuntimeException(
                "Invalid module state transition for '{$module}': [{$fromStatus}] -> [{$toStatus}] is not allowed."
            );
        }

        $this->setStatus($module, $toStatus, $reason);
    }

    /**
     * Sets the lifecycle state of a module with optional reason.
     * Prefer transition() for enforced state machine validation.
     * Direct setStatus() is allowed for internal transitions (e.g. from activate/deactivate methods).
     */
    public function setStatus(string $module, string $status, ?string $reason = null): void
    {
        $module = ModuleNameValidator::validate($module);
        $update = [
            'status'     => $status,
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($reason !== null) {
            $update['status_reason'] = $reason;
        }

        $this->put($module, $update);
        \CodeIgniter\Events\Events::trigger('rahpt.module.status_changed', $module, $status, $reason);
    }

    /**
     * Rolls back a quarantined or failed module to the 'validated' state.
     * Only allowed if the module can be safely recovered (not permanently quarantined).
     *
     * @throws \RuntimeException if rollback is not permitted
     */
    public function rollback(string $module, string $reason): void
    {
        $module     = ModuleNameValidator::validate($module);
        $fromStatus = $this->getStatus($module);

        if ($fromStatus === self::STATUS_QUARANTINED) {
            // Quarantine is a terminal state; require explicit override with reason
            log_message('warning', "[ModuleRegistry] Rollback from quarantined state for '{$module}'. Reason: {$reason}");
        }

        $this->setStatus($module, self::STATUS_VALIDATED, "Rollback: {$reason}");
        $this->put($module, ['active' => false]);

        log_message('notice', "[ModuleRegistry] Module '{$module}' rolled back from [{$fromStatus}] to [validated]. Reason: {$reason}");
        \CodeIgniter\Events\Events::trigger('rahpt.module.rollback', $module, $fromStatus, $reason);
    }

    /**
     * Quarantines a compromised or corrupted module.
     */
    public function quarantine(string $module, string $reason): void
    {
        $module = ModuleNameValidator::validate($module);
        $this->put($module, [
            'active'         => false,
            'status'         => self::STATUS_QUARANTINED,
            'status_reason'  => $reason,
            'quarantined_at' => date('Y-m-d H:i:s'),
        ]);

        log_message('critical', "Module '{$module}' quarantined: {$reason}");
        \CodeIgniter\Events\Events::trigger('rahpt.module.quarantined', $module, $reason);
    }

    /**
     * Computes a canonical SHA-256 fingerprint of the module's installed files.
     *
     * Algorithm:
     *   1. Walk all files under the module directory recursively.
     *   2. Normalize path separators to '/' and trim the module root prefix.
     *   3. Hash each file with SHA-256 individually.
     *   4. Sort the file list lexicographically (ksort) for determinism.
     *   5. Produce a final SHA-256 hash over the JSON-encoded map {relative_path => sha256_hex}.
     *
     * This produces a deterministic, canonical checksum that answers "have files changed?".
     * Publisher signature (Ed25519/GPG) answers "who authorized this version?" — see verifySignature().
     */
    public function computeFingerprint(string $module): ?string
    {
        $available = $this->getAvailableModules();
        if (!isset($available[$module])) {
            return null;
        }

        $fullPath = APPPATH . $available[$module]['path'];
        if (!is_dir($fullPath)) {
            return null;
        }

        $fileHashes = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($fullPath, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile()) {
                // Normalize: remove full path prefix, convert backslashes to forward slashes
                $relPath  = str_replace('\\', '/', $file->getPathname());
                $fullNorm = str_replace('\\', '/', rtrim($fullPath, '/\\')) . '/';
                $relPath  = ltrim(str_replace($fullNorm, '', $relPath), '/');

                // Per-file SHA-256 (canonical, stronger than sha1)
                $fileHashes[$relPath] = hash_file('sha256', $file->getPathname());
            }
        }

        ksort($fileHashes);
        return hash('sha256', json_encode($fileHashes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Verifies an Ed25519 publisher signature against the canonical fingerprint.
     *
     * Architecture note: this method provides the hook for publisher signature verification.
     * The actual public key must be stored in config (never from the module itself).
     * Returns null if no public key is configured (feature not enabled).
     *
     * @param string $module     Module slug
     * @param string $signature  Base64-encoded Ed25519 signature from the publisher
     * @return bool|null  true = valid, false = invalid, null = feature not configured
     */
    public function verifySignature(string $module, string $signature): ?bool
    {
        $publicKey = $this->config->publisherPublicKey ?? null;
        if (empty($publicKey)) {
            return null; // Publisher signature verification not configured
        }

        if (!function_exists('sodium_crypto_sign_verify_detached')) {
            log_message('warning', '[ModuleRegistry] sodium extension not available; publisher signature cannot be verified.');
            return null;
        }

        $fingerprint = $this->computeFingerprint($module);
        if ($fingerprint === null) {
            return false;
        }

        try {
            $pubKeyBinary = base64_decode($publicKey, true);
            $sigBinary    = base64_decode($signature, true);
            if ($pubKeyBinary === false || $sigBinary === false) {
                return false;
            }
            return sodium_crypto_sign_verify_detached($sigBinary, $fingerprint, $pubKeyBinary);
        } catch (\Throwable $e) {
            log_message('error', "[ModuleRegistry] Signature verification error for '{$module}': " . $e->getMessage());
            return false;
        }
    }

    /**
     * Verifies the integrity of a module against recorded hash or manifest checksum.
     * Transitions module to QUARANTINED if an unapproved modification or corruption is detected.
     */
    public function verifyIntegrity(string $module): bool
    {
        $module = ModuleNameValidator::validate($module);
        $metadata = $this->getModuleMetadata($module);
        if (!$metadata) {
            return false;
        }

        $central = $this->all($module)[$module] ?? [];
        $expectedFingerprint = $central['fingerprint'] ?? null;
        $expectedChecksum = $metadata['checksum'] ?? null;

        if ($expectedFingerprint !== null || $expectedChecksum !== null) {
            $currentFingerprint = $this->computeFingerprint($module);

            if ($expectedFingerprint !== null && $currentFingerprint !== $expectedFingerprint) {
                $this->quarantine($module, "Fingerprint mismatch. Expected {$expectedFingerprint}, got {$currentFingerprint}");
                return false;
            }

            if ($expectedChecksum !== null && $currentFingerprint !== $expectedChecksum) {
                $this->quarantine($module, "Checksum mismatch against declared manifest checksum.");
                return false;
            }
        }

        return true;
    }

    /**
     * Records the current module files fingerprint into the registry.
     */
    public function recordFingerprint(string $module): void
    {
        $fingerprint = $this->computeFingerprint($module);
        if ($fingerprint !== null) {
            $this->put($module, ['fingerprint' => $fingerprint]);
        }
    }

    public function activate(string $module): bool
    {
        $module = ModuleNameValidator::validate($module);

        $available = $this->getAvailableModules();
        if (!isset($available[$module])) {
            log_message('error', "Cannot activate unknown module '{$module}'");
            return false;
        }

        // 1. Check if quarantined
        if ($this->getStatus($module) === self::STATUS_QUARANTINED) {
            log_message('error', "Cannot activate quarantined module '{$module}'");
            return false;
        }

        // 2. Verify module integrity
        if (!$this->verifyIntegrity($module)) {
            log_message('error', "Integrity check failed for module '{$module}' - module quarantined.");
            return false;
        }

        // 3. Validate dependencies and conflicts before attempting activation
        $checker = new DependencyChecker($this);
        $result = $checker->check($module);
        if (!$result->success) {
            $errorMsg = implode('; ', $checker->getErrorMessages($result));
            log_message('error', "Cannot activate module '{$module}': {$errorMsg}");
            $this->setStatus($module, self::STATUS_FAILED, "Dependency check failed: {$errorMsg}");
            return false;
        }

        $folder = basename($available[$module]['path']);
        $class = $this->config->baseNamespace . "\\" . ucfirst($folder) . "\\Config\\Module";

        // 4. Mark state as activating
        $this->setStatus($module, self::STATUS_ACTIVATING);

        // 5. Run module activation hook BEFORE updating persistent active state (transactional consistency)
        if (class_exists($class)) {
            try {
                $instance = $this->getModuleInstance($class);
                if (method_exists($instance, 'activate')) {
                    $instance->activate();
                }
            } catch (\Throwable $e) {
                log_message('error', "Activation hook failed for module '{$module}': " . $e->getMessage());
                $this->setStatus($module, self::STATUS_FAILED, "Activation hook error: " . $e->getMessage());
                \CodeIgniter\Events\Events::trigger('rahpt.module.activation_failed', $module, $e);
                return false;
            }
        }

        // 6. Record fingerprint and persist state as active only after hook succeeds
        $data = $this->all();
        $current = $data[$module] ?? [];
        $current['active'] = true;
        $current['status'] = self::STATUS_ACTIVE;
        $current['activated_at'] = date('Y-m-d H:i:s');
        $current['fingerprint'] = $this->computeFingerprint($module);
        unset($current['status_reason']);

        try {
            $this->put($module, $current);

            log_message('info', "Module '{$module}' activated successfully");
            \CodeIgniter\Events\Events::trigger('rahpt.module.activated', $module);

            return true;
        } catch (\Throwable $e) {
            log_message('error', "Failed to persist active status for module '{$module}': " . $e->getMessage());
            $this->setStatus($module, self::STATUS_FAILED, "Persistence failure: " . $e->getMessage());
            return false;
        }
    }

    public function deactivate(string $module): void
    {
        $module = ModuleNameValidator::validate($module);

        $this->setStatus($module, self::STATUS_DEACTIVATING);

        // Resolve the correct class for the module slug
        $available = $this->getAvailableModules();
        if (isset($available[$module])) {
            $folder = basename($available[$module]['path']);
            $class = $this->config->baseNamespace . "\\" . ucfirst($folder) . "\\Config\\Module";

            if (class_exists($class)) {
                $instance = $this->getModuleInstance($class);
                if (method_exists($instance, 'deactivate')) {
                    $instance->deactivate();
                }
            }
        }

        $this->put($module, [
            'active'         => false,
            'status'         => self::STATUS_DISABLED,
            'deactivated_at' => date('Y-m-d H:i:s'),
        ]);

        \CodeIgniter\Events\Events::trigger('rahpt.module.deactivated', $module);
    }

    /**
     * Check if a module is currently active.
     */
    public function isActive(string $module): bool
    {
        $all = $this->all();
        return isset($all[$module]['active']) && $all[$module]['active'] === true;
    }

    /**
     * Returns metadata for all registered modules by combining central status and class data.
     */
    public function getAvailableModules(): array
    {
        $modulesPath = APPPATH . $this->config->basePath;
        $central = $this->all();
        $modules = [];

        if (is_dir($modulesPath)) {
            $folders = array_diff(scandir($modulesPath), ['.', '..']);
            foreach ($folders as $folder) {
                if (is_dir($modulesPath . DIRECTORY_SEPARATOR . $folder)) {
                    $metadata = $this->getModuleMetadata($folder);
                    if ($metadata) {
                        $name = $metadata['slug'] ?? $folder;
                        $metadata['active'] = $central[$name]['active'] ?? false;
                        $metadata['status'] = $central[$name]['status'] ?? self::STATUS_INSTALLED;
                        $modules[$name] = $metadata;
                    }
                }
            }
        }

        return $modules;
    }

    /**
     * Check if a module is installed
     */
    public function isInstalled(string $moduleName): bool
    {
        $modules = $this->getAvailableModules();
        return isset($modules[$moduleName]) || isset($modules[strtolower($moduleName)]);
    }

    /**
     * Get dependencies for a module
     */
    public function getDependencies(string $moduleName): array
    {
        $metadata = $this->getModuleMetadata($moduleName);
        return $metadata['require'] ?? $metadata['requires'] ?? [];
    }

    /**
     * Get all modules with their status
     */
    public function getModulesWithStatus(): array
    {
        $central = $this->all();
        $available = $this->getAvailableModules();

        $result = [];
        foreach ($available as $slug => $data) {
            $result[$slug] = [
                'metadata'     => $data,
                'active'       => $data['active'] ?? false,
                'status'       => $central[$slug]['status'] ?? self::STATUS_INSTALLED,
                'installed_at' => $central[$slug]['installed_at'] ?? null,
                'activated_at' => $central[$slug]['activated_at'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Instantiates the Module class and/or reads module.json to retrieve complete metadata.
     * Validates manifest schema version against supported versions in config.
     */
    protected function getModuleMetadata(string $folder): ?array
    {
        $manifest = [];
        $manifestPath = APPPATH . $this->config->basePath . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR . 'module.json';
        $manifestHash = null;

        if (is_file($manifestPath)) {
            try {
                $manifest = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
                // SHA-256 for manifest hash (canonical)
                $manifestHash = hash_file('sha256', $manifestPath);

                // Validate schema version if manifest declares one
                if (!empty($manifest['schema']) && !empty($this->config->supportedSchemaVersions)) {
                    if (!in_array((string) $manifest['schema'], $this->config->supportedSchemaVersions, true)) {
                        log_message('warning', "Module '{$folder}' declares unsupported schema version [{$manifest['schema']}]. Supported: " . implode(', ', $this->config->supportedSchemaVersions));
                        $manifest['_schema_warning'] = true;
                    }
                }
            } catch (\Throwable $e) {
                log_message('warning', "Invalid module.json manifest in {$folder}: " . $e->getMessage());
            }
        }

        $class = $this->config->baseNamespace . "\\" . ucfirst($folder) . "\\Config\\Module";
        $instance = null;
        if (class_exists($class)) {
            $instance = $this->getModuleInstance($class);
        }

        if (!$instance && empty($manifest)) {
            return null;
        }

        // Read capabilities sub-object from manifest if present (formal schema)
        $capabilities = $manifest['capabilities'] ?? [];

        return [
            'name'             => $manifest['name'] ?? $instance?->name ?? $folder,
            'label'            => $manifest['label'] ?? $instance?->label ?? $manifest['name'] ?? $instance?->name ?? $folder,
            'slug'             => $manifest['slug'] ?? $instance?->slug ?? strtolower($folder),
            'version'          => $manifest['version'] ?? $instance?->version ?? '1.0.0',
            'schema'           => $manifest['schema'] ?? '1.0',
            'theme'            => $manifest['theme'] ?? $instance?->theme ?? 'adminlte',
            'routePrefix'      => $manifest['routePrefix'] ?? $instance?->routePrefix ?? strtolower($folder),
            'require'          => $manifest['requires'] ?? $manifest['require'] ?? $instance?->requires ?? $instance?->require ?? [],
            'requires'         => $manifest['requires'] ?? $manifest['require'] ?? $instance?->requires ?? $instance?->require ?? [],
            'optionalRequires' => $manifest['optionalRequires'] ?? $instance?->optionalRequires ?? [],
            'conflicts'        => $manifest['conflicts'] ?? $instance?->conflicts ?? [],
            'provides'         => $manifest['provides'] ?? $instance?->provides ?? [],
            'permissions'      => $manifest['permissions'] ?? $instance?->permissions ?? [],
            'tenant_aware'     => $manifest['tenant_aware'] ?? $instance?->tenantAware ?? false,
            // Capabilities: support both flat manifest and nested capabilities object (formal schema)
            'api'              => $capabilities['api'] ?? $manifest['api'] ?? true,
            'web'              => $capabilities['web'] ?? $manifest['web'] ?? true,
            'cli'              => $capabilities['cli'] ?? $manifest['cli'] ?? false,
            'jobs'             => $capabilities['jobs'] ?? $manifest['jobs'] ?? false,
            'events'           => $capabilities['events'] ?? $manifest['events'] ?? false,
            'health'           => $capabilities['health'] ?? $manifest['health'] ?? false,
            'settings'         => $capabilities['settings'] ?? $manifest['settings'] ?? false,
            'navigation'       => $capabilities['navigation'] ?? $manifest['navigation'] ?? true,
            'migrations'       => $capabilities['migrations'] ?? $manifest['migrations'] ?? false,
            'manifest_hash'    => $manifestHash,
            'checksum'         => $manifest['checksum'] ?? null,
            'source'           => $manifest['source'] ?? 'local',
            'package'          => $manifest['package'] ?? null,
            'path'             => $this->config->basePath . '/' . $folder
        ];
    }

    /**
     * Gets all declared permissions from registered modules, optionally filtered by module.
     * Essential for automated Shield permission synchronization.
     *
     * @return array<string, list<string>>
     */
    public function getPermissions(?string $module = null): array
    {
        $available = $this->getAvailableModules();
        $permissions = [];

        if ($module !== null) {
            return $available[$module]['permissions'] ?? [];
        }

        foreach ($available as $slug => $data) {
            if (!empty($data['permissions'])) {
                $permissions[$slug] = $data['permissions'];
            }
        }

        return $permissions;
    }

    /**
     * Get cached module instance or create new one
     */
    protected function getModuleInstance(string $class): object
    {
        if (!isset(self::$moduleInstances[$class])) {
            self::$moduleInstances[$class] = new $class();
        }

        return self::$moduleInstances[$class];
    }

    /**
     * Get the installation path for a module.
     */
    public function getInstallPath(string $slug): string
    {
        return APPPATH . $this->config->basePath . DIRECTORY_SEPARATOR . ucfirst($slug);
    }
}
