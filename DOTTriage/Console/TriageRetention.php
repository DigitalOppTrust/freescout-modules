<?php

namespace Modules\DOTTriage\Console;

use Illuminate\Console\Command;
use Modules\DOTTriage\Services\RetentionSweeper;
use Modules\DOTTriage\Services\Settings;

/**
 * Permanently delete resolved conversations past the retention period.
 *
 * Defaults to --dry like triage:sweep, but the stakes differ: a wrong close
 * self-corrects when the customer replies, a wrong delete is gone. Hence the
 * dry run shows exactly which tickets would go, and --apply additionally
 * requires retention to be enabled in Manage → Triage.
 */
class TriageRetention extends Command
{
    protected $signature = 'triage:retention
                            {--apply : Actually delete. Without this it is a dry run.}
                            {--limit= : Maximum conversations this run (capped by the setting)}';

    protected $description = 'Permanently delete resolved conversations past the retention period';

    public function handle()
    {
        $apply   = (bool) $this->option('apply');
        $limit   = $this->option('limit') !== null ? (int) $this->option('limit') : null;
        $enabled = (bool) Settings::get('retention_enabled');
        $months  = (int) Settings::get('retention_months');
        $sweeper = new RetentionSweeper(!$apply);

        $this->info(sprintf(
            'Retention: %s, period %d months (closed before %s).',
            $enabled ? 'enabled' : 'DISABLED',
            $months,
            substr(RetentionSweeper::cutoff(), 0, 10)
        ));

        if (!$apply) {
            $this->warn('DRY RUN — nothing will be deleted. Add --apply to act.');
            $this->line('');

            $rows = $sweeper->collect($limit);
            $this->render($rows);

            $this->line('');
            $this->info(count($rows).' conversation(s) would be permanently deleted.'
                .($enabled ? '' : ' Retention is switched off, so --apply would do nothing.'));

            return 0;
        }

        $rows = $sweeper->sweep($limit);

        if (isset($rows['skipped'])) {
            $this->warn('Skipped: '.$rows['skipped']);

            return 0;
        }

        $this->line('');
        $this->render($rows);
        $this->line('');
        $this->info(count($rows).' conversation(s) permanently deleted, attachments included.');

        return 0;
    }

    protected function render(array $rows)
    {
        foreach ($rows as $r) {
            $this->line(sprintf('  #%-5s %-45s closed %s', $r['number'],
                mb_substr($r['subject'], 0, 43), $r['closed']));
        }
    }
}
