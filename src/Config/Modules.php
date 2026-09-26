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

    /**
     * Base64-encoded Ed25519 public key for publisher signature verification.
     * Leave null to disable publisher signature checks (feature opt-in).
     * Key must be 32 bytes (64 chars base64-encoded) per sodium_crypto_sign_verify_detached.
     */
    public ?string $publisherPublicKey = null;

    /**
     * Supported module.json schema versions.
     * Any module manifest with a schema version not in this list will be rejected.
     */
    public array $supportedSchemaVersions = ['1.0'];
}
