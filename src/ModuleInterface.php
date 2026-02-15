<?php

namespace Rahpt\Ci4Module;

interface ModuleInterface {
    
    /**
     * Returns the module menu items.
     * Should follow an array format with label, route, icon, items.
     */
    public function menu(): array;

    /**
     * Method executed during module installation or initialization.
     */
    public function install(): void;
}
