<?php

namespace Modules\DOTSSO\Services;

/**
 * Settings storage for DOTSSO.
 *
 * Same precedence as DOTTriage: database option, then .env, then the config
 * default. That lets the module be configured from the UI without a deploy,
 * while still allowing .env to seed it on a fresh install.
 *
 * The client secret is the exception: it is encrypted at rest with the app
 * key, and is never returned to a view in full.
 */
class Settings
{
    const PREFIX = 'dotsso.';

    /** Settings held in the database rather than config/env. */
    const KEYS = ['client_id', 'client_secret', 'enabled', 'enforce', 'domain'];

    /** Values that must never be rendered or logged in full. */
    const SECRET_KEYS = ['client_secret'];

    public static function get($key, $default = null)
    {
        if (!in_array($key, self::KEYS, true)) {
            return $default;
        }

        $stored = \Option::get(self::PREFIX.$key, null);

        if ($stored !== null && $stored !== '') {
            if (in_array($key, self::SECRET_KEYS, true)) {
                return self::decrypt($stored);
            }

            return $stored;
        }

        // Fall back to config, which itself falls back to .env.
        return config('dotsso.'.$key, $default);
    }

    public static function set($key, $value)
    {
        if (!in_array($key, self::KEYS, true)) {
            return false;
        }

        if (in_array($key, self::SECRET_KEYS, true) && $value !== '' && $value !== null) {
            $value = \Crypt::encryptString((string) $value);
        }

        \Option::set(self::PREFIX.$key, (string) $value);

        return true;
    }

    /**
     * Booleans need care: config() returns a real bool from env(), while
     * Option returns the string '0' or '1'. filter_var handles both, and
     * treats the empty string as false.
     */
    public static function bool($key)
    {
        return filter_var(self::get($key), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Is the module configured well enough to attempt a login? Without both
     * halves of the client credentials the redirect would bounce off Google
     * with an unhelpful error.
     */
    public static function configured()
    {
        return self::get('client_id') !== null && self::get('client_id') !== ''
            && self::get('client_secret') !== null && self::get('client_secret') !== '';
    }

    /** The button appears. */
    public static function enabled()
    {
        return self::bool('enabled') && self::configured();
    }

    /**
     * Password login is refused. Enforcement additionally requires the module
     * to be usable - enforcing SSO while SSO cannot work would lock everyone
     * out, so the two conditions are deliberately ANDed here rather than
     * trusted to be set consistently.
     */
    public static function enforcing()
    {
        return self::enabled() && self::bool('enforce');
    }

    public static function domain()
    {
        $domain = self::get('domain');

        return is_string($domain) ? strtolower(trim($domain)) : '';
    }

    /** Lower-cased break-glass addresses. Never empty strings. */
    public static function breakglass()
    {
        $raw = (string) config('dotsso.breakglass', '');

        return array_values(array_filter(array_map(
            function ($email) {
                return strtolower(trim($email));
            },
            explode(',', $raw)
        ), function ($email) {
            return $email !== '';
        }));
    }

    public static function isBreakglass($email)
    {
        return in_array(strtolower(trim((string) $email)), self::breakglass(), true);
    }

    /** For display: enough to recognise the value, not enough to use it. */
    public static function masked($key)
    {
        $value = (string) self::get($key);

        if ($value === '') {
            return '';
        }

        return str_repeat('•', 8).' '.substr($value, -4);
    }

    /**
     * Decrypting can fail if APP_KEY was rotated after the secret was saved.
     * That must not take down the login page, so it degrades to "unset",
     * which surfaces as "not configured" rather than as an exception.
     */
    protected static function decrypt($value)
    {
        try {
            return \Crypt::decryptString($value);
        } catch (\Throwable $e) {
            \Log::error('[DOTSSO] could not decrypt a stored secret; '
                .'APP_KEY may have changed since it was saved');

            return null;
        }
    }
}
