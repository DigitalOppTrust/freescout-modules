<?php

namespace Modules\DOTSSO\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\DOTSSO\Services\Audit;
use Modules\DOTSSO\Services\GoogleIdToken;
use Modules\DOTSSO\Services\OAuthFlow;
use Modules\DOTSSO\Services\Settings;
use Modules\DOTSSO\Services\UserResolver;

/**
 * The two ends of the sign-in round trip.
 *
 * Both refuse to do anything when the module is switched off, so a stale
 * bookmark or a half-finished redirect cannot log anyone in after an
 * administrator has turned SSO off.
 */
class SSOController extends Controller
{
    /** Send the browser to Google. */
    public function redirect()
    {
        if (!Settings::enabled()) {
            return redirect()->route('login')
                ->withErrors(['email' => __('Single sign-on is not available.')]);
        }

        return redirect()->away(OAuthFlow::start());
    }

    /** Google sends the browser back here. */
    public function callback(Request $request)
    {
        if (!Settings::enabled()) {
            return $this->deny(__('Single sign-on is not available.'));
        }

        $flow = OAuthFlow::pending();

        // No stashed attempt: a replayed callback URL, a bookmark, or a
        // session that expired mid-flow.
        if ($flow === null) {
            return $this->deny(__('That sign-in link has expired. Please try again.'));
        }

        // Consume the attempt immediately. Whatever happens below, this
        // state/nonce/verifier triple must not be usable a second time.
        OAuthFlow::forget();

        // The user declined at Google's consent screen, or Google refused.
        if ($request->filled('error')) {
            Audit::refused('', 'provider_error', 'Google returned: '.$request->input('error'));

            return $this->deny(__('Sign-in was cancelled.'));
        }

        // CSRF: this callback must belong to the attempt this browser began.
        if (!hash_equals((string) $flow['state'], (string) $request->input('state'))) {
            Audit::refused('', 'state_mismatch', 'state parameter did not match');

            return $this->deny(__('Sign-in could not be verified. Please try again.'));
        }

        if (!$request->filled('code')) {
            return $this->deny(__('Sign-in did not complete. Please try again.'));
        }

        $exchange = OAuthFlow::exchange($request->input('code'), $flow['verifier']);

        if (!$exchange['ok']) {
            Audit::refused('', 'exchange_failed', $exchange['reason']);

            return $this->deny(__('Sign-in could not be completed. Please try again.'));
        }

        // ── Gate 1: the token is genuine, current, ours, and from our domain.
        $verified = GoogleIdToken::verify(
            $exchange['id_token'],
            Settings::get('client_id'),
            $flow['nonce'],
            Settings::domain()
        );

        if (!$verified['ok']) {
            Audit::refused('', 'token_invalid', $verified['reason']);

            return $this->deny(__('Sign-in could not be verified.').' '.$verified['reason']);
        }

        $email = (string) $verified['claims']['email'];

        // ── Gate 2: we already have this user, and they may log in.
        $resolved = UserResolver::resolve($email);

        if ($resolved['user'] === null) {
            Audit::refused($email, $resolved['code'], $resolved['reason']);

            return $this->deny($resolved['reason']);
        }

        $user = $resolved['user'];

        $activated = UserResolver::activateIfInvited($user);

        // Session fixation: the pre-login session id must not survive into
        // the authenticated session.
        $request->session()->regenerate();

        // Never "remember" an SSO session. A remembered cookie is a login
        // that never revisits Google, so it would outlive a Workspace
        // suspension - which is most of the reason for doing this at all.
        \Auth::login($user, false);

        Audit::success($email, $user->id, $activated ? 'invite activated' : '');

        return redirect()->intended('/');
    }

    /** Back to the login page with a message, never authenticated. */
    protected function deny($message)
    {
        return redirect()->route('login')->withErrors(['email' => $message]);
    }
}
