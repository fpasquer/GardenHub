<?php

namespace App\Entity;

use App\Repository\ProcessedUplinkRepository;
use Doctrine\ORM\Mapping as ORM;

/**
 * One row per ChirpStack uplink (by its ChirpStack deduplicationId) whose
 * device-level alert evaluation has already run. Identity-based replay
 * protection: timestamps are deliberately ignored, so an exact, delayed,
 * out-of-order or same-measuredAt replay of an already-processed uplink can
 * never advance invalid-reading counters or recreate a resolved incident.
 * Rejected readings leave no measurement row, which is why measurement
 * deduplication alone is not sufficient here.
 */
#[ORM\Entity(repositoryClass: ProcessedUplinkRepository::class)]
#[ORM\Table(name: 'alert_processed_uplink')]
#[ORM\UniqueConstraint(name: 'uniq_alert_processed_uplink_dedup', columns: ['deduplication_id'])]
class ProcessedUplink
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 36)]
    private ?string $deduplicationId = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $firstProcessedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDeduplicationId(): ?string
    {
        return $this->deduplicationId;
    }

    public function setDeduplicationId(string $deduplicationId): static
    {
        $this->deduplicationId = $deduplicationId;

        return $this;
    }

    public function getFirstProcessedAt(): ?\DateTimeImmutable
    {
        return $this->firstProcessedAt;
    }

    public function setFirstProcessedAt(\DateTimeImmutable $firstProcessedAt): static
    {
        $this->firstProcessedAt = $firstProcessedAt;

        return $this;
    }
}
