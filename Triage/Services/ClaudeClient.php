<?php

namespace Modules\Triage\Services;

/**
 * Minimal Claude API client.
 *
 * Deliberately uses curl directly rather than pulling in an SDK: FreeScout
 * bundles its own vendor/ directory and committing extra dependencies into it
 * would conflict on every FreeScout upgrade.
 */
class ClaudeClient
{
    const API_URL = 'https://api.anthropic.com/v1/messages';
    const API_VERSION = '2023-06-01';

    protected $apiKey;
    protected $model;
    protected $timeout;

    public function __construct($apiKey = null, $model = null)
    {
        $this->apiKey  = $apiKey ?: config('triage.api_key');
        $this->model   = $model ?: config('triage.model');
        $this->timeout = (int) config('triage.timeout', 30);
    }

    public function isConfigured()
    {
        return !empty($this->apiKey);
    }

    /**
     * Cheap liveness probe for the settings screen.
     *
     * Sends a 1-token request rather than hitting a models endpoint, because
     * this exercises the exact path triage uses: same key, same model, same
     * network egress. A models list could succeed while inference fails.
     *
     * @return array{ok: bool, status: string, detail: string, latency_ms: int}
     */
    public function testConnection()
    {
        if (!$this->isConfigured()) {
            return [
                'ok'         => false,
                'status'     => 'not_configured',
                'detail'     => 'CLAUDE_API_KEY is not set in the FreeScout .env file.',
                'latency_ms' => 0,
            ];
        }

        $start = microtime(true);

        $result = $this->request([
            'model'      => $this->model,
            'max_tokens' => 1,
            'messages'   => [['role' => 'user', 'content' => 'Hi']],
        ]);

        $latency = (int) round((microtime(true) - $start) * 1000);

        if ($result['ok']) {
            return [
                'ok'         => true,
                'status'     => 'connected',
                'detail'     => 'Model '.$this->model.' responded normally.',
                'latency_ms' => $latency,
            ];
        }

        // Map the common failures to something actionable rather than
        // showing a raw API error to whoever opens the settings page.
        $code   = $result['http_code'];
        $detail = $result['error'];

        if ($code === 401) {
            $status = 'bad_key';
            $detail = 'The API key was rejected. Check CLAUDE_API_KEY in .env.';
        } elseif ($code === 404) {
            $status = 'bad_model';
            $detail = 'Model "'.$this->model.'" was not found. Check TRIAGE_MODEL.';
        } elseif ($code === 429) {
            $status = 'rate_limited';
            $detail = 'Rate limited or out of credit. Check your Anthropic console.';
        } elseif ($code === 0) {
            $status = 'unreachable';
            $detail = 'Could not reach api.anthropic.com: '.$detail;
        } elseif ($code >= 500) {
            $status = 'api_error';
            $detail = 'Anthropic API returned '.$code.'. Usually transient.';
        } else {
            $status = 'error';
        }

        return [
            'ok'         => false,
            'status'     => $status,
            'detail'     => $detail,
            'latency_ms' => $latency,
        ];
    }

    /**
     * Send a prompt and return the text response plus token usage.
     *
     * @return array{ok: bool, text: string, tokens_in: int, tokens_out: int, error: string, http_code: int}
     */
    public function complete($system, $userMessage, $maxTokens = 1024)
    {
        $payload = [
            'model'      => $this->model,
            'max_tokens' => $maxTokens,
            'system'     => $system,
            'messages'   => [['role' => 'user', 'content' => $userMessage]],
        ];

        $result = $this->request($payload);

        if (!$result['ok']) {
            return [
                'ok'         => false,
                'text'       => '',
                'tokens_in'  => 0,
                'tokens_out' => 0,
                'error'      => $result['error'],
                'http_code'  => $result['http_code'],
            ];
        }

        $body = $result['body'];
        $text = '';
        if (!empty($body['content'][0]['text'])) {
            $text = $body['content'][0]['text'];
        }

        return [
            'ok'         => true,
            'text'       => $text,
            'tokens_in'  => $body['usage']['input_tokens'] ?? 0,
            'tokens_out' => $body['usage']['output_tokens'] ?? 0,
            'error'      => '',
            'http_code'  => 200,
        ];
    }

    protected function request(array $payload)
    {
        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'x-api-key: '.$this->apiKey,
                'anthropic-version: '.self::API_VERSION,
            ],
        ]);

        $raw      = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        // curl_close() is deprecated from PHP 8.5 and has been a no-op since
        // 8.0 - the handle is freed when it goes out of scope.
        unset($ch);

        if ($raw === false) {
            return ['ok' => false, 'body' => [], 'error' => $curlErr, 'http_code' => 0];
        }

        $body = json_decode($raw, true);

        if ($httpCode !== 200) {
            $msg = $body['error']['message'] ?? ('HTTP '.$httpCode);
            return ['ok' => false, 'body' => $body ?: [], 'error' => $msg, 'http_code' => $httpCode];
        }

        return ['ok' => true, 'body' => $body ?: [], 'error' => '', 'http_code' => 200];
    }
}
