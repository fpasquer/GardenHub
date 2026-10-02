<?php

declare(strict_types=1);

require '/app/vendor/autoload.php';
require __DIR__.'/support.php';

(new Symfony\Component\Dotenv\Dotenv())->bootEnv('/app/.env');

use App\Command\WateringCheckCommand;
use App\Command\WateringMonitorCommand;
use App\Command\WateringProposalPollCommand;
use App\Kernel;
use App\Watering\InterfaceTelegramGateway;
use App\Watering\MqttWateringPublisher;
use App\Watering\WateringConfiguration;
use App\Watering\WateringManager;
use App\Watering\WateringReadiness;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;

final class ProductionFakeTelegram implements InterfaceTelegramGateway
{
    public int $polls = 0;
    public function send(string $text, int $proposalId): int { return 1; }
    public function updates(int $offset): array { ++$this->polls; return []; }
    public function acknowledge(string $callbackId): void {}
    public function edit(int $messageId, string $text): void {}
}

final class ProductionWateringKernel extends Kernel
{
    public function __construct(private readonly string $suffix)
    {
        parent::__construct('prod', false);
    }
    public function getProjectDir(): string { return '/app'; }
    public function getCacheDir(): string { return '/tmp/watering-prod-'.getmypid().'-'.$this->suffix; }
    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->addCompilerPass(new class implements CompilerPassInterface {
            public function process(ContainerBuilder $container): void
            {
                $container->register(ProductionFakeTelegram::class, ProductionFakeTelegram::class)->setPublic(true);
                $container->setAlias(InterfaceTelegramGateway::class, ProductionFakeTelegram::class);
                foreach ([WateringConfiguration::class, WateringManager::class, MqttWateringPublisher::class,
                    WateringMonitorCommand::class, WateringProposalPollCommand::class, WateringCheckCommand::class] as $class) {
                    $container->getDefinition($class)->setPublic(true);
                }
            }
        });
    }
}

function check(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function rejects(callable $call): void
{
    try { $call(); } catch (LogicException|RuntimeException) { return; }
    throw new RuntimeException('Unsafe production operation accepted.');
}

function setEnvironment(array $values): void
{
    foreach ($values as $key => $value) {
        putenv(sprintf('%s=%s', $key, $value));
        $_ENV[$key] = $_SERVER[$key] = $value;
    }
}

setEnvironment([
    'WATERING_ENABLED' => 'false', 'WATERING_MQTT_TOPIC' => '',
    'WATERING_MQTT_USERNAME' => '', 'WATERING_MQTT_PASSWORD' => '',
    'WATERING_DEV_USERNAME' => 'must-not-fallback', 'WATERING_DEV_PASSWORD' => 'must-not-fallback',
    'TELEGRAM_ENABLED' => 'false', 'TELEGRAM_BOT_TOKEN' => 'synthetic',
    'WATERING_TELEGRAM_USER_ID' => '123', 'WATERING_TELEGRAM_CHAT_ID' => '456',
]);
$disabled = new ProductionWateringKernel('disabled');
$em = bootTestEntityManager($disabled);
$db = $em->getConnection();
clearWateringRuns($db);
$container = $disabled->getContainer();
$configuration = $container->get(WateringConfiguration::class);
check($configuration->environment === 'prod', 'Kernel is not production.');
check($configuration->username === '' && $configuration->password === '' && $configuration->topic === '', 'Production inherited development defaults.');
rejects(fn () => $container->get(WateringManager::class)->request(3));
rejects(fn () => $container->get(MqttWateringPublisher::class)->publish(['state' => 'ON']));
foreach ([WateringMonitorCommand::class, WateringProposalPollCommand::class] as $class) {
    $tester = new CommandTester($container->get($class));
    check($tester->execute([]) === 1, 'Disabled command accepted.');
}
check($container->get(ProductionFakeTelegram::class)->polls === 0, 'Disabled command polled Telegram.');
$disabled->shutdown();

foreach ([['host' => ''], ['port' => 0], ['username' => ''], ['password' => ' '],
    ['topic' => ''], ['topic' => 'pump/#'], ['topic' => 'pump/+'], ['topic' => 'pump/set'],
    ['enabled' => false], ['environment' => 'test']] as $invalid) {
    $values = array_replace(['enabled' => true, 'environment' => 'prod', 'host' => 'never-connect.invalid',
        'port' => 1883, 'username' => 'test', 'password' => 'test', 'topic' => 'isolated/pump'], $invalid);
    $configuration = new WateringConfiguration(...$values);
    rejects(fn () => $configuration->assertAvailable());
    rejects(fn () => (new MqttWateringPublisher($configuration))->publish(['state' => 'ON']));
}

setEnvironment(['WATERING_ENABLED' => 'true']);
$incomplete = new ProductionWateringKernel('incomplete');
bootTestEntityManager($incomplete);
rejects(fn () => $incomplete->getContainer()->get(WateringManager::class)->request(3));
check((int) $db->fetchOne('SELECT COUNT(*) FROM watering_run') === 0, 'Incomplete config reserved a run.');
$incomplete->shutdown();

setEnvironment(['WATERING_MQTT_HOST' => 'mqtt', 'WATERING_MQTT_TOPIC' => MqttWateringPublisher::DEFAULT_TOPIC,
    'WATERING_MQTT_USERNAME' => 'test', 'WATERING_MQTT_PASSWORD' => 'test']);
$enabled = new ProductionWateringKernel('enabled');
$em = bootTestEntityManager($enabled);
$db = $em->getConnection();
$container = $enabled->getContainer();
$watering = $container->get(WateringManager::class);
$readiness = new WateringReadiness($db);
$readiness->assertIdle();
$watering->heartbeat();
$readiness->assertMonitorReady();
$id = $watering->request(3);
check(freshRun($em, $id)?->getStatus() === 'pending', 'Enabled prod failed to reserve and publish.');
$checker = new CommandTester($container->get(WateringCheckCommand::class));
foreach (['pending', 'running', 'uncertain', 'timed_out'] as $status) {
    $db->executeStatement('UPDATE watering_run SET status = ? WHERE id = ?', [$status, $id]);
    $before = [$db->fetchAllAssociative('SELECT * FROM watering_control'), $db->fetchAllAssociative('SELECT * FROM watering_run')];
    check($checker->execute([]) === 1, 'Deployment accepted unresolved '.$status);
    $after = [$db->fetchAllAssociative('SELECT * FROM watering_control'), $db->fetchAllAssociative('SELECT * FROM watering_run')];
    check($before === $after, 'Deployment check mutated watering state.');
}
$watering->acknowledgeStopped();
check($checker->execute([]) === 0, 'Reviewed idle cycle refused deployment.');
$db->executeStatement('UPDATE watering_control SET monitor_seen_at = NULL WHERE id = 1');
check($checker->execute(['--monitor' => true]) === 1, 'Missing monitor readiness accepted.');
$db->executeStatement('UPDATE watering_control SET monitor_seen_at = DATE_SUB(UTC_TIMESTAMP(), INTERVAL 10 SECOND) WHERE id = 1');
check($checker->execute(['--monitor' => true]) === 1, 'Stale monitor readiness accepted.');
$watering->heartbeat();
check($checker->execute(['--monitor' => true]) === 0, 'Fresh monitor readiness rejected.');
$poller = new CommandTester($container->get(WateringProposalPollCommand::class));
check($poller->execute(['--once' => true]) === 0, 'Enabled prod proposal poller failed.');
check($container->get(ProductionFakeTelegram::class)->polls === 1, 'Production did not use the fake Telegram gateway.');
$enabled->shutdown();
echo "PASS production DI, enablement, configuration, fake MQTT/Telegram and read-only deployment readiness\n";