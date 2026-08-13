<?php

namespace Modules\DOTMCP\OAuth\Repositories;

use Illuminate\Support\Facades\DB;
use League\OAuth2\Server\Entities\AccessTokenEntityInterface;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Repositories\AccessTokenRepositoryInterface;
use Modules\DOTMCP\OAuth\Entities\AccessTokenEntity;
use Modules\DOTMCP\Services\AccessLevel;

class AccessTokenRepository implements AccessTokenRepositoryInterface
{
    public function getNewToken(
        ClientEntityInterface $clientEntity,
        array $scopes,
        $userIdentifier = null
    ): AccessTokenEntityInterface {
        $token = new AccessTokenEntity();
        $token->setClient($clientEntity);
        foreach ($scopes as $scope) {
            $token->addScope($scope);
        }
        if ($userIdentifier !== null) {
            $token->setUserIdentifier($userIdentifier);
        }

        return $token;
    }

    public function persistNewAccessToken(AccessTokenEntityInterface $accessTokenEntity): void
    {
        $userId = (int) $accessTokenEntity->getUserIdentifier();
        $user   = \App\User::find($userId);

        // Snapshot the level at issue time for the audit trail. The live user
        // flag is still checked on every request, so this never grants more
        // than the user currently has - it records what was true when issued.
        $level = $user ? AccessLevel::normalise($user->mcp_access_level) : AccessLevel::LOW;

        DB::table('mcp_tokens')->insert([
            'id'           => $accessTokenEntity->getIdentifier(),
            'user_id'      => $userId,
            'client_id'    => $accessTokenEntity->getClient()->getIdentifier(),
            'scopes'       => json_encode(array_map(function ($s) {
                                  return $s->getIdentifier();
                              }, $accessTokenEntity->getScopes())),
            'revoked'      => false,
            'access_level' => $level,
            'expires_at'   => $accessTokenEntity->getExpiryDateTime()->format('Y-m-d H:i:s'),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);
    }

    public function revokeAccessToken($tokenId): void
    {
        DB::table('mcp_tokens')->where('id', $tokenId)->update([
            'revoked'    => true,
            'updated_at' => now(),
        ]);
    }

    public function isAccessTokenRevoked($tokenId): bool
    {
        $row = DB::table('mcp_tokens')->where('id', $tokenId)->first();

        // Unknown token ids are treated as revoked: fail closed.
        return !$row || (bool) $row->revoked;
    }
}
