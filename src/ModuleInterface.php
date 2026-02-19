<?php

namespace Rahpt\Ci4Module;

interface ModuleInterface
{

    /**
     * Returns the module menu items.
     * Should follow an array format with label, route, icon, items.
     */
    public function menu(): array;

    /**
     * Method executed during module installation.
     */
    public function install(): void;

    /**
     * Method executed when the module is initialized.
     */
    public function initialize(): void;

    /**
     * Method executed when the module is activated.
     */
    public function activate(): void;

    /**
     * Method executed when the module is deactivated.
     */
    public function deactivate(): void;

    /**
     * Method executed during module uninstallation (deletion).
     */
    public function uninstall(): void;

    /**
     * Returns the module settings definition.
     */
    public function settings(): array;
}
