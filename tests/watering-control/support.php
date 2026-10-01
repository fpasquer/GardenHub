<?php

declare(strict_types=1);

// Shared factories for the watering-control test scripts. The fixtures
// themselves keep raw SQL on purpose: they stand in for an external writer.

use App\Entity\Device;
use App\Entity\WateringControl;
use App\Entity\WateringProposal;
use App\Entity\WateringProposalState;
use App\Entity\WateringRun;
use App\Entity\WateringTelegramProgress;
use App\Kernel;
use App\Repository\DeviceRepository;
use App\Repository\MeasurementRepository;
use App\Repository\WateringControlRepository;
use App\Repository\WateringProposalRepository;
use App\Repository\WateringProposalStateRepository;
use App\Repository\WateringRunRepository;
use App\Repository\WateringTelegramProgressRepository;
use App\Watering\ProposalBot;
use App\Watering\ProposalPolicy;
use App\Watering\InterfaceTelegramGateway;
use App\Watering\TransactionRunner;
use App\Watering\WateringManager;
use App\Watering\InterfaceWateringPublisher;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Serializer\Encoder\JsonEncoder;
use Symfony\Component\Serializer\Mapping\Factory\ClassMetadataFactory;
use Symfony\Component\Serializer\Mapping\Loader\AttributeLoader;
use Symfony\Component\Serializer\Normalizer\DateTimeNormalizer;
use Symfony\Component\Serializer\Normalizer\ObjectNormalizer;
use Symfony\Component\Serializer\Serializer;
use Symfony\Component\Serializer\SerializerInterface;

const TEST_DATABASE = 'watering_control_test';

function testRegistry(?ManagerRegistry $set = null): ManagerRegistry
{
    static $registry = null;
    $registry = $set ?? $registry;
    if (null === $registry) {
        throw new RuntimeException('Boot the test entity manager first.');
    }

    return $registry;
}

function bootTestEntityManager(Kernel $kernel): EntityManagerInterface
{
    $kernel->boot();
    $registry = $kernel->getContainer()->get('doctrine');
    testRegistry($registry);
    $em = $registry->getManager();
    if (TEST_DATABASE !== $em->getConnection()->getDatabase()) {
        throw new RuntimeException('Refusing to use a non-test database.');
    }

    return $em;
}

/** The container hides its serializer; build one that reads the same group attributes. */
function testSerializer(Kernel $kernel): SerializerInterface
{
    $metadata = new ClassMetadataFactory(new AttributeLoader());

    return new Serializer(
        [new DateTimeNormalizer(), new ObjectNormalizer($metadata)],
        [new JsonEncoder()],
    );
}

function testRepository(EntityManagerInterface $em, string $class): object
{
    return $em->getRepository($class);
}

function newTestManager(
    EntityManagerInterface $em,
    InterfaceWateringPublisher $publisher,
    bool $enabled = true,
    string $environment = 'dev',
    int $maxSeconds = WateringManager::DEFAULT_MAX_SECONDS,
    int $dailySeconds = WateringManager::DEFAULT_DAILY_SECONDS,
    int $cooldownSeconds = WateringManager::DEFAULT_COOLDOWN_SECONDS,
): WateringManager {
    /** @var WateringControlRepository $controls */
    $controls = $em->getRepository(WateringControl::class);
    /** @var WateringRunRepository $runs */
    $runs = $em->getRepository(WateringRun::class);

    return new WateringManager(
        $controls,
        $runs,
        new TransactionRunner($em, testRegistry()),
        $publisher,
        $enabled,
        $environment,
        $maxSeconds,
        $dailySeconds,
        $cooldownSeconds,
    );
}

function newTestPolicy(
    EntityManagerInterface $em,
    WateringManager $manager,
    string $actuatorTopic,
    int $durationSeconds = 3,
): ProposalPolicy {
    /** @var DeviceRepository $devices */
    $devices = $em->getRepository(Device::class);
    /** @var MeasurementRepository $measurements */
    $measurements = $em->getRepository(App\Entity\Measurement::class);
    /** @var WateringRunRepository $runs */
    $runs = $em->getRepository(WateringRun::class);
    /** @var WateringProposalRepository $proposals */
    $proposals = $em->getRepository(WateringProposal::class);
    /** @var WateringProposalStateRepository $states */
    $states = $em->getRepository(WateringProposalState::class);

    return new ProposalPolicy(
        $devices,
        $measurements,
        $runs,
        $proposals,
        $states,
        new TransactionRunner($em, testRegistry()),
        $manager,
        15,
        35,
        35,
        30,
        $durationSeconds,
        $actuatorTopic,
    );
}

function newTestBot(EntityManagerInterface $em, ProposalPolicy $policy, InterfaceTelegramGateway $telegram): ProposalBot
{
    /** @var WateringTelegramProgressRepository $progress */
    $progress = $em->getRepository(WateringTelegramProgress::class);

    return new ProposalBot($progress, $policy, $telegram, '123', '456');
}

/** Re-reads a row bypassing the identity map, as an external observer would. */
function freshRun(EntityManagerInterface $em, int $id): ?WateringRun
{
    /** @var WateringRunRepository $runs */
    $runs = $em->getRepository(WateringRun::class);

    return $runs->fresh($id);
}

function freshControl(EntityManagerInterface $em): WateringControl
{
    $em->clear();

    return $em->find(WateringControl::class, WateringControl::SINGLETON_ID)
        ?? throw new RuntimeException('Missing watering_control row.');
}

function freshProposal(EntityManagerInterface $em, int $id): ?WateringProposal
{
    /** @var WateringProposalRepository $proposals */
    $proposals = $em->getRepository(WateringProposal::class);

    return $proposals->fresh($id);
}

/** Runs are referenced by the control row and by proposals; release both first. */
function clearWateringRuns(Connection $db): void
{
    $db->executeStatement('UPDATE watering_control SET active_run_id = NULL, last_request_at = NULL WHERE id = 1');
    $db->executeStatement('UPDATE watering_proposal SET run_id = NULL');
    $db->executeStatement('DELETE FROM watering_run');
}
