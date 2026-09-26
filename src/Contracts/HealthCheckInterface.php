<?php

namespace Rahpt\Ci4Module\Contracts;

/**
 * HealthResult - Value object for a single health check result.
 */
readonly class HealthResult
{
    public function __construct(
        public string  $name,
        public bool    $healthy,
        public string  $message,
        public array   $details  = [],
        public ?string $duration = null,
    ) {}

    public static function ok(string $name, string $message = 'OK', array $details = []): self
    {
        return new self(name: $name, healthy: true, message: $message, details: $details);
    }

    public static function fail(string $name, string $message, array $details = []): self
    {
        return new self(name: $name, healthy: false, message: $message, details: $details);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'name'     => $this->name,
            'healthy'  => $this->healthy,
            'message'  => $this->message,
            'details'  => $this->details,
            'duration' => $this->duration,
        ];
    }
}

/**
 * HealthCheckInterface - Contract for package/component health checks.
 *
 * Examples:
 *   Core:    registry integrity, dependency graph
 *   Tenancy: context availability, membership service
 *   Theme:   asset registry, CSP config
 *   Nav:     menu registry, cache
 *   Tools:   installer readiness, security config
 */
interface HealthCheckInterface
{
    /**
     * Performs the health check and returns a result.
     */
    public function check(): HealthResult;

    /**
     * Returns a unique identifier for this health check (e.g. 'module.registry').
     */
    public function name(): string;
}
