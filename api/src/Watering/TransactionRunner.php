<?php

declare(strict_types=1);

namespace App\Watering;

use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Runs a callback in one locking transaction.
 *
 * EntityManager::wrapInTransaction() closes the manager on any exception,
 * which would break the DomainException control flow of the watering
 * services. This runner rolls back and clears instead, and only resets the
 * manager when Doctrine itself closed it after a failed flush. It is not
 * reentrant: the identity map is cleared before every transaction so locked
 * reads never see entities cached by an earlier one.
 */
final class TransactionRunner
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly ManagerRegistry $registry,
    ) {
    }

    /**
     * @template T
     *
     * @param callable(): T $callback
     *
     * @return T
     */
    public function run(callable $callback): mixed
    {
        $connection = $this->em->getConnection();
        if ($connection->isTransactionActive()) {
            throw new \LogicException('TransactionRunner is not reentrant.');
        }

        $this->em->clear();
        $connection->beginTransaction();
        try {
            $result = $callback();
            $this->em->flush();
            $connection->commit();

            return $result;
        } catch (\Throwable $e) {
            $this->abort();

            throw $e;
        }
    }

    private function abort(): void
    {
        $connection = $this->em->getConnection();
        try {
            if ($connection->isTransactionActive()) {
                $connection->rollBack();
            }
        } catch (\Throwable) {
            // The original failure is more useful than a failed rollback.
        }
        if (!$this->em->isOpen()) {
            $this->registry->resetManager();
        }
        $this->em->clear();
    }
}
