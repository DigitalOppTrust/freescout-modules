<?php

namespace Modules\DOTTriage\Console;

use Illuminate\Console\Command;
use Modules\DOTTriage\Services\Escalator;

/**
 * Nudge, then transfer, tickets whose assignee has gone quiet.
 *
 * Defaults to a dry run, like triage:sweep: the first thing anyone wants to
 * know about an escalation rule is who it would chase right now.
 */
class TriageEscalate extends Command
{
    protected $signature = 'triage:escalate
                            {--apply : Actually notify and transfer. Without this it is a dry run.}
                            {--seed : Start clocks for tickets already assigned and unanswered, then stop}
                            {--limit=100 : Maximum escalations to act on per run}';

    protected $description = 'Escalate assigned tickets that have had no reply within their window';

    public function handle()
    {
        $apply = (bool) $this->option('apply');

        if (!$apply) {
            $this->warn('DRY RUN — nothing will be sent or reassigned. Add --apply to act.');
        }

        if ($this->option('seed')) {
            $rows = (new Escalator())->seed(!$apply);
            foreach ($rows as $r) {
                $this->line(sprintf('  clock   #%-5s %-34s %s, unanswered since %s',
                    $r['number'], mb_substr((string) $r['subject'], 0, 32), $r['assignee'] ?: '?', $r['since']));
            }
            $this->line('');
            $this->info($apply
                ? count($rows).' clock(s) started. The next sweep acts on any already past their window.'
                : count($rows).' clock(s) would start. Re-run with --apply.');
            return 0;
        }

        $rows = (new Escalator())->sweep(!$apply, (int) $this->option('limit'));

        foreach ($rows as $r) {
            $this->line(sprintf('  %-8s #%-5s %-34s %s -> %s  (quiet %s, window %s, hop %d)',
                $r['action'], $r['number'], mb_substr((string) $r['subject'], 0, 32),
                $r['assignee'] ?: '?', $r['target'], $r['elapsed'], $r['window'], $r['depth']));
        }

        $this->line('');
        $this->info($apply
            ? count($rows).' escalation(s) acted on.'
            : count($rows).' escalation(s) due. Re-run with --apply.');

        return 0;
    }
}
