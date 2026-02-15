<?php

namespace Rahpt\Ci4Module\Validators;

use Exception;
use Rahpt\Ci4Module\ModuleRegistry;

/**
 * DependencyChecker - Validates module dependencies
 */
class DependencyChecker
{
    protected ModuleRegistry $registry;

    public function __construct(?ModuleRegistry $registry = null)
    {
        $this->registry = $registry ?? service('modules');
    }

    /**
     * Check if all dependencies for a module are satisfied
     * 
     * @throws Exception if dependencies are not met
     */
    public function check(string $moduleName): DependencyCheckResult
    {
        $dependencies = $this->registry->getDependencies($moduleName);
        
        if (empty($dependencies)) {
            return new DependencyCheckResult(true, []);
        }

        $missing = [];
        $versionMismatches = [];

        foreach ($dependencies as $depName => $requiredVersion) {
            // Check if dependency is installed
            if (!$this->registry->isInstalled($depName)) {
                $missing[] = [
                    'module' => $depName,
                    'required_version' => $requiredVersion,
                    'reason' => 'Module not installed'
                ];
                continue;
            }

            // Check version compatibility
            $installedVersion = $this->getInstalledVersion($depName);
            
            if (!$this->isVersionCompatible($installedVersion, $requiredVersion)) {
                $versionMismatches[] = [
                    'module' => $depName,
                    'required_version' => $requiredVersion,
                    'installed_version' => $installedVersion,
                    'reason' => 'Version mismatch'
                ];
            }
        }

        $issues = array_merge($missing, $versionMismatches);
        $success = empty($issues);

        return new DependencyCheckResult($success, $issues);
    }

    /**
     * Get installed version of a module
     */
    protected function getInstalledVersion(string $moduleName): string
    {
        $modules = $this->registry->getAvailableModules();
        
        foreach ($modules as $slug => $data) {
            if (strtolower($slug) === strtolower($moduleName) || 
                strtolower($data['name'] ?? '') === strtolower($moduleName)) {
                return $data['version'] ?? '0.0.0';
            }
        }
        
        return '0.0.0';
    }

    /**
     * Check if installed version satisfies requirement
     * Supports: ^1.0, ~1.2, >=1.0, >1.0, <=1.0, <1.0, 1.0, 1.0.*, 1.*
     */
    protected function isVersionCompatible(string $installed, string $requirement): bool
    {
        // Exact match
        if ($installed === $requirement) {
            return true;
        }

        // Caret (^) - Compatible with version (no major version change)
        if (str_starts_with($requirement, '^')) {
            return $this->checkCaretVersion($installed, substr($requirement, 1));
        }

        // Tilde (~) - Compatible with patch-level changes
        if (str_starts_with($requirement, '~')) {
            return $this->checkTildeVersion($installed, substr($requirement, 1));
        }

        // Comparison operators
        if (preg_match('/^(>=|>|<=|<|=)(.+)$/', $requirement, $matches)) {
            $operator = $matches[1];
            $version = $matches[2];
            return $this->compareVersions($installed, $operator, $version);
        }

        // Wildcard (1.0.*, 1.*)
        if (str_contains($requirement, '*')) {
            return $this->checkWildcardVersion($installed, $requirement);
        }

        // Default: exact match required
        return $installed === $requirement;
    }

    /**
     * Caret version check (^1.2.3)
     * Allows changes that do not modify left-most non-zero digit
     */
    protected function checkCaretVersion(string $installed, string $required): bool
    {
        $installedParts = explode('.', $installed);
        $requiredParts = explode('.', $required);

        // Major version must match
        if (($installedParts[0] ?? '0') !== ($requiredParts[0] ?? '0')) {
            return false;
        }

        // If major is 0, minor must match
        if (($requiredParts[0] ?? '0') === '0' && 
            ($installedParts[1] ?? '0') !== ($requiredParts[1] ?? '0')) {
            return false;
        }

        return version_compare($installed, $required, '>=');
    }

    /**
     * Tilde version check (~1.2.3)
     * Allows patch-level changes
     */
    protected function checkTildeVersion(string $installed, string $required): bool
    {
        $installedParts = explode('.', $installed);
        $requiredParts = explode('.', $required);

        // Major and minor must match
        if (($installedParts[0] ?? '0') !== ($requiredParts[0] ?? '0') ||
            ($installedParts[1] ?? '0') !== ($requiredParts[1] ?? '0')) {
            return false;
        }

        return version_compare($installed, $required, '>=');
    }

    /**
     * Compare versions using operator
     */
    protected function compareVersions(string $installed, string $operator, string $required): bool
    {
        return version_compare($installed, $required, $operator);
    }

    /**
     * Wildcard version check (1.0.*, 1.*)
     */
    protected function checkWildcardVersion(string $installed, string $pattern): bool
    {
        $installedParts = explode('.', $installed);
        $patternParts = explode('.', $pattern);

        foreach ($patternParts as $index => $part) {
            if ($part === '*') {
                return true; // Rest can be anything
            }
            
            if (($installedParts[$index] ?? '0') !== $part) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get human-readable error messages
     */
    public function getErrorMessages(DependencyCheckResult $result): array
    {
        if ($result->success) {
            return [];
        }

        $messages = [];
        foreach ($result->issues as $issue) {
            $module = $issue['module'];
            $required = $issue['required_version'];
            
            if ($issue['reason'] === 'Module not installed') {
                $messages[] = "Missing dependency: {$module} (required: {$required})";
            } else {
                $installed = $issue['installed_version'];
                $messages[] = "Version mismatch: {$module} requires {$required}, but {$installed} is installed";
            }
        }

        return $messages;
    }
}

/**
 * Dependency check result value object
 */
class DependencyCheckResult
{
    public function __construct(
        public readonly bool $success,
        public readonly array $issues
    ) {}

    public function hasIssues(): bool
    {
        return !$this->success;
    }

    public function getIssueCount(): int
    {
        return count($this->issues);
    }
}
