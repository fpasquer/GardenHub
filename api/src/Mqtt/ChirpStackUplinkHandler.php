<?php

namespace App\Mqtt;

use App\Alert\AlertLifecycleService;
use App\Alert\AlertSignal;
use App\Alert\InvalidReading\InvalidReadingAlertService;
use App\Alert\Message\EvaluateUplinkMeasurements;
use App\Entity\Device;
use App\Entity\Measurement;
use App\Entity\Sensor;
use App\Mqtt\Exception\SensorIdentityChangedException;
use App\Repository\DeviceRepository;
use App\Repository\SensorRepository;
use App\Validator\AssertPhysicalRange;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Persists the decoded payload of a ChirpStack uplink as measurements.
 *
 * Devices and sensors are auto-provisioned on first sight: an unknown
 * devEUI creates a Device, and each mapped payload field creates the
 * matching Sensor. Payload fields not present in the field map are
 * ignored, so the codec output can grow without breaking ingestion.
 *
 * Idempotency: each measurement carries the ChirpStack deduplicationId and
 * its type. A per-type pre-check skips types already stored for this event and
 * only inserts the missing ones, so a replayed uplink is stored at most once per
 * (deduplicationId, type). A concurrent delivery that slips past the pre-check
 * hits the unique (deduplication_id, type) index on flush; the exception is left
 * to propagate so Messenger retries with its bounded strategy, and the retry's
 * pre-check then no-ops. The exception is deliberately not caught here: Doctrine
 * closes the entity manager whenever an exception escapes storeMeasurements()'s
 * transaction, so catch-and-ack would be unsafe.
 *
 * Sensor-identity race: storeMeasurements() wraps the loop and the final
 * flush in one explicit transaction (no ambient/messenger-provided transaction
 * exists for this handler). Each resolved sensor is re-locked and its full
 * (device, type, unit) identity reconfirmed under that lock, both against
 * what was just resolved and against this field's own mapped device/type/unit;
 * a mismatch means a concurrent API update relabeled the sensor, so it throws
 * SensorIdentityChangedException, which rolls back this uplink's transaction
 * and lets Messenger's bounded retry_strategy redeliver the whole message for
 * a fresh, current-data resolution.
 */
#[AsMessageHandler]
final class ChirpStackUplinkHandler
{
    /**
     * @param array<string, array{type: string, unit: string}> $fieldMap Maps payload field names to sensor type/unit
     */
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DeviceRepository $deviceRepository,
        private readonly SensorRepository $sensorRepository,
        private readonly ValidatorInterface $validator,
        private readonly LoggerInterface $logger,
        private readonly MessageBusInterface $messageBus,
        private readonly InvalidReadingAlertService $invalidReadingAlertService,
        private readonly AlertLifecycleService $alertLifecycleService,
        private readonly array $fieldMap,
        private readonly int $processingFailureConfirmationCount,
        private readonly int $processingFailureRecoveryCount,
    ) {
    }

    public function __invoke(ChirpStackUplink $uplink): void
    {
        $device = $this->deviceRepository->findOneByDevEui($uplink->devEui)
            ?? $this->createDevice($uplink->devEui, $uplink->deviceName);

        // Upgrade devices still named after their devEUI placeholder to the
        // ChirpStack device name once it is known.
        if (null !== $uplink->deviceName && $device->getName() === $uplink->devEui) {
            $device->setName($uplink->deviceName);
            $this->entityManager->flush();
        }

        $existingTypes = $this->existingTypesForEvent($device, $uplink->deduplicationId);
        $stored = $this->storeMeasurements($uplink, $device, $existingTypes);

        // Any successful processing of this exact uplink means whatever
        // previously failed for it (if anything) is now resolved. A no-op
        // when no processing_failure incident is open for it.
        $this->alertLifecycleService->openOrAdvance(
            'processing_failure',
            sprintf('chirpstack_uplink:%s', $uplink->deduplicationId),
            new AlertSignal(breach: false),
            $this->processingFailureConfirmationCount,
            $this->processingFailureRecoveryCount,
        );

        $this->logger->info('Uplink processed.', ['devEui' => $uplink->devEui, 'deduplicationId' => $uplink->deduplicationId, 'stored' => $stored]);
    }

    /**
     * Stores every mapped payload field inside one transaction, so a
     * sensor-identity lock taken while resolving a field is still held when
     * the final flush commits. Alert evaluation for the newly stored
     * measurements is dispatched after the flush (so their ids are known)
     * but still inside this same transaction, giving it the same
     * all-or-nothing durability as the measurements themselves.
     *
     * @param array<string, true> $existingTypes Types already stored for this event (pre-check)
     */
    private function storeMeasurements(ChirpStackUplink $uplink, Device $device, array $existingTypes): int
    {
        return $this->entityManager->wrapInTransaction(function () use ($uplink, $device, $existingTypes): int {
            $seenTypes = [];
            $persisted = [];
            foreach ($uplink->payload as $field => $rawValue) {
                $measurement = $this->storeFieldMeasurement($uplink, $device, (string) $field, $rawValue, $existingTypes, $seenTypes);
                if (null !== $measurement) {
                    $persisted[] = $measurement;
                }
            }
            $this->entityManager->flush();
            $this->dispatchEvaluation($persisted);

            return count($persisted);
        });
    }

    /**
     * @param list<Measurement> $measurements
     */
    private function dispatchEvaluation(array $measurements): void
    {
        if ([] === $measurements) {
            return;
        }

        $ids = array_map(static fn (Measurement $measurement): int => $measurement->getId(), $measurements);
        $this->messageBus->dispatch(new EvaluateUplinkMeasurements($ids));
    }

    /**
     * Returns the measurement types already stored for this event on this device.
     *
     * @return array<string, true>
     */
    private function existingTypesForEvent(Device $device, string $deduplicationId): array
    {
        $types = $this->entityManager->createQuery(
            'SELECT DISTINCT m.type FROM App\Entity\Measurement m JOIN m.sensor s WHERE s.device = :device AND m.deduplicationId = :id'
        )
            ->setParameter('device', $device)
            ->setParameter('id', $deduplicationId)
            ->getSingleColumnResult();

        return array_fill_keys($types, true);
    }

    /**
     * Validates and persists one payload field as a measurement.
     *
     * @param array<string, true> $existingTypes Types already stored for this event (pre-check)
     * @param array<string, true> $seenTypes     Types already handled in this invocation (in-event guard)
     */
    private function storeFieldMeasurement(
        ChirpStackUplink $uplink,
        Device $device,
        string $field,
        mixed $rawValue,
        array $existingTypes,
        array &$seenTypes,
    ): ?Measurement {
        $mapping = $this->fieldMap[$field] ?? null;
        if (null === $mapping) {
            $this->logger->debug('Ignoring unmapped payload field.', ['devEui' => $uplink->devEui, 'field' => $field]);
            return null;
        }
        $type = $mapping['type'];

        if (!is_numeric($rawValue)) {
            $this->logger->warning('Skipping non-numeric payload value.', ['devEui' => $uplink->devEui, 'field' => $field, 'value' => $rawValue]);
            return null;
        }

        // Already stored for this event (replay / concurrent delivery): top-up only the missing types.
        if (isset($existingTypes[$type])) {
            $this->logger->info('Measurement type already processed for this event; skipping.', ['devEui' => $uplink->devEui, 'deduplicationId' => $uplink->deduplicationId, 'type' => $type]);
            return null;
        }

        // Two fields mapping to the same type within one event: first wins, so a
        // misconfigured field map cannot collide with the unique index.
        if (isset($seenTypes[$type])) {
            $this->logger->warning('Duplicate measurement type in one uplink; keeping the first value.', ['devEui' => $uplink->devEui, 'field' => $field, 'type' => $type]);
            return null;
        }
        $seenTypes[$type] = true;

        $sensor = $this->sensorRepository->findOneBy(['device' => $device, 'type' => $type])
            ?? $this->createSensor($device, $type, $mapping['unit'], $field);
        $this->assertSensorIdentityCompatible($sensor, $device, $type, $mapping['unit']);

        $measurement = (new Measurement())
            ->setSensor($sensor)
            ->setValue((float) $rawValue)
            ->setMeasuredAt($uplink->measuredAt)
            ->setDeduplicationId($uplink->deduplicationId)
            ->setType($type);

        $violations = $this->validator->validate($measurement);
        if (count($violations) > 0) {
            $this->logger->error('Measurement rejected by validation.', [
                'devEui' => $uplink->devEui,
                'field' => $field,
                'violations' => (string) $violations,
            ]);
            if (self::containsPhysicalRangeViolation($violations)) {
                $this->invalidReadingAlertService->recordRejection($device, $uplink->measuredAt, (string) $violations);
            }

            return null;
        }

        $this->entityManager->persist($measurement);
        $this->invalidReadingAlertService->recordValidReading($device, $uplink->measuredAt);

        return $measurement;
    }

    private static function containsPhysicalRangeViolation(ConstraintViolationListInterface $violations): bool
    {
        foreach ($violations as $violation) {
            if ($violation->getConstraint() instanceof AssertPhysicalRange) {
                return true;
            }
        }

        return false;
    }


    /**
     * Locks the sensor row and reconfirms its (device, type, unit) identity
     * still matches what was just resolved (a concurrent API update relabeled
     * it after resolution but before this lock), then reconfirms the locked
     * identity is still compatible with this uplink field's own mapping (a
     * relabel that committed before resolution would pass the check above but
     * still silently store this measurement under the wrong type/unit).
     */
    private function assertSensorIdentityCompatible(Sensor $sensor, Device $device, string $type, string $unit): void
    {
        $expected = [
            'device_id' => $sensor->getDevice()?->getId(),
            'type' => $sensor->getType(),
            'unit' => $sensor->getUnit(),
        ];

        $locked = $this->sensorRepository->lockAndFetchIdentity($sensor->getId());
        if (null === $locked) {
            return;
        }

        if ($locked['device_id'] !== $expected['device_id']
            || $locked['type'] !== $expected['type']
            || $locked['unit'] !== $expected['unit']
        ) {
            throw new SensorIdentityChangedException(sprintf('Sensor #%d identity changed between resolution and locking.', $sensor->getId()));
        }

        if ($locked['device_id'] !== $device->getId() || $locked['type'] !== $type || $locked['unit'] !== $unit) {
            throw new SensorIdentityChangedException(sprintf(
                "Sensor #%d identity is incompatible with the mapped device/type/unit for this uplink field (expected device #%d type '%s' unit '%s', found device #%d type '%s' unit '%s').",
                $sensor->getId(),
                $device->getId(),
                $type,
                $unit,
                $locked['device_id'],
                $locked['type'],
                $locked['unit'],
            ));
        }
    }

    private function createDevice(string $devEui, ?string $deviceName): Device
    {
        $device = (new Device())
            ->setName($deviceName ?? $devEui)
            ->setDevEui($devEui);

        $this->entityManager->persist($device);
        // Flush now so the device exists before its sensors are looked up.
        $this->entityManager->flush();

        $this->logger->info('Device auto-provisioned from uplink.', ['devEui' => $devEui, 'name' => $device->getName()]);

        return $device;
    }

    private function createSensor(Device $device, string $type, string $unit, string $field): Sensor
    {
        $sensor = (new Sensor())
            ->setDevice($device)
            ->setType($type)
            ->setUnit($unit)
            ->setLabel($field);

        $this->entityManager->persist($sensor);
        $this->entityManager->flush();

        $this->logger->info('Sensor auto-provisioned from uplink.', ['devEui' => $device->getDevEui(), 'type' => $type]);

        return $sensor;
    }
}
