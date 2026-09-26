<?php

namespace Rahpt\Ci4Module;

abstract class BaseModule implements ModuleInterface
{
    public string $name;
    public string $label;
    public string $slug;
    public string $version = '1.0.0';
    public string $theme = 'adminlte';
    public string $routePrefix = '';
    public string $tablePrefix = '';
    public int $priority = 0;

    /**
     * Module requirements (e.g. ['php' => '>=8.2', 'codeigniter4/framework' => '^4.6', 'another_module' => '^1.0'])
     */
    public array $require = [];
    public array $requires = [];

    /**
     * Conflicting modules that cannot coexist with this module
     */
    public array $conflicts = [];

    /**
     * Virtual features or aliases provided by this module
     */
    public array $provides = [];

    /**
     * Declared Shield permissions (e.g. ['contracts.view', 'contracts.create'])
     */
    public array $permissions = [];

    /**
     * Indicates whether this module is tenant-isolated
     */
    public bool $tenantAware = false;

    /**
     * Returns the module menu items.
     */
    public function menu(): array
    {
        return [];
    }

    /**
     * Optional method executed during module installation.
     */
    public function install(): void
    {
    }

    /**
     * Called when the module is initialized.
     */
    public function initialize(): void
    {
    }

    /**
     * Called when the module is activated.
     */
    public function activate(): void
    {
    }

    /**
     * Called when the module is deactivated.
     */
    public function deactivate(): void
    {
    }

    /**
     * Optional method executed during module uninstallation.
     */
    public function uninstall(): void
    {
    }

    /**
     * Default settings definition.
     */
    public function settings(): array
    {
        return [];
    }
}
