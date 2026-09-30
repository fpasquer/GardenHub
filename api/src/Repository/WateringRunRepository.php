<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WateringControl;
use App\Entity\WateringRun;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WateringRun>
 */
class WateringRunRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WateringRun::class);
    }

    /** Persists and flushes so the generated id is available. */
    public function add(WateringRun $run): void
    {
        $this->getEntityManager()->persist($run);
        $this->getEntityManager()->flush();
    }

    /** Re-reads the row; long-running loops may hold a stale identity map. */
    public function fresh(int $id): ?WateringRun
    {
        return $this->createQueryBuilder('r')
            ->where('r.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

    public function latest(): ?WateringRun
    {
        return $this->createQueryBuilder('r')
            ->orderBy('r.requestedAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

    public function sumRequestedSince(\DateTimeImmutable $since): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COALESCE(SUM(r.requestedSeconds), 0)')
            ->where('r.requestedAt >= :since')
            ->setParameter('since', $since, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /** Compare-and-set; returns the number of rows changed (0 when no longer open). */
    public function markUncertainIfOpen(int $id, string $error): int
    {
        return (int) $this->createQueryBuilder('r')
            ->update()
            ->set('r.status', ':uncertain')
            ->set('r.error', ':error')
            ->where('r.id = :id')
            ->andWhere('r.status IN (:open)')
            ->setParameter('uncertain', WateringRun::STATUS_UNCERTAIN)
            ->setParameter('error', $error)
            ->setParameter('id', $id)
            ->setParameter('open', WateringRun::OPEN_STATUSES)
            ->getQuery()
            ->execute();
    }

    /** Inspects the run referenced by active_run_id, never one inferred by recency. */
    public function requiresStop(): bool
    {
        $status = $this->getEntityManager()->createQueryBuilder()
            ->select('r.status')
            ->from(WateringControl::class, 'c')
            ->join('c.activeRun', 'r')
            ->where('c.id = :id')
            ->setParameter('id', WateringControl::SINGLETON_ID)
            ->getQuery()
            ->getOneOrNullResult();

        return null !== $status && in_array($status['status'], WateringRun::BLOCKED_STATUSES, true);
    }
}
