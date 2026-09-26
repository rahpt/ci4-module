# CodeIgniter 4 Module System - Core

[![Version](https://img.shields.io/badge/version-1.3.0-blue.svg)](https://github.com/rahpt/ci4-module)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.1-brightgreen.svg)](https://php.net)

Sistema modular central para CodeIgniter 4 com arquitetura orientada a contratos, integridade criptográfica de arquivos, isolamento por quarentena, persistência atômica e ciclo de vida transacional.

---

## 📋 Índice

- [Características](#-características)
- [Requisitos](#-requisitos)
- [Instalação](#-instalação)
- [Contrato de Manifesto (BaseModule)](#-contrato-de-manifesto-basemodule)
- [Integridade Criptográfica e Quarentena](#-integridade-criptográfica-e-quarentena)
- [Ciclo de Vida e Estados](#-ciclo-de-vida-e-estados)
- [Gerenciamento e API Reference](#-gerenciamento-e-api-reference)
- [Eventos Globais](#-eventos-globais)
- [Histórico de Versões](#-histórico-de-versões)
- [Licença](#-licença)

---

## ✨ Características

### Core Features & Contratos
- ✅ **Contratos de Manifesto Ricos** - Suporte a `tablePrefix`, `priority`, `requires`, `conflicts`, `provides`, `permissions` e `tenantAware`.
- ✅ **Integridade Criptográfica (Fingerprint)** - Cálculo de hash SHA-256 determinístico dos arquivos do módulo com verificação automática antes da ativação.
- ✅ **Sistema de Quarentena** - Módulos com falha de integridade, adulteração ou erro crítico são imediatamente isolados para proteger a aplicação.
- ✅ **Persistência Atômica** - Gravação transacional segura do registro central (`modules.json`) com arquivo temporário e substituição atômica imune a colisões de escrita.
- ✅ **Dependency & Conflict Management** - Resolução de dependências com SemVer (`^`, `~`, `>=`, etc.) e bloqueio de módulos conflitantes.
- ✅ **Ciclo de Vida Completo** - Suporte a `install()`, `initialize()`, `activate()`, `deactivate()`, `uninstall()` e `settings()`.
- ✅ **Integração com RBAC/Shield** - Agregação de permissões declaradas pelos módulos via `getPermissions()`.
- ✅ **PSR-4 Autoloading** - Descoberta automática de módulos e injeção de namespaces em tempo de execução.

### Segurança & Confiabilidade
- ✅ **Type-Safe & Strict** - Código 100% tipado com PHP 8.1+ e validação estrita de nomes de módulo.
- ✅ **Event-Driven Architecture** - Emissão reativa de eventos para desacoplamento de navegação, temas e ferramentas.
- ✅ **Auditoria e Logs Detalhados** - Registro de timestamps, motivos de alteração de status e alertas de quarentena.

---

## 📦 Requisitos

- **PHP**: >= 8.1
- **CodeIgniter**: >= 4.5
- **Extensões PHP**: `json`, `fileinfo`, `hash`

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

2. **Configure `app/Config/Modules.php`**:
```php
<?php

namespace Config;

use Rahpt\Ci4Module\Config\Modules as BaseModules;

class Modules extends BaseModules
{
    public string $basePath = 'Modules';
    public string $baseNamespace = 'App\\Modules';
    public string $registrationFile = 'modules.json';
    public string $defaultTheme = 'adminlte';
}
```

3. **Registre o serviço em `app/Config/Services.php`**:
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

## 🏗️ Contrato de Manifesto (`BaseModule`)

Cada módulo declara sua identidade, requisitos e comportamentos em `Config/Module.php` estendendo `BaseModule`.

```php
<?php

namespace App\Modules\Financeiro\Config;

use Rahpt\Ci4Module\BaseModule;

class Module extends BaseModule
{
    // Identificação básica
    public string $name = 'Financeiro';
    public string $label = 'Gestão Financeira';
    public string $slug = 'financeiro';
    public string $version = '1.3.0';
    public string $theme = 'adminlte';
    public string $routePrefix = 'financeiro';
    
    // Configurações arquiteturais
    public string $tablePrefix = 'fin_';  // Prefixo para tabelas do módulo
    public int $priority = 10;            // Prioridade de inicialização e menu
    public bool $tenantAware = true;      // Suporta isolamento Multi-Tenant

    // Requisitos de dependências com SemVer
    public array $require = [
        'php' => '>=8.1',
        'codeigniter4/framework' => '^4.5',
        'auth' => '^1.0'
    ];

    // Conflitos: módulos que NÃO podem coexistir ativos
    public array $conflicts = [
        'financeiro-legado'
    ];

    // Capacidades providas para outros módulos consumirem
    public array $provides = [
        'billing-engine',
        'invoicing'
    ];

    // Permissões Shield declaradas pelo módulo
    public array $permissions = [
        'financeiro.view',
        'financeiro.create',
        'financeiro.admin'
    ];

    /**
     * Declaração de menu integrado
     */
    public function menu(): array
    {
        return [
            [
                'label'      => 'Financeiro',
                'url'        => 'financeiro',
                'icon'       => 'fas fa-dollar-sign',
                'permission' => 'financeiro.view',
                'order'      => $this->priority
            ]
        ];
    }

    /**
     * Ciclo de Vida: Executado ao instalar
     */
    public function install(): void
    {
        // Migrations e seeds iniciais
    }

    /**
     * Ciclo de Vida: Executado em toda requisição se o módulo estiver ativo
     */
    public function initialize(): void
    {
        // Registro de bindings, listeners ou serviços locais
    }

    /**
     * Ciclo de Vida: Executado imediatamente antes da ativação
     */
    public function activate(): void
    {
        // Validações pré-ativação
    }

    /**
     * Ciclo de Vida: Executado na desativação
     */
    public function deactivate(): void
    {
        // Limpeza de caches efêmeros
    }

    /**
     * Ciclo de Vida: Executado ao desinstalar
     */
    public function uninstall(): void
    {
        // Remoção segura de recursos locais
    }

    /**
     * Configurações dinâmicas gerenciadas via painel
     */
    public function settings(): array
    {
        return [
            'financeiro' => [
                'label' => 'Configurações de Faturamento',
                'fields' => [
                    'moeda_padrao' => ['type' => 'text', 'label' => 'Moeda', 'default' => 'BRL'],
                    'dias_vencimento' => ['type' => 'number', 'label' => 'Dias Vencimento', 'default' => 5],
                ]
            ]
        ];
    }
}
```

---

## 🔒 Integridade Criptográfica e Quarentena

Para garantir que módulos em produção não sofram modificações não auditadas, injeções maliciosas ou corrupção de arquivos, o `ModuleRegistry` conta com verificação criptográfica:

### 1. Cálculo de Fingerprint
Gera um hash SHA-256 determinístico baseado nos hashes individuais (SHA-1) de todos os arquivos do módulo ordenados alfabeticamente:
```php
$registry = service('modules');
$fingerprint = $registry->computeFingerprint('financeiro');
```

### 2. Verificação Contínua
Durante a ativação (`activate()`), o registry executa automaticamente `verifyIntegrity($module)`. Se o hash real divergir do hash registrado no manifesto ou no registro central, o módulo é **bloqueado e enviado para Quarentena**:
```php
if (! $registry->verifyIntegrity('financeiro')) {
    // Módulo foi colocado em STATUS_QUARANTINED
}
```

### 3. Isolamento em Quarentena
Módulos em quarentena são desativados com status `quarantined`, recebem registro do motivo (`status_reason`), registram log crítico e disparam o evento `rahpt.module.quarantined`:
```php
$registry->quarantine('financeiro', 'Assinatura SHA-256 divergente dos arquivos locais.');
```

---

## 🔄 Ciclo de Vida e Estados

O ciclo de vida de cada módulo transita de maneira determinística entre os seguintes estados:

| Status | Descrição |
| :--- | :--- |
| `discovered` | Módulo detectado no disco, aguardando instalação. |
| `installed` | Módulo instalado, migrações rodadas, mas inativo. |
| `activating` | Transição de ativação em andamento (executando hook `activate()`). |
| `active` | Módulo ativo, íntegro e operacional na aplicação. |
| `deactivating` | Transição de desativação em andamento. |
| `inactive` | Módulo desativado com segurança. |
| `quarantined` | Módulo bloqueado por violação de integridade ou segurança. |
| `failed` | Falha ao ativar (ex: dependências ausentes ou erro em hook). |

---

## 🔧 Gerenciamento e API Reference

O serviço `service('modules')` (`Rahpt\Ci4Module\ModuleRegistry`) provê a API central:

```php
use Rahpt\Ci4Module\ModuleRegistry;

$registry = service('modules');

// Ativação com verificação de integridade e dependências
$success = $registry->activate('financeiro');

// Desativação segura
$registry->deactivate('financeiro');

// Consulta de status do ciclo de vida
$status = $registry->getStatus('financeiro'); // 'active', 'quarantined', etc.

// Obter todos os módulos com status detalhado e timestamps
$modules = $registry->getModulesWithStatus();

// Gravar e atualizar impressão digital de integridade
$registry->recordFingerprint('financeiro');

// Listar todas as permissões Shield declaradas pelos módulos
$permissions = $registry->getPermissions();
// Exemplo: ['financeiro.view', 'financeiro.create', 'dashboard.access']

// Obter caminho absoluto do módulo no disco
$path = $registry->getInstallPath('financeiro');
```

---

## 🔔 Eventos Globais

O ecossistema emite eventos nativos do CodeIgniter 4 (`\CodeIgniter\Events\Events`):

| Evento | Argumentos | Momento |
| :--- | :--- | :--- |
| `rahpt.module.changed` | `$module, $data` | Qualquer alteração gravada atomicamente no registro. |
| `rahpt.module.status_changed` | `$module, $status, $reason` | Transição de status do ciclo de vida. |
| `rahpt.module.quarantined` | `$module, $reason` | Módulo isolado por quebra de integridade. |
| `rahpt.module.activated` | `$module` | Ativação concluída com sucesso. |
| `rahpt.module.deactivated` | `$module` | Desativação concluída com sucesso. |
| `rahpt.module.activation_failed` | `$module, $throwable` | Falha de ativação ou erro em hook. |

---

## 🕒 Histórico de Versões

### [1.3.0] - 2026-09-26
- **Novo**: Contratos de manifesto expandidos em `BaseModule`: `tablePrefix`, `priority`, `conflicts`, `provides`, `permissions`, `tenantAware` e `routePrefix`.
- **Novo**: Verificação de integridade criptográfica com `computeFingerprint()` e `verifyIntegrity()`.
- **Novo**: Sistema de Quarentena (`quarantine()`) para módulos adulterados ou corrompidos.
- **Novo**: Gravação atômica (`writeAtomic()`) do registro de módulos imune a concorrência.
- **Novo**: Suporte completo a agregação de permissões Shield via `getPermissions()`.
- **Novo**: Máquina de estados detalhada do ciclo de vida (`activating`, `quarantined`, `failed`).
- **Novo**: Eventos desacoplados `rahpt.module.quarantined` e `rahpt.module.status_changed`.

### [1.2.0] - 2026-02-18
- **Novo**: Suporte a hooks de Ciclo de Vida: `uninstall()` e `settings()`.
- **Arquitetura**: Novo método `getInstallPath()` no `ModuleRegistry`.
- **Melhoria**: Sistema de cache de instâncias aprimorado.
- **Segurança**: Validação de caminhos durante a desinstalação automática.

### [1.1.0] - 2026-02-16
- **Segurança**: Sanitização rigorosa de slugs de módulos.
- **Arquitetura**: Eventos `rahpt.module.changed` para invalidação reativa.

### [1.0.1] - 2026-02-15
- Versão inicial estável.

---

## 📄 Licença

Distribuído sob a licença MIT. Veja `LICENSE` para mais informações.

---

## 👏 Créditos

Desenvolvido por **Rahpt**  
Mantido pela comunidade Rahpt / CodeIgniter 4 Modular.
