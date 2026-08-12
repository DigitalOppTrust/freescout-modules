<?php

namespace Modules\DOTTriage\Providers;

use Illuminate\Support\ServiceProvider;

class TriageServiceProvider extends ServiceProvider
{
    protected $defer = false;
    public $moduleName = 'Triage';
    public $moduleNameLower = 'triage';

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
            \Log::error('[Triage] boot failed: '.$e->getMessage());
        }
    }

    public function register()
    {
        $this->app->register(RouteServiceProvider::class);
        $this->commands([\Modules\DOTTriage\Console\TriageRun::class]);
    }

    protected function registerConfig()
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'triage');
    }

    protected function registerViews()
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'triage');
    }

    protected function registerHooks()
    {
        // The settings link is registered regardless of the kill switch, so
        // the module can always be configured and diagnosed - including when
        // it is switched off.
        \Eventy::addFilter('settings.sections', function ($sections) {
            $sections['triage'] = ['title' => 'Triage', 'icon' => 'random', 'order' => 400];
            return $sections;
        });

        \Eventy::addFilter('settings.section_settings', function ($settings, $section) {
            return $settings;
        }, 20, 2);

        // Add a Triage entry to the Manage menu.
        \Eventy::addAction('menu.manage.append', function () {
            echo '<li><a href="'.route('triage.settings').'">'
                .'<i class="glyphicon glyphicon-random"></i> Triage</a></li>';
        });

        // Everything below acts on tickets, so it respects the kill switch.
        if (!config('triage.enabled')) {
            return;
        }

        // A new customer message arrived. Queue triage for it if the
        // conversation has nobody assigned.
        //
        // thread.created fires for every thread including agent replies and
        // notes, so the type check matters - without it the module would
        // triage its own notes.
        \Eventy::addAction('thread.created', function ($thread) {
            try {
                $this->maybeQueueTriage($thread);
            } catch (\Throwable $e) {
                \Log::error('[Triage] thread.created handler failed: '.$e->getMessage());
            }
        });

        // A human reassigned a conversation. If triage had suggested someone
        // else, record that as an override - this is the accuracy signal.
        \Eventy::addAction('conversation.user_changed', function ($conversation, $user = null) {
            try {
                $this->recordOverride($conversation, $user);
            } catch (\Throwable $e) {
                \Log::error('[Triage] user_changed handler failed: '.$e->getMessage());
            }
        }, 20, 2);
    }

    /**
     * Decide whether a newly created thread should trigger triage.
     *
     * Scope (agreed): new customer conversations, plus customer replies on
     * conversations that have no assignee - e.g. where the previous owner
     * has left. Replies on assigned conversations are deliberately ignored:
     * re-triaging every message is expensive and yanks tickets between
     * agents mid-thread.
     */
    protected function maybeQueueTriage($thread)
    {
        if (!$thread || (int) $thread->type !== (int) \App\Thread::TYPE_CUSTOMER) {
            return;
        }

        $conversation = $thread->conversation;
        if (!$conversation) {
            return;
        }

        // An auto-reply arriving on an existing ticket must not reopen it or
        // pull an assignee back in - a mail server saying "I'm on leave"
        // should never reactivate a closed conversation. Note it and stop.
        $noise = (new \Modules\DOTTriage\Services\NoiseDetector())
            ->classify($thread, $conversation->mailbox);

        if ($noise['noise'] && $this->isReplyToExisting($conversation, $thread)) {
            $this->noteWithoutReopening($conversation, $noise);
            return;
        }

        if ($conversation->user_id) {
            return;
        }

        // Do not re-triage a conversation already decided on.
        $already = \Modules\DOTTriage\Entities\TriageDecision::where('conversation_id', $conversation->id)
            ->whereNull('error')
            ->exists();
        if ($already) {
            return;
        }

        // thread.created can fire more than once for the same thread, and the
        // decision row does not exist until the job runs - so the check above
        // cannot catch a duplicate dispatched microseconds later. An atomic
        // cache lock closes that window.
        $lock = 'triage.queued.'.$conversation->id;
        if (!\Cache::add($lock, 1, 300)) {
            return;
        }

        \Modules\DOTTriage\Jobs\TriageConversation::dispatch($conversation->id);
    }

    /** Is this thread a later message on a conversation that already existed? */
    protected function isReplyToExisting($conversation, $thread)
    {
        return $conversation->threads()
            ->where('id', '!=', $thread->id)
            ->exists();
    }

    /**
     * Note an auto-reply on a conversation without changing its status or
     * assignment. Restores whatever FreeScout may already have changed.
     */
    protected function noteWithoutReopening($conversation, $noise)
    {
        $original = $conversation->getOriginal();

        $body = '<strong>Triage</strong><br>'
            .e(\Modules\DOTTriage\Services\NoiseDetector::label($noise['category'])
               .' received. '.$noise['reason']
               .' Ticket status and assignment left unchanged.');

        try {
            $note = new \App\Thread();
            $note->conversation_id = $conversation->id;
            $note->type    = \App\Thread::TYPE_NOTE;
            $note->status  = \App\Thread::STATUS_NOCHANGE;
            $note->state   = \App\Thread::STATE_PUBLISHED;
            $note->body    = $body;
            $note->source_via  = \App\Thread::PERSON_USER;
            $note->source_type = \App\Thread::SOURCE_TYPE_WEB;
            $note->customer_id = $conversation->customer_id;
            $note->save();
        } catch (\Throwable $e) {
            \Log::warning('[Triage] could not note auto-reply on conversation '
                .$conversation->id.': '.$e->getMessage());
        }

        // FreeScout reopens a closed conversation when a customer message
        // arrives. Put the status back if it did.
        if (isset($original['status']) && $conversation->status != $original['status']) {
            $conversation->status = $original['status'];
            $conversation->save();
        }

        \Log::info('[Triage] noted '.$noise['category'].' on existing conversation '
            .$conversation->id.' without reopening');
    }

    /**
     * Record that a human moved a ticket triage had routed.
     *
     * Only counts when the new assignee differs from what triage chose -
     * confirming the suggestion is not an override.
     */
    protected function recordOverride($conversation, $user)
    {
        if (!$conversation || !$conversation->user_id) {
            return;
        }

        $decision = \Modules\DOTTriage\Entities\TriageDecision::where('conversation_id', $conversation->id)
            ->whereNotNull('suggested_user_id')
            ->whereNull('overridden_by_user_id')
            ->orderBy('id', 'desc')
            ->first();

        if (!$decision) {
            return;
        }

        if ((int) $decision->suggested_user_id === (int) $conversation->user_id) {
            return;
        }

        $decision->overridden_by_user_id = $user ? $user->id : (auth()->id() ?: null);
        $decision->overridden_to_user_id = $conversation->user_id;
        $decision->overridden_at = now();
        $decision->save();
    }

    public function provides()
    {
        return [];
    }
}
