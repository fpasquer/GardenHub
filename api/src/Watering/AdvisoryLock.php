<?php

declare(strict_types=1);

namespace App\Watering;

use App\Entity\WateringControl;
use Doctrine\ORM\EntityManagerInterface;

/** MySQL named advisory locks, held per database connection. */
final class AdvisoryLock
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    /** Non-blocking; false when another connection holds the lock. */
    public function acquire(string $name): bool
    {
        return 1 === (int) $this->scalar('GET_LOCK(:name, 0)', $name);
    }

    public function release(string $name): void
    {
        $this->scalar('RELEASE_LOCK(:name)', $name);
    }

    /** DQL needs an entity to select from; the seeded control row provides exactly one. */
    private function scalar(string $expression, string $name): mixed
    {
        return $this->em->createQuery(sprintf(
            'SELECT %s FROM %s c WHERE c.id = :id',
            $expression,
            WateringControl::class,
        ))
            ->setParameter('name', $name)
            ->setParameter('id', WateringControl::SINGLETON_ID)
            ->getSingleScalarResult();
    }
}
