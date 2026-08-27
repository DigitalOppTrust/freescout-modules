<?php

namespace Modules\DOTTriage\Services;

use Modules\DOTTriage\Entities\TriageProfile;
use Modules\DOTTriage\Entities\TriageDecision;

class TriageEngine
{
    protected $client;

    /** Whether the last failure was the kind worth retrying. */
    protected $lastTransient = false;

    public function __construct(?ClaudeClient $client = null)
    {
        $this->client = $client ?: new ClaudeClient();
    }

    /**
     * Decide who should handle a conversation.
     *
     * Returns the persisted TriageDecision. Never throws: a failure is
     * recorded on the decision row and the conversation is left unassigned,
     * because a triage fault must never block support email.
     */
    /**
     * @param bool $latest judge the most recent customer message rather than
     *                     the first - used when a closed ticket is reopened
     *                     by a follow-up and needs routing afresh
     */
    public function triage(\App\Conversation $conversation, $latest = false)
    {
        $started = microtime(true);
        $this->lastTransient = false;

        $body    = $this->conversationText($conversation, $latest);
        $subject = (string) $conversation->subject;

        $profiles = TriageProfile::routable($conversation->mailbox_id);

        if ($profiles->isEmpty()) {
            return $this->record($conversation, [
                'method'    => 'skipped',
                'reasoning' => 'No available agent profiles with a description.',
            ]);
        }

        // No keyword shortcut. Keyword hits were recorded as confidence 1.00
        // and never learned anything; in production they produced most of the
        // human overrides. Routing is the model reasoning over the agent
        // descriptions, and improving routing means improving those.

        // Budget guard - stop calling the API past the daily cap.
        $limit = (int) config('triage.daily_call_limit', 500);
        if ($limit > 0 && TriageDecision::callsToday() >= $limit) {
            return $this->record($conversation, [
                'method'    => 'skipped',
                'reasoning' => 'Daily API call limit ('.$limit.') reached.',
            ]);
        }

        if (!$this->client->isConfigured()) {
            return $this->record($conversation, [
                'method' => 'skipped',
                'error'  => 'CLAUDE_API_KEY not configured.',
            ]);
        }

        $result = $this->client->complete(
            $this->systemPrompt($profiles),
            $this->userPrompt($subject, $body, $conversation),
            512
        );

        $duration = (int) round((microtime(true) - $started) * 1000);

        if (!$result['ok']) {
            $this->lastTransient = !empty($result['transient']);

            return $this->record($conversation, [
                'method'      => 'model',
                'model'       => config('triage.model'),
                'error'       => $result['error'],
                'duration_ms' => $duration,
            ]);
        }

        $parsed = $this->parseResponse($result['text'], $profiles);

        return $this->record($conversation, [
            'suggested_user_id' => $parsed['not_support'] ? null : $parsed['user_id'],
            'confidence'        => $parsed['confidence'],
            'reasoning'         => $parsed['reasoning'],
            'noise_category'    => $parsed['not_support'] ? 'not_support' : null,
            'method'            => 'model',
            'model'             => config('triage.model'),
            'tokens_in'         => $result['tokens_in'],
            'tokens_out'        => $result['tokens_out'],
            'duration_ms'       => $duration,
        ]);
    }

    protected function systemPrompt($profiles)
    {
        $lines = [];
        foreach ($profiles as $p) {
            $name = $p->user ? $p->user->getFullName() : ('User '.$p->user_id);
            $lines[] = "- id={$p->user_id} | {$name} | {$p->description}";
        }

        return "You route incoming customer support emails to the most suitable agent.\n\n"
            ."Available agents:\n".implode("\n", $lines)."\n\n"
            ."Reply with ONLY a JSON object, no other text:\n"
            .'{"user_id": <id or null>, "confidence": <0.0-1.0>, "not_support": <true|false>, "reasoning": "<one short sentence>"}'."\n\n"
            ."Rules:\n"
            ."- Choose the agent whose described responsibilities best match the request.\n"
            ."- Set not_support to true when the message is not a customer support request at all:\n"
            ."  marketing or newsletter mail, automated service notifications, delivery reports,\n"
            ."  or anything sent by a system rather than a person seeking help.\n"
            ."- Use null for user_id when no agent is a clear fit.\n"
            ."- confidence reflects how certain the match is. Below 0.5 means uncertain.\n"
            ."- Judge on the nature of the request, not on politeness or urgency of tone.\n"
            ."- Keep reasoning to one sentence a human can check at a glance.";
    }

    protected function userPrompt($subject, $body, $conversation)
    {
        $from = $conversation->customer_email ?: 'unknown';
        $max  = (int) config('triage.max_body_chars', 4000);

        if (mb_strlen($body) > $max) {
            $body = mb_substr($body, 0, $max)."\n[truncated]";
        }

        return "From: {$from}\nSubject: {$subject}\n\n{$body}";
    }

    /** Did the last triage() fail in a way that is worth retrying? */
    public function lastErrorTransient()
    {
        return $this->lastTransient;
    }

    /** Extract plain text from the conversation's first (or latest) customer message. */
    protected function conversationText(\App\Conversation $conversation, $latest = false)
    {
        $thread = $conversation->threads()
            ->where('type', \App\Thread::TYPE_CUSTOMER)
            ->orderBy('created_at', $latest ? 'desc' : 'asc')
            ->first();

        if (!$thread) {
            return '';
        }

        $text = strip_tags((string) $thread->body);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Parse the model's JSON reply defensively - a model can return prose,
     * markdown fences, or an id that does not exist.
     */
    protected function parseResponse($text, $profiles)
    {
        $default = ['user_id' => null, 'confidence' => 0.0, 'not_support' => false,
                    'reasoning' => 'Could not parse model response.'];

        if (preg_match('/\{.*\}/s', $text, $m)) {
            $json = json_decode($m[0], true);
        } else {
            return $default;
        }

        if (!is_array($json)) {
            return $default;
        }

        $userId = isset($json['user_id']) && $json['user_id'] !== null
            ? (int) $json['user_id']
            : null;

        // Reject an id the model invented.
        if ($userId !== null && !$profiles->contains('user_id', $userId)) {
            return [
                'user_id'     => null,
                'confidence'  => 0.0,
                'not_support' => false,
                'reasoning'   => 'Model suggested an unknown user id ('.$userId.').',
            ];
        }

        $reasoning = isset($json['reasoning']) ? mb_substr((string) $json['reasoning'], 0, 500) : '';

        // Models reliably explain *why* something is not a support request but
        // do not always set the boolean asked for. Treat a confident "no agent
        // fits" plus reasoning that says so as not_support, rather than
        // leaving obvious newsletter mail sitting in the queue.
        $notSupport = !empty($json['not_support']);

        if (!$notSupport
            && $userId === null
            && (float) ($json['confidence'] ?? 0) >= 0.8
            && preg_match('/\b(not a (customer )?support|newsletter|promotional|marketing|automated notification|no-reply|unsolicited)\b/i', $reasoning)) {
            $notSupport = true;
        }

        return [
            'user_id'     => $userId,
            'confidence'  => isset($json['confidence']) ? (float) $json['confidence'] : 0.0,
            'not_support' => $notSupport,
            'reasoning'   => $reasoning,
        ];
    }

    protected function record(\App\Conversation $conversation, array $attrs)
    {
        return TriageDecision::create(array_merge([
            'conversation_id' => $conversation->id,
            'mailbox_id'      => $conversation->mailbox_id,
            'applied'         => false,
        ], $attrs));
    }
}
