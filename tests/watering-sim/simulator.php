<?php

declare(strict_types=1);

// Development-only MQTT double for the THIRDREALITY watering kit. It never
// connects to Zigbee and cannot address the production Zigbee2MQTT namespace.
require '/app/vendor/autoload.php';

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;

$host = getenv('WATERING_SIM_MQTT_HOST');
$username = getenv('WATERING_SIM_USERNAME');
$password = getenv('WATERING_SIM_PASSWORD');
$port = filter_var(getenv('WATERING_SIM_MQTT_PORT') ?: '1883', FILTER_VALIDATE_INT);
if (!$host || !$username || !$password || !$port || $port < 1 || $port > 65535 || getenv('APP_ENV') !== 'dev') {
    fwrite(STDERR, "Simulator requires APP_ENV=dev and dedicated broker host, username and password.\n");
    exit(1);
}

$base = 'gardenhub/dev/watering/avocado';
$state = 'OFF';
$battery = 100;
$alarm = false;
$duration = 10;
$deadline = null;

$client = new MqttClient($host, $port, 'gardenhub-watering-sim');
$client->connect((new ConnectionSettings())
    ->setUsername($username)
    ->setPassword($password)
    ->setKeepAliveInterval(30), true);

$publish = static function () use ($client, $base, &$state, &$battery, &$alarm, &$duration): void {
    $client->publish($base, json_encode([
        'state' => $state,
        'battery' => $battery,
        'battery_low' => $battery <= 10,
        'alarm_1' => $alarm,
        'watering_times' => $duration,
        'interval_day' => 0,
    ], JSON_THROW_ON_ERROR), MqttClient::QOS_AT_LEAST_ONCE, true);
};

$client->subscribe($base.'/set', static function (string $topic, string $message) use (&$state, &$battery, &$alarm, &$duration, &$deadline, $publish): void {
    try {
        $command = json_decode($message, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException) {
        fwrite(STDERR, "Ignoring malformed watering command.\n");
        return;
    }
    if (!is_array($command)) {
        return;
    }

    // Test-only controls for exercising low battery and empty reservoir paths.
    if (isset($command['simulate_battery']) && is_int($command['simulate_battery']) && $command['simulate_battery'] >= 0 && $command['simulate_battery'] <= 100) {
        $battery = $command['simulate_battery'];
    }
    if (isset($command['simulate_alarm_1']) && is_bool($command['simulate_alarm_1'])) {
        $alarm = $command['simulate_alarm_1'];
    }
    if (isset($command['watering_times']) && is_int($command['watering_times']) && $command['watering_times'] >= 1 && $command['watering_times'] <= 1800) {
        $duration = $command['watering_times'];
    }

    if (isset($command['state'])) {
        if ($command['state'] === 'OFF') {
            $state = 'OFF';
            $deadline = null;
        } elseif ($command['state'] === 'ON' && $state === 'OFF' && !$alarm && $battery > 10) {
            $state = 'ON';
            $deadline = microtime(true) + $duration;
        }
        // Duplicate ON requests never extend a running cycle.
    }
    $publish();
}, MqttClient::QOS_AT_LEAST_ONCE);

// Reset a retained ON status after a restart. The simulator cannot persist a
// running cycle and must never report an indefinitely running fake pump.
$publish();
fwrite(STDOUT, "Watering simulator ready on {$base}/set (duration {$duration}s).\n");
$loopStartedAt = microtime(true);
while (true) {
    // loop() never returns while subscribed. loopOnce() lets us run the
    // simulated pump timer without a second process or an unbounded ON.
    $client->loopOnce($loopStartedAt, true);
    if ($deadline !== null && microtime(true) >= $deadline) {
        $state = 'OFF';
        $deadline = null;
        $publish();
    }
}
