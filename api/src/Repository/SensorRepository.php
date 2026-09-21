<?php

namespace App\Repository;

use App\Entity\Sensor;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Sensor>
 */
class SensorRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Sensor::class);
    }

    /**
     * Advisory-only read of the persisted identity; not safe as the sole
     * guard against a concurrent write, see lockAndFetchIdentity().
     *
     * @return array{device_id: int, type: string, unit: string}|null
     */
    public function findPersistedIdentity(int $id): ?array
    {
        return $this->fetchIdentity($id, forUpdate: false);
    }

    /**
     * Locks the sensor row and reads its current identity. Authoritative:
     * callers must run this inside a transaction that also covers their
     * own write, otherwise the lock is released before it protects anything.
     *
     * @return array{device_id: int, type: string, unit: string}|null
     */
    public function lockAndFetchIdentity(int $id): ?array
    {
        return $this->fetchIdentity($id, forUpdate: true);
    }

    /**
     * @return array{device_id: int, type: string, unit: string}|null
     */
    private function fetchIdentity(int $id, bool $forUpdate): ?array
    {
        $sql = 'SELECT device_id, type, unit FROM sensor WHERE id = ?';
        if ($forUpdate) {
            $sql .= ' FOR UPDATE';
        }

        $row = $this->getEntityManager()->getConnection()->fetchAssociative($sql, [$id]);
        if (false === $row) {
            return null;
        }

        return [
            'device_id' => (int) $row['device_id'],
            'type' => (string) $row['type'],
            'unit' => (string) $row['unit'],
        ];
    }
}
