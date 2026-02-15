<?php

namespace Rahpt\Ci4Module\Config;

use CodeIgniter\Config\BaseConfig;

class Modules extends BaseConfig
{
    /**
     * The directory where modules are located.
     * Relative to APPPATH.
     */
    public string $basePath = 'Modules';

    /**
     * The base namespace for modules.
     */
    public string $baseNamespace = 'App\\Modules';

    /**
     * Registration file name inside each module.
     */
    public string $registrationFile = 'modules.json';

    /**
     * Default theme for modules.
     */
    public string $defaultTheme = 'adminlte';
}
