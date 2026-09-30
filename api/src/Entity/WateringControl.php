<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WateringControlRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;

/** Singleton row (id 1) that serializes access to the single actuator. */
#[ORM\Entity(repositoryClass: WateringControlRepository::class)]
#[ORM\Table(name: 'watering_control')]
class WateringControl
{
    public const SINGLETON_ID = 1;

    #[ORM\Id]
    #[ORM\Column]
    #[Groups(['read:watering_control'])]
    private int $id = self::SINGLETON_ID;

    #[ORM\ManyToOne(targetEntity: WateringRun::class)]
    #[ORM\JoinColumn(name: 'active_run_id', nullable: true)]
    private ?WateringRun $activeRun = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['read:watering_control'])]
    private ?\DateTimeImmutable $lastRequestAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['read:watering_control'])]
    private ?\DateTimeImmutable $monitorSeenAt = null;

    public function getId(): int
    {
        return $this->id;
    }

    public function getActiveRun(): ?WateringRun
    {
        return $this->activeRun;
    }

    public function setActiveRun(?WateringRun $activeRun): static
    {
        $this->activeRun = $activeRun;

        return $this;
    }

    #[Groups(['read:watering_control'])]
    public function getActiveRunId(): ?int
    {
        return $this->activeRun?->getId();
    }

    public function getLastRequestAt(): ?\DateTimeImmutable
    {
        return $this->lastRequestAt;
    }

    public function setLastRequestAt(?\DateTimeImmutable $lastRequestAt): static
    {
        $this->lastRequestAt = $lastRequestAt;

        return $this;
    }

    public function getMonitorSeenAt(): ?\DateTimeImmutable
    {
        return $this->monitorSeenAt;
    }

    public function setMonitorSeenAt(?\DateTimeImmutable $monitorSeenAt): static
    {
        $this->monitorSeenAt = $monitorSeenAt;

        return $this;
    }
}
