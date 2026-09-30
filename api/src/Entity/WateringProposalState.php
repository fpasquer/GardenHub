<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WateringProposalStateRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/** Prompt counter of one dry episode per device and actuator topic. */
#[ORM\Entity(repositoryClass: WateringProposalStateRepository::class)]
#[ORM\Table(name: 'watering_proposal_state')]
class WateringProposalState
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Device::class)]
    #[ORM\JoinColumn(name: 'device_id', nullable: false)]
    private Device $device;

    /** An empty topic marks a counter created before the topic was recorded. */
    #[ORM\Id]
    #[ORM\Column(length: 255, options: ['default' => ''])]
    #[Assert\Length(max: 255, groups: ['validation:watering_proposal_state'])]
    #[Groups(['read:watering_proposal_state'])]
    private string $actuatorTopic = '';

    #[ORM\Column(options: ['default' => 0])]
    #[Assert\PositiveOrZero(groups: ['validation:watering_proposal_state'])]
    #[Groups(['read:watering_proposal_state'])]
    private int $promptCount = 0;

    #[ORM\Column(nullable: true)]
    #[Groups(['read:watering_proposal_state'])]
    private ?\DateTimeImmutable $lastPromptAt = null;

    public function __construct(Device $device, string $actuatorTopic)
    {
        $this->device = $device;
        $this->actuatorTopic = $actuatorTopic;
    }

    public function getDevice(): Device
    {
        return $this->device;
    }

    #[Groups(['read:watering_proposal_state'])]
    public function getDeviceId(): ?int
    {
        return $this->device->getId();
    }

    public function getActuatorTopic(): string
    {
        return $this->actuatorTopic;
    }

    public function getPromptCount(): int
    {
        return $this->promptCount;
    }

    public function setPromptCount(int $promptCount): static
    {
        $this->promptCount = $promptCount;

        return $this;
    }

    public function getLastPromptAt(): ?\DateTimeImmutable
    {
        return $this->lastPromptAt;
    }

    public function setLastPromptAt(?\DateTimeImmutable $lastPromptAt): static
    {
        $this->lastPromptAt = $lastPromptAt;

        return $this;
    }
}
