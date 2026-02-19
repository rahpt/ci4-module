# CodeIgniter 4 Module System - Core

[![Version](https://img.shields.io/badge/version-1.2.0-blue.svg)](https://github.com/rahpt/ci4-module)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.1-brightgreen.svg)](https://php.net)

Sistema modular central para CodeIgniter 4 que permite criar aplicações com arquitetura modular, ativação dinâmica de módulos e gerenciamento de dependências.

---

## 📋 Índice

- [Características](#características)
- [Requisitos](#requisitos)
- [Instalação](#instalação)
- [Uso Básico](#uso-básico)
- [Arquitetura](#arquitetura)
- [API Reference](#api-reference)
- [Validadores](#validadores)
- [Performance](#performance)
- [Testes](#testes)
- [Contribuindo](#contribuindo)

---

## ✨ Características

### Core Features
- ✅ **Módulos dinâmicos** - Instale e ative módulos sem alterar código
- ✅ **Dependency Management** - Sistema completo de gerenciamento de dependências com SemVer
- ✅ **Structure Validation** - Validação automática de estrutura de módulos
- ✅ **Instance Caching** - Cache automático para melhor performance
- ✅ **PSR-4 Autoloading** - Descoberta automática de módulos
- ✅ **Timestamps** - Rastreamento de instalação e ativação
- ✅ **Lifecycle Hooks** - Suporte a `install()`, `uninstall()`, `activate()`, `deactivate()` e `initialize()`.

### Security & Performance
- ✅ **Type-Safe** - PHP 8.1+ com strict types
- ✅ **Cached Metadata** - Metadados de módulos em cache
- ✅ **Logging** - Logs detalhados de todas as operações
- ✅ **Safe Activation** - Validação de dependências antes de ativar

---

## 📦 Requisitos

- **PHP**: >= 8.1
- **CodeIgniter**: >= 4.5
- **Extensions**: json, fileinfo

---

## 🚀 Instalação

### Via Composer

```bash
composer require rahpt/ci4-module
```

### Configuração

1. **Copie o arquivo de configuração**:
```bash
cp vendor/rahpt/ci4-module/src/Config/Modules.php app/Config/Modules.php
```

2. **Configure** `app/Config/Modules.php`:
```php
<?php

namespace Config;

use Rahpt\Ci4Module\Config\Modules as BaseModules;

class Modules extends BaseModules
{
    public string $basePath = 'Modules';
    public string $baseNamespace = 'App\\Modules';
    public string $registrationFile = 'modules.json';
}
```

3. **Crie o diretório de módulos**:
```bash
mkdir app/Modules
```

4. **Registre o serviço** em `app/Config/Services.php`:
```php
public static function modules(bool $getShared = true)
{
    if ($getShared) {
        return static::getSharedInstance('modules');
    }

    return new \Rahpt\Ci4Module\ModuleRegistry();
}
```

---

## 📖 Uso Básico

### Criando um Módulo

```
app/Modules/Dashboard/
├── Config/
│   └── Module.php
├── Controllers/
│   └── DashboardController.php
├── Models/
│   └── DashboardModel.php
├── Views/
│   └── index.php
└── README.md
```

**Config/Module.php**:
```php
<?php

namespace App\Modules\Dashboard\Config;

use Rahpt\Ci4Module\BaseModule;

class Module extends BaseModule
{
    public string $name = 'Dashboard';
    public string $label = 'Dashboard Principal';
    public string $slug = 'dashboard';
    public string $version = '1.0.0';
    public string $theme = 'adminlte';
    
    // Defina dependências
    public array $require = [
        'auth' => '^1.0',      // SemVer support
        'database' => '~2.1'   // Tilde, caret, >=, etc
    ];
    
    public function menu(): array
    {
        return [
            [
                'label' => 'Dashboard',
                'url' => 'dashboard',
                'icon' => 'fas fa-tachometer-alt'
            ]
        ];
    }
    
    public function install(): void
    {
        // Roda migrações ou seeds
    }
    
    public function uninstall(): void
    {
        // Limpa banco de dados
    }

    public function settings(): array
    {
        // Define configurações dinâmicas
        return [
            'dashboard' => [
                'label' => 'Configurações de Dashboard',
                'fields' => [
                   'items_per_page' => ['type' => 'number', 'label' => 'Itens por Página', 'default' => 10]
                ]
            ]
        ];
    }
}
```

### Gerenciando Módulos

```php
$registry = service('modules');

// Verificar se módulo está instalado
if ($registry->isInstalled('Dashboard')) {
    echo "Dashboard está instalado!";
}

// Ativar módulo (com validação de dependências)
$registry->activate('dashboard');

// Obter status completo
$status = $registry->getModulesWithStatus();
```

---

## 🏗️ Arquitetura

### 1. ModuleRegistry

Classe central que gerencia o registro de módulos.

**Principais Métodos**:
- `getAvailableModules()` - Lista todos os módulos
- `activate(string $module)` - Ativa um módulo
- `deactivate(string $module)` - Desativa um módulo
- `getInstallPath(string $slug)` - Retorna o path absoluto do módulo
- `getModulesWithStatus()` - Status completo com timestamps

### 2. BaseModule

Classe base para módulos. Implementa `ModuleInterface`.

---

## 🔍 Histórico de Versões

### [1.2.0] - 2026-02-18
- **Novo**: Adicionado suporte a hooks de Ciclo de Vida: `uninstall()` e `settings()`.
- **Arquitetura**: Novo método `getInstallPath()` no `ModuleRegistry` para facilitar localização de arquivos.
- **Melhoria**: Sistema de cache de instâncias aprimorado.
- **Segurança**: Validação de caminhos durante a desinstalação automática.

### [1.1.0] - 2026-02-16
- **Segurança**: Adicionada sanitização rigorosa de slugs de módulos para prevenir manipulação de caminhos.
- **Arquitetura**: Implementação de sistema de Eventos (Event-Driven) para desacoplamento de pacotes.
- **Melhoria**: Sistema de logs aprimorado para rastreabilidade de ativação.

### [1.0.1] - 2026-02-15
- Estabilização do sistema de autoload.
- Correção de dependências SemVer.

---

## 📄 Licença

MIT License

---

## 👏 Créditos

Desenvolvido por **Rahpt**

---

**Versão**: 1.2.0  
**Última Atualização**: 2026-02-18
