<?php

namespace App\Validator;

use App\Entity\Sensor;
use App\Repository\MeasurementRepository;
use App\Repository\SensorRepository;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Compares a Sensor's incoming device/type/unit against its persisted
 * identity. Shared by the advisory Validator constraint (lockForUpdate:
 * false) and the authoritative, transaction-locked Processor check
 * (lockForUpdate: true) so the comparison logic exists in one place.
 */
final class SensorIdentityChecker
{
    public function __construct(
        private readonly SensorRepository $sensorRepository,
        private readonly MeasurementRepository $measurementRepository,
    ) {
    }

    public function violations(Sensor $sensor, bool $lockForUpdate = false): ConstraintViolationListInterface
    {
        $id = $sensor->getId();
        if (null === $id) {
            return new ConstraintViolationList();
        }

        $persisted = $lockForUpdate
            ? $this->sensorRepository->lockAndFetchIdentity($id)
            : $this->sensorRepository->findPersistedIdentity($id);
        $hasMeasurements = $lockForUpdate
            ? $this->measurementRepository->existsForSensorForUpdate($id)
            : $this->measurementRepository->existsForSensor($id);

        if (null === $persisted || !$hasMeasurements) {
            return new ConstraintViolationList();
        }

        return $this->buildViolations($sensor, $persisted);
    }

    /**
     * @param array{device_id: int, type: string, unit: string} $persisted
     */
    private function buildViolations(Sensor $sensor, array $persisted): ConstraintViolationListInterface
    {
        $violations = new ConstraintViolationList();
        $message = 'This value cannot be changed once the sensor has recorded measurements.';

        if ($persisted['device_id'] !== $sensor->getDevice()?->getId()) {
            $violations->add($this->violation($sensor, 'device', $message));
        }
        if ($persisted['type'] !== $sensor->getType()) {
            $violations->add($this->violation($sensor, 'type', $message));
        }
        if ($persisted['unit'] !== $sensor->getUnit()) {
            $violations->add($this->violation($sensor, 'unit', $message));
        }

        return $violations;
    }

    private function violation(Sensor $sensor, string $property, string $message): ConstraintViolation
    {
        $value = match ($property) {
            'device' => $sensor->getDevice()?->getId(),
            'type' => $sensor->getType(),
            'unit' => $sensor->getUnit(),
        };

        return new ConstraintViolation($message, $message, [], $sensor, $property, $value);
    }
}
