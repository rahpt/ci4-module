# CodeIgniter 4 Module System - Core Kernel

[![Version](https://img.shields.io/badge/version-1.4.0-blue.svg)](https://github.com/rahpt/ci4-module)
[![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/php-%3E%3D8.1-brightgreen.svg)](https://php.net)
[![CodeIgniter](https://img.shields.io/badge/CodeIgniter-%3E%3D4.5-orange.svg)](https://codeigniter.com)

Kernel modular central para CodeIgniter 4 com máquina de estados finita, validação estrita de transições, manifesto declarativo versionado (`module.json`), integridade criptográfica determinística, contratos transversais de auditoria e health check, isolamento por quarentena e persistência atômica transacional.

---

## 🏛️ Arquitetura da Plataforma

```text
                    RAHPT MODULAR PLATFORM

                         CodeIgniter 4
                              |
                    +---------+---------+
                    |                   |
                 Shield             Application
                    |                   |
                    +---------+---------+
                              |
                       +------+------+
                       | ci4-module |
                       | Kernel Core |
                       +------+------+
                              |
          +-------------------+-------------------+
          |                   |                   |
          v                   v                   v
      tenancy               tools                nav
          |                   |                   |
          +-------------------+----------+--------+
                                         |
                                         v
                                       theme
                                         |
                                         v
                                  UI / Design System
```

---

## 📋 Índice

- [Características](#-características)
- [Requisitos](#-requisitos)
- [Instalação e Configuração](#-instalação-e-configuração)
- [Manifesto Declarativo (module.json) vs Implementação](#-manifesto-declarativo-modulejson-vs-implementação)
- [Contrato BaseModule](#-contrato-basemodule)
- [Máquina de Estados Finita e Ciclo de Vida](#-máquina-de-estados-finita-e-ciclo-de-vida)
- [Integridade Criptográfica e Quarentena](#-integridade-criptográfica-e-quarentena)
- [Contratos Transversais (AuditEvent e HealthCheck)](#-contratos-transversais-auditevent-e-healthcheck)
- [Gerenciamento e API Reference](#-gerenciamento-e-api-reference)
- [Eventos Globais](#-eventos-globais)
- [Histórico de Versões](#-histórico-de-versões)
- [Licença](#-licença)

---

## ✨ Características

### Kernel Modular & Máquina de Estados
- ✅ **Máquina de Estados Finita Estrita** - Transições controladas e semânticas (`discover`, `validate`, `install`, `activate`, `deactivate`, `quarantine`, `rollback`) que bloqueiam saltos arbitrários e transições inválidas com `DomainException`.
- ✅ **Manifesto Declarativo Versionado (`module.json`)** - Schema 1.0 formal para especificação estática de metadados, dependências, conflitos, permissões e capacidades (`capabilities`).
- ✅ **Separação Declaração vs Implementação** - `module.json` como fonte declarativa pura de verdade e `Config/Module.php` (`BaseModule`) para execução e ganchos em runtime.
- ✅ **Resolução SemVer de Dependências** - Suporte completo a restrições de versão (`^`, `~`, `>=`, `<`, etc.) para PHP, CodeIgniter e outros módulos.
- ✅ **Prevenção de Conflitos** - Bloqueio determinístico de módulos incompatíveis declarados em `conflicts`.

### Segurança, Integridade & Confiabilidade
- ✅ **Integridade Criptográfica (Canonical Checksum)** - Hash SHA-256 canônico gerado a partir de lista ordenada de caminhos relativos de arquivos e hash determinístico do manifesto.
- ✅ **Diferenciação Integridade vs Autenticidade** - Verificação contínua contra alterações não autorizadas em disco e base arquitetural para assinaturas de publisher (ex: Ed25519).
- ✅ **Isolamento por Quarentena** - Módulos com falha de integridade, adulteração ou erro fatal em hook são imediatamente neutralizados e colocados em quarentena sem corromper a aplicação.
- ✅ **Persistência Atômica Transacional** - Gravação do arquivo de registro (`modules.json`) com arquivo temporário e substituição atômica (`rename`), imune a falhas parciais e concorrência.
- ✅ **Zero-Trust Autoloading** - Validação estrita de namespaces PSR-4 e caminhos no disco.

### Observabilidade & Contratos Transversais
- ✅ **Contrato AuditEvent** - Objeto de valor imutável (`readonly class AuditEvent`) para padronizar logs estruturados de auditoria em todos os pacotes da plataforma.
- ✅ **Contrato HealthCheckInterface** - Padronização de verificações de integridade (`HealthResult`) para monitoramento em tempo real do estado operacional do módulo.
- ✅ **Agregação de Permissões Shield** - Coleta centralizada de permissões via `getPermissions()` para alimentar RBAC e controle de acesso.

---

## 📦 Requisitos

- **PHP**: >= 8.1
- **CodeIgniter**: >= 4.5
- **Extensões PHP**: `json`, `fileinfo`, `hash`

---

## 🚀 Instalação e Configuração

### 1. Instalação via Composer

```bash
composer require rahpt/ci4-module
```

### 2. Arquivo de Configuração

Copie o arquivo base para `app/Config/Modules.php`:

```bash
cp vendor/rahpt/ci4-module/src/Config/Modules.php app/Config/Modules.php
```

Personalize as diretrizes:

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
    
    // Regras de validação de estrutura mínima obrigatória
    public array $requiredStructure = [
        'Config/Module.php',
    ];
}
```

### 3. Registro do Serviço

Em `app/Config/Services.php`:

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

## 📄 Manifesto Declarativo (`module.json`) vs Implementação

Para garantir separação clara de responsabilidades, o ecossistema Rahpt adota o manifesto estático `module.json` como especificação declarativa de metadados:

### Schema Formal `module.json` (v1.0)

```json
{
  "schema": "1.0",
  "name": "Contratos",
  "slug": "contratos",
  "version": "2.1.0",
  "description": "Módulo de gestão de contratos e faturamento",
  "requires": {
    "php": ">=8.1",
    "codeigniter4/framework": "^4.5",
    "financeiro": "^2.0"
  },
  "optionalRequires": {
    "notificacoes": "^1.0"
  },
  "provides": [
    "contract-management",
    "document-signing"
  ],
  "conflicts": [
    "contratos-legado"
  ],
  "permissions": [
    "contratos.view",
    "contratos.create",
    "contratos.edit",
    "contratos.delete",
    "contratos.admin"
  ],
  "tenant_aware": true,
  "capabilities": {
    "web": true,
    "api": true,
    "cli": false,
    "jobs": true,
    "events": true,
    "health": true,
    "settings": true,
    "navigation": true,
    "migrations": true
  }
}
```

---

## 🏗️ Contrato `BaseModule`

A implementação em código PHP é declarada em `Config/Module.php` estendendo `BaseModule`:

```php
<?php

namespace App\Modules\Contratos\Config;

use Rahpt\Ci4Module\BaseModule;

class Module extends BaseModule
{
    public string $name = 'Contratos';
    public string $label = 'Gestão de Contratos';
    public string $slug = 'contratos';
    public string $version = '2.1.0';
    public string $theme = 'adminlte';
    public string $routePrefix = 'contratos';
    
    public string $tablePrefix = 'cnt_';
    public int $priority = 20;
    public bool $tenantAware = true;

    public array $require = [
        'php' => '>=8.1',
        'codeigniter4/framework' => '^4.5',
    ];

    public array $conflicts = ['contratos-legado'];
    public array $provides = ['contract-management'];
    public array $permissions = ['contratos.view', 'contratos.create'];

    public function install(): void
    {
        // Migrations e inicializações de persistência
    }

    public function initialize(): void
    {
        // Listeners, bindings e registro de rotas/serviços
    }

    public function activate(): void
    {
        // Pré-condições antes de marcar o módulo como ativo
    }

    public function deactivate(): void
    {
        // Limpeza de recursos e caches
    }

    public function uninstall(): void
    {
        // Remoção segura de tabelas e artefatos
    }

    public function settings(): array
    {
        return [
            'contratos' => [
                'label' => 'Configurações de Contratos',
                'fields' => [
                    'dias_notificacao' => ['type' => 'number', 'label' => 'Aviso Prévio (dias)', 'default' => 30],
                ]
            ]
        ];
    }
}
```

---

## 🔄 Máquina de Estados Finita e Ciclo de Vida

O `ModuleRegistry` opera como uma **máquina de estados finita rigorosa**. Nenhuma transição de status pode violar a tabela de estados permitidos:

```text
  [discovered] ─── validate() ───► [validated] ─── install() ───► [installed]
                                                                        │
                                                                    activate()
                                                                        ▼
  [active] ◄─── (concluído) ─── [activating]
     │
 deactivate()
     ▼
[deactivating] ──► [disabled / inactive]

*Qualquer Estado com Falha Crítica* ──► [failed]
   │
   ├─► quarantine() ──► [quarantined] ──► rollback()
   └─► validate()   ──► [validated]
```

### Tabela de Transições Controladas

| Estado Atual | Operações Válidas | Próximo Estado |
| :--- | :--- | :--- |
| `discovered` | `validate()` | `validated` |
| `validated` | `install()` | `installed` |
| `installed` | `activate()` | `activating` -> `active` |
| `active` | `deactivate()` | `deactivating` -> `inactive` |
| `inactive` | `activate()` | `activating` -> `active` |
| *Qualquer estado* | Erro crítico detectado | `failed` |
| `failed` | `quarantine()` | `quarantined` |
| `failed` | `validate()` | `validated` (após correção) |
| `quarantined` | `rollback()` ou `validate()` | `discovered` ou `validated` |

Qualquer tentativa de transição não permitida resulta em `DomainException`, garantindo que módulos em estado inconsistente nunca sejam ativados.

---

## 🔒 Integridade Criptográfica e Quarentena

### 1. Checksum Canônico Determinístico
O cálculo de fingerprint gera um SHA-256 canônico a partir de:
1. Lista ordenada alfabeticamente dos caminhos relativos de todos os arquivos.
2. Hash determinístico do conteúdo de cada arquivo.
3. Hash canônico do manifesto declarativo `module.json`.

```php
$registry = service('modules');
$fingerprint = $registry->computeFingerprint('contratos');
```

### 2. Verificação de Integridade em Runtime
Antes de concluir a ativação (`activate()`), o registry executa `verifyIntegrity($module)`. Se qualquer arquivo tiver sido adulterado, substituído ou corrompido, a ativação é abortada imediatamente.

### 3. Isolamento por Quarentena
Módulos que falham na validação de integridade ou sofrem exceção não tratada são isolados:

```php
$registry->quarantine('contratos', 'Assinatura SHA-256 divergente dos arquivos em disco.');
```

Módulos em quarentena não têm seus controllers, rotas, views ou comandos carregados pelo sistema, emitindo o evento `rahpt.module.quarantined`.

---

## 🧩 Contratos Transversais (`AuditEvent` e `HealthCheck`)

O `ci4-module` provê contratos padronizados consumidos por todos os outros pacotes do ecossistema:

### 1. `AuditEvent` (Value Object Imutável)
Contrato único para logs estruturados em auditorias de ciclo de vida, multi-tenancy, autorização e assets:

```php
use Rahpt\Ci4Module\Contracts\AuditEvent;

$event = new AuditEvent(
    eventType: 'module.activated',
    module: 'contratos',
    actorId: 'user_42',
    tenantId: 'org_acme',
    data: ['version' => '2.1.0'],
    timestamp: time()
);
```

### 2. `HealthCheckInterface` & `HealthResult`
Contrato para diagnóstico e observabilidade do ecossistema:

```php
use Rahpt\Ci4Module\Contracts\HealthCheckInterface;
use Rahpt\Ci4Module\Contracts\HealthResult;

class ModuleHealthCheck implements HealthCheckInterface
{
    public function check(): HealthResult
    {
        $registry = service('modules');
        $allHealthy = true;
        $details = [];

        foreach ($registry->getModules() as $module => $info) {
            $valid = $registry->verifyIntegrity($module);
            $details[$module] = $valid ? 'ok' : 'integrity_failed';
            if (!$valid) {
                $allHealthy = false;
            }
        }

        return new HealthResult(
            name: 'ModuleIntegrityCheck',
            healthy: $allHealthy,
            details: $details
        );
    }
}
```

---

## 🔧 Gerenciamento e API Reference

```php
use Rahpt\Ci4Module\ModuleRegistry;

$registry = service('modules');

// Ativação controlada com validação e integridade
$success = $registry->activate('contratos');

// Desativação segura
$registry->deactivate('contratos');

// Transição explícita para quarentena
$registry->quarantine('contratos', 'Falha crítica de segurança');

// Consulta de status do ciclo de vida
$status = $registry->getStatus('contratos'); // 'active', 'quarantined', 'failed', etc.

// Consulta consolidada com timestamps e motivos
$modules = $registry->getModulesWithStatus();

// Gravação atômica de fingerprint
$registry->recordFingerprint('contratos');

// Lista agregada de todas as permissões Shield declaradas pelos módulos
$permissions = $registry->getPermissions();
```

---

## 🔔 Eventos Globais

| Evento | Argumentos | Descrição |
| :--- | :--- | :--- |
| `rahpt.module.changed` | `$module, $data` | Alteração gravada atomicamente no registro. |
| `rahpt.module.status_changed` | `$module, $status, $reason` | Transição de estado do ciclo de vida. |
| `rahpt.module.quarantined` | `$module, $reason` | Módulo colocado em quarentena por falha ou violação. |
| `rahpt.module.activated` | `$module` | Ativação concluída com sucesso. |
| `rahpt.module.deactivated` | `$module` | Desativação concluída com sucesso. |
| `rahpt.module.activation_failed` | `$module, $throwable` | Falha durante o processo de ativação. |

---

## 🕒 Histórico de Versões

### [1.4.0] - 2026-09-26
- **Novo**: Máquina de estados finita rigorosa com validação de transições semânticas (`discover`, `validate`, `install`, `activate`, `deactivate`, `quarantine`, `rollback`).
- **Novo**: Formalização do manifesto declarativo `module.json` com Schema 1.0 e matriz de capacidades (`capabilities`).
- **Novo**: Contrato transversal imutável `AuditEvent` para padronização de logs de auditoria em todos os pacotes.
- **Novo**: Contrato transversal `HealthCheckInterface` e `HealthResult` para observabilidade de módulos.
- **Melhoria**: Algoritmo determinístico canônico para cálculo de checksum SHA-256 e fingerprint.
- **Testes**: Cobertura ampliada para `StateMachineTest` e `DependencyCheckerTest`.

### [1.3.0] - 2026-09-26
- **Novo**: Contratos de manifesto ricos em `BaseModule` (`tablePrefix`, `priority`, `conflicts`, `provides`, `permissions`, `tenantAware`).
- **Novo**: Sistema de Quarentena (`quarantine()`) e gravação atômica (`writeAtomic()`).
- **Novo**: Agregação de permissões para integração com CodeIgniter Shield.

### [1.2.0] - 2026-02-18
- **Novo**: Hooks de ciclo de vida `uninstall()` e `settings()`.

### [1.0.1] - 2026-02-15
- Versão inicial estável do kernel modular.

---

## 📄 Licença

Distribuído sob a licença MIT. Veja `LICENSE` para mais informações.

Desenvolvido por **Rahpt**  
Mantido pela equipe Rahpt / CodeIgniter 4 Modular Platform.
