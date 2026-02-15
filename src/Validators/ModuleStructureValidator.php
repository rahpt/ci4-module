<?php

namespace Rahpt\Ci4Module\Validators;

use Exception;

/**
 * ModuleStructureValidator - Validates module file structure
 */
class ModuleStructureValidator
{
    protected array $requiredFiles = [
        'Config/Module.php'
    ];

    protected array $recommendedFiles = [
        'README.md',
        'Controllers',
        'Models',
        'Views',
    ];

    /**
     * Validate module structure
     */
    public function validate(string $modulePath): StructureValidationResult
    {
        $errors = [];
        $warnings = [];

        // Check if path exists
        if (!is_dir($modulePath)) {
            $errors[] = "Module path does not exist: {$modulePath}";
            return new StructureValidationResult(false, $errors, $warnings);
        }

        // Check required files
        foreach ($this->requiredFiles as $file) {
            $fullPath = $modulePath . DIRECTORY_SEPARATOR . $file;
            if (!file_exists($fullPath)) {
                $errors[] = "Required file missing: {$file}";
            }
        }

        // Check recommended files/folders
        foreach ($this->recommendedFiles as $item) {
            $fullPath = $modulePath . DIRECTORY_SEPARATOR . $item;
            if (!file_exists($fullPath)) {
                $warnings[] = "Recommended item missing: {$item}";
            }
        }

        // Validate Config/Module.php if it exists
        $moduleConfigPath = $modulePath . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR . 'Module.php';
        if (file_exists($moduleConfigPath)) {
            $configValidation = $this->validateModuleConfig($moduleConfigPath);
            $errors = array_merge($errors, $configValidation['errors']);
            $warnings = array_merge($warnings, $configValidation['warnings']);
        }

        $success = empty($errors);
        return new StructureValidationResult($success, $errors, $warnings);
    }

    /**
     * Validate Config/Module.php content
     */
    protected function validateModuleConfig(string $filePath): array
    {
        $errors = [];
        $warnings = [];

        $content = file_get_contents($filePath);

        // Check for required properties
        $requiredProperties = ['name', 'slug', 'version'];
        foreach ($requiredProperties as $prop) {
            if (!preg_match('/public\s+(?:string|array)\s+\$' . $prop . '\s*=/', $content)) {
                $warnings[] = "Property '\${$prop}' not defined or not public in Config/Module.php";
            }
        }

        // Check if implements ModuleInterface
        if (!str_contains($content, 'implements ModuleInterface') && 
            !str_contains($content, 'extends BaseModule')) {
            $warnings[] = "Module should implement ModuleInterface or extend BaseModule";
        }

        // Check namespace
        if (!preg_match('/namespace\s+App\\\\Modules\\\\(\w+)\\\\Config/', $content)) {
            $warnings[] = "Unexpected namespace format in Config/Module.php";
        }

        return ['errors' => $errors, 'warnings' => $warnings];
    }

    /**
     * Quick check if module has minimum required structure
     */
    public function hasMinimumStructure(string $modulePath): bool
    {
        $moduleConfigPath = $modulePath . DIRECTORY_SEPARATOR . 'Config' . DIRECTORY_SEPARATOR . 'Module.php';
        return file_exists($moduleConfigPath);
    }
}

/**
 * Structure validation result value object
 */
class StructureValidationResult
{
    public function __construct(
        public readonly bool $success,
        public readonly array $errors,
        public readonly array $warnings
    ) {}

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    public function hasWarnings(): bool
    {
        return !empty($this->warnings);
    }

    public function getErrorCount(): int
    {
        return count($this->errors);
    }

    public function getWarningCount(): int
    {
        return count($this->warnings);
    }

    public function getAllMessages(): array
    {
        return array_merge(
            array_map(fn($e) => ['type' => 'error', 'message' => $e], $this->errors),
            array_map(fn($w) => ['type' => 'warning', 'message' => $w], $this->warnings)
        );
    }
}
