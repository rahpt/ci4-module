<?php

namespace Rahpt\Ci4Module\Validators;

use InvalidArgumentException;

/**
 * ModuleNameValidator - Enforces strict slug/identifier standards for modules.
 */
class ModuleNameValidator
{
    /**
     * Pattern: Starts with a letter, followed by 1 to 63 letters, numbers, underscores or dashes.
     */
    public const SLUG_PATTERN = '/^[A-Za-z][A-Za-z0-9_\-]{0,63}$/';

    /**
     * Validates a module name against strict identifier rules.
     * Rejects path traversal attempts, special characters, or invalid lengths.
     *
     * @throws InvalidArgumentException
     */
    public static function validate(string $module): string
    {
        $trimmed = trim($module);

        if ($trimmed === '') {
            throw new InvalidArgumentException('Module identifier cannot be empty.');
        }

        if (!preg_match(self::SLUG_PATTERN, $trimmed)) {
            throw new InvalidArgumentException(
                sprintf('Invalid module identifier: "%s". Module names must start with a letter and contain only alphanumeric characters, dashes, and underscores (max 64 chars).', $module)
            );
        }

        return $trimmed;
    }

    /**
     * Checks if a module name is valid without throwing an exception.
     */
    public static function isValid(string $module): bool
    {
        return (bool) preg_match(self::SLUG_PATTERN, trim($module));
    }
}
