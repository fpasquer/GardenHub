<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WateringControl;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WateringControl>
 */
class WateringControlRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WateringControl::class);
    }

    /** Locks and re-reads the singleton row; must run inside a transaction. */
    public function lock(): ?WateringControl
    {
        return $this->createQueryBuilder('c')
            ->where('c.id = :id')
            ->setParameter('id', WateringControl::SINGLETON_ID)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
    }

    public function touchHeartbeat(\DateTimeImmutable $now): void
    {
        $this->createQueryBuilder('c')
            ->update()
            ->set('c.monitorSeenAt', ':now')
            ->where('c.id = :id')
            ->setParameter('now', $now, Types::DATETIME_IMMUTABLE)
            ->setParameter('id', WateringControl::SINGLETON_ID)
            ->getQuery()
            ->execute();
    }

    public function clearHeartbeat(): void
    {
        $this->createQueryBuilder('c')
            ->update()
            ->set('c.monitorSeenAt', 'NULL')
            ->where('c.id = :id')
            ->setParameter('id', WateringControl::SINGLETON_ID)
            ->getQuery()
            ->execute();
    }
}
