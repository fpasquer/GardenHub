<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WateringProposalRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/** A dry-soil watering proposal awaiting (or carrying) a human decision. */
#[ORM\Entity(repositoryClass: WateringProposalRepository::class)]
#[ORM\Table(name: 'watering_proposal')]
#[ORM\Index(name: 'idx_proposal_device_created', columns: ['device_id', 'created_at'])]
class WateringProposal
{
    /** Ids are INT auto-increment values; callbacks outside this range are ignored. */
    public const MAX_ID = 2147483647;

    public const STATUS_PENDING = 'pending';
    public const STATUS_EXECUTING = 'executing';
    public const STATUS_APPROVED = 'approved';
    public const STATUS_REJECTED = 'rejected';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_INVALIDATED = 'invalidated';
    public const STATUS_FAILED = 'failed';
    public const STATUS_UNCERTAIN = 'uncertain';

    public const NOTIFICATION_NEW = 'new';
    public const NOTIFICATION_SENDING = 'sending';
    public const NOTIFICATION_SENT = 'sent';
    public const NOTIFICATION_UNCERTAIN = 'uncertain';
    public const NOTIFICATION_FINAL = 'final';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['read:watering_proposal'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Device::class)]
    #[ORM\JoinColumn(name: 'device_id', nullable: false)]
    private ?Device $device = null;

    #[ORM\Column(length: 24)]
    #[Assert\NotBlank(groups: ['validation:watering_proposal'])]
    #[Assert\Length(max: 24, groups: ['validation:watering_proposal'])]
    #[Groups(['read:watering_proposal'])]
    private ?string $status = null;

    #[ORM\Column]
    #[Groups(['read:watering_proposal'])]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    #[Groups(['read:watering_proposal'])]
    private ?\DateTimeImmutable $expiresAt = null;

    #[ORM\Column]
    #[Assert\Positive(groups: ['validation:watering_proposal'])]
    #[Groups(['read:watering_proposal'])]
    private ?int $durationSeconds = null;

    /** @var list<array{value: float, measured_at: string}> */
    #[ORM\Column(type: Types::JSON)]
    #[Groups(['read:watering_proposal'])]
    private array $readingsJson = [];

    #[ORM\Column(nullable: true)]
    #[Groups(['read:watering_proposal'])]
    private ?\DateTimeImmutable $lastAttemptAt = null;

    #[ORM\Column(type: Types::BIGINT, nullable: true)]
    #[Groups(['read:watering_proposal'])]
    private ?int $messageId = null;

    #[ORM\Column(length: 24)]
    #[Assert\NotBlank(groups: ['validation:watering_proposal'])]
    #[Assert\Length(max: 24, groups: ['validation:watering_proposal'])]
    #[Groups(['read:watering_proposal'])]
    private ?string $notificationStatus = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['read:watering_proposal'])]
    private ?\DateTimeImmutable $decidedAt = null;

    #[ORM\ManyToOne(targetEntity: WateringRun::class)]
    #[ORM\JoinColumn(name: 'run_id', nullable: true)]
    private ?WateringRun $run = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255, groups: ['validation:watering_proposal'])]
    #[Groups(['read:watering_proposal'])]
    private ?string $failure = null;

    /** NULL marks a proposal created before the topic was recorded. */
    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255, groups: ['validation:watering_proposal'])]
    #[Groups(['read:watering_proposal'])]
    private ?string $actuatorTopic = null;

    public static function isValidId(int $id): bool
    {
        return $id >= 1 && $id <= self::MAX_ID;
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDevice(): ?Device
    {
        return $this->device;
    }

    public function setDevice(Device $device): static
    {
        $this->device = $device;

        return $this;
    }

    #[Groups(['read:watering_proposal'])]
    public function getDeviceId(): ?int
    {
        return $this->device?->getId();
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

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeImmutable $createdAt): static
    {
        $this->createdAt = $createdAt;

        return $this;
    }

    public function getExpiresAt(): ?\DateTimeImmutable
    {
        return $this->expiresAt;
    }

    public function setExpiresAt(\DateTimeImmutable $expiresAt): static
    {
        $this->expiresAt = $expiresAt;

        return $this;
    }

    public function getDurationSeconds(): ?int
    {
        return $this->durationSeconds;
    }

    public function setDurationSeconds(int $durationSeconds): static
    {
        $this->durationSeconds = $durationSeconds;

        return $this;
    }

    /** @return list<array{value: float, measured_at: string}> */
    public function getReadingsJson(): array
    {
        return $this->readingsJson;
    }

    /** @param list<array{value: float, measured_at: string}> $readingsJson */
    public function setReadingsJson(array $readingsJson): static
    {
        $this->readingsJson = $readingsJson;

        return $this;
    }

    public function getLastAttemptAt(): ?\DateTimeImmutable
    {
        return $this->lastAttemptAt;
    }

    public function setLastAttemptAt(?\DateTimeImmutable $lastAttemptAt): static
    {
        $this->lastAttemptAt = $lastAttemptAt;

        return $this;
    }

    public function getMessageId(): ?int
    {
        return $this->messageId;
    }

    public function setMessageId(?int $messageId): static
    {
        $this->messageId = $messageId;

        return $this;
    }

    public function getNotificationStatus(): ?string
    {
        return $this->notificationStatus;
    }

    public function setNotificationStatus(string $notificationStatus): static
    {
        $this->notificationStatus = $notificationStatus;

        return $this;
    }

    public function getDecidedAt(): ?\DateTimeImmutable
    {
        return $this->decidedAt;
    }

    public function setDecidedAt(?\DateTimeImmutable $decidedAt): static
    {
        $this->decidedAt = $decidedAt;

        return $this;
    }

    public function getRun(): ?WateringRun
    {
        return $this->run;
    }

    public function setRun(?WateringRun $run): static
    {
        $this->run = $run;

        return $this;
    }

    #[Groups(['read:watering_proposal'])]
    public function getRunId(): ?int
    {
        return $this->run?->getId();
    }

    public function getFailure(): ?string
    {
        return $this->failure;
    }

    public function setFailure(?string $failure): static
    {
        $this->failure = $failure;

        return $this;
    }

    public function getActuatorTopic(): ?string
    {
        return $this->actuatorTopic;
    }

    public function setActuatorTopic(?string $actuatorTopic): static
    {
        $this->actuatorTopic = $actuatorTopic;

        return $this;
    }
}
