<?php

namespace App\Repository;

use App\Entity\AlertIncident;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
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
     */
    public function lockOpenIncident(string $alertType, string $subjectKey): ?AlertIncident
    {
        $incident = $this->findOpenIncident($alertType, $subjectKey);
        if (null === $incident) {
            return null;
        }

        $locked = $this->getEntityManager()->getConnection()
            ->fetchOne('SELECT id FROM alert_incident WHERE id = ? FOR UPDATE', [$incident->getId()]);
        if (false === $locked) {
            // Resolved/removed concurrently between the two reads above.
            return null;
        }

        $this->getEntityManager()->refresh($incident);

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
