<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WateringRunRepository;
use App\Watering\WateringRunStatus;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/** One requested watering cycle of the single development actuator. */
#[ORM\Entity(repositoryClass: WateringRunRepository::class)]
#[ORM\Table(name: 'watering_run')]
#[ORM\Index(name: 'idx_watering_requested', columns: ['requested_at'])]
class WateringRun
{
    public const STATUS_PENDING = WateringRunStatus::Pending->value;
    public const STATUS_RUNNING = WateringRunStatus::Running->value;
    public const STATUS_COMPLETED = WateringRunStatus::Completed->value;
    public const STATUS_UNCERTAIN = WateringRunStatus::Uncertain->value;
    public const STATUS_TIMED_OUT = WateringRunStatus::TimedOut->value;
    public const STATUS_REVIEWED = WateringRunStatus::Reviewed->value;
    public const ERROR_MAX_LENGTH = 255;

    /** Cycles that can still time out or become uncertain. */
    public const OPEN_STATUSES = [self::STATUS_PENDING, self::STATUS_RUNNING];

    /** Cycles whose actuator state is unknown until reviewed. */
    public const BLOCKED_STATUSES = [self::STATUS_TIMED_OUT, self::STATUS_UNCERTAIN];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['read:watering_run'])]
    private ?int $id = null;

    #[ORM\Column]
    #[Assert\Positive(groups: ['validation:watering_run'])]
    #[Groups(['read:watering_run'])]
    private ?int $requestedSeconds = null;

    #[ORM\Column(length: 20)]
    #[Assert\NotBlank(groups: ['validation:watering_run'])]
    #[Assert\Length(max: 20, groups: ['validation:watering_run'])]
    #[Groups(['read:watering_run'])]
    private ?string $status = null;

    #[ORM\Column]
    #[Groups(['read:watering_run'])]
    private ?\DateTimeImmutable $requestedAt = null;

    #[ORM\Column]
    #[Groups(['read:watering_run'])]
    private ?\DateTimeImmutable $deadlineAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['read:watering_run'])]
    private ?\DateTimeImmutable $startedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['read:watering_run'])]
    private ?\DateTimeImmutable $finishedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['read:watering_run'])]
    private ?\DateTimeImmutable $lastStateAt = null;

    #[ORM\Column(length: 10, nullable: true)]
    #[Assert\Length(max: 10, groups: ['validation:watering_run'])]
    #[Groups(['read:watering_run'])]
    private ?string $lastState = null;

    #[ORM\Column(length: self::ERROR_MAX_LENGTH, nullable: true)]
    #[Assert\Length(max: self::ERROR_MAX_LENGTH, groups: ['validation:watering_run'])]
    #[Groups(['read:watering_run'])]
    private ?string $error = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getRequestedSeconds(): ?int
    {
        return $this->requestedSeconds;
    }

    public function setRequestedSeconds(int $requestedSeconds): static
    {
        $this->requestedSeconds = $requestedSeconds;

        return $this;
    }

    public function getStatus(): ?string
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

    public function setRequestedAt(\DateTimeImmutable $requestedAt): static
    {
        $this->requestedAt = $requestedAt;

        return $this;
    }

    public function getDeadlineAt(): ?\DateTimeImmutable
    {
        return $this->deadlineAt;
    }

    public function setDeadlineAt(\DateTimeImmutable $deadlineAt): static
    {
        $this->deadlineAt = $deadlineAt;

        return $this;
    }

    public function getStartedAt(): ?\DateTimeImmutable
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTimeImmutable $startedAt): static
    {
        $this->startedAt = $startedAt;

        return $this;
    }

    public function getFinishedAt(): ?\DateTimeImmutable
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?\DateTimeImmutable $finishedAt): static
    {
        $this->finishedAt = $finishedAt;

        return $this;
    }

    public function getLastStateAt(): ?\DateTimeImmutable
    {
        return $this->lastStateAt;
    }

    public function setLastStateAt(?\DateTimeImmutable $lastStateAt): static
    {
        $this->lastStateAt = $lastStateAt;

        return $this;
    }

    public function getLastState(): ?string
    {
        return $this->lastState;
    }

    public function setLastState(?string $lastState): static
    {
        $this->lastState = $lastState;

        return $this;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function setError(?string $error): static
    {
        $this->error = $error;

        return $this;
    }

    public function isOpen(): bool
    {
        return in_array($this->status, self::OPEN_STATUSES, true);
    }

    public function isBlocked(): bool
    {
        return in_array($this->status, self::BLOCKED_STATUSES, true);
    }

    /** Only a still-open cycle can be overdue. */
    public function isOverdue(\DateTimeImmutable $now): bool
    {
        return $this->isOpen() && $this->deadlineAt <= $now;
    }
}
