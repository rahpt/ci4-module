<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Rahpt\Ci4Module\ModuleRegistry;
use Rahpt\Ci4Module\Config\Modules;

class ModuleRegistryTest extends TestCase
{
    protected string $tempPath;
    protected Modules $config;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempPath = sys_get_temp_dir() . '/ci4-module-tests';
        if (!is_dir($this->tempPath)) {
            mkdir($this->tempPath, 0777, true);
        }

        // Mock APPPATH and WRITEPATH if not defined
        if (!defined('APPPATH')) {
            define('APPPATH', $this->tempPath . '/');
        }
        if (!defined('WRITEPATH')) {
            define('WRITEPATH', $this->tempPath . '/writable/');
            mkdir(WRITEPATH, 0777, true);
        }

        $this->config = new Modules();
        $this->config->basePath = 'Modules';
        $this->config->registrationFile = 'modules.json';
    }

    protected function tearDown(): void
    {
        $this->recursiveDelete($this->tempPath);
        parent::tearDown();
    }

    protected function recursiveDelete(string $dir): void
    {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->recursiveDelete("$dir/$file") : unlink("$dir/$file");
        }
        rmdir($dir);
    }

    public function testSaveAndStatus()
    {
        $registry = new ModuleRegistry($this->config);
        $registry->put('Blog', ['active' => true]);

        $loaded = $registry->all('Blog');
        $this->assertArrayHasKey('Blog', $loaded);
        $this->assertTrue($loaded['Blog']['active']);
    }

    public function testGetAvailableModules()
    {
        // Create a dummy module class
        $moduleDir = APPPATH . 'Modules/Blog/Config';
        mkdir($moduleDir, 0777, true);
        
        $content = "<?php namespace App\Modules\Blog\Config; class Module { public string \$name = 'Blog'; public string \$label = 'My Blog'; }";
        $file = $moduleDir . '/Module.php';
        file_put_contents($file, $content);
        require_once $file;

        $registry = new ModuleRegistry($this->config);
        $registry->activate('Blog');
        
        $modules = $registry->getAvailableModules();
        $this->assertArrayHasKey('blog', $modules);
        $this->assertEquals('My Blog', $modules['blog']['label']);
        $this->assertTrue($modules['blog']['active']);
    }

    public function testActivateDeactivate()
    {
        $registry = new ModuleRegistry($this->config);
        $registry->put('Test', ['active' => false]);
        
        $registry->activate('Test');
        $data = $registry->all('Test');
        $this->assertTrue($data['Test']['active']);

        $registry->deactivate('Test');
        $data = $registry->all('Test');
        $this->assertFalse($data['Test']['active']);
    }
}
