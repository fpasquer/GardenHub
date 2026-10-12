<?php

declare(strict_types=1);

namespace App\Tests\Support;

use RuntimeException;

final class ComposeModel
{
    public const APPLICATION = [
        'gardenhub-nginx', 'gardenhub-api', 'gardenhub-worker',
        'gardenhub-consumer', 'gardenhub-scheduler',
    ];
    public const WATERING = [
        'gardenhub-watering-monitor', 'gardenhub-watering-proposals',
        'gardenhub-watering-sim',
    ];
    public const SIM_TOPIC = 'gardenhub/dev/watering/avocado';

    public static function synthetic(): array
    {
        return [
            'PATH' => getenv('PATH'), 'HOME' => '/tmp',
            'BIND_ADDRESS' => '127.0.0.1', 'MYSQL_DATABASE' => 'synthetic',
            'MYSQL_USER' => 'synthetic', 'MYSQL_PASSWORD' => 'synthetic',
            'MYSQL_ROOT_PASSWORD' => 'synthetic', 'MQTT_HOST' => 'synthetic-broker',
            'MQTT_CLIENT_ID' => 'synthetic', 'GRAFANA_MYSQL_USER' => 'synthetic',
            'GRAFANA_MYSQL_PASSWORD' => 'synthetic',
        ];
    }

    public static function enabledSettings(): array
    {
        return [
            'WATERING_ENABLED' => 'true', 'WATERING_MQTT_HOST' => 'synthetic',
            'WATERING_MQTT_TOPIC' => 'isolated/actuator',
            'WATERING_MQTT_USERNAME' => 'synthetic',
            'WATERING_MQTT_PASSWORD' => 'synthetic',
            'TELEGRAM_BOT_TOKEN' => 'synthetic',
            'WATERING_TELEGRAM_USER_ID' => '123',
            'WATERING_TELEGRAM_CHAT_ID' => '456',
        ];
    }

    public static function render(
        bool $development = false, array $profiles = [], array $overrides = [],
    ): array {
        $command = ['docker', 'compose', '--env-file', '/dev/null',
            '-f', 'compose.yaml'];
        if ($development) {
            array_push($command, '-f', 'compose-dev.yaml');
        }
        foreach ($profiles as $profile) {
            array_push($command, '--profile', $profile);
        }
        array_push($command, 'config', '--format', 'json');
        $environment = array_replace(
            array_fill_keys(array_keys(getenv()), false),
            self::synthetic(), $overrides,
        );
        $result = Command::run($command, Command::root(), $environment);
        if (0 !== $result['status']) {
            throw new RuntimeException(sprintf(
                'Compose render failed: %s', $result['stderr'],
            ));
        }

        return json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);
    }

    public static function setEnabled(array &$model, ?string $value): void
    {
        foreach ($model['services'] as &$service) {
            if (null === $value) {
                unset($service['environment']['WATERING_ENABLED']);
            } else {
                $service['environment']['WATERING_ENABLED'] = $value;
            }
        }
    }
}