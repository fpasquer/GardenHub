#!/bin/sh
set -eu

cd "$(dirname "$0")/../.."
compose() {
    docker compose -f tests/production-smoke/compose.yaml "$@"
}

cleanup() {
    status=$?
    trap - EXIT
    if [ "$status" -ne 0 ]; then
        compose ps -a || true
        compose logs --no-color --tail=100 mysql nginx || true
    fi
    compose down -v --remove-orphans || status=1
    exit "$status"
}
trap cleanup EXIT

compose up -d --wait --wait-timeout 120 mysql
compose run --rm --no-deps -T -u www-data gardenhub-api php -r '
require "vendor/autoload.php";
$kernel = new App\Kernel($_SERVER["APP_ENV"], (bool) $_SERVER["APP_DEBUG"]);
if ($kernel->getEnvironment() !== "prod" || $kernel->isDebug()
    || class_exists("Symfony\\Bundle\\MakerBundle\\MakerBundle")
    || class_exists("App\\Tests\\Monolog\\FakeTelegramTransport")) {
    throw new RuntimeException("Production image contains development configuration.");
}
'
compose run --rm --no-deps -T -u www-data gardenhub-api composer check-platform-reqs --no-dev
compose run --rm --no-deps -T -u www-data gardenhub-api php bin/console doctrine:migrations:migrate --no-interaction
compose run --rm --no-deps -T -u www-data gardenhub-api php bin/console doctrine:schema:validate --no-debug
compose run --rm --no-deps -T -u www-data gardenhub-api php bin/console cache:clear --no-debug
compose up -d --wait --wait-timeout 120 gardenhub-api nginx
compose exec -T -u www-data gardenhub-api php <<'PHP'
<?php
require 'vendor/autoload.php';

function request(string $path, int $expected, ?string $key = null, ?array $body = null): array
{
    $headers = ['Content-Type: application/ld+json', 'Accept: application/ld+json'];
    if ($key !== null) {
        $headers[] = 'Authorization: Bearer '.$key;
    }
    $context = stream_context_create(['http' => [
        'method' => $body === null ? 'GET' : 'POST',
        'header' => implode("\r\n", $headers),
        'content' => $body === null ? '' : json_encode($body, JSON_THROW_ON_ERROR),
        'ignore_errors' => true, 'timeout' => 10, 'follow_location' => 0,
    ]]);
    $response = file_get_contents('http://nginx'.$path, false, $context);
    $status = $http_response_header[0] ?? '';
    if (!preg_match('/^HTTP\/\S+ '.$expected.'\b/', $status)) {
        throw new RuntimeException(sprintf('%s: expected %d, received %s', $path, $expected, $status));
    }
    return json_decode($response, true, flags: JSON_THROW_ON_ERROR);
}

function createKey(string $name, bool $readonly): string
{
    $command = ['php', 'bin/console', 'gardenhub:api-client:create', $name, '--no-ansi'];
    if ($readonly) {
        $command[] = '--readonly';
    }
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $output = stream_get_contents($pipes[1]);
    stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || !preg_match('/\bgh_[a-f0-9]{48}\b/', $output, $match)) {
        throw new RuntimeException('Unable to create disposable API client.');
    }
    return $match[0];
}

if ((request('/healthz', 200)['status'] ?? null) !== 'ok') {
    throw new RuntimeException('Invalid health response.');
}
request('/api/devices', 401);
request('/api/devices', 401, 'invalid-smoke-key');
$reader = createKey('smoke-reader', true);
$writer = createKey('smoke-writer', false);
request('/api/devices', 200, $reader);
request('/api/devices', 403, $reader, ['name' => 'Forbidden smoke device']);
$device = request('/api/devices', 201, $writer, ['name' => 'Production smoke device']);
request($device['@id'], 200, $reader);
echo "PASS packaged production configuration, migrations, cache, health and API authorization\n";
PHP