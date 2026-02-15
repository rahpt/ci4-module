
    /**
     * __autoload_marker__
     */
    public function __construct()
    {
        parent::__construct();
        $this->scanModules();
    }

    /**
     * Scans the modules directory and registers namespaces.
     */
    private function scanModules(): void
    {
        $modulesPath = APPPATH . '__basePath__';
        if (!is_dir($modulesPath)) {
            return;
        }

        $folders = array_diff(scandir($modulesPath), ['.', '..']);
        foreach ($folders as $folder) {
            if (!is_dir($modulesPath . DIRECTORY_SEPARATOR . $folder)) {
                continue;
            }
            
            $namespace = "__namespace__\\" . $folder;
            $path = $modulesPath . DIRECTORY_SEPARATOR . $folder . DIRECTORY_SEPARATOR;
            
            if (!isset($this->psr4[$namespace])) {
                $this->psr4[$namespace] = $path;
            }
        }
    }
