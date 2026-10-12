<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Entity\ApiClient;
use App\Kernel;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

final class AuthenticatedApi
{
    private readonly string $key;

    public function __construct(private readonly Kernel $kernel, EntityManagerInterface $manager)
    {
        $this->key = 'gh_test_'.bin2hex(random_bytes(16));
        $manager->persist((new ApiClient())
            ->setName('identity-'.bin2hex(random_bytes(8)))
            ->setKeyHash(hash('sha256', $this->key))
            ->setRoles([ApiClient::ROLE_READ, ApiClient::ROLE_WRITE]));
        $manager->flush();
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function request(string $method, string $uri, ?array $body = null): array
    {
        $server = [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->key,
            'HTTP_ACCEPT' => 'application/ld+json',
            'CONTENT_TYPE' => 'PATCH' === $method
                ? 'application/merge-patch+json' : 'application/ld+json',
        ];
        $request = Request::create('https://sensor-identity.test'.$uri,
            $method, [], [], [], $server,
            null === $body ? null : json_encode($body, JSON_THROW_ON_ERROR));
        $response = $this->kernel->handle($request);
        $this->kernel->terminate($request, $response);
        $raw = $response->getContent();
        $decoded = is_string($raw) && '' !== $raw
            ? json_decode($raw, true, 512, JSON_THROW_ON_ERROR) : null;

        return [$response, $decoded];
    }
}