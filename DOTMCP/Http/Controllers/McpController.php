<?php

namespace Modules\DOTMCP\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use League\OAuth2\Server\Exception\OAuthServerException;
use Modules\DOTMCP\Services\AccessLevel;
use Modules\DOTMCP\Services\OAuthServer;
use Modules\DOTMCP\Services\Psr7;
use Modules\DOTMCP\Services\ToolRegistry;
use Modules\DOTMCP\Services\Tools\AggregateTools;
use Modules\DOTMCP\Services\Tools\DetailTools;

/**
 * MCP JSON-RPC endpoint.
 *
 * Stateless HTTP rather than SSE: the PHP-FPM pool here is ondemand with 8
 * children on 908MB, so a handful of long-lived streaming connections would
 * exhaust it and take the whole help desk down with it.
 */
class McpController extends Controller
{
    const PROTOCOL_VERSION = '2025-06-18';

    public function handle(Request $request)
    {
        $started = microtime(true);

        if (!config('dotmcp.enabled')) {
            return $this->error(null, -32000, 'MCP is disabled on this server.', 503);
        }

        // ── Authenticate ─────────────────────────────────────────────
        $auth = $this->authenticate($request);
        if (isset($auth['error'])) {
            return $this->unauthorized($auth['error']);
        }

        $user    = $auth['user'];
        $tokenId = $auth['token_id'];

        $payload = $request->json()->all();
        if (!is_array($payload) || empty($payload)) {
            return $this->error(null, -32700, 'Parse error: expected a JSON-RPC object.');
        }

        $id     = $payload['id'] ?? null;
        $method = $payload['method'] ?? '';

        try {
            switch ($method) {
                case 'initialize':
                    $result = $this->initialize();
                    break;

                case 'notifications/initialized':
                    // Notification - no id, no response body expected.
                    return response('', 202);

                case 'ping':
                    $result = (object) [];
                    break;

                case 'tools/list':
                    $result = ['tools' => ToolRegistry::listFor($user)];
                    break;

                case 'tools/call':
                    $result = $this->callTool($user, $tokenId, $payload['params'] ?? [], $started);
                    if (isset($result['__error'])) {
                        return $this->error($id, -32602, $result['__error']);
                    }
                    break;

                default:
                    return $this->error($id, -32601, 'Method not found: '.$method);
            }
        } catch (\Throwable $e) {
            \Log::error('[DOTMCP] '.$method.' failed: '.$e->getMessage());
            return $this->error($id, -32603, 'Internal error handling '.$method.'.');
        }

        $this->touchToken($tokenId, $request);

        return response()->json([
            'jsonrpc' => '2.0',
            'id'      => $id,
            'result'  => $result,
        ]);
    }

    protected function initialize()
    {
        return [
            'protocolVersion' => self::PROTOCOL_VERSION,
            'capabilities'    => ['tools' => (object) []],
            'serverInfo'      => [
                'name'    => config('dotmcp.server_name', 'DO Trust Support'),
                'version' => config('dotmcp.server_version', '0.1.0'),
            ],
        ];
    }

    /**
     * Validate the bearer token, then re-check the user's live permissions.
     *
     * The token carries a level snapshot, but the current flag is what
     * governs: withdrawing access must take effect immediately rather than
     * waiting for a token to expire.
     */
    protected function authenticate(Request $request)
    {
        try {
            $psr = OAuthServer::resourceServer()->validateAuthenticatedRequest(
                Psr7::fromRequest($request)
            );
        } catch (OAuthServerException $e) {
            return ['error' => $e->getMessage()];
        } catch (\Throwable $e) {
            return ['error' => 'Invalid access token.'];
        }

        $tokenId = $psr->getAttribute('oauth_access_token_id');
        $userId  = (int) $psr->getAttribute('oauth_user_id');

        $token = DB::table('mcp_tokens')->where('id', $tokenId)->first();
        if (!$token || $token->revoked) {
            return ['error' => 'This access token has been revoked.'];
        }

        $user = \App\User::find($userId);

        $check = AccessLevel::checkUser($user);
        if (!$check['allowed']) {
            return ['error' => $check['reason']];
        }

        return ['user' => $user, 'token_id' => $tokenId];
    }

    protected function callTool($user, $tokenId, array $params, $started)
    {
        $name = $params['name'] ?? '';
        $args = $params['arguments'] ?? [];

        $tool = ToolRegistry::get($name);
        if (!$tool) {
            return ['__error' => 'Unknown tool: '.$name];
        }

        // Gates 2 and 3.
        $check = AccessLevel::checkTool($user, $tool['level']);
        if (!$check['allowed']) {
            $this->log($user, $tokenId, 'tools/call', $name, null, false, $check['reason'], $started);

            return [
                'content' => [['type' => 'text', 'text' => 'Access denied. '.$check['reason']]],
                'isError' => true,
            ];
        }

        $effective = $check['effective'];
        $data      = $this->dispatch($name, $args, $effective);

        $this->log($user, $tokenId, 'tools/call', $name, $effective, true, null, $started);

        return [
            'content' => [[
                'type' => 'text',
                'text' => json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            ]],
        ];
    }

    protected function dispatch($name, array $args, $effectiveLevel)
    {
        $agg = new AggregateTools();

        switch ($name) {
            case 'conversation_volume': return $agg->conversationVolume($args);
            case 'volume_trend':        return $agg->volumeTrend($args);
            case 'triage_summary':      return $agg->triageSummary($args);
            case 'triage_accuracy':     return $agg->triageAccuracy($args);
            case 'noise_summary':       return $agg->noiseSummary($args);
            case 'topic_summary':       return $agg->topicSummary($args);
            case 'response_times':      return $agg->responseTimes($args);
            case 'agent_workload':      return $agg->agentWorkload($args);
        }

        $detail = new DetailTools($effectiveLevel);

        switch ($name) {
            case 'list_conversations':   return $detail->listConversations($args);
            case 'search_conversations': return $detail->searchConversations($args);
            case 'get_conversation':     return $detail->getConversation($args);
        }

        return ['error' => 'Tool '.$name.' is registered but has no implementation.'];
    }

    protected function touchToken($tokenId, Request $request)
    {
        DB::table('mcp_tokens')->where('id', $tokenId)->update([
            'last_used_at' => now(),
            'last_used_ip' => $request->ip(),
            'use_count'    => DB::raw('use_count + 1'),
        ]);
    }

    protected function log($user, $tokenId, $method, $tool, $level, $allowed, $reason, $started)
    {
        try {
            DB::table('mcp_requests')->insert([
                'user_id'       => $user ? $user->id : null,
                'token_id'      => $tokenId,
                'method'        => $method,
                'tool'          => $tool,
                'access_level'  => $level,
                'allowed'       => $allowed,
                'denied_reason' => $reason,
                'duration_ms'   => (int) round((microtime(true) - $started) * 1000),
                'ip'            => request()->ip(),
                'created_at'    => now(),
            ]);
        } catch (\Throwable $e) {
            // Logging must never break a working request.
            \Log::warning('[DOTMCP] request log failed: '.$e->getMessage());
        }
    }

    protected function error($id, $code, $message, $status = 200)
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id'      => $id,
            'error'   => ['code' => $code, 'message' => $message],
        ], $status);
    }

    /** 401 with WWW-Authenticate, so the client knows to re-authorise. */
    protected function unauthorized($message)
    {
        return response()->json([
            'jsonrpc' => '2.0',
            'id'      => null,
            'error'   => ['code' => -32001, 'message' => $message],
        ], 401)->header(
            'WWW-Authenticate',
            'Bearer realm="MCP", resource_metadata="'.url('/.well-known/oauth-protected-resource').'"'
        );
    }
}
