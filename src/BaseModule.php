<?php

namespace Rahpt\Ci4Module;

abstract class BaseModule implements ModuleInterface {

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
    public function menu(): array {
        return [];
    }

    /**
     * Optional method executed during module installation.
     */
    public function install(): void {
        // Optional implementation
    }
}
