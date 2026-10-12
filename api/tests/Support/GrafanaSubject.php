<?php

declare(strict_types=1);

namespace App\Tests\Support;

use PHPUnit\Framework\Assert;

final class GrafanaSubject
{
    public readonly \PDO $database;

    public function __construct()
    {
        if ('1' !== getenv('GARDENHUB_GRAFANA_TESTS')) {
            throw new \RuntimeException('Grafana disposable test flag required.');
        }
        $this->database = new \PDO('mysql:host=gardenhub-mysql;dbname=gardenhub', 'root', '',
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        Assert::assertSame('gardenhub', $this->database->query('SELECT DATABASE()')->fetchColumn());
    }

    public function http(string $path, ?array $body = null): array
    {
        $headers = ['Authorization: Basic '.base64_encode('admin:admin'),
            'Content-Type: application/json'];
        $context = stream_context_create(['http' => ['method' => null === $body ? 'GET' : 'POST',
            'header' => implode("\r\n", $headers), 'content' => null === $body ? '' : json_encode($body, JSON_THROW_ON_ERROR),
            'timeout' => 10, 'ignore_errors' => true]]);
        $response = file_get_contents('http://grafana:3000'.$path, false, $context);
        Assert::assertNotFalse($response, $path);
        Assert::assertMatchesRegularExpression('/^HTTP\/\S+ 200\b/', $http_response_header[0] ?? '', $path);
        return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    }

    public function dashboard(string $uid): array
    {
        $deadline = microtime(true) + 120;
        do {
            try {
                return $this->http('/api/dashboards/uid/'.$uid);
            } catch (\Throwable $exception) {
                if (microtime(true) >= $deadline) {
                    throw $exception;
                }
                usleep(2_000_000);
            }
        } while (true);
    }

    public function frame(array $panel, int $from, int $to): array
    {
        $result = $this->http('/api/ds/query', ['from' => (string) $from, 'to' => (string) $to,
            'queries' => [array_replace($panel['targets'][0], ['intervalMs' => 1000, 'maxDataPoints' => 1000])]]);
        $query = $result['results']['A'];
        Assert::assertNull($query['error'] ?? null);
        Assert::assertSame(200, $query['status'] ?? 200);
        Assert::assertCount(1, $query['frames']);
        return $query['frames'][0];
    }

    public function seed(string $name): void
    {
        $sql = file_get_contents(Command::root().'/tests/grafana-db/'.$name);
        Assert::assertNotFalse($sql);
        $this->database->exec($sql);
    }

    public function rows(array $frame): array
    {
        $rows = [];
        foreach ($frame['data']['values'][0] ?? [] as $index => $value) {
            $rows[] = array_column($frame['data']['values'], $index);
        }
        return $rows;
    }

    public function rule(string $name): array
    {
        $response = $this->http('/api/prometheus/grafana/api/v1/rules');
        foreach ($response['data']['groups'] as $group) {
            foreach ($group['rules'] as $rule) {
                if ($rule['name'] === $name) {
                    return $rule;
                }
            }
        }
        Assert::fail('Provisioned rule missing: '.$name);
    }

    public function instance(array $rule, string $device, string $type): ?array
    {
        foreach ($rule['alerts'] ?? [] as $alert) {
            if (($alert['labels']['device'] ?? null) === $device
                && ($alert['labels']['sensor_type'] ?? null) === $type) {
                return $alert;
            }
        }
        return null;
    }

    public function wait(string $name, callable $condition, int $seconds): array
    {
        $deadline = microtime(true) + $seconds;
        do {
            $rule = $this->rule($name);
            if ($condition($rule)) {
                return $rule;
            }
            usleep(5_000_000);
        } while (microtime(true) < $deadline);
        Assert::fail(sprintf('%s exceeded %ds: %s', $name, $seconds,
            json_encode(array_intersect_key($rule, array_flip(['state', 'health', 'lastError', 'alerts'])), JSON_THROW_ON_ERROR)));
    }

    public function waitInstance(string $name, string $device, string $type, string $state): array
    {
        return $this->wait($name, fn (array $rule): bool =>
            str_starts_with($this->instance($rule, $device, $type)['state'] ?? '', $state), 180);
    }

    public function measurements(string $device, array $readings): void
    {
        $statement = $this->database->prepare('INSERT INTO measurement (value, measured_at, created_at, sensor_id, deduplication_id, type) SELECT ?, UTC_TIMESTAMP() - INTERVAL ? MINUTE, UTC_TIMESTAMP() - INTERVAL ? MINUTE, s.id, UUID(), s.type FROM sensor s INNER JOIN device d ON d.id = s.device_id WHERE d.name = ? AND s.type = ?');
        foreach ($readings as [$value, $minutes]) {
            $statement->execute([$value, $minutes, $minutes, $device, 'soil_moisture']);
        }
    }
}