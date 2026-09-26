<?php

namespace Rahpt\Ci4Module;

use Exception;
use JsonException;
use Rahpt\Ci4Module\Config\Modules;
use Rahpt\Ci4Module\Validators\DependencyChecker;
use Rahpt\Ci4Module\Validators\ModuleNameValidator;

/**
 * ModuleRegistry - Manages module registration, lifecycle state machine, and dependency tracking in modules.json
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
     * Sets the lifecycle state of a module with optional reason.
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

        // 2. Validate dependencies and conflicts before attempting activation
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

        // 3. Mark state as activating
        $this->setStatus($module, self::STATUS_ACTIVATING);

        // 4. Run module activation hook BEFORE updating persistent active state (transactional consistency)
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

        // 5. Persist state as active only after hook succeeds
        $data = $this->all();
        $current = $data[$module] ?? [];
        $current['active'] = true;
        $current['status'] = self::STATUS_ACTIVE;
        $current['activated_at'] = date('Y-m-d H:i:s');
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
     */
    protected function getModuleMetadata(string $folder): ?array
    {
        $manifest = [];
        $manifestPath = APPPATH . $this->config->basePath . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR . 'module.json';
        $manifestHash = null;

        if (is_file($manifestPath)) {
            try {
                $manifest = json_decode(file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
                $manifestHash = sha1_file($manifestPath);
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

        return [
            'name'          => $manifest['name'] ?? $instance?->name ?? $folder,
            'label'         => $manifest['label'] ?? $instance?->label ?? $manifest['name'] ?? $instance?->name ?? $folder,
            'slug'          => $manifest['slug'] ?? $instance?->slug ?? strtolower($folder),
            'version'       => $manifest['version'] ?? $instance?->version ?? '1.0.0',
            'theme'         => $manifest['theme'] ?? $instance?->theme ?? 'adminlte',
            'routePrefix'   => $manifest['routePrefix'] ?? $instance?->routePrefix ?? strtolower($folder),
            'require'       => $manifest['requires'] ?? $manifest['require'] ?? $instance?->requires ?? $instance?->require ?? [],
            'requires'      => $manifest['requires'] ?? $manifest['require'] ?? $instance?->requires ?? $instance?->require ?? [],
            'conflicts'     => $manifest['conflicts'] ?? $instance?->conflicts ?? [],
            'provides'      => $manifest['provides'] ?? $instance?->provides ?? [],
            'permissions'   => $manifest['permissions'] ?? $instance?->permissions ?? [],
            'tenant_aware'  => $manifest['tenant_aware'] ?? $instance?->tenantAware ?? false,
            'manifest_hash' => $manifestHash,
            'checksum'      => $manifest['checksum'] ?? null,
            'source'        => $manifest['source'] ?? 'local',
            'package'       => $manifest['package'] ?? null,
            'path'          => $this->config->basePath . '/' . $folder
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
