<?php

namespace Modules\DOTMCP\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\DOTMCP\Entities\McpToken;
use Modules\DOTMCP\Services\AccessLevel;
use Modules\DOTMCP\Services\OAuthServer;

class SettingsController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');

        // Managing who has MCP access is an administrative act. Viewing your
        // own tokens is not - both land here, and the view distinguishes them.
        $this->middleware(function ($request, $next) {
            $user = auth()->user();
            if (!$user) {
                abort(403);
            }

            $isAdmin  = $user->isAdmin();
            $isMcp    = AccessLevel::checkUser($user)['allowed'];

            // Anyone who is neither an admin nor MCP-enabled should not learn
            // that this module exists.
            if (!$isAdmin && !$isMcp) {
                abort(404);
            }

            return $next($request);
        });
    }

    public function index()
    {
        $user = auth()->user();

        return view('dotmcp::settings', [
            'isAdmin'   => $user->isAdmin(),
            'users'     => $user->isAdmin()
                            ? \App\User::where('status', \App\User::STATUS_ACTIVE)
                                ->orderBy('first_name')->get()
                            : collect(),
            'tokens'    => $user->isAdmin()
                            ? McpToken::active()
                            : McpToken::with('user')->where('user_id', $user->id)
                                ->where('revoked', false)->get(),
            'keysReady' => OAuthServer::keysExist(),
            'endpoint'  => url('/mcp'),
        ]);
    }

    /** Admin: enable or disable a user, and set their access level. */
    public function saveUser(Request $request)
    {
        if (!auth()->user()->isAdmin()) {
            abort(403);
        }

        $target = \App\User::findOrFail((int) $request->input('user_id'));
        $enabled = (bool) $request->input('mcp_enabled');
        $level   = AccessLevel::normalise($request->input('mcp_access_level'));

        $target->mcp_enabled      = $enabled;
        $target->mcp_access_level = $level;
        $target->save();

        // Withdrawing access must take effect now, not when the token expires.
        if (!$enabled) {
            $revoked = McpToken::revokeForUser($target->id);
            if ($revoked) {
                return redirect()->route('mcp.settings')->with('success',
                    'Disabled MCP for '.$target->getFullName().' and revoked '
                    .$revoked.' active token'.($revoked === 1 ? '' : 's').'.');
            }
        }

        return redirect()->route('mcp.settings')
            ->with('success', 'Saved '.$target->getFullName().'.');
    }

    /** Revoke a single token. */
    public function revokeToken(Request $request)
    {
        $token = McpToken::find($request->input('token_id'));

        if (!$token) {
            return redirect()->route('mcp.settings')->with('error', 'Token not found.');
        }

        // A non-admin may only revoke their own.
        if (!auth()->user()->isAdmin() && $token->user_id !== auth()->id()) {
            abort(403);
        }

        $token->revoked = true;
        $token->save();

        return redirect()->route('mcp.settings')->with('success', 'Token revoked.');
    }

    /** Generate the OAuth signing keypair. Idempotent. */
    public function generateKeys()
    {
        if (!auth()->user()->isAdmin()) {
            abort(403);
        }

        $result = OAuthServer::generateKeys();

        return redirect()->route('mcp.settings')
            ->with($result['created'] ? 'success' : 'error', $result['message']);
    }
}
