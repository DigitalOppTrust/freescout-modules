<?php

namespace Modules\DOTMCP\OAuth\Repositories;

use Illuminate\Support\Facades\DB;
use League\OAuth2\Server\Repositories\ClientRepositoryInterface;
use Modules\DOTMCP\OAuth\Entities\ClientEntity;

class ClientRepository implements ClientRepositoryInterface
{
    public function getClientEntity($clientIdentifier): ?ClientEntity
    {
        $row = DB::table('mcp_clients')
            ->where('client_id', $clientIdentifier)
            ->where('revoked', false)
            ->first();

        if (!$row) {
            return null;
        }

        $client = new ClientEntity();
        $client->setIdentifier($row->client_id);
        $client->setName($row->name);
        $client->setRedirectUri(json_decode($row->redirect_uris, true) ?: []);
        $client->setConfidential((bool) $row->is_confidential);

        return $client;
    }

    /**
     * Claude is a public client using PKCE, so there is no secret to check.
     * A confidential client must still present a matching secret.
     */
    public function validateClient($clientIdentifier, $clientSecret, $grantType): bool
    {
        $row = DB::table('mcp_clients')
            ->where('client_id', $clientIdentifier)
            ->where('revoked', false)
            ->first();

        if (!$row) {
            return false;
        }

        if (!$row->is_confidential) {
            return true;
        }

        return $clientSecret !== null
            && $row->secret_hash !== null
            && password_verify($clientSecret, $row->secret_hash);
    }
}
