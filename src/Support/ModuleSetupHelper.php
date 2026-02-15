<?php

namespace Rahpt\Ci4Module\Support;

use CodeIgniter\CLI\CLI;

/**
 * ModuleSetupHelper - Configures CodeIgniter's autoloader to support modules.
 */
class ModuleSetupHelper
{
    private const AUTOLOAD_MARKER = 'modules-autoload';
    private const AUTOLOAD_FILE = APPPATH . 'Config/Autoload.php';
    
    private static function getAutoloadTemplatePath(): string {
        return dirname(__DIR__) . '/Templates/AutoloadConstructor.tpl';
    }

    /**
     * Set up the modular environment in the application.
     */
    public static function setup(): bool
    {
        $config = config(\Rahpt\Ci4Module\Config\Modules::class);
        $namespace = $config->baseNamespace;
        $basePath = $config->basePath;
        
        if (!is_file(self::AUTOLOAD_FILE)) {
            CLI::error('Autoload.php not found.');
            return false;
        }

        if (self::isPatched()) {
            CLI::write('✔ Autoload already contains the module scanner.', 'green');
            return true;
        }

        // Backup current Autoload
        $backup = self::AUTOLOAD_FILE . '.bak';
        copy(self::AUTOLOAD_FILE, $backup);
        CLI::write("Backup created at Autoload.php.bak", 'yellow');

        $templatePath = self::getAutoloadTemplatePath();
        if (!is_file($templatePath)) {
            CLI::error('Autoload template not found.');
            return false;
        }
        
        $content = file_get_contents($templatePath);
        $content = str_replace(
            ['__namespace__', '__basePath__', '__autoload_marker__'],
            [addslashes($namespace), addslashes($basePath), self::AUTOLOAD_MARKER],
            $content
        );

        // Insert before the last closing brace of the class
        $code = file_get_contents(self::AUTOLOAD_FILE);
        $lastBracePos = strrpos($code, '}');
        
        if ($lastBracePos === false) {
            CLI::error('Could not find closing brace in Autoload.php');
            return false;
        }

        $patched = substr($code, 0, $lastBracePos) . $content . "\n" . substr($code, $lastBracePos);
        
        file_put_contents(self::AUTOLOAD_FILE, $patched);
        CLI::write('✔ Module constructor injected into Autoload.php', 'green');
        return true;
    }

    /**
     * Remove module configuration from Autoload.php.
     */
    public static function unsetup(): bool
    {
        if (!self::isPatched()) {
            CLI::write('✔ Autoload does not contain the scanner.', 'green');
            return true;
        }

        $backup = self::AUTOLOAD_FILE . '.bak';
        if (!is_file($backup)) {
            CLI::error('Backup file not found.');
            return false;
        }

        copy($backup, self::AUTOLOAD_FILE);
        CLI::write('✔ Autoload.php restored from backup.', 'green');
        return true;
    }

    /**
     * Check if Autoload.php is already patched.
     */
    public static function isPatched(): bool
    {
        if (!is_file(self::AUTOLOAD_FILE)) {
            return false;
        }
        return str_contains(file_get_contents(self::AUTOLOAD_FILE), self::AUTOLOAD_MARKER);
    }
}
