<?php

namespace Modules\Triage\Console;

use Illuminate\Console\Command;
use Modules\Triage\Jobs\TriageConversation;
use Modules\Triage\Services\TriageEngine;

/**
 * Triage a conversation by hand.
 *
 * Two uses: testing routing against real tickets before switching the module
 * on, and picking up conversations that arrived while triage was disabled.
 */
class TriageRun extends Command
{
    protected $signature = 'triage:run
                            {conversation? : Conversation id. Omit to process all unassigned.}
                            {--dry : Show the decision without assigning or noting}
                            {--limit=10 : Maximum conversations to process in bulk mode}';

    protected $description = 'Run triage against one conversation or all unassigned ones';

    public function handle()
    {
        $id = $this->argument('conversation');

        if ($id) {
            $conversations = \App\Conversation::where('id', (int) $id)->get();
            if ($conversations->isEmpty()) {
                $this->error('Conversation '.$id.' not found.');
                return 1;
            }
        } else {
            $conversations = \App\Conversation::whereNull('user_id')
                ->where('status', \App\Conversation::STATUS_ACTIVE)
                ->orderBy('id', 'desc')
                ->limit((int) $this->option('limit'))
                ->get();

            if ($conversations->isEmpty()) {
                $this->info('No unassigned active conversations.');
                return 0;
            }
        }

        $dry = (bool) $this->option('dry');
        $this->info(($dry ? 'DRY RUN — ' : '').'Processing '.$conversations->count().' conversation(s)');
        $this->line('');

        foreach ($conversations as $conversation) {
            $this->line('#'.$conversation->number.'  '.mb_substr((string) $conversation->subject, 0, 60));

            if ($dry) {
                $decision = (new TriageEngine())->triage($conversation);

                $this->line('   method:     '.$decision->method);
                $this->line('   suggested:  '.($decision->suggested_user_id
                    ? ($decision->suggestedUser ? $decision->suggestedUser->getFullName() : $decision->suggested_user_id)
                    : 'nobody'));
                $this->line('   confidence: '.($decision->confidence !== null
                    ? number_format($decision->confidence, 2) : '—'));
                $this->line('   reasoning:  '.$decision->reasoning);
                if ($decision->error) {
                    $this->error('   error:      '.$decision->error);
                }

                // A dry run must not leave a decision row behind, or it would
                // pollute the accuracy figures and block later real triage.
                $decision->delete();
            } else {
                TriageConversation::dispatch($conversation->id);
                $this->line('   queued');
            }

            $this->line('');
        }

        if (!$dry) {
            $this->info('Queued. The FreeScout queue worker will process these shortly.');
        }

        return 0;
    }
}
