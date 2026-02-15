<?php

namespace Rahpt\Ci4Module\Config;

use CodeIgniter\Config\BaseService;
use Rahpt\Ci4Module\ModuleRegistry;

class Services extends BaseService
{
    /**
     * Returns the Module Registry service.
     */
    public static function modules(bool $getShared = true): ModuleRegistry
    {
        if ($getShared) {
            return static::getSharedInstance('modules');
        }

        return new ModuleRegistry(config(\Rahpt\Ci4Module\Config\Modules::class));
    }
}
