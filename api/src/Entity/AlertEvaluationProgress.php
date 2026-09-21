<?php

namespace App\Entity;

use App\Repository\AlertEvaluationProgressRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * Durable per-(alert_type, subject_key) evaluation progress, kept
 * independently of any alert_incident row so replay/ordering protection
 * survives incident resolution.
 *
 * last_considered_measurement_id is the highest measurement ID applied to
 * this subject (exact-replay guard); last_considered_measured_at is the
 * measured_at watermark of the newest-measured applied reading (ingested
 * IDs do not establish measurement-time order, so an older reading
 * ingested later must not supersede a newer evaluation). Both are only
 * ever advanced by threshold-rule signals that carry a measurementId.
 */
#[ORM\Entity(repositoryClass: AlertEvaluationProgressRepository::class)]
#[ORM\Table(name: 'alert_evaluation_progress')]
#[ORM\UniqueConstraint(name: 'uniq_alert_evaluation_progress_subject', columns: ['alert_type', 'subject_key'])]
class AlertEvaluationProgress
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 30)]
    private ?string $alertType = null;

    #[ORM\Column(length: 150)]
    private ?string $subjectKey = null;

    #[ORM\Column(nullable: true)]
    private ?int $lastConsideredMeasurementId = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $lastConsideredMeasuredAt = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column]
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

    public function getLastConsideredMeasurementId(): ?int
    {
        return $this->lastConsideredMeasurementId;
    }

    public function getLastConsideredMeasuredAt(): ?\DateTimeImmutable
    {
        return $this->lastConsideredMeasuredAt;
    }

    /**
     * Advances both watermarks monotonically: IDs only upward, the
     * measured_at watermark only to a newer (never equal or older) time.
     */
    public function recordConsidered(int $measurementId, ?\DateTimeImmutable $measuredAt, \DateTimeImmutable $now): static
    {
        $this->lastConsideredMeasurementId = max($this->lastConsideredMeasurementId ?? 0, $measurementId);
        if (null !== $measuredAt && (null === $this->lastConsideredMeasuredAt || $measuredAt > $this->lastConsideredMeasuredAt)) {
            $this->lastConsideredMeasuredAt = $measuredAt;
        }
        $this->updatedAt = $now;

        return $this;
    }
}
