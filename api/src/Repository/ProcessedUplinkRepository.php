<?php

namespace App\Repository;

use App\Entity\ProcessedUplink;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Clock\ClockInterface;

/**
 * @extends ServiceEntityRepository<ProcessedUplink>
 */
class ProcessedUplinkRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry, private readonly ClockInterface $clock)
    {
        parent::__construct($registry, ProcessedUplink::class);
    }

    /**
     * Returns true exactly once per ChirpStack deduplicationId: the first
     * caller records the marker row (in the caller's own transaction, so the
     * marker commits or rolls back together with the uplink's measurements
     * and alert signals), every later or concurrent delivery returns false.
     * A concurrent first delivery collides on the unique index; the
     * exception propagates so Messenger retries, and the retry's pre-check
     * then sees the committed marker and returns false.
     */
    public function isFirstProcessing(string $deduplicationId): bool
    {
        if (null !== $this->findOneBy(['deduplicationId' => $deduplicationId])) {
            return false;
        }

        $this->getEntityManager()->persist(
            (new ProcessedUplink())
                ->setDeduplicationId($deduplicationId)
                ->setFirstProcessedAt($this->clock->now())
        );

        return true;
    }
}
