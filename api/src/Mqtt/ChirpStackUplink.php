<?php

namespace App\Mqtt;

/**
 * Messenger message representing one ChirpStack uplink event received over MQTT.
 *
 * Dispatched by the MQTT consumer command and handled synchronously by
 * ChirpStackUplinkHandler. Routing to an async transport later only
 * requires a messenger.yaml change.
 */
final readonly class ChirpStackUplink
{
    /**
     * @param array<string, mixed> $payload Codec-decoded payload (ChirpStack "object" field)
     */
    public function __construct(
        public string $devEui,
        public array $payload,
        public \DateTimeImmutable $measuredAt,
        public ?string $deviceName = null,
    ) {
    }
}
