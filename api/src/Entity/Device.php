<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use App\Repository\DeviceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A physical IoT device (e.g. an SE01 sensor node) attached to the garden.
 */
#[ORM\Entity(repositoryClass: DeviceRepository::class)]
#[UniqueEntity(fields: 'name', message: 'A device with this name already exists.')]
#[ApiResource(
    normalizationContext: ['groups' => ['device:read']],
    denormalizationContext: ['groups' => ['device:write']],
    paginationItemsPerPage: 30,
)]
#[ApiFilter(SearchFilter::class, properties: ['name' => 'partial'])]
#[ApiFilter(OrderFilter::class, properties: ['name', 'createdAt'])]
class Device
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    #[Groups(['device:read', 'sensor:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    #[Groups(['device:read', 'device:write', 'sensor:read'])]
    private ?string $name = null;

    /**
     * LoRaWAN device EUI, used later to map ChirpStack MQTT payloads
     * to this device. Optional until MQTT ingestion is implemented.
     */
    #[ORM\Column(length: 32, nullable: true)]
    #[Assert\Length(max: 32)]
    #[Groups(['device:read', 'device:write'])]
    private ?string $devEui = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Length(max: 255)]
    #[Groups(['device:read', 'device:write'])]
    private ?string $description = null;

    #[ORM\Column]
    #[Groups(['device:read'])]
    private ?\DateTimeImmutable $createdAt = null;

    /** @var Collection<int, Sensor> */
    #[ORM\OneToMany(targetEntity: Sensor::class, mappedBy: 'device', orphanRemoval: true)]
    private Collection $sensors;

    public function __construct()
    {
        $this->sensors = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getDevEui(): ?string
    {
        return $this->devEui;
    }

    public function setDevEui(?string $devEui): static
    {
        $this->devEui = $devEui;

        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    /** @return Collection<int, Sensor> */
    public function getSensors(): Collection
    {
        return $this->sensors;
    }
}
