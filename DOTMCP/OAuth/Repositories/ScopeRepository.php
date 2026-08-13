<?php

namespace Modules\DOTMCP\OAuth\Repositories;

use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\ScopeRepositoryInterface;
use Modules\DOTMCP\OAuth\Entities\ScopeEntity;

/**
 * A single scope. Granularity comes from the user's access level, not from
 * scopes: a scope the user picks is a claim about intent, whereas the access
 * level is an administrator's decision, which is the control that matters.
 */
class ScopeRepository implements ScopeRepositoryInterface
{
    const SCOPE = 'mcp:read';

    public function getScopeEntityByIdentifier($identifier): ?ScopeEntity
    {
        if ($identifier !== self::SCOPE) {
            return null;
        }

        $scope = new ScopeEntity();
        $scope->setIdentifier(self::SCOPE);

        return $scope;
    }

    public function finalizeScopes(
        array $scopes,
        $grantType,
        ClientEntityInterface $clientEntity,
        $userIdentifier = null,
        ?string $authCodeId = null
    ): array {
        // Always exactly the read scope, whatever was requested.
        $scope = new ScopeEntity();
        $scope->setIdentifier(self::SCOPE);

        return [$scope];
    }
}
