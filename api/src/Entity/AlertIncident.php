<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Repository\AlertIncidentRepository;
use App\State\AlertAcknowledgeProcessor;
use App\State\AlertResolveProcessor;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A Symfony-owned alert incident (plant condition, invalid reading, or
 * ingestion processing failure), tracked from first detection through
 * confirmation, notification and recovery/resolution.
 *
 * status is the single source of truth; the database-only is_open column
 * (see the migration) is never mapped here — it exists purely to enforce
 * "at most one open incident per (alert_type, subject_key)" in the schema.
 */
#[ORM\Entity(repositoryClass: AlertIncidentRepository::class)]
#[ORM\Table(name: 'alert_incident')]
#[ORM\Index(columns: ['alert_type', 'subject_key'], name: 'idx_alert_incident_subject')]
#[ApiResource(
    normalizationContext: ['groups' => ['alert_incident:read']],
    operations: [
        new GetCollection(),
        new Get(),
        new Post(
            uriTemplate: '/alert_incidents/{id}/acknowledge',
            status: 200,
            deserialize: false,
            read: true,
            processor: AlertAcknowledgeProcessor::class,
            security: "is_granted('ROLE_API_ADMIN')",
        ),
        new Post(
            uriTemplate: '/alert_incidents/{id}/resolve',
            status: 200,
            read: true,
            denormalizationContext: ['groups' => ['alert_incident:resolve']],
            validationContext: ['groups' => ['alert_incident:resolve']],
            processor: AlertResolveProcessor::class,
            security: "is_granted('ROLE_API_ADMIN')",
        ),
    ],
    paginationItemsPerPage: 50,
)]
#[ApiFilter(SearchFilter::class, properties: ['alertType' => 'exact', 'subjectKey' => 'exact', 'status' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['firstDetectedAt', 'lastEvaluatedAt'])]
class AlertIncident
{
    public const string STATUS_PENDING = 'pending';
    public const string STATUS_ACTIVE = 'active';
    public const string STATUS_RESOLVED = 'resolved';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['alert_incident:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 30)]
    #[Groups(['alert_incident:read'])]
    private ?string $alertType = null;

    #[ORM\Column(length: 150)]
    #[Groups(['alert_incident:read'])]
    private ?string $subjectKey = null;

    #[ORM\Column(length: 20)]
    #[Groups(['alert_incident:read'])]
    private ?string $status = null;

    #[ORM\Column]
    #[Groups(['alert_incident:read'])]
    private int $confirmationCount = 0;

    #[ORM\Column]
    #[Groups(['alert_incident:read'])]
    private int $recoveryCount = 0;

    #[ORM\Column]
    #[Groups(['alert_incident:read'])]
    private ?\DateTimeImmutable $firstDetectedAt = null;

    #[ORM\Column]
    #[Groups(['alert_incident:read'])]
    private ?\DateTimeImmutable $lastEvaluatedAt = null;

    #[ORM\Column(name: 'observed_value', nullable: true)]
    #[Groups(['alert_incident:read'])]
    private ?float $lastValue = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['alert_incident:read'])]
    private ?string $lastValueUnit = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['alert_incident:read'])]
    private ?\DateTimeImmutable $lastMeasuredAt = null;

    #[ORM\Column(nullable: true)]
    private ?int $lastConsideredMeasurementId = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['alert_incident:read'])]
    private ?\DateTimeImmutable $openedNotifiedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['alert_incident:read'])]
    private ?\DateTimeImmutable $lastReminderAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['alert_incident:read'])]
    private ?\DateTimeImmutable $nextReminderAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['alert_incident:read'])]
    private ?\DateTimeImmutable $recoveryNotifiedAt = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['alert_incident:read'])]
    private ?\DateTimeImmutable $acknowledgedAt = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['alert_incident:read'])]
    private ?string $acknowledgedBy = null;

    #[ORM\Column(nullable: true)]
    #[Groups(['alert_incident:read'])]
    private ?\DateTimeImmutable $resolvedAt = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['alert_incident:read'])]
    private ?string $resolvedBy = null;

    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\NotBlank(groups: ['alert_incident:resolve'])]
    #[Assert\Length(max: 500, groups: ['alert_incident:resolve'])]
    #[Groups(['alert_incident:read', 'alert_incident:resolve'])]
    private ?string $resolutionReason = null;

    #[ORM\Column]
    #[Groups(['alert_incident:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
    #[Groups(['alert_incident:read'])]
    private ?\DateTimeImmutable $updatedAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getAlertType(): ?string
    {
        return $this->alertType;
    }

    public function setAlertType(string $alertType): static
    {
        $this->alertType = $alertType;

        return $this;
    }

    public function getSubjectKey(): ?string
    {
        return $this->subjectKey;
    }

    public function setSubjectKey(string $subjectKey): static
    {
        $this->subjectKey = $subjectKey;

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

    public function getConfirmationCount(): int
    {
        return $this->confirmationCount;
    }

    public function setConfirmationCount(int $confirmationCount): static
    {
        $this->confirmationCount = $confirmationCount;

        return $this;
    }

    public function getRecoveryCount(): int
    {
        return $this->recoveryCount;
    }

    public function setRecoveryCount(int $recoveryCount): static
    {
        $this->recoveryCount = $recoveryCount;

        return $this;
    }

    public function getFirstDetectedAt(): ?\DateTimeImmutable
    {
        return $this->firstDetectedAt;
    }

    public function setFirstDetectedAt(\DateTimeImmutable $firstDetectedAt): static
    {
        $this->firstDetectedAt = $firstDetectedAt;

        return $this;
    }

    public function getLastEvaluatedAt(): ?\DateTimeImmutable
    {
        return $this->lastEvaluatedAt;
    }

    public function setLastEvaluatedAt(\DateTimeImmutable $lastEvaluatedAt): static
    {
        $this->lastEvaluatedAt = $lastEvaluatedAt;

        return $this;
    }

    public function getLastValue(): ?float
    {
        return $this->lastValue;
    }

    public function setLastValue(?float $lastValue): static
    {
        $this->lastValue = $lastValue;

        return $this;
    }

    public function getLastValueUnit(): ?string
    {
        return $this->lastValueUnit;
    }

    public function setLastValueUnit(?string $lastValueUnit): static
    {
        $this->lastValueUnit = $lastValueUnit;

        return $this;
    }

    public function getLastMeasuredAt(): ?\DateTimeImmutable
    {
        return $this->lastMeasuredAt;
    }

    public function setLastMeasuredAt(?\DateTimeImmutable $lastMeasuredAt): static
    {
        $this->lastMeasuredAt = $lastMeasuredAt;

        return $this;
    }

    public function getLastConsideredMeasurementId(): ?int
    {
        return $this->lastConsideredMeasurementId;
    }

    public function setLastConsideredMeasurementId(?int $lastConsideredMeasurementId): static
    {
        $this->lastConsideredMeasurementId = $lastConsideredMeasurementId;

        return $this;
    }

    public function getOpenedNotifiedAt(): ?\DateTimeImmutable
    {
        return $this->openedNotifiedAt;
    }

    public function setOpenedNotifiedAt(?\DateTimeImmutable $openedNotifiedAt): static
    {
        $this->openedNotifiedAt = $openedNotifiedAt;

        return $this;
    }

    public function getLastReminderAt(): ?\DateTimeImmutable
    {
        return $this->lastReminderAt;
    }

    public function setLastReminderAt(?\DateTimeImmutable $lastReminderAt): static
    {
        $this->lastReminderAt = $lastReminderAt;

        return $this;
    }

    public function getNextReminderAt(): ?\DateTimeImmutable
    {
        return $this->nextReminderAt;
    }

    public function setNextReminderAt(?\DateTimeImmutable $nextReminderAt): static
    {
        $this->nextReminderAt = $nextReminderAt;

        return $this;
    }

    public function getRecoveryNotifiedAt(): ?\DateTimeImmutable
    {
        return $this->recoveryNotifiedAt;
    }

    public function setRecoveryNotifiedAt(?\DateTimeImmutable $recoveryNotifiedAt): static
    {
        $this->recoveryNotifiedAt = $recoveryNotifiedAt;

        return $this;
    }

    public function getAcknowledgedAt(): ?\DateTimeImmutable
    {
        return $this->acknowledgedAt;
    }

    public function setAcknowledgedAt(?\DateTimeImmutable $acknowledgedAt): static
    {
        $this->acknowledgedAt = $acknowledgedAt;

        return $this;
    }

    public function getAcknowledgedBy(): ?string
    {
        return $this->acknowledgedBy;
    }

    public function setAcknowledgedBy(?string $acknowledgedBy): static
    {
        $this->acknowledgedBy = $acknowledgedBy;

        return $this;
    }

    public function getResolvedAt(): ?\DateTimeImmutable
    {
        return $this->resolvedAt;
    }

    public function setResolvedAt(?\DateTimeImmutable $resolvedAt): static
    {
        $this->resolvedAt = $resolvedAt;

        return $this;
    }

    public function getResolvedBy(): ?string
    {
        return $this->resolvedBy;
    }

    public function setResolvedBy(?string $resolvedBy): static
    {
        $this->resolvedBy = $resolvedBy;

        return $this;
    }

    public function getResolutionReason(): ?string
    {
        return $this->resolutionReason;
    }

    public function setResolutionReason(?string $resolutionReason): static
    {
        $this->resolutionReason = $resolutionReason;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function touch(\DateTimeImmutable $now): static
    {
        $this->updatedAt = $now;

        return $this;
    }
}
