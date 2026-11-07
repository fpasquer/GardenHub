<?php

declare(strict_types=1);

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use App\Repository\SensorRepository;
use App\Validator\AssertSensorIdentityImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

/**
 * A single measured quantity on a device (e.g. soil temperature on SE01).
 *
 * A sensor is identified by its type and unit rather than a dedicated table
 * per sensor kind, so new sensor types can be added without schema changes.
 */
#[ORM\Entity(repositoryClass: SensorRepository::class)]
#[ORM\UniqueConstraint(
    name: 'uniq_sensor_device_type',
    columns: ['device_id', 'type'],
)]
#[UniqueEntity(
    fields: ['device', 'type'],
    message: 'This device already has a sensor of this type.',
    groups: ['validate:sensor'],
)]
#[AssertSensorIdentityImmutable(groups: ['validate:sensor'])]
#[ApiResource(
    normalizationContext: ['groups' => ['read:sensor']],
    denormalizationContext: ['groups' => ['write:sensor']],
    validationContext: ['groups' => ['validate:sensor']],
    paginationItemsPerPage: 50,
)]
#[ApiFilter(SearchFilter::class, properties: ['device' => 'exact', 'type' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['type', 'createdAt'])]
class Sensor
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['read:sensor', 'read:measurement'])]
    private ?int $id = null;

    #[ORM\ManyToOne(inversedBy: 'sensors')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(groups: ['validate:sensor'])]
    #[Groups(['read:sensor', 'write:sensor'])]
    private ?Device $device = null;

    /**
     * Free-form sensor type: temperature, humidity, soil_moisture, ...
     */
    #[ORM\Column(length: 50)]
    #[Assert\NotBlank(groups: ['validate:sensor'])]
    #[Assert\Length(max: 50, groups: ['validate:sensor'])]
    #[Groups(['read:sensor', 'write:sensor', 'read:measurement'])]
    private ?string $type = null;

    /**
     * Unit of the measured value: °C, %, hPa, lux, ...
     */
    #[ORM\Column(length: 20)]
    #[Assert\NotBlank(groups: ['validate:sensor'])]
    #[Assert\Length(max: 20, groups: ['validate:sensor'])]
    #[Groups(['read:sensor', 'write:sensor', 'read:measurement'])]
    private ?string $unit = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Assert\Length(max: 100, groups: ['validate:sensor'])]
    #[Groups(['read:sensor', 'write:sensor'])]
    private ?string $label = null;

    #[ORM\Column]
    #[Groups(['read:sensor'])]
    private ?\DateTimeImmutable $createdAt = null;

    /** @var Collection<int, Measurement> */
    #[ORM\OneToMany(targetEntity: Measurement::class, mappedBy: 'sensor', orphanRemoval: true)]
    private Collection $measurements;

    public function __construct()
    {
        $this->measurements = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDevice(): ?Device
    {
        return $this->device;
    }

    public function setDevice(?Device $device): static
    {
        $this->device = $device;

        return $this;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getUnit(): ?string
    {
        return $this->unit;
    }

    public function setUnit(string $unit): static
    {
        $this->unit = $unit;

        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, Measurement> */
    public function getMeasurements(): Collection
    {
        return $this->measurements;
    }
}
