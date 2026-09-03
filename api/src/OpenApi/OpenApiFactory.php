<?php

namespace App\OpenApi;

use ApiPlatform\OpenApi\Factory\OpenApiFactoryInterface;
use ApiPlatform\OpenApi\Model;
use ApiPlatform\OpenApi\OpenApi;

/**
 * Adds the API key security scheme to the OpenAPI document so the Swagger UI
 * shows an "Authorize" button and sends the Bearer token with each request.
 */
final class OpenApiFactory implements OpenApiFactoryInterface
{
    public function __construct(
        private readonly OpenApiFactoryInterface $decorated,
    ) {
    }

    public function __invoke(array $context = []): OpenApi
    {
        $openApi = ($this->decorated)($context);

        $securitySchemes = $openApi->getComponents()->getSecuritySchemes() ?? new \ArrayObject();
        $securitySchemes['api_key'] = new Model\SecurityScheme(
            type: 'http',
            description: 'API key (create one with `gardenhub:api-client:create`). Paste the `gh_...` key.',
            scheme: 'bearer',
        );

        $components = $openApi->getComponents()->withSecuritySchemes($securitySchemes);

        // Apply the scheme globally so Swagger UI attaches the Bearer token
        // to every operation once the user clicks "Authorize".
        return $openApi
            ->withComponents($components)
            ->withSecurity([['api_key' => []]]);
    }
}
