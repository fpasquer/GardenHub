<?php

declare(strict_types=1);

require '/app/vendor/autoload.php';

function wateringRead($connection, int $length, float $deadline): string
{
    $data = '';
    while (strlen($data) < $length) {
        if (microtime(true) >= $deadline || feof($connection)) {
            throw new RuntimeException('Broker read deadline or EOF.');
        }
        $chunk = fread($connection, $length - strlen($data));
        if (false === $chunk) {
            throw new RuntimeException('Broker read failed.');
        }
        $data .= $chunk;
        if ('' === $chunk) {
            usleep(1000);
        }
    }
    return $data;
}

function wateringPacket($connection, float $deadline): array
{
    $header = ord(wateringRead($connection, 1, $deadline));
    $multiplier = 1;
    $remaining = 0;
    do {
        $byte = ord(wateringRead($connection, 1, $deadline));
        $remaining += ($byte & 127) * $multiplier;
        $multiplier *= 128;
    } while ($byte & 128);
    return [$header, wateringRead($connection, $remaining, $deadline)];
}

function wateringWrite($connection, string $data, float $deadline): void
{
    $written = 0;
    while ($written < strlen($data)) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException('Broker write deadline.');
        }
        $result = fwrite($connection, substr($data, $written));
        if (false === $result) {
            throw new RuntimeException('Broker write failed.');
        }
        $written += $result;
    }
}

function wateringServe(string $mode): void
{
    $deadline = microtime(true) + 20;
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (false === $server) {
        throw new RuntimeException($error);
    }
    $address = stream_socket_get_name($server, false);
    echo json_encode(['port' => (int) substr(strrchr($address, ':'), 1)], JSON_THROW_ON_ERROR)."\n";
    $connection = stream_socket_accept($server, 15);
    if (false === $connection) {
        throw new RuntimeException('Broker accept deadline.');
    }
    stream_set_blocking($connection, false);
    [$connect] = wateringPacket($connection, $deadline);
    wateringWrite($connection, "\x20\x02\x00\x00", $deadline);
    [$header, $body] = wateringPacket($connection, $deadline);
    $observation = ['connectType' => $connect >> 4, 'type' => $header >> 4];
    if ('withhold-puback' === $mode) {
        $length = unpack('n', substr($body, 0, 2))[1];
        $observation += ['retain' => $header & 1, 'qos' => ($header >> 1) & 3,
            'topic' => substr($body, 2, $length)];
    } elseif ('rejected-suback' === $mode) {
        wateringWrite($connection, "\x90\x03".substr($body, 0, 2)."\x80", $deadline);
    } elseif ('callback-failure-during-suback' === $mode) {
        $topic = App\Watering\MqttWateringPublisher::TOPIC;
        $packets = "\x90\x03".substr($body, 0, 2)."\x01";
        foreach ([1, 2] as $identifier) {
            $publish = pack('n', strlen($topic)).$topic.pack('n', $identifier).'{"state":"ON"}';
            $packets .= "\x32".chr(strlen($publish)).$publish;
        }
        wateringWrite($connection, $packets, $deadline);
    }
    echo json_encode($observation, JSON_THROW_ON_ERROR)."\n";
    while (microtime(true) < $deadline && !feof($connection)) {
        fread($connection, 8192);
        usleep(10000);
    }
    fclose($connection);
    fclose($server);
}

wateringServe($argv[1]);