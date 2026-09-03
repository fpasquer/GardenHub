<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\RangeFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Repository\MeasurementRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A single timestamped value produced by a sensor.
 *
 * Measurements are immutable: they are never updated, only appended.
 * The (sensor, measuredAt) index supports the time-range queries used
 * by the API, future dashboards and future AI consumers.
 */
#[ORM\Entity(repositoryClass: MeasurementRepository::class)]
#[ORM\Index(columns: ['sensor_id', 'measured_at'], name: 'idx_measurement_sensor_measured_at')]
#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
        // Measurements are created by the ingestion pipeline (MQTT/Messenger)
        // and immutable by design: no PATCH or DELETE operations.
        new Post(),
    ],
    normalizationContext: ['groups' => ['measurement:read']],
    denormalizationContext: ['groups' => ['measurement:write']],
    paginationItemsPerPage: 100,
)]
#[ApiFilter(SearchFilter::class, properties: ['sensor' => 'exact'])]
#[ApiFilter(DateFilter::class, properties: ['measuredAt'])]
#[ApiFilter(RangeFilter::class, properties: ['value'])]
#[ApiFilter(OrderFilter::class, properties: ['measuredAt'], arguments: ['orderParameterName' => 'order'])]
class Measurement
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['measurement:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'measurements')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['measurement:read', 'measurement:write'])]
    private ?Sensor $sensor = null;

    #[ORM\Column]
    #[Assert\NotNull]
    #[Groups(['measurement:read', 'measurement:write'])]
    private ?float $value = null;

    /**
     * Moment the value was measured by the device (not when it was stored).
     */
    #[ORM\Column]
    #[Assert\NotNull]
    #[Assert\LessThanOrEqual('now', message: 'The measurement date cannot be in the future.')]
    #[Groups(['measurement:read', 'measurement:write'])]
    private ?\DateTimeImmutable $measuredAt = null;

    #[ORM\Column]
    #[Groups(['measurement:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getSensor(): ?Sensor
    {
        return $this->sensor;
    }

    public function setSensor(?Sensor $sensor): static
    {
        $this->sensor = $sensor;

        return $this;
    }

    public function getValue(): ?float
    {
        return $this->value;
    }

    public function setValue(float $value): static
    {
        $this->value = $value;

        return $this;
    }

    public function getMeasuredAt(): ?\DateTimeImmutable
    {
        return $this->measuredAt;
    }

    public function setMeasuredAt(\DateTimeImmutable $measuredAt): static
    {
        $this->measuredAt = $measuredAt;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }
}
