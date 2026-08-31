<?php

namespace Modules\DOTSSO\Services;

/**
 * The OAuth 2.0 / OIDC authorization-code flow against Google, client side.
 *
 * Holds the three anti-forgery values in the session:
 *   state          - ties the callback to a login this browser started (CSRF)
 *   nonce          - ties the ID token to that same attempt (replay)
 *   code_verifier  - PKCE, so an intercepted code cannot be redeemed
 */
class OAuthFlow
{
    const AUTH_URL  = 'https://accounts.google.com/o/oauth2/v2/auth';
    const TOKEN_URL = 'https://oauth2.googleapis.com/token';

    const SESSION_KEY = 'dotsso.flow';

    /** Identity only. No Gmail, no Drive, nothing that touches customer data. */
    const SCOPES = 'openid email profile';

    /** Build the authorization URL and stash the matching secrets. */
    public static function start()
    {
        $state    = self::random();
        $nonce    = self::random();
        $verifier = self::random(64);

        session()->put(self::SESSION_KEY, [
            'state'    => $state,
            'nonce'    => $nonce,
            'verifier' => $verifier,
            'started'  => time(),
        ]);

        $params = [
            'client_id'             => Settings::get('client_id'),
            'redirect_uri'          => self::redirectUri(),
            'response_type'         => 'code',
            'scope'                 => self::SCOPES,
            'state'                 => $state,
            'nonce'                 => $nonce,
            'code_challenge'        => self::challenge($verifier),
            'code_challenge_method' => 'S256',

            // Ask Google to show only accounts in our Workspace. This is a
            // convenience, not a control - it is trivially removable by the
            // user, which is why 'hd' is verified on the returned token.
            'hd'                    => Settings::domain(),

            // Without this, a user already signed in to a personal Google
            // account is silently carried through it and then refused.
            'prompt'                => 'select_account',
        ];

        return self::AUTH_URL.'?'.http_build_query($params);
    }

    /** The stashed values for this attempt, or null if there is no attempt. */
    public static function pending()
    {
        $flow = session()->get(self::SESSION_KEY);

        if (!is_array($flow) || empty($flow['state'])) {
            return null;
        }

        // A login left half-finished for a long time is more likely a stale
        // tab than a real attempt.
        if ((time() - (int) ($flow['started'] ?? 0)) > 600) {
            self::forget();

            return null;
        }

        return $flow;
    }

    public static function forget()
    {
        session()->forget(self::SESSION_KEY);
    }

    /**
     * Exchange the authorization code for tokens.
     *
     * @return array ['ok' => bool, 'reason' => string, 'id_token' => string]
     */
    public static function exchange($code, $verifier)
    {
        try {
            $client   = new \GuzzleHttp\Client(['timeout' => 15]);
            $response = $client->post(self::TOKEN_URL, [
                'form_params' => [
                    'code'          => $code,
                    'client_id'     => Settings::get('client_id'),
                    'client_secret' => Settings::get('client_secret'),
                    'redirect_uri'  => self::redirectUri(),
                    'grant_type'    => 'authorization_code',
                    'code_verifier' => $verifier,
                ],
                'http_errors' => false,
            ]);

            $body = json_decode((string) $response->getBody(), true);
        } catch (\Throwable $e) {
            \Log::error('[DOTSSO] token exchange failed: '.$e->getMessage());

            return ['ok' => false, 'reason' => 'could not reach Google', 'id_token' => ''];
        }

        if (!is_array($body) || empty($body['id_token'])) {
            // Google's error body can contain the client secret back in some
            // failure modes, so log only the error code it names.
            $err = is_array($body) ? ($body['error'] ?? 'unknown') : 'unreadable';
            \Log::error('[DOTSSO] token exchange rejected: '.$err);

            return ['ok' => false, 'reason' => 'Google rejected the sign-in', 'id_token' => ''];
        }

        return ['ok' => true, 'reason' => '', 'id_token' => (string) $body['id_token']];
    }

    /**
     * Must match the redirect URI registered in Google Cloud Console exactly.
     * Built from the app URL so it follows the environment rather than being
     * configured twice.
     */
    public static function redirectUri()
    {
        return url('/sso/callback');
    }

    protected static function challenge($verifier)
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    protected static function random($bytes = 32)
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }
}
