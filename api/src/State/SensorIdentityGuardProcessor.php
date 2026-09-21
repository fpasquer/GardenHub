<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Entity\Measurement;
use App\Entity\Sensor;
use App\Repository\SensorRepository;
use App\Validator\SensorIdentityChecker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Authoritative, lock-protected guard decorating the shared Doctrine ORM
 * persist processor: rejects Sensor identity changes once measurements
 * exist, and rejects Measurement creates whose sensor identity drifted
 * after the request was prepared. Every other entity passes straight
 * through to the decorated processor.
 */
final class SensorIdentityGuardProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly ProcessorInterface $decorated,
        private readonly EntityManagerInterface $entityManager,
        private readonly SensorRepository $sensorRepository,
        private readonly SensorIdentityChecker $checker,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof Sensor && null !== $data->getId()) {
            return $this->processSensorUpdate($data, $operation, $uriVariables, $context);
        }

        if ($data instanceof Measurement && null === $data->getId()) {
            return $this->processMeasurementCreate($data, $operation, $uriVariables, $context);
        }

        return $this->decorated->process($data, $operation, $uriVariables, $context);
    }

    private function processSensorUpdate(Sensor $data, Operation $operation, array $uriVariables, array $context): mixed
    {
        return $this->entityManager->wrapInTransaction(function () use ($data, $operation, $uriVariables, $context) {
            $violations = $this->checker->violations($data, lockForUpdate: true);
            if (\count($violations) > 0) {
                throw new ValidationException($violations);
            }

            return $this->decorated->process($data, $operation, $uriVariables, $context);
        });
    }

    private function processMeasurementCreate(Measurement $data, Operation $operation, array $uriVariables, array $context): mixed
    {
        $sensor = $data->getSensor();
        if (null === $sensor?->getId()) {
            return $this->decorated->process($data, $operation, $uriVariables, $context);
        }

        return $this->entityManager->wrapInTransaction(function () use ($data, $sensor, $operation, $uriVariables, $context) {
            $locked = $this->sensorRepository->lockAndFetchIdentity($sensor->getId());
            if (null !== $locked && $this->identityDrifted($locked, $sensor)) {
                throw new ValidationException($this->sensorDriftViolation($data));
            }

            return $this->decorated->process($data, $operation, $uriVariables, $context);
        });
    }

    /**
     * @param array{device_id: int, type: string, unit: string} $locked
     */
    private function identityDrifted(array $locked, Sensor $sensor): bool
    {
        return $locked['device_id'] !== $sensor->getDevice()?->getId()
            || $locked['type'] !== $sensor->getType()
            || $locked['unit'] !== $sensor->getUnit();
    }

    private function sensorDriftViolation(Measurement $data): ConstraintViolationList
    {
        $message = "The sensor's identity changed after this request was prepared; fetch the sensor again and resubmit.";

        return new ConstraintViolationList([
            new ConstraintViolation($message, $message, [], $data, 'sensor', $data->getSensor()),
        ]);
    }
}
