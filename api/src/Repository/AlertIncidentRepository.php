<?php

namespace App\Repository;

use App\Entity\AlertIncident;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AlertIncident>
 */
class AlertIncidentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AlertIncident::class);
    }

    /**
     * Advisory-only read; not safe as the sole guard against a concurrent
     * open/confirm, see lockOpenIncident().
     */
    public function findOpenIncident(string $alertType, string $subjectKey): ?AlertIncident
    {
        return $this->createOpenIncidentQueryBuilder($alertType, $subjectKey)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Locks and returns the currently open incident for this
     * (alert_type, subject_key), or null if none exists.
     *
     * Callers must run this inside a transaction that also covers their own
     * write, otherwise the lock is released before it protects anything.
     * There is nothing to lock before a first incident exists: the unique
     * index on the database-generated is_open column is the backstop for
     * that race (see AlertLifecycleService).
     *
     * This is a direct locking read (SELECT ... FOR UPDATE), not an
     * advisory read followed by a conditional lock: a plain query would
     * reuse this transaction's REPEATABLE-READ snapshot and could miss a
     * row another transaction inserted after that snapshot was taken,
     * making the caller wrongly conclude no incident is open. The locking
     * read always sees the latest committed state, and HINT_REFRESH forces
     * the hydrated entity to reflect it even if a stale instance already
     * sits in the identity map. The status is re-validated under the lock
     * as a defensive check.
     */
    public function lockOpenIncident(string $alertType, string $subjectKey): ?AlertIncident
    {
        $incident = $this->createOpenIncidentQueryBuilder($alertType, $subjectKey)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        if (null === $incident) {
            return null;
        }

        if (!in_array($incident->getStatus(), [AlertIncident::STATUS_PENDING, AlertIncident::STATUS_ACTIVE], true)) {
            // Resolved concurrently between the query parse and the lock.
            return null;
        }

        return $incident;
    }

    private function createOpenIncidentQueryBuilder(string $alertType, string $subjectKey): \Doctrine\ORM\QueryBuilder
    {
        return $this->createQueryBuilder('a')
            ->where('a.alertType = :alertType')
            ->andWhere('a.subjectKey = :subjectKey')
            ->andWhere('a.status IN (:openStatuses)')
            ->setParameter('alertType', $alertType)
            ->setParameter('subjectKey', $subjectKey)
            ->setParameter('openStatuses', [AlertIncident::STATUS_PENDING, AlertIncident::STATUS_ACTIVE]);
    }

    /**
     * Active incidents whose reminder is due, for the periodic scheduler.
     *
     * @return list<AlertIncident>
     */
    public function findDueForReminder(\DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('a')
            ->where('a.status = :status')
            ->andWhere('a.nextReminderAt IS NOT NULL')
            ->andWhere('a.nextReminderAt <= :now')
            ->setParameter('status', AlertIncident::STATUS_ACTIVE)
            ->setParameter('now', $now)
            ->getQuery()
            ->getResult();
    }
}
