<?php

declare(strict_types=1);

namespace App\Repository;

use App\Entity\Device;
use App\Entity\WateringProposalState;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\Query;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WateringProposalState>
 */
class WateringProposalStateRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WateringProposalState::class);
    }

    /**
     * Locks the counter, creating it when absent. Callers hold the device
     * row lock, so two creators can never race on the same key.
     */
    public function lockOrCreate(Device $device, string $actuatorTopic): WateringProposalState
    {
        $state = $this->createQueryBuilder('s')
            ->where('s.device = :device')
            ->andWhere('s.actuatorTopic = :topic')
            ->setParameter('device', $device)
            ->setParameter('topic', $actuatorTopic)
            ->getQuery()
            ->setLockMode(LockMode::PESSIMISTIC_WRITE)
            ->setHint(Query::HINT_REFRESH, true)
            ->getOneOrNullResult();
        if (null !== $state) {
            return $state;
        }

        $state = new WateringProposalState($device, $actuatorTopic);
        $this->getEntityManager()->persist($state);
        $this->getEntityManager()->flush();

        return $state;
    }

    /** A wet reading starts a new dry episode for every actuator of the device. */
    public function resetEpisode(int $deviceId): int
    {
        return (int) $this->createQueryBuilder('s')
            ->update()
            ->set('s.promptCount', '0')
            ->set('s.lastPromptAt', 'NULL')
            ->where('s.device = :device')
            ->setParameter('device', $deviceId)
            ->getQuery()
            ->execute();
    }
}
