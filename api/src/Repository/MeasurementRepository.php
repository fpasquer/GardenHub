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

    /**
     * Counts distinct ChirpStack uplink events (not measurement rows: one
     * uplink yields one row per sensor type) within a half-open time window.
     */
    public function countDistinctEventsInWindow(\DateTimeImmutable $since, \DateTimeImmutable $until): int
    {
        return (int) $this->createQueryBuilder('m')
            ->select('COUNT(DISTINCT m.deduplicationId)')
            ->andWhere('m.measuredAt >= :since')
            ->andWhere('m.measuredAt < :until')
            ->setParameter('since', $since)
            ->setParameter('until', $until)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Per-sensor min/max value within the same half-open time window.
     *
     * @return array<int, array{deviceName: string, sensorId: int, sensorType: string, sensorLabel: ?string, unit: string, minValue: float, maxValue: float}>
     */
    public function aggregateMinMaxInWindow(\DateTimeImmutable $since, \DateTimeImmutable $until): array
    {
        return $this->createQueryBuilder('m')
            ->select(
                'd.name AS deviceName',
                's.id AS sensorId',
                's.type AS sensorType',
                's.label AS sensorLabel',
                's.unit AS unit',
                'MIN(m.value) AS minValue',
                'MAX(m.value) AS maxValue',
            )
            ->join('m.sensor', 's')
            ->join('s.device', 'd')
            ->andWhere('m.measuredAt >= :since')
            ->andWhere('m.measuredAt < :until')
            ->groupBy('s.id')
            ->orderBy('d.name', 'ASC')
            ->addOrderBy('s.id', 'ASC')
            ->setParameter('since', $since)
            ->setParameter('until', $until)
            ->getQuery()
            ->getArrayResult();
    }
}
