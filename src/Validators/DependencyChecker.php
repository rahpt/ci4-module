<?php

namespace Rahpt\Ci4Module\Validators;

use Exception;
use Rahpt\Ci4Module\ModuleRegistry;

/**
 * DependencyChecker - Validates module dependencies, conflicts, environment requirements, and virtual provides.
 */
class DependencyChecker
{
    protected ModuleRegistry $registry;

    public function __construct(?ModuleRegistry $registry = null)
    {
        $this->registry = $registry ?? service('modules');
    }

    /**
     * Check if all dependencies, environment constraints, and conflict rules are satisfied.
     */
    public function check(string $moduleName): DependencyCheckResult
    {
        $metadata = $this->getModuleMetadata($moduleName);
        $dependencies = $metadata['require'] ?? $metadata['requires'] ?? [];
        $conflicts = $metadata['conflicts'] ?? [];

        $issues = [];

        // 1. Validate requirements (requires)
        foreach ($dependencies as $depName => $requiredVersion) {
            $depLower = strtolower($depName);

            // PHP runtime requirement check
            if ($depLower === 'php') {
                if (!$this->isVersionCompatible(PHP_VERSION, $requiredVersion)) {
                    $issues[] = [
                        'type'              => 'runtime',
                        'module'            => 'php',
                        'required_version'  => $requiredVersion,
                        'installed_version' => PHP_VERSION,
                        'reason'            => 'PHP version requirement not met',
                    ];
                }
                continue;
            }

            // CodeIgniter 4 framework version check
            if ($depLower === 'codeigniter4/framework' || $depLower === 'ci4' || $depLower === 'codeigniter') {
                $ciVersion = defined('\CodeIgniter\CodeIgniter::CI_VERSION') ? \CodeIgniter\CodeIgniter::CI_VERSION : '4.0.0';
                if (!$this->isVersionCompatible($ciVersion, $requiredVersion)) {
                    $issues[] = [
                        'type'              => 'runtime',
                        'module'            => 'CodeIgniter',
                        'required_version'  => $requiredVersion,
                        'installed_version' => $ciVersion,
                        'reason'            => 'CodeIgniter version requirement not met',
                    ];
                }
                continue;
            }

            // Check if installed or provided by another module
            if (!$this->isInstalledOrProvided($depName)) {
                $issues[] = [
                    'type'             => 'missing',
                    'module'           => $depName,
                    'required_version' => $requiredVersion,
                    'reason'           => 'Module not installed or provided',
                ];
                continue;
            }

            // Check version compatibility if module is directly installed
            $installedVersion = $this->getInstalledVersion($depName);
            if ($installedVersion !== null && !$this->isVersionCompatible($installedVersion, $requiredVersion)) {
                $issues[] = [
                    'type'              => 'version_mismatch',
                    'module'            => $depName,
                    'required_version'  => $requiredVersion,
                    'installed_version' => $installedVersion,
                    'reason'            => 'Version mismatch',
                ];
            }
        }

        // 2. Validate declared conflicts
        foreach ($conflicts as $conflictKey => $conflictVal) {
            $conflictName = is_int($conflictKey) ? $conflictVal : $conflictKey;
            if ($this->registry->isInstalled($conflictName)) {
                $issues[] = [
                    'type'             => 'conflict',
                    'module'           => $conflictName,
                    'required_version' => 'none',
                    'reason'           => "Module conflicts with installed module '{$conflictName}'",
                ];
            }
        }

        // 3. Validate reverse conflicts (is this module conflicted by an already installed module?)
        $allModules = $this->registry->getAvailableModules();
        $targetSlug = strtolower($moduleName);

        foreach ($allModules as $slug => $data) {
            if ($slug === $targetSlug) {
                continue;
            }

            $otherConflicts = $data['conflicts'] ?? [];
            foreach ($otherConflicts as $cKey => $cVal) {
                $conflicting = strtolower(is_int($cKey) ? $cVal : $cKey);
                if ($conflicting === $targetSlug) {
                    $issues[] = [
                        'type'             => 'conflict',
                        'module'           => $slug,
                        'required_version' => 'none',
                        'reason'           => "Installed module '{$slug}' conflicts with '{$moduleName}'",
                    ];
                }
            }
        }

        $success = empty($issues);
        return new DependencyCheckResult($success, $issues);
    }

    /**
     * Checks if a package name is directly installed or provided by another active module.
     */
    protected function isInstalledOrProvided(string $name): bool
    {
        if ($this->registry->isInstalled($name)) {
            return true;
        }

        $nameLower = strtolower($name);
        $modules = $this->registry->getAvailableModules();

        foreach ($modules as $data) {
            $provides = $data['provides'] ?? [];
            foreach ($provides as $provKey => $provVal) {
                $providedName = strtolower(is_int($provKey) ? $provVal : $provKey);
                if ($providedName === $nameLower) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Get module metadata.
     */
    protected function getModuleMetadata(string $moduleName): array
    {
        $modules = $this->registry->getAvailableModules();
        $target = strtolower($moduleName);

        foreach ($modules as $slug => $data) {
            if (strtolower($slug) === $target || strtolower($data['name'] ?? '') === $target) {
                return $data;
            }
        }

        return [];
    }

    /**
     * Get installed version of a module.
     */
    protected function getInstalledVersion(string $moduleName): ?string
    {
        $modules = $this->registry->getAvailableModules();
        $target = strtolower($moduleName);

        foreach ($modules as $slug => $data) {
            if (strtolower($slug) === $target || strtolower($data['name'] ?? '') === $target) {
                return $data['version'] ?? '0.0.0';
            }
        }

        return null;
    }

    /**
     * Check if installed version satisfies requirement.
     * Supports: ^1.0, ~1.2, >=1.0, >1.0, <=1.0, <1.0, 1.0, 1.0.*, 1.*
     */
    public function isVersionCompatible(string $installed, string $requirement): bool
    {
        $requirement = trim($requirement);

        if ($installed === $requirement || $requirement === '*' || empty($requirement)) {
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
            $version = trim($matches[2]);
            return version_compare($installed, $version, $operator);
        }

        // Wildcard (1.0.*, 1.*)
        if (str_contains($requirement, '*')) {
            return $this->checkWildcardVersion($installed, $requirement);
        }

        return $installed === $requirement;
    }

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

    protected function checkWildcardVersion(string $installed, string $pattern): bool
    {
        $installedParts = explode('.', $installed);
        $patternParts = explode('.', $pattern);

        foreach ($patternParts as $index => $part) {
            if ($part === '*') {
                return true;
            }

            if (($installedParts[$index] ?? '0') !== $part) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get human-readable error messages.
     */
    public function getErrorMessages(DependencyCheckResult $result): array
    {
        if ($result->success) {
            return [];
        }

        $messages = [];
        foreach ($result->issues as $issue) {
            $reason = $issue['reason'] ?? 'Dependency issue';
            $module = $issue['module'] ?? 'unknown';

            if (($issue['type'] ?? '') === 'conflict') {
                $messages[] = "Conflict error: {$reason}";
            } elseif (($issue['type'] ?? '') === 'missing') {
                $req = $issue['required_version'] ?? '*';
                $messages[] = "Missing dependency: {$module} (required: {$req})";
            } elseif (($issue['type'] ?? '') === 'version_mismatch') {
                $req = $issue['required_version'] ?? '';
                $inst = $issue['installed_version'] ?? '';
                $messages[] = "Version mismatch: {$module} requires {$req}, but {$inst} is installed";
            } else {
                $messages[] = "{$module}: {$reason}";
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
