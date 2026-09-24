<?php

return [
    /*
     * Master switch. When false the module registers no hooks: no button on
     * the login page, no interference with password login. The routes stay
     * registered so a half-finished OAuth round trip fails cleanly rather
     * than 404-ing into a confusing place.
     *
     * Default false. Deploying this module must not change how anybody logs
     * in until an admin turns it on deliberately.
     */
    'enabled' => env('DOTSSO_ENABLED', false),

    /*
     * Enforcement. Separate from 'enabled' on purpose, and this separation is
     * the whole safety story of the module:
     *
     *   enabled=true, enforce=false  - the Google button appears, password
     *                                  login still works. Prove SSO works for
     *                                  a real account in this state.
     *   enabled=true, enforce=true   - password login is refused and the
     *                                  password-reset link is hidden.
     *
     * Never set enforce=true before a successful SSO login has been observed.
     * There is no staging environment, and 6 of the 8 users are admins.
     */
    'enforce' => env('DOTSSO_ENFORCE', false),

    /*
     * The Workspace domain users must belong to, checked against the verified
     * 'hd' claim on Google's ID token - never against the email string, which
     * an attacker controls the right-hand side of via a lookalike domain.
     */
    'domain' => env('DOTSSO_DOMAIN', 'dotrust.org'),

    /*
     * Break-glass. Emails listed here may always use password login, even
     * under enforcement. Keep this to one administrator: every address here
     * is an account whose security is back to being a password.
     *
     * Comma-separated.
     */
    'breakglass' => env('DOTSSO_BREAKGLASS_EMAILS', ''),

    /*
     * Whether a first successful SSO login activates a user still sitting on
     * an unaccepted invite (invite_state = INVITE_STATE_SENT). Google has
     * proven the identity more strongly than clicking a link in an email
     * does, so accepting it is reasonable - but it is a policy choice, so it
     * is a switch.
     */
    'activate_invited' => env('DOTSSO_ACTIVATE_INVITED', true),

    /*
     * How long a Google sign-in keeps someone signed in, in days. It sets a
     * remember-me cookie of this length, so a closed browser or an expired
     * session does not send them back to the login page. After it runs out
     * they sign in with Google again. The date is set at sign-in and is not
     * extended by using the help desk.
     *
     * The cookie does not revisit Google, so suspending someone in Workspace
     * does not sign them out here. Disable the user in FreeScout as well: core's
     * LogoutIfDeleted middleware then ends a remembered login on the next request.
     *
     * 0 turns remember-me off, so a sign-in lasts only as long as the session.
     */
    'remember_days' => env('DOTSSO_REMEMBER_DAYS', 90),

    /*
     * Seconds of clock skew tolerated when validating ID token iat/exp.
     */
    'leeway' => env('DOTSSO_LEEWAY', 60),
];
