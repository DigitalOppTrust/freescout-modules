<?php

namespace Modules\DOTMCP\Services;

use DateInterval;
use League\OAuth2\Server\AuthorizationServer;
use League\OAuth2\Server\CryptKey;
use League\OAuth2\Server\Grant\AuthCodeGrant;
use League\OAuth2\Server\Grant\RefreshTokenGrant;
use League\OAuth2\Server\ResourceServer;
use Modules\DOTMCP\OAuth\Repositories\AccessTokenRepository;
use Modules\DOTMCP\OAuth\Repositories\AuthCodeRepository;
use Modules\DOTMCP\OAuth\Repositories\ClientRepository;
use Modules\DOTMCP\OAuth\Repositories\RefreshTokenRepository;
use Modules\DOTMCP\OAuth\Repositories\ScopeRepository;

/**
 * Builds the league/oauth2-server instances.
 *
 * Keys live outside the web root and outside the module directory, so a
 * mis-scoped nginx rule cannot serve the private key and a module reinstall
 * cannot delete it.
 */
class OAuthServer
{
    /** Access tokens are short; refresh tokens carry the session. */
    const ACCESS_TOKEN_TTL  = 'PT1H';    // 1 hour
    const REFRESH_TOKEN_TTL = 'P90D';    // 90 days
    const AUTH_CODE_TTL     = 'PT10M';   // 10 minutes

    public static function keyDir()
    {
        return config('dotmcp.key_path', storage_path('app/mcp-keys'));
    }

    public static function privateKeyPath()
    {
        return self::keyDir().'/private.key';
    }

    public static function publicKeyPath()
    {
        return self::keyDir().'/public.key';
    }

    public static function keysExist()
    {
        return file_exists(self::privateKeyPath()) && file_exists(self::publicKeyPath());
    }

    /**
     * The encryption key signs authorization codes and refresh tokens.
     * Derived from Laravel's APP_KEY so there is no second secret to manage,
     * but namespaced so it is not literally the application key.
     */
    public static function encryptionKey()
    {
        return hash_hmac('sha256', 'dotmcp-oauth-v1', config('app.key'));
    }

    public static function authorizationServer(): AuthorizationServer
    {
        $server = new AuthorizationServer(
            new ClientRepository(),
            new AccessTokenRepository(),
            new ScopeRepository(),
            new CryptKey(self::privateKeyPath(), null, false),
            self::encryptionKey()
        );

        // Authorization code grant with PKCE.
        $authCodeGrant = new AuthCodeGrant(
            new AuthCodeRepository(),
            new RefreshTokenRepository(),
            new DateInterval(self::AUTH_CODE_TTL)
        );

        // PKCE is mandatory. league/oauth2-server 9.x requires a code
        // challenge from public clients by default; the only way to weaken
        // that is disableRequireCodeChallengeForPublicClients(), which is
        // deliberately never called. Claude is a public client, so without
        // PKCE an intercepted authorization code could be exchanged by anyone.
        $authCodeGrant->setRefreshTokenTTL(new DateInterval(self::REFRESH_TOKEN_TTL));

        $server->enableGrantType($authCodeGrant, new DateInterval(self::ACCESS_TOKEN_TTL));

        // Refresh grant, so a 1-hour access token does not mean hourly
        // re-authorisation. Refresh tokens rotate on use.
        $refreshGrant = new RefreshTokenGrant(new RefreshTokenRepository());
        $refreshGrant->setRefreshTokenTTL(new DateInterval(self::REFRESH_TOKEN_TTL));
        $server->enableGrantType($refreshGrant, new DateInterval(self::ACCESS_TOKEN_TTL));

        return $server;
    }

    public static function resourceServer(): ResourceServer
    {
        return new ResourceServer(
            new AccessTokenRepository(),
            new CryptKey(self::publicKeyPath(), null, false)
        );
    }

    /**
     * Generate the signing keypair. Idempotent - never overwrites, because
     * replacing the key silently invalidates every issued token.
     *
     * @return array{created: bool, message: string}
     */
    public static function generateKeys()
    {
        $dir = self::keyDir();

        if (self::keysExist()) {
            return ['created' => false, 'message' => 'Keys already exist.'];
        }

        if (!is_dir($dir) && !@mkdir($dir, 0700, true)) {
            return ['created' => false, 'message' => 'Could not create '.$dir];
        }

        $res = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($res === false) {
            return ['created' => false, 'message' => 'openssl_pkey_new failed: '.openssl_error_string()];
        }

        openssl_pkey_export($res, $privateKey);
        $details = openssl_pkey_get_details($res);

        file_put_contents(self::privateKeyPath(), $privateKey);
        file_put_contents(self::publicKeyPath(), $details['key']);

        // The library refuses to load a key readable by other users.
        chmod(self::privateKeyPath(), 0600);
        chmod(self::publicKeyPath(), 0600);

        return ['created' => true, 'message' => 'Keypair generated in '.$dir];
    }
}
