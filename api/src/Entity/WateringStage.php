<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity]
#[ORM\Table(name: 'watering_stage')]
#[ORM\UniqueConstraint(name: 'uniq_watering_stage', columns: ['run_id', 'position'])]
class WateringStage
{
    public const SCHEDULED = 'scheduled';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'stages')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?WateringRun $run = null;

    #[ORM\Column]
    #[Groups(['read:watering_run'])]
    private int $position = 1;

    #[ORM\Column]
    #[Groups(['read:watering_run'])]
    private int $requestedSeconds = 65;

    #[ORM\Column(length: 20)]
    #[Assert\Length(max: 20, groups: ['validation:watering_stage'])]
    #[Groups(['read:watering_run'])]
    private string $status = self::SCHEDULED;

    #[ORM\Column(nullable: true)]
    #[Groups(['read:watering_run'])]
    private ?\DateTimeImmutable $requestedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['read:watering_run'])]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['read:watering_run'])]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['read:watering_run'])]
    private ?\DateTimeImmutable $deadlineAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRun(): ?WateringRun
    {
        return $this->run;
    }

    public function setRun(WateringRun $run): static
    {
        $this->run = $run;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): static
    {
        $this->position = $position;

        return $this;
    }

    public function getRequestedSeconds(): int
    {
        return $this->requestedSeconds;
    }

    public function setRequestedSeconds(int $seconds): static
    {
        $this->requestedSeconds = $seconds;

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getRequestedAt(): ?\DateTimeImmutable
    {
        return $this->requestedAt;
    }

    public function setRequestedAt(?\DateTimeImmutable $at): static
    {
        $this->requestedAt = $at;

        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $at): static
    {
        $this->startedAt = $at;

        return $this;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?\DateTimeImmutable $at): static
    {
        $this->finishedAt = $at;

        return $this;
    }

    public function getDeadlineAt(): ?\DateTimeImmutable
    {
        return $this->deadlineAt;
    }

    public function setDeadlineAt(?\DateTimeImmutable $at): static
    {
        $this->deadlineAt = $at;

        return $this;
    }
}
