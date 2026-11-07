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
            ->addSelect('s')->leftJoin('r.stages', 's')
            ->where('r.id = :id')
            ->setParameter('id', $id)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

    public function latest(): ?WateringRun
    {
        $id = $this->createQueryBuilder('r')
            ->select('r.id')->orderBy('r.requestedAt', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();

        return null === $id ? null : $this->fresh((int) $id['id']);
    }

    public function sumRequestedSince(\DateTimeImmutable $since): int
    {
        $legacy = (int) $this->createQueryBuilder('r')
            ->select('COALESCE(SUM(r.requestedSeconds), 0)')
            ->where('r.requestedAt >= :since')
            ->andWhere('r.stages IS EMPTY')
            ->setParameter('since', $since, Types::DATETIME_IMMUTABLE)
            ->getQuery()
            ->getSingleScalarResult();

        $stages = $this->getEntityManager()->getConnection()->fetchOne(
            'SELECT COALESCE(SUM(s.requested_seconds), 0)
             FROM watering_stage s JOIN watering_run r ON r.id = s.run_id
             WHERE s.requested_at >= :since OR
               (s.requested_at IS NULL AND r.status IN
                 (\'pending\', \'running\', \'break\', \'uncertain\', \'timed_out\'))',
            ['since' => $since->format('Y-m-d H:i:s')],
        );

        return $legacy + (int) $stages;
    }

    public function resetSafetyProbe(): void
    {
        $this->createQueryBuilder('r')->update()
            ->set('r.safetyProbeAt', 'NULL')
            ->where('r.status = :break')
            ->setParameter('break', WateringRun::STATUS_BREAK)
            ->getQuery()->execute();
    }

    /** Compare-and-set; returns the number of rows changed (0 when no longer open). */
    public function markUncertainIfOpen(int $id, string $error): int
    {
        $changed = (int) $this->createQueryBuilder('r')
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
        if ($changed > 0) {
            $this->getEntityManager()->getConnection()->executeStatement(
                "UPDATE watering_stage SET status = 'uncertain'
                 WHERE run_id = ? AND status IN ('pending', 'running')",
                [$id],
            );
        }

        return $changed;
    }

    /** Inspects the run referenced by active_run_id, never one inferred by recency. */
    public function requiresStop(?string $topic = null): bool
    {
        $status = $this->getEntityManager()->createQueryBuilder()
            ->select('r.status')
            ->from(WateringControl::class, 'c')
            ->join('c.activeRun', 'r')
            ->where('c.id = :id')
            ->andWhere('(r.actuatorTopic IS NULL OR r.actuatorTopic = :topic)')
            ->setParameter('topic', $topic)
            ->setParameter('id', WateringControl::SINGLETON_ID)
            ->getQuery()
            ->getOneOrNullResult();

        return null !== $status && in_array($status['status'], WateringRun::BLOCKED_STATUSES, true);
    }
}
