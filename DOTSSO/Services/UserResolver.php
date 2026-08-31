<?php

namespace Modules\DOTSSO\Services;

use App\User;

/**
 * Gate 2: does a verified Google identity correspond to a user who is allowed
 * to log in here?
 *
 * Deliberately does NOT create users. An identity Google vouches for is not
 * an authorisation to use this help desk - accounts are still created by an
 * administrator, and SSO only decides whether an existing one may proceed.
 *
 * Every refusal returns a reason, so the login page can say something honest
 * and the audit log can record why, rather than a bare null that reads the
 * same whether the user is unknown, disabled or a robot.
 */
class UserResolver
{
    /**
     * @param string $email the verified email from the ID token
     *
     * @return array ['user' => ?User, 'reason' => string, 'code' => string]
     */
    public static function resolve($email)
    {
        $email = strtolower(trim((string) $email));

        if ($email === '') {
            return self::refuse('no_email', 'Google returned no email address.');
        }

        // Workspace addresses are not case-sensitive, and the column is a
        // unique varchar, so match case-insensitively rather than trusting
        // whatever casing Google happens to send.
        $user = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if (!$user) {
            // The message is deliberately the same shape as the other
            // refusals: an unauthenticated visitor should not be able to use
            // this page to learn which addresses have accounts.
            return self::refuse(
                'unknown_user',
                'That Google account is not registered on this help desk.'
            );
        }

        // Robot accounts exist for workflows and teams. They are not people
        // and must never hold an interactive session.
        if ((int) $user->type !== User::TYPE_USER) {
            return self::refuse(
                'not_a_person',
                'That account cannot be used to sign in.'
            );
        }

        // The same test core applies on every request in LogoutIfDeleted;
        // if SSO did not repeat it, SSO would be a way in for accounts core
        // would immediately log back out.
        if ($user->isDeleted() || $user->isDisabled()) {
            return self::refuse(
                'inactive',
                'That account is no longer active.'
            );
        }

        // Belt and braces: isDisabled()/isDeleted() cover the known states,
        // but anything other than ACTIVE should not be logging in.
        if ((int) $user->status !== User::STATUS_ACTIVE) {
            return self::refuse(
                'inactive',
                'That account is no longer active.'
            );
        }

        return ['user' => $user, 'reason' => '', 'code' => 'ok'];
    }

    /**
     * A user who was invited but never clicked the link has now proven their
     * identity through Google, which is a stronger proof than the invite
     * email. Accepting it saves an administrator from chasing them.
     */
    public static function activateIfInvited(User $user)
    {
        if (!config('dotsso.activate_invited', true)) {
            return false;
        }

        if ((int) $user->invite_state === User::INVITE_STATE_ACTIVATED) {
            return false;
        }

        $user->invite_state = User::INVITE_STATE_ACTIVATED;
        $user->save();

        return true;
    }

    protected static function refuse($code, $reason)
    {
        return ['user' => null, 'reason' => $reason, 'code' => $code];
    }
}
