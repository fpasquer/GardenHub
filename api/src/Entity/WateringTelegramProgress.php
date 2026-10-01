<?php

declare(strict_types=1);

namespace App\Entity;

use App\Repository\WateringTelegramProgressRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Validator\Constraints as Assert;

/** Singleton row (id 1) holding the next Telegram update offset to consume. */
#[ORM\Entity(repositoryClass: WateringTelegramProgressRepository::class)]
#[ORM\Table(name: 'watering_telegram_progress')]
class WateringTelegramProgress
{
    public const SINGLETON_ID = 1;

    #[ORM\Id]
    #[ORM\Column]
    #[Groups(['read:watering_telegram_progress'])]
    private int $id = self::SINGLETON_ID;

    #[ORM\Column(type: Types::BIGINT, options: ['default' => 0])]
    #[Assert\PositiveOrZero(groups: ['validate:watering_telegram_progress'])]
    #[Groups(['read:watering_telegram_progress'])]
    private int $nextUpdateId = 0;

    public function getId(): int
    {
        return $this->id;
    }

    public function getNextUpdateId(): int
    {
        return $this->nextUpdateId;
    }

    public function setNextUpdateId(int $nextUpdateId): static
    {
        $this->nextUpdateId = $nextUpdateId;

        return $this;
    }
}
