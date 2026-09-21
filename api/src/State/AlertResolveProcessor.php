<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Entity\AlertIncident;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Clock\ClockInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;

/**
 * Handles POST /alert_incidents/{id}/resolve. Manual resolution is only
 * allowed for an active processing_failure incident (e.g. fixed by a
 * deploy with no matching successful redelivery to auto-resolve it);
 * every other alert type resolves itself once the underlying condition
 * recovers, so manually forcing it closed would hide a still-real problem.
 */
final class AlertResolveProcessor implements ProcessorInterface
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

        $this->assertManuallyResolvable($data);

        $now = $this->clock->now();
        $data->setStatus(AlertIncident::STATUS_RESOLVED)
            ->setResolvedAt($now)
            ->setResolvedBy($this->security->getUser()?->getUserIdentifier())
            ->touch($now);

        $this->entityManager->flush();

        return $data;
    }

    private function assertManuallyResolvable(AlertIncident $data): void
    {
        if ('processing_failure' === $data->getAlertType() && AlertIncident::STATUS_ACTIVE === $data->getStatus()) {
            return;
        }

        $message = 'Only an active processing_failure incident can be manually resolved; other alert types resolve automatically once the underlying condition recovers.';
        throw new ValidationException(new ConstraintViolationList([
            new ConstraintViolation($message, $message, [], $data, 'status', $data->getStatus()),
        ]));
    }
}
