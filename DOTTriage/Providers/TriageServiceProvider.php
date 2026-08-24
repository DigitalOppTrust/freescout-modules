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
        $this->commands([
            \Modules\DOTTriage\Console\TriageRun::class,
            \Modules\DOTTriage\Console\TriageSweep::class,
            \Modules\DOTTriage\Console\TriageRetention::class,
        ]);
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

        // The Resolved folder is registered regardless of the kill switch:
        // tickets already in it must stay visible while triage is off.
        \Modules\DOTTriage\Services\ResolvedFolder::register();

        // Consistency guards, also outside the kill switch: a ticket that
        // stops being closed must leave Resolved however it was reopened.
        \Eventy::addAction('conversation.status_changed', function ($conversation) {
            try {
                if (!$conversation->isClosed()) {
                    \Modules\DOTTriage\Services\ResolvedFolder::remove($conversation);
                }
            } catch (\Throwable $e) {
                \Log::error('[Triage] status_changed resolved-folder cleanup failed: '.$e->getMessage());
            }
        });

        // FreeScout reopens a closed conversation on a customer reply without
        // firing status_changed, so watch threads too. Priority 30 runs this
        // after maybeQueueTriage (default 20), which restores the closed
        // status when the "reply" was only an auto-responder - in that case
        // the ticket stays in Resolved.
        \Eventy::addAction('thread.created', function ($thread) {
            try {
                if (!$thread || (int) $thread->type !== (int) \App\Thread::TYPE_CUSTOMER) {
                    return;
                }
                $conversation = $thread->conversation;
                if ($conversation && !$conversation->isClosed()) {
                    \Modules\DOTTriage\Services\ResolvedFolder::remove($conversation);
                }
            } catch (\Throwable $e) {
                \Log::error('[Triage] thread.created resolved-folder cleanup failed: '.$e->getMessage());
            }
        }, 30);

        // Deleting a resolved ticket takes it out of the folder, keeping the
        // sidebar counter honest - the pivot count ignores state.
        \Eventy::addAction('conversation.state_changed', function ($conversation) {
            try {
                if ((int) $conversation->state === (int) \App\Conversation::STATE_DELETED) {
                    \Modules\DOTTriage\Services\ResolvedFolder::remove($conversation);
                }
            } catch (\Throwable $e) {
                \Log::error('[Triage] state_changed resolved-folder cleanup failed: '.$e->getMessage());
            }
        });

        // Manual Resolved control: an entry at the top of the More Actions
        // menu, and - because that is where people look first - a "Resolved"
        // entry added to the status dropdown. Part of the folder feature, so
        // also outside the kill switch.
        \Eventy::addAction('conversation.prepend_action_buttons', function ($conversation, $mailbox) {
            try {
                if ((int) $conversation->state !== (int) \App\Conversation::STATE_PUBLISHED) {
                    return;
                }

                $item = function ($route, $label, $formClass = '') {
                    echo '<li>'
                        .'<a href="#" role="button" onclick="this.parentNode.querySelector(\'form\').submit(); return false;">'
                        .'<i class="glyphicon glyphicon-ok"></i> '.e($label).'</a>'
                        .'<form'.($formClass ? ' class="'.$formClass.'"' : '').' method="POST" action="'.e($route).'" style="display:none;">'
                        .'<input type="hidden" name="_token" value="'.e(csrf_token()).'">'
                        .'</form>'
                        .'</li>';
                };

                if (\Modules\DOTTriage\Services\ResolvedFolder::contains($conversation)) {
                    $item(route('triage.unresolve', ['id' => $conversation->id]), __('Remove from Resolved'));
                    return;
                }

                // Deliberately NOT in the status dropdown: Resolved is not a
                // real status (the ticket becomes Closed underneath), and a
                // pseudo-entry there that then displays as "Closed" reads as
                // a bug. Folder membership is an action, not a status.
                $item(route('triage.resolve', ['id' => $conversation->id]), __('Mark as resolved'), 'triage-resolve-form');
            } catch (\Throwable $e) {
                \Log::error('[Triage] resolve menu item failed: '.$e->getMessage());
            }
        }, 20, 2);

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

        if (class_exists(\Modules\DOTLog\Services\DotLog::class)) {
            \Modules\DOTLog\Services\DotLog::write('triage.queued',
                'Queued for triage', ['conversation' => $conversation]);
        }
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
