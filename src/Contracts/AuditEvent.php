<?php

namespace Rahpt\Ci4Module\Contracts;

/**
 * AuditEvent - Immutable value object for structured audit log entries.
 *
 * Covers lifecycle, tenancy, permissions, and asset events across all packages.
 */
readonly class AuditEvent
{
    public function __construct(
        public string  $event,
        public string  $actorId,
        public string  $actorType,
        public ?string $tenantId,
        public ?string $resourceType,
        public ?string $resourceId,
        public string  $source,
        public ?string $reason,
        public array   $context,
        public string  $timestamp,
        public ?string $requestId,
        public ?string $ip,
    ) {}

    /**
     * Factory method for quick event creation.
     *
     * @param array<string, mixed> $context
     */
    public static function make(
        string  $event,
        string  $actorId   = 'system',
        string  $actorType = 'system',
        ?string $tenantId  = null,
        ?string $reason    = null,
        array   $context   = [],
    ): self {
        return new self(
            event:        $event,
            actorId:      $actorId,
            actorType:    $actorType,
            tenantId:     $tenantId,
            resourceType: $context['resource_type'] ?? null,
            resourceId:   $context['resource_id']   ?? null,
            source:       $context['source']        ?? 'web',
            reason:       $reason,
            context:      $context,
            timestamp:    date('c'),
            requestId:    $context['request_id']    ?? null,
            ip:           $context['ip']            ?? null,
        );
    }

    /**
     * Returns the event as an array for logging or persistence.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'event'         => $this->event,
            'actor_id'      => $this->actorId,
            'actor_type'    => $this->actorType,
            'tenant_id'     => $this->tenantId,
            'resource_type' => $this->resourceType,
            'resource_id'   => $this->resourceId,
            'source'        => $this->source,
            'reason'        => $this->reason,
            'context'       => $this->context,
            'timestamp'     => $this->timestamp,
            'request_id'    => $this->requestId,
            'ip'            => $this->ip,
        ];
    }
}
