<?php

namespace Modules\DOTSSO\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\DOTSSO\Services\OAuthFlow;
use Modules\DOTSSO\Services\Settings;
use Modules\DOTSSO\Services\UserResolver;

class SettingsController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');

        $this->middleware(function ($request, $next) {
            if (!auth()->check() || !auth()->user()->isAdmin()) {
                abort(403);
            }

            return $next($request);
        });
    }

    public function index()
    {
        return view('dotsso::settings', $this->viewData());
    }

    public function save(Request $request)
    {
        $request->validate([
            'client_id' => 'nullable|string|max:255',
            'domain'    => 'nullable|string|max:255',
        ]);

        Settings::set('client_id', trim((string) $request->input('client_id')));
        Settings::set('domain', trim((string) $request->input('domain')));

        // An empty secret field means "leave it alone", so that saving the
        // form without retyping the secret does not wipe it.
        $secret = (string) $request->input('client_secret');
        if ($secret !== '') {
            Settings::set('client_secret', $secret);
        }

        Settings::set('enabled', $request->input('enabled') ? '1' : '0');

        // Enforcement is refused unless SSO could actually work. This is the
        // lockout guard: without it, a mistyped client id plus this checkbox
        // locks all eight users out of a site with no staging environment.
        $wantsEnforce = (bool) $request->input('enforce');
        $canEnforce   = $wantsEnforce
            && Settings::configured()
            && $request->input('enabled')
            && Settings::domain() !== '';

        Settings::set('enforce', $canEnforce ? '1' : '0');

        $message = 'Settings saved.';

        if ($wantsEnforce && !$canEnforce) {
            $message .= ' Enforcement was NOT enabled: SSO must be switched on, '
                .'fully configured, and have a Workspace domain set first.';
        }

        return redirect()->route('dotsso.settings')->with('success', $message);
    }

    protected function viewData()
    {
        // Which of the current users would actually be able to sign in? This
        // is the question an admin has before switching enforcement on, and
        // getting it wrong is what causes a lockout.
        $users = \App\User::orderBy('first_name')->get()->map(function ($user) {
            $resolved = UserResolver::resolve($user->email);

            return [
                'name'    => $user->getFullName(),
                'email'   => $user->email,
                'admin'   => $user->isAdmin(),
                'ok'      => $resolved['user'] !== null,
                'why'     => $resolved['reason'],
                'domain'  => $this->domainMatches($user->email),
                'invited' => (int) $user->invite_state === \App\User::INVITE_STATE_SENT,
            ];
        });

        return [
            'clientId'    => Settings::get('client_id'),
            'secretSet'   => (bool) Settings::get('client_secret'),
            'domain'      => Settings::domain(),
            'enabled'     => Settings::bool('enabled'),
            'enforcing'   => Settings::enforcing(),
            'configured'  => Settings::configured(),
            'redirectUri' => OAuthFlow::redirectUri(),
            'breakglass'  => Settings::breakglass(),
            'users'       => $users,
        ];
    }

    /**
     * Advisory only. The real check is the signed 'hd' claim on the ID token;
     * this just warns an admin that an account's address does not look like
     * it belongs to the Workspace.
     */
    protected function domainMatches($email)
    {
        $domain = Settings::domain();

        if ($domain === '') {
            return true;
        }

        return strtolower(substr((string) $email, -(strlen($domain) + 1))) === '@'.$domain;
    }
}
