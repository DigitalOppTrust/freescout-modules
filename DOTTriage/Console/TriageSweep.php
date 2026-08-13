<?php

namespace Modules\DOTTriage\Console;

use Illuminate\Console\Command;
use Modules\DOTTriage\Services\AutoCloser;

/**
 * Close conversations that no longer need a human.
 *
 * Defaults to --dry so the first run shows what WOULD close without touching
 * anything. Auto-closing is the kind of thing you want to inspect before
 * trusting.
 */
class TriageSweep extends Command
{
    protected $signature = 'triage:sweep
                            {--apply : Actually close. Without this it is a dry run.}
                            {--noise : Only the backlog noise pass}
                            {--inactive : Only the inactivity pass}
                            {--resolved : Only the AI resolution pass}
                            {--limit=100 : Maximum conversations per pass}';

    protected $description = 'Close non-support, inactive or resolved conversations';

    public function handle()
    {
        $apply  = (bool) $this->option('apply');
        $limit  = (int) $this->option('limit');
        $closer = new AutoCloser(!$apply);

        // No pass flags means run them all.
        $only    = $this->option('noise') || $this->option('inactive') || $this->option('resolved');
        $doNoise = !$only || $this->option('noise');
        $doInact = !$only || $this->option('inactive');
        $doResol = !$only || $this->option('resolved');

        if (!$apply) {
            $this->warn('DRY RUN — nothing will be closed. Add --apply to act.');
        }
        $this->line('');

        $total = 0;

        if ($doNoise) {
            $this->info('Backlog noise (never triaged, matches the header rules)');
            $rows = $closer->sweepBacklogNoise($limit);
            foreach ($rows as $r) {
                $this->line(sprintf('  #%-5s %-40s %s', $r['number'],
                    mb_substr($r['subject'], 0, 38), $r['category']));
            }
            $this->line('  '.count($rows).' conversation(s)');
            $this->line('');
            $total += count($rows);
        }

        if ($doInact) {
            $this->info('Inactive (agent replied, customer never came back)');
            $rows = $closer->sweepInactive($limit);
            foreach ($rows as $r) {
                $this->line(sprintf('  #%-5s %-40s quiet %s', $r['number'],
                    mb_substr($r['subject'], 0, 38), $r['quiet']));
            }
            $this->line('  '.count($rows).' conversation(s)');
            $this->line('');
            $total += count($rows);
        }

        if ($doResol) {
            $this->info('Resolved (model judgement)');
            $rows = $closer->sweepResolved(min($limit, 25));
            if (isset($rows['skipped'])) {
                $this->line('  skipped: '.$rows['skipped']);
            } else {
                foreach ($rows as $r) {
                    $this->line(sprintf('  #%-5s %-34s %.2f  %s', $r['number'],
                        mb_substr($r['subject'], 0, 32), $r['confidence'],
                        mb_substr($r['reasoning'], 0, 50)));
                }
                $this->line('  '.count($rows).' conversation(s)');
                $total += count($rows);
            }
            $this->line('');
        }

        $this->info($apply
            ? $total.' conversation(s) closed.'
            : $total.' conversation(s) would be closed. Re-run with --apply.');

        return 0;
    }
}
