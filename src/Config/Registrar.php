<?php

namespace Rahpt\Ci4Module\Config;

/**
 * Registrar - Autoconfiguração de componentes do pacote ci4-module (Core) no CodeIgniter 4.
 */
class Registrar
{
    /**
     * Registers module namespaces for the Autoload config.
     */
    public static function Autoload(): array
    {
        $psr4 = [];
        $config = config(\Rahpt\Ci4Module\Config\Modules::class);
        $modulesPath = APPPATH . $config->basePath;

        if (is_dir($modulesPath)) {
            $folders = array_diff(scandir($modulesPath), ['.', '..']);
            foreach ($folders as $folder) {
                if (is_dir($modulesPath . DIRECTORY_SEPARATOR . $folder)) {
                    $psr4[$config->baseNamespace . '\\' . $folder] = $modulesPath . DIRECTORY_SEPARATOR . $folder;
                }
            }
        }

        return [
            'psr4' => $psr4,
        ];
    }

    /**
     * Registers module namespaces for the View config.
     */
    public static function View(): array
    {
        $namespaces = [];
        $modulesConfig = config(\Rahpt\Ci4Module\Config\Modules::class);
        $modulesPath = rtrim(APPPATH, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . trim($modulesConfig->basePath, DIRECTORY_SEPARATOR);

        if (is_dir($modulesPath)) {
            $folders = array_diff(scandir($modulesPath), ['.', '..']);
            foreach ($folders as $folder) {
                $viewPath = $modulesPath . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR . 'Views';
                if (is_dir($viewPath)) {
                    // O namespace da View será o nome da pasta (ex: Dashboard, Landingpage)
                    $namespaces[ucfirst($folder)] = $viewPath;
                }
            }
        }

        return [
            'namespaces' => $namespaces,
        ];
    }
}
