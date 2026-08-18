<?php

namespace Modules\DOTLog\Console;

use Illuminate\Console\Command;
use Modules\DOTLog\Entities\LogEntry;
use Modules\DOTLog\Services\Settings;

/**
 * Delete log entries past the retention period.
 *
 * Scheduled daily by the service provider, so retention needs no server
 * setup. Unlike triage:retention this applies by default rather than
 * dry-running: it deletes only this module's own debugging records, never
 * ticket data, so the stakes do not warrant the extra ceremony.
 */
class LogPrune extends Command
{
    protected $signature = 'dotlog:prune
                            {--days= : Override the configured retention period}
                            {--dry : Report what would be deleted without deleting}';

    protected $description = 'Delete DOTLog entries older than the retention period';

    public function handle()
    {
        $days = $this->option('days') !== null
            ? max(1, (int) $this->option('days'))
            : (int) Settings::get('retention_days');

        $cutoff = now()->subDays($days);

        $count = LogEntry::where('created_at', '<', $cutoff)->count();

        $this->info(sprintf(
            'Retention %d days — %d entr%s older than %s.',
            $days, $count, $count === 1 ? 'y' : 'ies', $cutoff->toDateTimeString()
        ));

        if ($this->option('dry')) {
            $this->warn('DRY RUN — nothing deleted.');

            return 0;
        }

        // Chunked so a long backlog cannot hold a table lock for the whole
        // delete on a busy instance.
        $deleted = 0;
        do {
            $batch = LogEntry::where('created_at', '<', $cutoff)
                ->orderBy('id')
                ->limit(5000)
                ->delete();
            $deleted += $batch;
        } while ($batch > 0);

        $this->info($deleted.' entr'.($deleted === 1 ? 'y' : 'ies').' deleted.');

        return 0;
    }
}
