<?php

namespace Modules\DOTMCP\OAuth\Repositories;

use Illuminate\Support\Facades\DB;
use League\OAuth2\Server\Entities\RefreshTokenEntityInterface;
use League\OAuth2\Server\Repositories\RefreshTokenRepositoryInterface;
use Modules\DOTMCP\OAuth\Entities\RefreshTokenEntity;

class RefreshTokenRepository implements RefreshTokenRepositoryInterface
{
    public function getNewRefreshToken(): ?RefreshTokenEntityInterface
    {
        return new RefreshTokenEntity();
    }

    public function persistNewRefreshToken(RefreshTokenEntityInterface $refreshTokenEntity): void
    {
        DB::table('mcp_refresh_tokens')->insert([
            'id'              => $refreshTokenEntity->getIdentifier(),
            'access_token_id' => $refreshTokenEntity->getAccessToken()->getIdentifier(),
            'revoked'         => false,
            'expires_at'      => $refreshTokenEntity->getExpiryDateTime()->format('Y-m-d H:i:s'),
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);
    }

    public function revokeRefreshToken($tokenId): void
    {
        DB::table('mcp_refresh_tokens')->where('id', $tokenId)->update([
            'revoked'    => true,
            'updated_at' => now(),
        ]);
    }

    public function isRefreshTokenRevoked($tokenId): bool
    {
        $row = DB::table('mcp_refresh_tokens')->where('id', $tokenId)->first();

        return !$row || (bool) $row->revoked;
    }
}
