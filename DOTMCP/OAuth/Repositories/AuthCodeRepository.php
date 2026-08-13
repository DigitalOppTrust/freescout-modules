<?php

namespace Modules\DOTMCP\OAuth\Repositories;

use Illuminate\Support\Facades\DB;
use League\OAuth2\Server\Entities\AuthCodeEntityInterface;
use League\OAuth2\Server\Repositories\AuthCodeRepositoryInterface;
use Modules\DOTMCP\OAuth\Entities\AuthCodeEntity;

class AuthCodeRepository implements AuthCodeRepositoryInterface
{
    public function getNewAuthCode(): AuthCodeEntityInterface
    {
        return new AuthCodeEntity();
    }

    public function persistNewAuthCode(AuthCodeEntityInterface $authCodeEntity): void
    {
        DB::table('mcp_auth_codes')->insert([
            'id'         => $authCodeEntity->getIdentifier(),
            'user_id'    => (int) $authCodeEntity->getUserIdentifier(),
            'client_id'  => $authCodeEntity->getClient()->getIdentifier(),
            'scopes'     => json_encode(array_map(function ($s) {
                                return $s->getIdentifier();
                            }, $authCodeEntity->getScopes())),
            'revoked'    => false,
            'expires_at' => $authCodeEntity->getExpiryDateTime()->format('Y-m-d H:i:s'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function revokeAuthCode($codeId): void
    {
        DB::table('mcp_auth_codes')->where('id', $codeId)->update([
            'revoked'    => true,
            'updated_at' => now(),
        ]);
    }

    public function isAuthCodeRevoked($codeId): bool
    {
        $row = DB::table('mcp_auth_codes')->where('id', $codeId)->first();

        // Unknown or already-used codes are revoked. Codes are single use;
        // the library revokes on exchange, so replay is rejected here.
        return !$row || (bool) $row->revoked;
    }
}
