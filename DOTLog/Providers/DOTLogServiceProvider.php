<?php

namespace Modules\DOTLog\Providers;

use Illuminate\Support\ServiceProvider;
use Modules\DOTLog\Services\DotLog;

class DOTLogServiceProvider extends ServiceProvider
{
    protected $defer = false;
    public $moduleName = 'DOTLog';
    public $moduleNameLower = 'dotlog';

    public function boot()
    {
        $this->registerConfig();
        $this->registerViews();

        // Same posture as the Triage module: FreeScout boots every module's
        // provider on every request, so a fault here must degrade to a log
        // line, not a site-wide 500.
        try {
            $this->registerHooks();
        } catch (\Throwable $e) {
            \Log::error('[DOTLog] boot failed: '.$e->getMessage());
        }
    }

    public function register()
    {
        $this->app->register(RouteServiceProvider::class);
        $this->commands([
            \Modules\DOTLog\Console\LogPrune::class,
        ]);
    }

    protected function registerConfig()
    {
        $this->mergeConfigFrom(__DIR__.'/../Config/config.php', 'dotlog');
    }

    protected function registerViews()
    {
        $this->loadViewsFrom(__DIR__.'/../Resources/views', 'dotlog');
    }

    protected function registerHooks()
    {
        // Menu entry and the retention schedule are registered regardless of
        // the capture kill switch: history must stay readable, and the prune
        // must keep honouring the retention promise even when capture is off.
        \Eventy::addAction('menu.manage.append', function () {
            echo '<li><a href="'.route('dotlog.index').'">'
                .'<i class="glyphicon glyphicon-list-alt"></i> DOTLog</a></li>';
        });

        \Eventy::addFilter('schedule', function ($schedule) {
            $schedule->command('dotlog:prune')->dailyAt('03:40');
            return $schedule;
        });

        if (!config('dotlog.enabled')) {
            return;
        }

        $this->listenForThreads();
        $this->listenForConversationEvents();
        $this->listenForOutgoingMail();
    }

    /**
     * The spine of the timeline: every thread FreeScout creates - customer
     * message fetched, agent reply, note. Fires from the fetch cycle, the web
     * UI and the API alike.
     */
    protected function listenForThreads()
    {
        \Eventy::addAction('thread.created', function ($thread) {
            $this->guarded(function () use ($thread) {
                if (!$thread || !$thread->conversation) {
                    return;
                }

                $conversation = $thread->conversation;

                switch ((int) $thread->type) {
                    case (int) \App\Thread::TYPE_CUSTOMER:
                        $event = 'thread.customer';
                        $message = 'Customer message received'
                            .($conversation->threads()->where('id', '!=', $thread->id)->exists()
                                ? ' (reply on existing ticket)' : ' (new ticket)');
                        break;
                    case (int) \App\Thread::TYPE_MESSAGE:
                        $event = 'thread.agent';
                        $message = 'Agent reply added';
                        break;
                    case (int) \App\Thread::TYPE_NOTE:
                        $event = 'thread.note';
                        $message = 'Note added';
                        break;
                    default:
                        $event = 'thread.other';
                        $message = 'Thread of type '.$thread->type.' created';
                }

                DotLog::write($event, $message, [
                    'conversation' => $conversation,
                    'thread_id'    => $thread->id,
                    'user_id'      => $thread->created_by_user_id,
                    'context'      => [
                        'via'    => $thread->source_via,
                        'source' => $thread->source_type,
                    ],
                ]);
            });
        });
    }

    /**
     * Assignment and status transitions, via the same core events that drive
     * FreeScout's own notifications. A conversation that changes hands with
     * no matching entry here is exactly the bug DOTLog exists to expose: an
     * assignment made without firing the event, which core then never emails.
     */
    protected function listenForConversationEvents()
    {
        \Event::listen(\App\Events\ConversationUserChanged::class, function ($event) {
            $this->guarded(function () use ($event) {
                $conversation = $event->conversation;
                $assignee = $conversation->user_id
                    ? \App\User::find($conversation->user_id) : null;

                DotLog::write('conversation.assigned', sprintf(
                    'Assigned to %s by %s (assignment notification event fired)',
                    $assignee ? $assignee->getFullName() : 'nobody',
                    $event->user ? $event->user->getFullName() : 'system'
                ), [
                    'conversation' => $conversation,
                    'user_id'      => $conversation->user_id,
                    'context'      => [
                        'by' => $event->user ? $event->user->id : null,
                    ],
                ]);
            });
        });

        \Event::listen(\App\Events\ConversationStatusChanged::class, function ($event) {
            $this->guarded(function () use ($event) {
                $conversation = $event->conversation;

                DotLog::write('conversation.status',
                    'Status changed to '.$this->statusName($conversation->status), [
                    'conversation' => $conversation,
                    'context'      => ['status' => $conversation->status],
                ]);
            });
        });

        \Event::listen(\App\Events\CustomerCreatedConversation::class, function ($event) {
            $this->guarded(function () use ($event) {
                DotLog::write('conversation.created', 'Conversation created from customer email', [
                    'conversation' => $event->conversation,
                ]);
            });
        });
    }

    /**
     * Every email FreeScout hands to SMTP - replies to customers, agent
     * notifications, system mail. The question this answers is the one that
     * is otherwise painful: "was a notification for ticket X ever sent?"
     * An expected entry that is absent means the send failed or was never
     * attempted; the send error itself is in Manage → Logs.
     *
     * Recipients are logged, bodies never are.
     */
    protected function listenForOutgoingMail()
    {
        \Event::listen(\Illuminate\Mail\Events\MessageSent::class, function ($event) {
            $this->guarded(function () use ($event) {
                $message = $event->message;

                $to = $message->getTo() ? implode(', ', array_keys($message->getTo())) : '';
                $subject = (string) $message->getSubject();

                $typeHeader = $message->getHeaders()->get('X-FreeScout-Mail-Type');
                $type = $typeHeader ? $typeHeader->getFieldBody() : null;

                DotLog::write('mail.sent', sprintf(
                    'Email sent to %s%s: %s',
                    $to,
                    $type ? ' ('.$type.')' : '',
                    $subject
                ), [
                    'context' => [
                        'to'      => $to,
                        'subject' => $subject,
                        'type'    => $type,
                    ],
                ]);
            });
        });
    }

    /** Run a listener so that its failure can never break the pipeline it observes. */
    protected function guarded(callable $fn)
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            \Log::warning('[DOTLog] listener failed: '.$e->getMessage());
        }
    }

    protected function statusName($status)
    {
        $names = [
            \App\Conversation::STATUS_ACTIVE  => 'active',
            \App\Conversation::STATUS_PENDING => 'pending',
            \App\Conversation::STATUS_CLOSED  => 'closed',
            \App\Conversation::STATUS_SPAM    => 'spam',
        ];

        return $names[(int) $status] ?? (string) $status;
    }

    public function provides()
    {
        return [];
    }
}
