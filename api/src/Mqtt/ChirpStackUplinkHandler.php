<?php

namespace App\Mqtt;

use App\Entity\Device;
use App\Entity\Measurement;
use App\Entity\Sensor;
use App\Repository\DeviceRepository;
use App\Repository\SensorRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Persists the decoded payload of a ChirpStack uplink as measurements.
 *
 * Devices and sensors are auto-provisioned on first sight: an unknown
 * devEUI creates a Device, and each mapped payload field creates the
 * matching Sensor. Payload fields not present in the field map are
 * ignored, so the codec output can grow without breaking ingestion.
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
        private readonly array $fieldMap,
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

        $stored = 0;
        foreach ($uplink->payload as $field => $rawValue) {
            $mapping = $this->fieldMap[$field] ?? null;
            if (null === $mapping) {
                $this->logger->debug('Ignoring unmapped payload field.', ['devEui' => $uplink->devEui, 'field' => $field]);
                continue;
            }

            if (!is_numeric($rawValue)) {
                $this->logger->warning('Skipping non-numeric payload value.', ['devEui' => $uplink->devEui, 'field' => $field, 'value' => $rawValue]);
                continue;
            }

            $sensor = $this->sensorRepository->findOneBy(['device' => $device, 'type' => $mapping['type']])
                ?? $this->createSensor($device, $mapping['type'], $mapping['unit'], $field);

            $measurement = (new Measurement())
                ->setSensor($sensor)
                ->setValue((float) $rawValue)
                ->setMeasuredAt($uplink->measuredAt);

            $violations = $this->validator->validate($measurement);
            if (count($violations) > 0) {
                $this->logger->error('Measurement rejected by validation.', [
                    'devEui' => $uplink->devEui,
                    'field' => $field,
                    'violations' => (string) $violations,
                ]);
                continue;
            }

            $this->entityManager->persist($measurement);
            ++$stored;
        }

        $this->entityManager->flush();
        $this->logger->info('Uplink processed.', ['devEui' => $uplink->devEui, 'stored' => $stored]);
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
