<?php

namespace Modules\DOTTriage\Services;

/**
 * Identifies mail that is not a support request.
 *
 * Header checks only - deterministic, free, and instant. Anything this cannot
 * classify is left for the model, which sees the message body.
 *
 * Every pattern here was verified against real mail in this installation.
 * The important negative result: `List-Unsubscribe` is useless as a signal,
 * because Gmail adds it to ordinary person-to-person mail. It appeared on 13
 * of 14 conversations including genuine support requests, so matching on it
 * would close real tickets.
 */
class NoiseDetector
{
    // Categories, used for the note and the audit trail.
    const AUTO_REPLY   = 'auto_reply';
    const BULK         = 'bulk';
    const SYSTEM       = 'system';
    const SELF_SENT    = 'self_sent';
    const BOUNCE       = 'bounce';

    /**
     * Classify a thread from its headers.
     *
     * @return array{noise: bool, category: ?string, reason: ?string}
     */
    public function classify($thread, $mailbox = null)
    {
        // FreeScout stores headers with literal backslash-n rather than real
        // newlines. Without normalising, every line-anchored ^ match collapses
        // to a substring match against the whole blob - which silently
        // misclassified genuine support mail during testing.
        $headers = str_replace(['\\r\\n', '\\n', "\r\n"], "\n", (string) ($thread->headers ?? ''));
        $from    = mb_strtolower(trim((string) ($thread->from ?? '')));

        if ($headers === '' && $from === '') {
            return $this->notNoise();
        }

        // ── Bounces and delivery failures ─────────────────────────────
        // An empty Return-Path is the RFC-mandated marker for a bounce.
        if ($this->hasHeader($headers, 'Return-Path', '<>')
            || $this->hasHeader($headers, 'X-Failed-Recipients')) {
            return $this->noise(self::BOUNCE, 'Delivery failure notification.');
        }

        // ── Auto-replies (out of office, mailbox closed) ──────────────
        // Precedence: auto_reply correctly flagged exactly the two genuine
        // out-of-office messages here, with no false positives.
        if ($this->hasHeader($headers, 'Precedence', 'auto_reply')
            || $this->hasHeader($headers, 'X-Autoreply')
            || $this->hasHeader($headers, 'X-Auto-Response-Suppress')) {
            return $this->noise(self::AUTO_REPLY, 'Automatic reply (out of office or similar).');
        }

        // Auto-Submitted must be line-anchored: the string also appears inside
        // DKIM h= header lists as "auto-submitted:list-unsubscribe-post",
        // which is a signature fragment, not a real header.
        if (preg_match('/^Auto-Submitted:\s*auto-(replied|generated|notified)/mi', $headers)) {
            return $this->noise(self::AUTO_REPLY, 'Marked Auto-Submitted by the sending server.');
        }

        // ── The mailbox talking to itself ─────────────────────────────
        // Worth flagging separately: this usually means a misconfigured
        // forward or a loop, which is a problem to fix rather than ignore.
        if ($mailbox && $from !== '') {
            $mailboxEmail = mb_strtolower(trim((string) $mailbox->email));
            if ($mailboxEmail !== '' && strpos($from, $mailboxEmail) !== false) {
                return $this->noise(self::SELF_SENT,
                    'Sent from the support mailbox itself - check for a mail loop or misconfigured forward.');
            }
        }

        // ── Bulk mail and mailing lists ───────────────────────────────
        // Deliberately NOT matching "Precedence: list": Gmail applies it to
        // ordinary person-to-person mail, and it appeared on genuine support
        // requests during testing. Only "bulk" is a safe signal.
        if ($this->hasHeader($headers, 'Precedence', 'bulk')
            || $this->hasHeader($headers, 'X-Campaign-Id')
            || $this->hasHeader($headers, 'X-Mailchimp-Campaign')) {
            return $this->noise(self::BULK, 'Bulk or newsletter message.');
        }

        // ── No-reply senders ──────────────────────────────────────────
        // A sender that cannot receive replies is not opening a support
        // conversation. Matched on the local part to avoid catching a real
        // person whose domain happens to contain "noreply".
        // The marker can appear anywhere in the local part, not just at its
        // start or end - e.g. google-workspace-alerts-noreply@google.com.
        if (preg_match('/[^@\s]*(no-?reply|do-?not-?reply|donotreply|mailer-daemon|postmaster)[^@\s]*@/i', $from)) {
            return $this->noise(self::SYSTEM, 'Sent from a no-reply address.');
        }

        return $this->notNoise();
    }

    /**
     * Match a header at the start of a line, optionally checking its value.
     * Substring matching is unsafe here - see the Auto-Submitted note above.
     */
    protected function hasHeader($headers, $name, $value = null)
    {
        $name = preg_quote($name, '/');

        if ($value === null) {
            return (bool) preg_match('/^'.$name.':/mi', $headers);
        }

        return (bool) preg_match('/^'.$name.':\s*[^\r\n]*'.preg_quote($value, '/').'/mi', $headers);
    }

    protected function noise($category, $reason)
    {
        return ['noise' => true, 'category' => $category, 'reason' => $reason];
    }

    protected function notNoise()
    {
        return ['noise' => false, 'category' => null, 'reason' => null];
    }

    /** Human label for a category. */
    public static function label($category)
    {
        $labels = [
            self::AUTO_REPLY => 'Automatic reply',
            self::BULK       => 'Bulk or newsletter',
            self::SYSTEM     => 'System notification',
            self::SELF_SENT  => 'Sent by this mailbox',
            self::BOUNCE     => 'Delivery failure',
        ];

        return $labels[$category] ?? 'Not a support request';
    }
}
