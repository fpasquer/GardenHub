<?php

namespace App\Repository;

use App\Entity\Measurement;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Measurement>
 */
class MeasurementRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Measurement::class);
    }

    /**
     * Advisory-only existence check; not authoritative under concurrency,
     * see existsForSensorForUpdate().
     */
    public function existsForSensor(int $sensorId): bool
    {
        return $this->fetchExists($sensorId, forUpdate: false);
    }

    /**
     * Authoritative existence check: call only after locking the sensor
     * row (SensorRepository::lockAndFetchIdentity()), so this cannot read a
     * stale snapshot under MySQL's REPEATABLE READ isolation.
     */
    public function existsForSensorForUpdate(int $sensorId): bool
    {
        return $this->fetchExists($sensorId, forUpdate: true);
    }

    private function fetchExists(int $sensorId, bool $forUpdate): bool
    {
        $sql = 'SELECT 1 FROM measurement WHERE sensor_id = ? LIMIT 1';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        return false !== $this->getEntityManager()->getConnection()->fetchOne($sql, [$sensorId]);
    }
}
