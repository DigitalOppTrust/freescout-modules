<?php

namespace Modules\DOTMCP\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use League\OAuth2\Server\Exception\OAuthServerException;
use Modules\DOTMCP\OAuth\Entities\UserEntity;
use Modules\DOTMCP\Services\AccessLevel;
use Modules\DOTMCP\Services\OAuthServer;
use Modules\DOTMCP\Services\Psr7;

class OAuthController extends Controller
{
    /**
     * RFC 8414 discovery. Public by design - it advertises endpoints, not
     * data, and a client cannot connect without it.
     */
    public function metadata()
    {
        $base = url('/');

        return response()->json([
            'issuer'                                => $base,
            'authorization_endpoint'                => $base.'/mcp/oauth/authorize',
            'token_endpoint'                        => $base.'/mcp/oauth/token',
            'registration_endpoint'                 => $base.'/mcp/oauth/register',
            'revocation_endpoint'                   => $base.'/mcp/oauth/revoke',
            'scopes_supported'                      => ['mcp:read'],
            'response_types_supported'              => ['code'],
            'grant_types_supported'                 => ['authorization_code', 'refresh_token'],
            'token_endpoint_auth_methods_supported' => ['none'],
            'code_challenge_methods_supported'      => ['S256'],
        ]);
    }

    /**
     * RFC 7591 dynamic client registration.
     *
     * Open registration is intentional: it registers a client, not a user, and
     * grants nothing on its own. Every authorisation still requires a logged-in
     * FreeScout user who has been explicitly MCP-enabled.
     */
    public function register(Request $request)
    {
        $redirectUris = $request->input('redirect_uris', []);

        if (!is_array($redirectUris) || empty($redirectUris)) {
            return response()->json([
                'error'             => 'invalid_redirect_uri',
                'error_description' => 'redirect_uris is required and must be a non-empty array.',
            ], 400);
        }

        foreach ($redirectUris as $uri) {
            if (!is_string($uri) || !filter_var($uri, FILTER_VALIDATE_URL)) {
                return response()->json([
                    'error'             => 'invalid_redirect_uri',
                    'error_description' => 'Each redirect URI must be a valid absolute URL.',
                ], 400);
            }
        }

        $clientId = 'mcp_'.bin2hex(random_bytes(16));

        DB::table('mcp_clients')->insert([
            'client_id'       => $clientId,
            'secret_hash'     => null,          // public client, PKCE instead
            'name'            => mb_substr((string) $request->input('client_name', 'MCP Client'), 0, 191),
            'redirect_uris'   => json_encode(array_values($redirectUris)),
            'is_confidential' => false,
            'revoked'         => false,
            'created_at'      => now(),
            'updated_at'      => now(),
        ]);

        return response()->json([
            'client_id'                 => $clientId,
            'client_name'               => $request->input('client_name', 'MCP Client'),
            'redirect_uris'             => array_values($redirectUris),
            'grant_types'               => ['authorization_code', 'refresh_token'],
            'response_types'            => ['code'],
            'token_endpoint_auth_method'=> 'none',
        ], 201);
    }

    /**
     * Authorisation endpoint. Requires a logged-in, MCP-enabled FreeScout user.
     */
    public function authorize(Request $request)
    {
        $server = OAuthServer::authorizationServer();

        try {
            $authRequest = $server->validateAuthorizationRequest(Psr7::fromRequest($request));
        } catch (OAuthServerException $e) {
            return $this->oauthError($e);
        }

        $user = auth()->user();

        // Not logged in - send to FreeScout's login and come back here.
        if (!$user) {
            session(['url.intended' => $request->fullUrl()]);
            return redirect()->route('login');
        }

        // Gate 1. Admin status is deliberately not consulted.
        $check = AccessLevel::checkUser($user);
        if (!$check['allowed']) {
            return response()->view('dotmcp::denied', [
                'reason' => $check['reason'],
            ], 403);
        }

        // Stash the validated request; the POST below approves or denies it.
        session(['mcp_auth_request' => serialize($authRequest)]);

        return view('dotmcp::consent', [
            'client'      => $authRequest->getClient(),
            'user'        => $user,
            'accessLevel' => AccessLevel::normalise($user->mcp_access_level),
        ]);
    }

    /** The user clicked Allow or Deny. */
    public function approve(Request $request)
    {
        $stored = session('mcp_auth_request');
        if (!$stored) {
            return response()->view('dotmcp::denied', [
                'reason' => 'This authorisation request has expired. Please start again from Claude.',
            ], 400);
        }

        $authRequest = unserialize($stored);
        session()->forget('mcp_auth_request');

        $user = auth()->user();

        // Re-check on approval: the flag could have been withdrawn while the
        // consent screen was open.
        $check = AccessLevel::checkUser($user);
        if (!$check['allowed']) {
            return response()->view('dotmcp::denied', ['reason' => $check['reason']], 403);
        }

        $approved = $request->input('action') === 'allow';

        $authRequest->setUser(new UserEntity($user->id));
        $authRequest->setAuthorizationApproved($approved);
        if (method_exists($authRequest->getUser(), 'setIdentifier')) {
            $authRequest->getUser()->setIdentifier($user->id);
        }

        $server = OAuthServer::authorizationServer();

        try {
            $psrResponse = $server->completeAuthorizationRequest($authRequest, Psr7::response());
            return Psr7::toResponse($psrResponse);
        } catch (OAuthServerException $e) {
            return $this->oauthError($e);
        }
    }

    /** Token endpoint: code exchange and refresh. */
    public function token(Request $request)
    {
        $server = OAuthServer::authorizationServer();

        try {
            $psrResponse = $server->respondToAccessTokenRequest(
                Psr7::fromRequest($request),
                Psr7::response()
            );

            return Psr7::toResponse($psrResponse);
        } catch (OAuthServerException $e) {
            return $this->oauthError($e);
        }
    }

    /** RFC 7009 revocation. */
    public function revoke(Request $request)
    {
        $token = $request->input('token');

        if ($token) {
            // Best effort on both types; the spec says respond 200 regardless
            // so a caller cannot probe which tokens exist.
            DB::table('mcp_tokens')->where('id', $token)
                ->update(['revoked' => true, 'updated_at' => now()]);
            DB::table('mcp_refresh_tokens')->where('id', $token)
                ->update(['revoked' => true, 'updated_at' => now()]);
        }

        return response()->json([], 200);
    }

    protected function oauthError(OAuthServerException $e)
    {
        return response()->json([
            'error'             => $e->getErrorType(),
            'error_description' => $e->getMessage(),
            'hint'              => $e->getHint(),
        ], $e->getHttpStatusCode());
    }
}
