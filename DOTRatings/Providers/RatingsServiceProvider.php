<?php

namespace Modules\DOTRatings\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\DOTRatings\Jobs\SendClosureEmail;
use Modules\DOTRatings\Services\ClosureNotifier;

class RatingsServiceProvider extends ServiceProvider
{
    protected $defer = false;
    public $moduleName = 'DOTRatings';
    public $moduleNameLower = 'dotratings';

    public function boot()
    {
        $this->registerConfig();
        $this->registerViews();

        // Hooks are registered inside a try/catch so that a fault in module
        // code cannot take down the whole application. FreeScout boots every
        // module's provider on every request - an uncaught error here would
        // produce a 500 on every page, including the admin UI needed to
        // disable this module.
        try {
            $this->registerHooks();
        } catch (\Throwable $e) {
            \Log::error('[Ratings] boot failed: '.$e->getMessage());
        }
    }

    public function register()
    {
        $this->app->register(RouteServiceProvider::class);
    }

    protected function registerConfig()
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'dotratings');
    }

    protected function registerViews()
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'dotratings');
    }

    protected function registerHooks()
    {
        // The menu entry is registered regardless of the kill switch, so the
        // module can always be configured and diagnosed - including when it
        // is switched off.
        \Eventy::addAction('menu.manage.append', function () {
            echo '<li><a href="'.route('dotratings.settings').'">'
                .'<i class="glyphicon glyphicon-star"></i> Ratings</a></li>';
        });

        // Everything below emails customers, so it respects the kill switch.
        if (!config('dotratings.enabled')) {
            return;
        }

        // An agent closed a ticket. Both UI paths reach this: the status
        // dropdown (via Conversation::changeStatus) and replying with the
        // status set to Closed (fired directly by ConversationsController).
        //
        // The prev_status check is what makes this idempotent - saving a
        // closed conversation again must not send a second email.
        \Eventy::addAction('conversation.status_changed',
            function ($conversation, $user = null, $changed_on_reply = false, $prev_status = null) {
                try {
                    $this->onStatusChanged($conversation, $prev_status);
                } catch (\Throwable $e) {
                    \Log::error('[Ratings] status_changed handler failed: '.$e->getMessage());
                }
            }, 20, 4);

        // Triage closed a ticket automatically. It assigns status directly
        // rather than going through changeStatus(), so the core hook above
        // never fires for it - hence this separate signal.
        \Eventy::addAction('dottriage.auto_closed',
            function ($conversation, $reason = null, $explanation = '') {
                try {
                    $this->onAutoClosed($conversation, $reason);
                } catch (\Throwable $e) {
                    \Log::error('[Ratings] auto_closed handler failed: '.$e->getMessage());
                }
            }, 20, 3);
    }

    /** A human closed a conversation. */
    protected function onStatusChanged($conversation, $prevStatus)
    {
        if (!$conversation) {
            return;
        }

        $closed = (int) \App\Conversation::STATUS_CLOSED;

        if ((int) $conversation->status !== $closed) {
            return;
        }

        // Already closed before this change - nothing actually happened.
        if ($prevStatus !== null && (int) $prevStatus === $closed) {
            return;
        }

        SendClosureEmail::dispatch($conversation->id, ClosureNotifier::REASON_MANUAL);
    }

    /**
     * Triage closed a conversation.
     *
     * Noise closures - newsletters, auto-replies, bounces, mail the address
     * sent to itself - are never emailed. Replying to a spammer confirms the
     * address is real, and asking a mailing list to rate our support is
     * absurd. This is a hard rule, not a setting.
     */
    protected function onAutoClosed($conversation, $reason)
    {
        if (!$conversation) {
            return;
        }

        $emailable = [
            'inactivity' => ClosureNotifier::REASON_INACTIVITY,
            'resolved'   => ClosureNotifier::REASON_RESOLVED,
        ];

        if (!isset($emailable[$reason])) {
            \Log::info('[Ratings] not emailing conversation '.$conversation->id
                .': closed as '.($reason ?: 'unknown reason'));
            return;
        }

        SendClosureEmail::dispatch($conversation->id, $emailable[$reason]);
    }

    public function provides()
    {
        return [];
    }
}
