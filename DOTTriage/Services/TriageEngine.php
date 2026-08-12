<?php

namespace Modules\DOTTriage\Services;

use Modules\DOTTriage\Entities\TriageProfile;
use Modules\DOTTriage\Entities\TriageDecision;

class TriageEngine
{
    protected $client;

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
    public function triage(\App\Conversation $conversation)
    {
        $started = microtime(true);

        $body    = $this->conversationText($conversation);
        $subject = (string) $conversation->subject;

        $profiles = TriageProfile::routable();

        if ($profiles->isEmpty()) {
            return $this->record($conversation, [
                'method'    => 'skipped',
                'reasoning' => 'No available agent profiles with a description.',
            ]);
        }

        // Deterministic keyword match first - free, instant, and often more
        // reliable than a model for unambiguous cases like "invoice".
        if ($match = $this->keywordMatch($profiles, $subject.' '.$body)) {
            return $this->record($conversation, [
                'suggested_user_id' => $match->user_id,
                'confidence'        => 1.0,
                'method'            => 'keyword',
                'reasoning'         => 'Matched a configured keyword for this agent.',
                'duration_ms'       => (int) round((microtime(true) - $started) * 1000),
            ]);
        }

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
            return $this->record($conversation, [
                'method'      => 'model',
                'model'       => config('triage.model'),
                'error'       => $result['error'],
                'duration_ms' => $duration,
            ]);
        }

        $parsed = $this->parseResponse($result['text'], $profiles);

        return $this->record($conversation, [
            'suggested_user_id' => $parsed['user_id'],
            'confidence'        => $parsed['confidence'],
            'reasoning'         => $parsed['reasoning'],
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
            .'{"user_id": <id or null>, "confidence": <0.0-1.0>, "reasoning": "<one short sentence>"}'."\n\n"
            ."Rules:\n"
            ."- Choose the agent whose described responsibilities best match the request.\n"
            ."- Use null for user_id when no agent is a clear fit, or the request is spam/automated.\n"
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

    /** Extract plain text from the conversation's first customer message. */
    protected function conversationText(\App\Conversation $conversation)
    {
        $thread = $conversation->threads()
            ->where('type', \App\Thread::TYPE_CUSTOMER)
            ->orderBy('created_at', 'asc')
            ->first();

        if (!$thread) {
            return '';
        }

        $text = strip_tags((string) $thread->body);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim(preg_replace('/\s+/u', ' ', $text));
    }

    protected function keywordMatch($profiles, $haystack)
    {
        $haystack = mb_strtolower($haystack);

        foreach ($profiles as $p) {
            foreach ($p->keywordList() as $kw) {
                if ($kw !== '' && mb_strpos($haystack, $kw) !== false) {
                    return $p;
                }
            }
        }

        return null;
    }

    /**
     * Parse the model's JSON reply defensively - a model can return prose,
     * markdown fences, or an id that does not exist.
     */
    protected function parseResponse($text, $profiles)
    {
        $default = ['user_id' => null, 'confidence' => 0.0, 'reasoning' => 'Could not parse model response.'];

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
                'user_id'    => null,
                'confidence' => 0.0,
                'reasoning'  => 'Model suggested an unknown user id ('.$userId.').',
            ];
        }

        return [
            'user_id'    => $userId,
            'confidence' => isset($json['confidence']) ? (float) $json['confidence'] : 0.0,
            'reasoning'  => isset($json['reasoning']) ? mb_substr((string) $json['reasoning'], 0, 500) : '',
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
