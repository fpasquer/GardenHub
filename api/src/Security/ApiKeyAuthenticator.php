<?php

namespace App\Security;

use App\Repository\ApiClientRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;
use Symfony\Component\Security\Http\Authenticator\AbstractAuthenticator;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

/**
 * Authenticates API consumers via an `Authorization: Bearer <key>` or
 * `X-API-KEY: <key>` header. Only the SHA-256 hash of the key is compared
 * against the database.
 */
class ApiKeyAuthenticator extends AbstractAuthenticator
{
    public function __construct(
        private readonly ApiClientRepository $apiClientRepository,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function supports(Request $request): bool
    {
        return null !== $this->extractKey($request);
    }

    public function authenticate(Request $request): Passport
    {
        $key = $this->extractKey($request);
        if (null === $key) {
            throw new CustomUserMessageAuthenticationException('No API key provided.');
        }

        $keyHash = hash('sha256', $key);

        return new SelfValidatingPassport(
            new UserBadge($keyHash, function (string $hash) {
                $client = $this->apiClientRepository->findOneByKeyHash($hash);
                if (null === $client) {
                    throw new CustomUserMessageAuthenticationException('Invalid API key.');
                }

                $client->setLastUsedAt(new \DateTimeImmutable());
                $this->entityManager->flush();

                return new ApiClientUser($client);
            }),
        );
    }

    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        return null;
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): Response
    {
        return new JsonResponse(
            ['message' => $exception->getMessageKey()],
            Response::HTTP_UNAUTHORIZED,
        );
    }

    private function extractKey(Request $request): ?string
    {
        $header = $request->headers->get('Authorization');
        if (is_string($header) && preg_match('/^Bearer\s+(\S+)$/i', $header, $matches)) {
            return $matches[1];
        }

        $apiKey = $request->headers->get('X-API-KEY');

        return is_string($apiKey) && '' !== $apiKey ? $apiKey : null;
    }
}
