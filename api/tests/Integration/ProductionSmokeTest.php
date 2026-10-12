<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Tests\Support\Command;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

#[Group('functional_gardenhub-production-smoke')]
final class ProductionSmokeTest extends TestCase
{
    public function testPackagedProductionRuntimeReadinessAndApiAuthorization(): void
    {
        $this->assertProductionRuntime();
        $this->prepareProductionDatabaseAndCache();
        $started = $this->compose(['up', '-d', '--wait', '--wait-timeout', '120', 'gardenhub-api', 'nginx'], timeout: 150);
        self::assertSame(0, $started['status'], $started['stdout'].$started['stderr']);
        $this->assertProductionContainerIsolation();
        self::assertSame('ok', $this->request('/healthz', 200)['status'] ?? null);
        $this->request('/api/devices', 401);
        $this->request('/api/devices', 401, 'invalid-smoke-key');
        $reader = $this->createKey('smoke-reader', true);
        $writer = $this->createKey('smoke-writer', false);
        $this->request('/api/devices', 200, $reader);
        $this->request('/api/devices', 403, $reader, ['name' => 'Forbidden smoke device']);
        $device = $this->request('/api/devices', 201, $writer, ['name' => 'Production smoke device']);
        self::assertArrayHasKey('@id', $device);
        $this->request($device['@id'], 200, $reader);
    }

    private function assertProductionRuntime(): void
    {
        $code = <<<'PHP'
require 'vendor/autoload.php';
$kernel = new App\Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
echo json_encode([
    'environment' => $kernel->getEnvironment(), 'debug' => $kernel->isDebug(),
    'maker' => class_exists('Symfony\Bundle\MakerBundle\MakerBundle'),
    'fake' => class_exists('App\Tests\Monolog\FakeTelegramTransport'),
    'phpunit' => class_exists('PHPUnit\Framework\TestCase'),
    'xdebug' => extension_loaded('xdebug'), 'prepend' => ini_get('auto_prepend_file'),
    'tests' => file_exists('/app/tests'), 'xml' => file_exists('/app/phpunit.xml.dist'),
    'binary' => file_exists('/app/vendor/bin/phpunit'),
    'xdebugBinaries' => glob(ini_get('extension_dir').'/xdebug*'),
    'devIni' => file_exists('/usr/local/etc/php/conf.d/99-development.ini'),
], JSON_THROW_ON_ERROR);
PHP;
        $result = $this->compose(['run', '--rm', '--no-deps', '-T', '-u', 'www-data',
            '-e', 'XDEBUG_MODE=debug', 'gardenhub-api', 'php', '-r', $code]);
        self::assertSame(0, $result['status'], $result['stderr']);
        self::assertSame(['environment' => 'prod', 'debug' => false, 'maker' => false,
            'fake' => false, 'phpunit' => false, 'xdebug' => false, 'prepend' => '',
            'tests' => false, 'xml' => false, 'binary' => false,
            'xdebugBinaries' => [], 'devIni' => false],
            json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR));
    }

    private function prepareProductionDatabaseAndCache(): void
    {
        foreach ([['composer', 'check-platform-reqs', '--no-dev'],
            ['php', 'bin/console', 'doctrine:migrations:migrate', '--no-interaction'],
            ['php', 'bin/console', 'doctrine:schema:validate', '--no-debug'],
            ['php', 'bin/console', 'cache:clear', '--no-debug']] as $command) {
            $result = $this->compose(['run', '--rm', '--no-deps', '-T', '-u', 'www-data',
                'gardenhub-api', ...$command], timeout: 120);
            self::assertSame(0, $result['status'], implode(' ', $command)."\n".$result['stdout'].$result['stderr']);
        }
    }

    private function assertProductionContainerIsolation(): void
    {
        $id = $this->compose(['ps', '-q', 'gardenhub-api']);
        self::assertSame(0, $id['status'], $id['stderr']);
        $result = Command::run(['docker', 'inspect', '--format', '{{json .Mounts}}', trim($id['stdout'])]);
        self::assertSame(0, $result['status'], $result['stderr']);
        self::assertSame([], json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR));
    }

    private function createKey(string $name, bool $readonly): string
    {
        $command = ['exec', '-T', '-u', 'www-data', 'gardenhub-api', 'php',
            'bin/console', 'gardenhub:api-client:create', $name, '--no-ansi'];
        if ($readonly) {
            $command[] = '--readonly';
        }
        $result = $this->compose($command);
        self::assertSame(0, $result['status'], 'Disposable API client creation failed.');
        self::assertSame(1, preg_match('/\bgh_[a-f0-9]{48}\b/', $result['stdout'], $match),
            'Client command did not return a token.');
        return $match[0];
    }

    private function request(string $path, int $expected, ?string $key = null, ?array $body = null): array
    {
        $headers = ['Content-Type: application/ld+json', 'Accept: application/ld+json'];
        if (null !== $key) {
            $headers[] = 'Authorization: Bearer '.$key;
        }
        $context = stream_context_create(['http' => [
            'method' => null === $body ? 'GET' : 'POST', 'header' => implode("\r\n", $headers),
            'content' => null === $body ? '' : json_encode($body, JSON_THROW_ON_ERROR),
            'ignore_errors' => true, 'timeout' => 10, 'follow_location' => 0]]);
        $response = file_get_contents('http://nginx'.$path, false, $context);
        self::assertNotFalse($response, $path.' returned no response.');
        self::assertMatchesRegularExpression('/^HTTP\/\S+ '.$expected.'\b/',
            $http_response_header[0] ?? '', $path);
        return json_decode($response, true, 512, JSON_THROW_ON_ERROR);
    }

    private function compose(array $arguments, float $timeout = 30): array
    {
        return Command::run(['docker', 'compose', '-f', Command::root().'/tests/production-smoke/compose.yaml',
            ...$arguments], Command::root(), timeout: $timeout);
    }
}