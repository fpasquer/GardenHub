<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\AlertIncident;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Handles POST /alert_incidents/{id}/acknowledge. Acknowledging is purely
 * advisory bookkeeping (who has seen this incident); it does not change
 * status, so it is allowed on any non-resolved incident.
 */
final class AlertAcknowledgeProcessor implements ProcessorInterface
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly Security $security,
        private readonly ClockInterface $clock,
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if (!$data instanceof AlertIncident) {
            throw new \LogicException(sprintf('%s can only process %s.', self::class, AlertIncident::class));
        }

        $now = $this->clock->now();
        $data->setAcknowledgedAt($now)
            ->setAcknowledgedBy($this->security->getUser()?->getUserIdentifier())
            ->touch($now);

        $this->entityManager->flush();

        return $data;
    }
}
