<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\WateringTelegramProgress;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WateringTelegramProgress>
 */
class WateringTelegramProgressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WateringTelegramProgress::class);
    }

    public function getOffset(): int
    {
        $offset = $this->createQueryBuilder('p')
            ->select('p.nextUpdateId')
            ->where('p.id = :id')
            ->setParameter('id', WateringTelegramProgress::SINGLETON_ID)
            ->getQuery()
            ->getOneOrNullResult();

        return null === $offset ? 0 : (int) $offset['nextUpdateId'];
    }

    /** Compare-and-set: the offset only ever moves forward. */
    public function advanceTo(int $offset): bool
    {
        return 1 === $this->createQueryBuilder('p')
            ->update()
            ->set('p.nextUpdateId', ':offset')
            ->where('p.id = :id')
            ->andWhere('p.nextUpdateId < :offset')
            ->setParameter('offset', $offset)
            ->setParameter('id', WateringTelegramProgress::SINGLETON_ID)
            ->getQuery()
            ->execute();
    }
}
