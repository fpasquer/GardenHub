<?php

namespace App\Repository;

use App\Entity\AlertEvaluationProgress;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AlertEvaluationProgress>
 */
class AlertEvaluationProgressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AlertEvaluationProgress::class);
    }

    /**
     * Locks and returns the progress row for this (alert_type, subject_key),
     * creating it on first sight. The locking refresh (PESSIMISTIC_WRITE)
     * loads the latest committed state into the entity, so a stale
     * REPEATABLE-READ snapshot or identity-map state can never hide another
     * transaction's progress. The unique index on (alert_type, subject_key)
     * is the backstop for the first-create race: a concurrent create
     * collides, propagates, and Messenger's bounded retry then finds the row.
     *
     * Callers must run this inside a transaction that also covers their own
     * write (AlertLifecycleService's wrapInTransaction).
     */
    public function lockOrCreate(string $alertType, string $subjectKey): AlertEvaluationProgress
    {
        $progress = $this->findOneBy(['alertType' => $alertType, 'subjectKey' => $subjectKey]);
        if (null !== $progress) {
            $this->getEntityManager()->refresh($progress, LockMode::PESSIMISTIC_WRITE);

            return $progress;
        }

        $progress = (new AlertEvaluationProgress())
            ->setAlertType($alertType)
            ->setSubjectKey($subjectKey);
        $this->getEntityManager()->persist($progress);
        $this->getEntityManager()->flush();

        return $progress;
    }
}
