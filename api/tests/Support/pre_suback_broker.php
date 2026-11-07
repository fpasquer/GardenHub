<?php

declare(strict_types=1);

final class PreSubackBroker
{
    private float $deadline;

    public function run(): void
    {
        $this->deadline = microtime(true) + 20;
        $server = stream_socket_server('tcp://127.0.0.1:0', $number, $error);
        if (false === $server) {
            throw new RuntimeException($error);
        }
        $address = stream_socket_get_name($server, false);
        echo json_encode(['port' => (int) substr(strrchr($address, ':'), 1)], JSON_THROW_ON_ERROR)."\n";
        $connection = stream_socket_accept($server, 15);
        if (false === $connection) {
            throw new RuntimeException('Broker accept timed out.');
        }
        try {
            stream_set_blocking($connection, false);
            $this->exchange($connection);
            while (microtime(true) < $this->deadline && !feof($connection)) {
                fread($connection, 8192);
                usleep(10000);
            }
        } finally {
            fclose($connection);
            fclose($server);
        }
    }

    private function exchange($connection): void
    {
        [$header] = $this->packet($connection);
        if (1 !== $header >> 4) {
            throw new RuntimeException('Expected CONNECT.');
        }
        $this->write($connection, "\x20\x02\x00\x00");
        [$header, $body] = $this->packet($connection);
        if (8 !== $header >> 4) {
            throw new RuntimeException('Expected SUBSCRIBE.');
        }
        $id = unpack('n', substr($body, 0, 2))[1];
        $length = unpack('n', substr($body, 2, 2))[1];
        $topic = substr($body, 4, $length);
        if ('tests/pre-suback/up' !== $topic) {
            throw new RuntimeException('Unexpected subscription topic.');
        }
        $this->write($connection, $this->publish($topic, 'marker-a', 1));
        $this->write($connection, "\x90\x03".pack('n', $id)."\x01");
        $this->write($connection, $this->publish($topic, 'marker-b', 2));
        $this->write($connection, $this->publish('tests/pre-suback/other', 'marker-c', 3));
        $this->write($connection, $this->publish($topic, 'marker-d', 4));
    }

    private function packet($connection): array
    {
        $header = ord($this->read($connection, 1));
        $multiplier = 1;
        $remaining = 0;
        do {
            $byte = ord($this->read($connection, 1));
            $remaining += ($byte & 127) * $multiplier;
            $multiplier *= 128;
        } while ($byte & 128);

        return [$header, $this->read($connection, $remaining)];
    }

    private function read($connection, int $length): string
    {
        $data = '';
        while (strlen($data) < $length) {
            if (microtime(true) >= $this->deadline || feof($connection)) {
                throw new RuntimeException('Broker read timed out or disconnected.');
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

    private function write($connection, string $data): void
    {
        $written = 0;
        while ($written < strlen($data)) {
            if (microtime(true) >= $this->deadline) {
                throw new RuntimeException('Broker write timed out.');
            }
            $result = fwrite($connection, substr($data, $written));
            if (false === $result) {
                throw new RuntimeException('Broker write failed.');
            }
            $written += $result;
        }
    }

    private function publish(string $topic, string $payload, int $id): string
    {
        $body = pack('n', strlen($topic)).$topic.pack('n', $id).$payload;
        if (strlen($body) >= 128) {
            throw new RuntimeException('Fixture packet exceeds one-byte length.');
        }

        return "\x32".chr(strlen($body)).$body;
    }
}

(new PreSubackBroker())->run();