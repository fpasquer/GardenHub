<?php

namespace App\Security;

use App\Entity\ApiClient;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Security user wrapping an authenticated ApiClient.
 */
final readonly class ApiClientUser implements UserInterface
{
    public function __construct(
        private ApiClient $client,
    ) {
    }

    /** @return list<string> */
    public function getRoles(): array
    {
        return array_values(array_unique([...$this->client->getRoles(), 'ROLE_API_CLIENT']));
    }

    public function getUserIdentifier(): string
    {
        return (string) $this->client->getName();
    }

    public function eraseCredentials(): void
    {
        // The raw key is never stored; nothing to erase.
    }
}
