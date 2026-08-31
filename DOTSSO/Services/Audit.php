<?php

namespace Modules\DOTSSO\Services;

/**
 * Every sign-in decision, recorded.
 *
 * A refused login is the more interesting event of the two: repeated
 * unknown-user or wrong-domain refusals are what an attack looks like from
 * this side. So refusals log at warning, successes at info.
 *
 * Writes through DOTLog when it is installed, and always to laravel.log so
 * the trail survives DOTLog being disabled. Never logs tokens, codes, the
 * client secret, or anything else that would let a reader replay a login -
 * the same discipline DOTLog applies to message bodies.
 */
class Audit
{
    public static function success($email, $userId, $note = '')
    {
        self::write(
            'sso.login',
            'info',
            'SSO sign-in: '.$email.($note !== '' ? ' ('.$note.')' : ''),
            ['user_id' => $userId, 'email' => $email]
        );
    }

    public static function refused($email, $code, $reason)
    {
        self::write(
            'sso.refused',
            'warning',
            'SSO refused'.($email !== '' ? ' for '.$email : '').': '.$reason,
            ['email' => $email, 'code' => $code]
        );
    }

    /** A password login blocked because enforcement is on. */
    public static function passwordBlocked($email)
    {
        self::write(
            'sso.password_blocked',
            'warning',
            'Password sign-in refused (SSO enforced): '.$email,
            ['email' => $email]
        );
    }

    protected static function write($event, $level, $message, array $context = [])
    {
        try {
            if (class_exists(\Modules\DOTLog\Services\DotLog::class)) {
                \Modules\DOTLog\Services\DotLog::write($event, $message, [
                    'level'   => $level,
                    'user_id' => $context['user_id'] ?? null,
                    'context' => $context,
                ]);
            }
        } catch (\Throwable $e) {
            // Logging must never break a login.
        }

        try {
            \Log::log($level === 'warning' ? 'warning' : 'info', '[DOTSSO] '.$message);
        } catch (\Throwable $e) {
            // Nothing further we can do.
        }
    }
}
