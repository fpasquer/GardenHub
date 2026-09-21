<?php

declare(strict_types=1);

/*
 * Marker-rollback regression test (PR #10 review finding 5): proves that a
 * failure landing between alert_processed_uplink's marker flush and
 * ChirpStackUplinkHandler's enclosing transaction commit rolls back
 * everything from that uplink as one unit - measurements, the marker
 * itself, and any alert incident/notification side effects - and that
 * Messenger's real bounded retry (not a manual re-invocation) then
 * completes it exactly once.
 *
 * The existing scenarioTransactionFailureAndRetry (replay-protection.php)
 * fails while acquiring the progress lock, before any marker/measurement
 * flush; it does not cover this later failure window, which is the gap
 * this test closes.
 */

use App\Kernel;
use App\Mqtt\ChirpStackUplink;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Uid\Uuid;
use Tests\TelegramAlerts\MarkerFlushFailureConfig;
use Tests\TelegramAlerts\MarkerFlushFailureMiddleware;
use Tests\TelegramAlerts\MarkerRollbackSubscriber;

require '/app/vendor/autoload.php';

// Test-only classes under /tests/src are not in the Composer autoloader.
spl_autoload_register(static function (string $class): void {
    $prefix = 'Tests\\TelegramAlerts\\';
    if (str_starts_with($class, $prefix)) {
        require '/tests/src/'.substr($class, strlen($prefix)).'.php';
    }
});

const TEST_DATABASE = 'telegram_alerts';

final class MarkerRollbackKernel extends Kernel
{
    public function getProjectDir(): string
    {
        return '/app';
    }

    public function getCacheDir(): string
    {
        return '/tmp/gardenhub-marker-rollback/cache';
    }

    public function getLogDir(): string
    {
        return '/tmp/gardenhub-marker-rollback/log';
    }

    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        parent::registerContainerConfiguration($loader);
        // Test-only: shorten the async retry delay so the injected failure
        // is retried promptly in-process. Production retry settings in
        // api/config/packages/messenger.yaml are untouched.
        $loader->load('/tests/config/packages/messenger_test_retry.yaml');
    }

    protected function build(ContainerBuilder $container): void
    {
        parent::build($container);
        $container->setAlias('test.messenger.default_bus', 'messenger.default_bus')->setPublic(true);
        $container->register('test.marker_flush_failure_middleware', MarkerFlushFailureMiddleware::class)
            ->addTag('doctrine.middleware');
        $container->register('test.marker_rollback_subscriber', MarkerRollbackSubscriber::class)
            ->addTag('kernel.event_subscriber')
            ->setPublic(true);
    }
}

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function resetDatabase(Connection $connection): void
{
    check('1' === getenv('GARDENHUB_LIFECYCLE_TESTS'), 'Refusing to reset schema outside the isolated test Compose file.');
    check(TEST_DATABASE === $connection->getDatabase(), 'Refusing to reset a non-test database.');

    $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
    try {
        $tables = $connection->fetchFirstColumn(
            'SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ?',
            [TEST_DATABASE],
        );
        foreach ($tables as $table) {
            $connection->executeStatement('DROP TABLE IF EXISTS '.$connection->quoteIdentifier($table));
        }
    } finally {
        $connection->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
    }
}

function runMigrations(Application $application): void
{
    $output = new BufferedOutput();
    $exitCode = $application->run(new ArrayInput([
        'command' => 'doctrine:migrations:migrate',
        '--no-interaction' => true,
    ]), $output);
    check(0 === $exitCode, 'Migrations failed: '.$output->fetch());
}

function consume(Application $application): void
{
    $output = new BufferedOutput();
    $application->run(new ArrayInput([
        'command' => 'messenger:consume',
        'receivers' => ['async'],
        '--limit' => 1,
        '--time-limit' => 10,
        '--no-interaction' => true,
    ]), $output);
}

function uplink(string $deduplicationId): ChirpStackUplink
{
    // BatV persists a real measurement; water_SOIL is non-numeric, so this
    // uplink both stores a measurement and breaches invalid_reading
    // (confirmation_count = 1: it activates - and notifies - immediately).
    return new ChirpStackUplink(
        devEui: 'marker-rollback-device',
        payload: ['BatV' => 3.3, 'water_SOIL' => 'not-numeric'],
        measuredAt: new \DateTimeImmutable('2026-01-01T12:00:00Z'),
        deduplicationId: $deduplicationId,
        deviceName: 'Marker rollback device',
    );
}

try {
    check('1' === getenv('GARDENHUB_LIFECYCLE_TESTS'), 'Run only with the isolated test Compose file.');
    $kernel = new MarkerRollbackKernel('dev', true);
    $kernel->boot();
    $container = $kernel->getContainer();

    /** @var EntityManagerInterface $entityManager */
    $entityManager = $container->get('doctrine')->getManager();
    $connection = $entityManager->getConnection();
    resetDatabase($connection);
    $connection->close();

    $application = new Application($kernel);
    $application->setAutoExit(false);
    runMigrations($application);

    /** @var MessageBusInterface $bus */
    $bus = $container->get('test.messenger.default_bus');
    $subscriber = $container->get('test.marker_rollback_subscriber');

    $deduplicationId = (string) Uuid::v4();

    // -----------------------------------------------------------------
    // 1. Dispatch through the REAL async transport (not a direct handler
    //    call), so the failure below is observed through genuine
    //    Messenger consumption and retry, not a manual re-invocation.
    // -----------------------------------------------------------------
    $bus->dispatch(uplink($deduplicationId));
    check(
        1 === (int) $connection->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'async' AND body LIKE ?", ['%'.$deduplicationId.'%']),
        'The uplink must be durably enqueued to the async transport before consumption.'
    );

    // -----------------------------------------------------------------
    // 2. Inject a failure immediately after the alert_processed_uplink
    //    marker's own INSERT executes (uncommitted), forcing
    //    wrapInTransaction() to roll back the whole uplink as one unit.
    // -----------------------------------------------------------------
    MarkerFlushFailureMiddleware::arm(new MarkerFlushFailureConfig($deduplicationId));
    $subscriber->arm($deduplicationId);

    consume($application);

    check(1 === $subscriber->failureCount(), 'Exactly one failed processing attempt must be observed for this event.');
    $failureMessage = $subscriber->failureMessages()[0] ?? '';
    check(
        str_contains($failureMessage, 'Injected test-only failure') && str_contains($failureMessage, $deduplicationId),
        'The failure must be caused specifically by the injected marker-flush failure, not an unrelated error. Got: '.$failureMessage
    );
    check(1 === $subscriber->retryCount(), 'Messenger must schedule exactly one retry after the injected failure.');
    echo "PASS injected-failure: consumption fails with the exact injected marker-flush exception\n";

    // -----------------------------------------------------------------
    // 3. Full rollback: nothing from this uplink may have survived the
    //    failed attempt - not the marker, not the measurement, not the
    //    incident/notification side effects.
    // -----------------------------------------------------------------
    check(0 === (int) $connection->fetchOne('SELECT COUNT(*) FROM alert_processed_uplink WHERE deduplication_id = ?', [$deduplicationId]), 'The processed-uplink marker must not survive the failed attempt.');
    check(0 === (int) $connection->fetchOne('SELECT COUNT(*) FROM measurement WHERE deduplication_id = ?', [$deduplicationId]), 'No measurement may survive the failed attempt, even the otherwise-valid BatV field.');
    check(0 === (int) $connection->fetchOne("SELECT COUNT(*) FROM alert_incident WHERE alert_type = 'invalid_reading'"), 'No alert incident may survive the failed attempt.');
    check(0 === (int) $connection->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'telegram_notifications'"), 'No Telegram notification may survive the failed attempt.');
    check(
        false !== $connection->fetchAssociative("SELECT * FROM messenger_messages WHERE queue_name = 'async' AND body LIKE ? AND delivered_at IS NULL", ['%'.$deduplicationId.'%']),
        'The message must remain queued for retry, not lost.'
    );
    echo "PASS rollback: marker, measurement, and incident/notification side effects all rolled back together\n";

    // -----------------------------------------------------------------
    // 4. Disarm the injection; let Messenger's real retry (not a manual
    //    re-invocation) redeliver the SAME message and complete it.
    // -----------------------------------------------------------------
    MarkerFlushFailureMiddleware::disarm();
    $connection->executeStatement("UPDATE messenger_messages SET available_at = NOW() WHERE queue_name = 'async' AND body LIKE ?", ['%'.$deduplicationId.'%']);

    consume($application);

    check(1 === $subscriber->handledCount(), 'The real Messenger retry must successfully handle the message exactly once.');
    $subscriber->disarm();

    check(1 === (int) $connection->fetchOne('SELECT COUNT(*) FROM alert_processed_uplink WHERE deduplication_id = ?', [$deduplicationId]), 'Exactly one processed-uplink marker must exist after the retry succeeds.');
    check(1 === (int) $connection->fetchOne('SELECT COUNT(*) FROM measurement WHERE deduplication_id = ?', [$deduplicationId]), 'Exactly one measurement (BatV) must exist after the retry succeeds.');
    check(1 === (int) $connection->fetchOne("SELECT COUNT(*) FROM alert_incident WHERE alert_type = 'invalid_reading' AND status = 'active'"), 'Exactly one active invalid_reading incident must exist after the retry succeeds.');
    check(1 === (int) $connection->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'telegram_notifications'"), 'Exactly one Telegram notification must be queued after the retry succeeds.');
    check(0 === (int) $connection->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'async' AND body LIKE ?", ['%'.$deduplicationId.'%']), 'No async-queue message may remain for this event after it succeeds.');
    check(
        0 === (int) $connection->fetchOne("SELECT COUNT(*) FROM messenger_messages WHERE queue_name = 'failed' AND body LIKE ?", ['%'.$deduplicationId.'%']),
        'The message must never reach the failed transport - it must succeed on the real retry.'
    );
    echo "PASS retry: the real Messenger retry completed the uplink exactly once\n";

    // -----------------------------------------------------------------
    // 5. Replay: the same uplink delivered again is a no-op.
    // -----------------------------------------------------------------
    $bus->dispatch(uplink($deduplicationId));
    consume($application);
    check(1 === (int) $connection->fetchOne('SELECT COUNT(*) FROM measurement WHERE deduplication_id = ?', [$deduplicationId]), 'A replay of the same uplink must not duplicate the measurement.');
    check(1 === (int) $connection->fetchOne('SELECT COUNT(*) FROM alert_processed_uplink WHERE deduplication_id = ?', [$deduplicationId]), 'A replay of the same uplink must not duplicate the marker.');
    echo "PASS replay: replaying the same uplink after recovery is a no-op\n";

    // -----------------------------------------------------------------
    // 6. A subsequent, different uplink still processes correctly,
    //    proving the EntityManager recovered cleanly from the earlier
    //    forced close() and is not left in a broken state.
    // -----------------------------------------------------------------
    $otherDeduplicationId = (string) Uuid::v4();
    $bus->dispatch(new ChirpStackUplink(
        devEui: 'marker-rollback-device-2',
        payload: ['BatV' => 3.6],
        measuredAt: new \DateTimeImmutable('2026-01-01T12:05:00Z'),
        deduplicationId: $otherDeduplicationId,
        deviceName: 'Marker rollback device 2',
    ));
    consume($application);
    check(1 === (int) $connection->fetchOne('SELECT COUNT(*) FROM measurement WHERE deduplication_id = ?', [$otherDeduplicationId]), 'A subsequent, unrelated uplink must still process correctly after the earlier forced EntityManager closure.');
    echo "PASS recovery: a subsequent uplink processes correctly after the earlier forced EntityManager closure\n";

    echo "PASS marker rollback: a failure after the marker flush rolls back atomically and the real Messenger retry recovers exactly once\n";
    $kernel->shutdown();
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, 'FAIL: '.$exception->getMessage()."\n".$exception->getTraceAsString()."\n");
    exit(2);
}

