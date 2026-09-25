<?php

namespace Rahpt\Ci4Module;

use Exception;
use JsonException;
use Rahpt\Ci4Module\Config\Modules;

/**
 * ModuleRegistry - Manages module registration in modules.json
 */
class ModuleRegistry
{
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
        $module = \Rahpt\Ci4Module\Validators\ModuleNameValidator::validate($module);

        $fileName = $this->getCentralRegistryPath();
        $all = $this->all();

        $all[$module] = array_merge($all[$module] ?? ['active' => true], $data);

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

    public function activate(string $module): bool
    {
        $module = \Rahpt\Ci4Module\Validators\ModuleNameValidator::validate($module);

        $available = $this->getAvailableModules();
        if (!isset($available[$module])) {
            log_message('error', "Cannot activate unknown module '{$module}'");
            return false;
        }

        $folder = basename($available[$module]['path']);
        $class = $this->config->baseNamespace . "\\" . ucfirst($folder) . "\\Config\\Module";

        // 1. Run module activation hook BEFORE updating persistent state (transactional consistency)
        if (class_exists($class)) {
            try {
                $instance = $this->getModuleInstance($class);
                if (method_exists($instance, 'activate')) {
                    $instance->activate();
                }
            } catch (\Throwable $e) {
                log_message('error', "Activation hook failed for module '{$module}': " . $e->getMessage());
                \CodeIgniter\Events\Events::trigger('rahpt.module.activation_failed', $module, $e);
                return false;
            }
        }

        // 2. Persist state only after activation hook succeeds
        $data = $this->all();
        $current = $data[$module] ?? [];
        $current['active'] = true;
        $current['status'] = 'active';
        $current['activated_at'] = date('Y-m-d H:i:s');

        try {
            $this->put($module, $current);

            log_message('info', "Module '{$module}' activated successfully");
            \CodeIgniter\Events\Events::trigger('rahpt.module.activated', $module);

            return true;
        } catch (\Throwable $e) {
            log_message('error', "Failed to persist active status for module '{$module}': " . $e->getMessage());
            return false;
        }
    }

    public function deactivate(string $module): void
    {
        $module = \Rahpt\Ci4Module\Validators\ModuleNameValidator::validate($module);

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
            'active' => false,
            'status' => 'disabled',
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
        return $metadata['require'] ?? [];
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
                'metadata' => $data,
                'active' => $data['active'] ?? false,
                'installed_at' => $central[$slug]['installed_at'] ?? null,
                'activated_at' => $central[$slug]['activated_at'] ?? null,
            ];
        }

        return $result;
    }

    /**
     * Instantiates the Module class to retrieve its metadata.
     */
    protected function getModuleMetadata(string $folder): ?array
    {
        $class = $this->config->baseNamespace . "\\" . ucfirst($folder) . "\\Config\\Module";

        if (class_exists($class)) {
            $instance = $this->getModuleInstance($class);
            return [
                'name' => $instance->name ?? $folder,
                'label' => $instance->label ?? $instance->name ?? $folder,
                'slug' => $instance->slug ?? strtolower($folder),
                'version' => $instance->version ?? '1.0.0',
                'theme' => $instance->theme ?? 'adminlte',
                'routePrefix' => $instance->routePrefix ?? strtolower($folder),
                'require' => $instance->require ?? [],
                'path' => $this->config->basePath . '/' . $folder
            ];
        }

        return null;
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
