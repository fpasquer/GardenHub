<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WateringProposal;
use App\Entity\WateringRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Compare-and-set methods return the number of rows changed; callers rely on
 * it to detect that another process already moved the proposal on. Bulk
 * updates bypass the identity map, so re-read with fresh() afterwards.
 *
 * @extends ServiceEntityRepository<WateringProposal>
 */
class WateringProposalRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WateringProposal::class);
    }

    /** Persists and flushes so the generated id is available. */
    public function add(WateringProposal $proposal): void
    {
        $this->getEntityManager()->persist($proposal);
        $this->getEntityManager()->flush();
    }

    public function fresh(int $id): ?WateringProposal
    {
        return $this->createQueryBuilder('p')
            ->where('p.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

    /** Locks and re-reads the row; must run inside a transaction. */
    public function lockById(int $id): ?WateringProposal
    {
        return $this->createQueryBuilder('p')
            ->where('p.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

    public function hasPending(int $deviceId): bool
    {
        return [] !== $this->createQueryBuilder('p')
            ->select('p.id')
            ->where('p.device = :device')
            ->andWhere('p.status = :pending')
            ->setParameter('device', $deviceId)
            ->setParameter('pending', WateringProposal::STATUS_PENDING)
            ->setMaxResults(1)
            ->getQuery()
            ->getArrayResult();
    }

    /**
     * Only unclaimed, still actionable proposals may be sent after a restart.
     *
     * @return list<WateringProposal>
     */
    public function newNotifications(string $actuatorTopic, \DateTimeImmutable $now): array
    {
        return $this->createQueryBuilder('p')
            ->addSelect('d')
            ->join('p.device', 'd')
            ->where('p.status = :pending')
            ->andWhere('p.notificationStatus = :new')
            ->andWhere('p.expiresAt > :now')
            ->andWhere('p.actuatorTopic = :topic')
            ->setParameter('pending', WateringProposal::STATUS_PENDING)
            ->setParameter('new', WateringProposal::NOTIFICATION_NEW)
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->setParameter('topic', $actuatorTopic)
            ->orderBy('p.createdAt')
            ->addOrderBy('p.id')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();
    }

    /** @return list<WateringProposal> */
    public function finalMessages(): array
    {
        return $this->createQueryBuilder('p')
            ->addSelect('d', 'r')
            ->join('p.device', 'd')
            ->leftJoin('p.run', 'r')
            ->where('p.messageId IS NOT NULL')
            ->andWhere('p.notificationStatus = :sent')
            ->andWhere('p.status NOT IN (:open)')
            ->setParameter('sent', WateringProposal::NOTIFICATION_SENT)
            ->setParameter('open', [WateringProposal::STATUS_PENDING, WateringProposal::STATUS_EXECUTING])
            ->orderBy('p.createdAt')
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getResult();
    }

    public function claimNotification(int $id, string $actuatorTopic, \DateTimeImmutable $now): bool
    {
        return 1 === $this->createQueryBuilder('p')
            ->update()
            ->set('p.notificationStatus', ':sending')
            ->where('p.id = :id')
            ->andWhere('p.status = :pending')
            ->andWhere('p.notificationStatus = :new')
            ->andWhere('p.expiresAt > :now')
            ->andWhere('p.actuatorTopic = :topic')
            ->setParameter('sending', WateringProposal::NOTIFICATION_SENDING)
            ->setParameter('id', $id)
            ->setParameter('pending', WateringProposal::STATUS_PENDING)
            ->setParameter('new', WateringProposal::NOTIFICATION_NEW)
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->setParameter('topic', $actuatorTopic)
            ->getQuery()
            ->execute();
    }

    public function recordMessage(int $id, int $messageId): int
    {
        return $this->transitionNotification($id, WateringProposal::NOTIFICATION_SENDING, WateringProposal::NOTIFICATION_SENT)
            ->set('p.messageId', ':message')
            ->setParameter('message', $messageId)
            ->getQuery()
            ->execute();
    }

    public function markNotificationUncertain(int $id): int
    {
        return $this->transitionNotification($id, WateringProposal::NOTIFICATION_SENDING, WateringProposal::NOTIFICATION_UNCERTAIN)
            ->getQuery()
            ->execute();
    }

    public function markFinal(int $id): int
    {
        return $this->transitionNotification($id, WateringProposal::NOTIFICATION_SENT, WateringProposal::NOTIFICATION_FINAL)
            ->getQuery()
            ->execute();
    }

    /** Ends an approved execution; only an 'executing' proposal can be finished. */
    public function finishExecution(int $id, string $status, ?string $failure, ?int $runId): int
    {
        $builder = $this->createQueryBuilder('p')
            ->update()
            ->set('p.status', ':status')
            ->set('p.failure', ':failure')
            ->where('p.id = :id')
            ->andWhere('p.status = :executing')
            ->setParameter('status', $status)
            ->setParameter('failure', $failure)
            ->setParameter('id', $id)
            ->setParameter('executing', WateringProposal::STATUS_EXECUTING);
        if (null !== $runId) {
            $builder->set('p.run', ':run')
                ->setParameter('run', $this->getEntityManager()->getReference(WateringRun::class, $runId));
        }

        return (int) $builder->getQuery()->execute();
    }

    public function expireDue(\DateTimeImmutable $now, ?int $deviceId = null): int
    {
        $builder = $this->createQueryBuilder('p')
            ->update()
            ->set('p.status', ':expired')
            ->where('p.status = :pending')
            ->andWhere('p.expiresAt <= :now')
            ->setParameter('expired', WateringProposal::STATUS_EXPIRED)
            ->setParameter('pending', WateringProposal::STATUS_PENDING)
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE);
        if (null !== $deviceId) {
            $builder->andWhere('p.device = :device')->setParameter('device', $deviceId);
        }

        return (int) $builder->getQuery()->execute();
    }

    public function interruptExecuting(): int
    {
        return (int) $this->createQueryBuilder('p')
            ->update()
            ->set('p.status', ':uncertain')
            ->set('p.failure', ':failure')
            ->where('p.status = :executing')
            ->setParameter('uncertain', WateringProposal::STATUS_UNCERTAIN)
            ->setParameter('failure', 'Interrupted execution; manual review required')
            ->setParameter('executing', WateringProposal::STATUS_EXECUTING)
            ->getQuery()
            ->execute();
    }

    public function invalidatePending(int $deviceId, string $reason): int
    {
        return (int) $this->invalidation($reason)
            ->andWhere('p.device = :device')
            ->setParameter('device', $deviceId)
            ->getQuery()
            ->execute();
    }

    /** A NULL topic marks a legacy proposal, which never matches the configured actuator. */
    public function invalidateOtherActuators(int $deviceId, string $actuatorTopic): int
    {
        return (int) $this->invalidation('Actuator changed')
            ->andWhere('p.device = :device')
            ->andWhere('(p.actuatorTopic IS NULL OR p.actuatorTopic <> :topic)')
            ->setParameter('device', $deviceId)
            ->setParameter('topic', $actuatorTopic)
            ->getQuery()
            ->execute();
    }

    private function invalidation(string $reason): QueryBuilder
    {
        return $this->createQueryBuilder('p')
            ->update()
            ->set('p.status', ':invalidated')
            ->set('p.failure', ':reason')
            ->where('p.status = :pending')
            ->setParameter('invalidated', WateringProposal::STATUS_INVALIDATED)
            ->setParameter('reason', $reason)
            ->setParameter('pending', WateringProposal::STATUS_PENDING);
    }

    private function transitionNotification(int $id, string $from, string $to): QueryBuilder
    {
        return $this->createQueryBuilder('p')
            ->update()
            ->set('p.notificationStatus', ':to')
            ->where('p.id = :id')
            ->andWhere('p.notificationStatus = :from')
            ->setParameter('to', $to)
            ->setParameter('id', $id)
            ->setParameter('from', $from);
    }
}
