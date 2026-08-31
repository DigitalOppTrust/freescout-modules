<?php

namespace Modules\DOTSSO\Services;

/**
 * Verification of Google's OpenID Connect ID tokens.
 *
 * This is the security boundary of the module. Everything downstream trusts
 * whatever this class returns, so it fails closed: any check that cannot be
 * completed is a rejection, never a pass.
 *
 * Implemented directly on ext-openssl rather than pulling in a JWT library.
 * The verification is one algorithm (RS256, which is all Google signs ID
 * tokens with) and roughly forty lines; a vendored library here would be more
 * code to commit, audit and keep patched than it saves, and it would hide the
 * one part of this module that most deserves to be read.
 */
class GoogleIdToken
{
    const JWKS_URL = 'https://www.googleapis.com/oauth2/v3/certs';

    /** Google publishes both spellings; both are legitimate. */
    const ISSUERS = ['https://accounts.google.com', 'accounts.google.com'];

    const CACHE_KEY = 'dotsso.google_jwks';

    /** Only RS256. Notably this excludes 'none' and the HMAC algorithms. */
    const ALLOWED_ALG = 'RS256';

    /**
     * Verify a raw ID token.
     *
     * @param string $jwt        the id_token from the token endpoint
     * @param string $clientId   our OAuth client id - the expected audience
     * @param string $nonce      the nonce we generated for this login attempt
     * @param string $domain     the Workspace domain the user must belong to
     *
     * @return array ['ok' => bool, 'reason' => string, 'claims' => array]
     */
    public static function verify($jwt, $clientId, $nonce, $domain)
    {
        $parts = explode('.', (string) $jwt);
        if (count($parts) !== 3) {
            return self::fail('malformed token');
        }

        list($rawHeader, $rawPayload, $rawSignature) = $parts;

        $header = json_decode(self::b64($rawHeader), true);
        $claims = json_decode(self::b64($rawPayload), true);

        if (!is_array($header) || !is_array($claims)) {
            return self::fail('unreadable token');
        }

        // Pin the algorithm from our side. Trusting the token's own 'alg' is
        // how the classic "alg: none" and RS256->HS256 confusion attacks work.
        if (($header['alg'] ?? '') !== self::ALLOWED_ALG) {
            return self::fail('unexpected signing algorithm');
        }

        $kid = $header['kid'] ?? '';
        if ($kid === '') {
            return self::fail('token names no signing key');
        }

        $key = self::publicKeyFor($kid);
        if ($key === null) {
            return self::fail('unknown signing key');
        }

        $ok = openssl_verify(
            $rawHeader.'.'.$rawPayload,
            self::b64($rawSignature),
            $key,
            OPENSSL_ALGO_SHA256
        );

        if ($ok !== 1) {
            return self::fail('signature does not verify');
        }

        // ── Claims. Order matters only for the quality of the error. ──

        if (!in_array((string) ($claims['iss'] ?? ''), self::ISSUERS, true)) {
            return self::fail('unexpected issuer');
        }

        // 'aud' may be a string or a list.
        $aud = $claims['aud'] ?? '';
        $audOk = is_array($aud)
            ? in_array($clientId, $aud, true)
            : hash_equals((string) $clientId, (string) $aud);

        if (!$audOk) {
            return self::fail('token was not issued for this application');
        }

        $leeway = (int) config('dotsso.leeway', 60);
        $now    = time();

        if (isset($claims['exp']) && $now > ((int) $claims['exp'] + $leeway)) {
            return self::fail('token has expired');
        }

        if (isset($claims['iat']) && ((int) $claims['iat'] - $leeway) > $now) {
            return self::fail('token is not valid yet');
        }

        // Replay protection. We generated this nonce and stored it in the
        // session before the redirect; a token without it is not a response
        // to a login we started.
        if (!isset($claims['nonce']) || !hash_equals((string) $nonce, (string) $claims['nonce'])) {
            return self::fail('nonce mismatch');
        }

        // ── Gate 1: the Workspace domain. ──
        //
        // Checked against the signed 'hd' claim, NOT against the email. An
        // email check would accept someone@dotrust.org.attacker.com, because
        // the attacker owns everything to the right of the first dot.
        $hd = strtolower(trim((string) ($claims['hd'] ?? '')));
        if ($domain !== '' && $hd !== strtolower(trim($domain))) {
            return self::fail('account is not in the '.$domain.' workspace');
        }

        // A Workspace account's address is verified by definition, but the
        // claim is cheap to check and its absence is a real signal.
        if (isset($claims['email_verified'])
            && !filter_var($claims['email_verified'], FILTER_VALIDATE_BOOLEAN)) {
            return self::fail('email address is not verified');
        }

        if (empty($claims['email'])) {
            return self::fail('token carries no email address');
        }

        return ['ok' => true, 'reason' => '', 'claims' => $claims];
    }

    /**
     * The PEM public key for a key id, from Google's JWKS.
     *
     * Cached, because Google rotates these slowly and a fetch on every login
     * would put an outbound HTTP call on the critical path. An unknown kid
     * busts the cache once - that is exactly the rotation case.
     */
    protected static function publicKeyFor($kid)
    {
        $keys = self::jwks(false);

        if (!isset($keys[$kid])) {
            $keys = self::jwks(true);
        }

        if (!isset($keys[$kid])) {
            return null;
        }

        return self::pemFromJwk($keys[$kid]);
    }

    /** @return array keyed by kid */
    protected static function jwks($refresh)
    {
        if (!$refresh) {
            $cached = \Cache::get(self::CACHE_KEY);
            if (is_array($cached)) {
                return $cached;
            }
        }

        try {
            $client = new \GuzzleHttp\Client(['timeout' => 10]);
            $body   = (string) $client->get(self::JWKS_URL)->getBody();
            $json   = json_decode($body, true);
        } catch (\Throwable $e) {
            \Log::error('[DOTSSO] could not fetch Google signing keys: '.$e->getMessage());

            return [];
        }

        if (!is_array($json) || empty($json['keys'])) {
            return [];
        }

        $keys = [];
        foreach ($json['keys'] as $jwk) {
            if (!empty($jwk['kid'])) {
                $keys[$jwk['kid']] = $jwk;
            }
        }

        // Short enough that a revoked key stops being accepted quickly, long
        // enough that logins do not each cost an HTTP round trip.
        \Cache::put(self::CACHE_KEY, $keys, now()->addHours(6));

        return $keys;
    }

    /**
     * Build a PEM public key from an RSA JWK.
     *
     * Hand-rolling DER is unpleasant but avoids a dependency for what is, for
     * RSA, a fixed and well-specified structure.
     */
    protected static function pemFromJwk($jwk)
    {
        if (($jwk['kty'] ?? '') !== 'RSA' || empty($jwk['n']) || empty($jwk['e'])) {
            return null;
        }

        $modulus  = self::b64($jwk['n']);
        $exponent = self::b64($jwk['e']);

        $components = self::derInteger($modulus).self::derInteger($exponent);
        $sequence   = self::derSequence($components);

        // RSA algorithm identifier: OID 1.2.840.113549.1.1.1 + NULL params.
        $algorithm = self::derSequence(
            "\x06\x09\x2a\x86\x48\x86\xf7\x0d\x01\x01\x01"."\x05\x00"
        );

        // The key sequence, wrapped in a BIT STRING with no unused bits.
        $bitString = "\x03".self::derLength(strlen($sequence) + 1)."\x00".$sequence;

        $der = self::derSequence($algorithm.$bitString);

        $pem = "-----BEGIN PUBLIC KEY-----\n"
             .chunk_split(base64_encode($der), 64, "\n")
             ."-----END PUBLIC KEY-----\n";

        $key = openssl_pkey_get_public($pem);

        return $key ?: null;
    }

    protected static function derInteger($bytes)
    {
        // A leading high bit would make the integer negative in DER, so pad.
        if (ord($bytes[0]) > 0x7f) {
            $bytes = "\x00".$bytes;
        }

        return "\x02".self::derLength(strlen($bytes)).$bytes;
    }

    protected static function derSequence($contents)
    {
        return "\x30".self::derLength(strlen($contents)).$contents;
    }

    protected static function derLength($length)
    {
        if ($length < 0x80) {
            return chr($length);
        }

        $bytes = '';
        while ($length > 0) {
            $bytes = chr($length & 0xff).$bytes;
            $length >>= 8;
        }

        return chr(0x80 | strlen($bytes)).$bytes;
    }

    /** base64url decode. */
    protected static function b64($value)
    {
        return (string) base64_decode(strtr((string) $value, '-_', '+/'), false);
    }

    protected static function fail($reason)
    {
        return ['ok' => false, 'reason' => $reason, 'claims' => []];
    }
}
