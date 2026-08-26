<?php

namespace Modules\DOTRatings\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\DOTRatings\Services\ClosureNotifier;

/**
 * Sends the closure email out of band.
 *
 * Queued because closing a ticket must stay fast and must not fail if SMTP is
 * slow or down: an agent pressing Close should never see an error because a
 * mail server was unreachable.
 *
 * Only ids are stored, never models. Laravel serialises job properties, and a
 * serialised Conversation would be a stale snapshot by the time the worker
 * runs - the notifier's state guards exist precisely to re-check the world as
 * it is now.
 */
class SendClosureEmail implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $conversationId;

    public $reason;

    /**
     * Swiftmailer's fwrite() can hang indefinitely on a bad connection, which
     * blocks the whole queue worker. Core sets the same timeout on its own
     * mail jobs for this reason.
     */
    public $timeout = 120;

    public $tries = 3;

    public function __construct($conversationId, $reason = ClosureNotifier::REASON_MANUAL)
    {
        $this->conversationId = (int) $conversationId;
        $this->reason         = (string) $reason;
    }

    public function handle()
    {
        (new ClosureNotifier())->send($this->conversationId, $this->reason);
    }

    public function failed(\Exception $e)
    {
        \Log::error('[Ratings] closure email job failed for conversation '
            .$this->conversationId.': '.$e->getMessage());
    }
}
