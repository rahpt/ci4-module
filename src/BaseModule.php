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
    public array $require = [];

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
