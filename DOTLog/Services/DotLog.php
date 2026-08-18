<?php

namespace Modules\DOTLog\Services;

use Modules\DOTLog\Entities\LogEntry;

/**
 * The one write API. Other modules call this directly, guarded by
 * class_exists() so they keep working when DOTLog is not installed:
 *
 *     if (class_exists(\Modules\DOTLog\Services\DotLog::class)) {
 *         \Modules\DOTLog\Services\DotLog::write('triage.assigned', '...', [
 *             'conversation' => $conversation,
 *         ]);
 *     }
 *
 * Writing a log line must never break the operation being logged, so every
 * failure path here swallows the error and falls back to laravel.log.
 */
class DotLog
{
    /**
     * @param string $event   dotted key, e.g. 'triage.assigned'
     * @param string $message one human-readable line for the log view
     * @param array  $data    optional: conversation (model or id), mailbox_id,
     *                        thread_id, user_id, level, context (array)
     */
    public static function write($event, $message, array $data = [])
    {
        try {
            if (!config('dotlog.enabled')) {
                return;
            }

            $level = $data['level'] ?? 'info';
            if (!in_array($level, ['info', 'warning', 'error'])) {
                $level = 'info';
            }

            $conversationId = null;
            $mailboxId = isset($data['mailbox_id']) ? (int) $data['mailbox_id'] : null;

            if (isset($data['conversation'])) {
                $c = $data['conversation'];
                if (is_object($c)) {
                    $conversationId = (int) $c->id;
                    $mailboxId = $mailboxId ?: (int) $c->mailbox_id;
                } else {
                    $conversationId = (int) $c;
                }
            } elseif (isset($data['conversation_id'])) {
                $conversationId = (int) $data['conversation_id'];
            }

            LogEntry::create([
                'event'           => mb_substr($event, 0, 40),
                'level'           => $level,
                'conversation_id' => $conversationId,
                'mailbox_id'      => $mailboxId,
                'thread_id'       => isset($data['thread_id']) ? (int) $data['thread_id'] : null,
                'user_id'         => isset($data['user_id']) ? (int) $data['user_id'] : null,
                'message'         => mb_substr($message, 0, 998),
                'context'         => !empty($data['context']) ? $data['context'] : null,
                'created_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            \Log::warning('[DOTLog] could not write log entry '.$event.': '.$e->getMessage());
        }
    }
}
