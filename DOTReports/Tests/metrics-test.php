<?php
/**
 * Metric correctness tests for the Reports module.
 *
 * Runs the real report services against an in-memory SQLite database seeded
 * with fixtures whose correct answers are known by hand. The point is not
 * coverage for its own sake - it is to prove the schema traps documented in
 * ResolutionReport are actually handled:
 *
 *   - a conversation closed WITHOUT closed_at is still timed, via its
 *     status-change line item
 *   - a closed conversation with no usable timestamp is REPORTED as
 *     untimeable rather than silently dropped
 *   - internal notes never count as a first response
 *   - spam, deleted and imported conversations are excluded from message
 *     counts, not just conversation counts
 *
 * Usage - needs a FreeScout checkout for its vendor/ directory:
 *
 *     FREESCOUT_PATH=/var/www/freescout php DOTReports/Tests/metrics-test.php
 *
 * Exits non-zero if any assertion fails, so it can gate a deploy.
 */

$core = getenv('FREESCOUT_PATH') ?: '/var/www/freescout';

if (!is_file($core.'/vendor/autoload.php')) {
    fwrite(STDERR, "Cannot find vendor/autoload.php under $core\n"
        ."Set FREESCOUT_PATH to a FreeScout checkout.\n");
    exit(2);
}

$modules = dirname(__DIR__, 2);

require $core.'/vendor/autoload.php';

use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Container\Container;
use Illuminate\Events\Dispatcher;

// ── Boot a minimal Laravel-ish container ───────────────────────────────
$container = new Container();
Container::setInstance($container);

$capsule = new Capsule($container);
$capsule->addConnection([
    'driver'   => 'sqlite',
    'database' => ':memory:',
    'prefix'   => '',
]);
$capsule->setEventDispatcher(new Dispatcher($container));
$capsule->setAsGlobal();
$capsule->bootEloquent();

$container->instance('db', $capsule->getDatabaseManager());

// config() helper backing store
$GLOBALS['__config'] = [
    'reports.min_sample'          => 20,
    'reports.default_days'        => 30,
    'reports.table_limit'         => 50,
    'reports.cost_per_mtok_in'    => 1.00,
    'reports.cost_per_mtok_out'   => 5.00,
    'reports.confidence_buckets'  => [0.0, 0.5, 0.6, 0.7, 0.8, 0.9],
    'reports.backlog_buckets'     => [1, 3, 7, 14, 30],
    'triage.enabled'              => true,
    'triage.weekend_days'         => [6, 7],
    'triage.confidence_threshold' => 0.75,
    'triage.daily_call_limit'     => 500,
];

// ── Stubs for the framework surface the services touch ─────────────────
require __DIR__.'/stubs.php';

// ── Schema ─────────────────────────────────────────────────────────────
$s = Capsule::schema();

$s->create('conversations', function ($t) {
    $t->increments('id');
    $t->integer('number')->default(0);
    $t->integer('threads_count')->default(0);
    $t->tinyInteger('type')->default(1);
    $t->integer('folder_id')->nullable();
    $t->tinyInteger('status')->default(1);
    $t->tinyInteger('state')->default(2);
    $t->string('subject')->nullable();
    $t->boolean('imported')->default(0);
    $t->integer('mailbox_id')->default(1);
    $t->integer('user_id')->nullable();
    $t->integer('customer_id')->nullable();
    $t->integer('closed_by_user_id')->nullable();
    $t->timestamp('closed_at')->nullable();
    $t->timestamp('last_reply_at')->nullable();
    $t->timestamps();
});

$s->create('threads', function ($t) {
    $t->increments('id');
    $t->integer('conversation_id');
    $t->integer('user_id')->nullable();
    $t->tinyInteger('type');
    $t->tinyInteger('status')->default(1);
    $t->tinyInteger('state')->default(2);
    $t->tinyInteger('action_type')->nullable();
    $t->text('body')->nullable();
    $t->boolean('imported')->default(0);
    $t->integer('created_by_user_id')->nullable();
    $t->integer('created_by_customer_id')->nullable();
    $t->timestamps();
});

$s->create('users', function ($t) {
    $t->increments('id');
    $t->string('first_name');
    $t->string('last_name');
    $t->tinyInteger('status')->default(1);
});

$s->create('mailboxes', function ($t) {
    $t->increments('id');
    $t->string('name');
});

$s->create('triage_decisions', function ($t) {
    $t->increments('id');
    $t->integer('conversation_id');
    $t->integer('mailbox_id')->nullable();
    $t->integer('suggested_user_id')->nullable();
    $t->decimal('confidence', 4, 3)->nullable();
    $t->text('reasoning')->nullable();
    $t->string('method', 20)->default('model');
    $t->string('model', 64)->nullable();
    $t->integer('tokens_in')->nullable();
    $t->integer('tokens_out')->nullable();
    $t->integer('duration_ms')->nullable();
    $t->boolean('applied')->default(0);
    $t->integer('overridden_by_user_id')->nullable();
    $t->integer('overridden_to_user_id')->nullable();
    $t->timestamp('overridden_at')->nullable();
    $t->text('error')->nullable();
    $t->string('noise_category', 20)->nullable();
    $t->boolean('closed')->default(0);
    $t->string('close_reason', 20)->nullable();
    $t->integer('reopened_by_user_id')->nullable();
    $t->timestamp('reopened_at')->nullable();
    $t->timestamps();
});

$s->create('triage_escalations', function ($t) {
    $t->increments('id');
    $t->integer('conversation_id');
    $t->integer('assigned_user_id');
    $t->timestamp('clock_started_at')->nullable();
    $t->integer('escalate_after_minutes')->default(1440);
    $t->integer('escalate_to_user_id')->nullable();
    $t->timestamp('notified_at')->nullable();
    $t->timestamp('reassigned_at')->nullable();
    $t->integer('depth')->default(0);
    $t->string('chain')->nullable();
    $t->boolean('active')->default(1);
    $t->timestamps();
});

// ── Fixtures ───────────────────────────────────────────────────────────
// Anchor everything to a fixed Monday so weekend arithmetic is predictable.
$mon = '2026-08-03';  // Monday
$tue = '2026-08-04';

Capsule::table('mailboxes')->insert(['id' => 1, 'name' => 'Support']);
Capsule::table('users')->insert([
    ['id' => 1, 'first_name' => 'Ann',  'last_name' => 'Adams', 'status' => 1],
    ['id' => 2, 'first_name' => 'Ben',  'last_name' => 'Brown', 'status' => 1],
]);

function conv($id, $created, $opts = []) {
    Capsule::table('conversations')->insert(array_merge([
        'id'         => $id,
        'number'     => $id,
        'type'       => 1,
        'status'     => 1,
        'state'      => 2,
        'imported'   => 0,
        'mailbox_id' => 1,
        'created_at' => $created,
        'updated_at' => $created,
    ], $opts));
}

function thread($convId, $type, $created, $opts = []) {
    Capsule::table('threads')->insert(array_merge([
        'conversation_id' => $convId,
        'type'            => $type,
        'status'          => 1,
        'state'           => 2,
        'imported'        => 0,
        'created_at'      => $created,
        'updated_at'      => $created,
    ], $opts));
}

// C1: normal - closed WITH closed_at. Created Mon 09:00, replied 10:00,
//     closed 11:00. Resolution 120 min, FRT 60 min.
conv(1, "$mon 09:00:00", [
    'status'            => 3,
    'closed_at'         => "$mon 11:00:00",
    'closed_by_user_id' => 1,
    'user_id'           => 1,
]);
thread(1, 1, "$mon 09:00:00");                                    // customer
thread(1, 2, "$mon 10:00:00", ['created_by_user_id' => 1]);       // agent reply
thread(1, 4, "$mon 11:00:00", ['action_type' => 1, 'status' => 3, 'created_by_user_id' => 1]);

// C2: THE TRAP - closed, but closed_at is NULL. Must be timed from the
//     line item at Mon 12:00 => 180 min.
conv(2, "$mon 09:00:00", ['status' => 3, 'closed_at' => null, 'user_id' => 1]);
thread(2, 1, "$mon 09:00:00");
thread(2, 2, "$mon 09:30:00", ['created_by_user_id' => 1]);       // FRT 30 min
thread(2, 4, "$mon 12:00:00", ['action_type' => 1, 'status' => 3, 'created_by_user_id' => 1]);

// C3: closed with NO closed_at AND no line item - untimeable.
conv(3, "$mon 09:00:00", ['status' => 3, 'closed_at' => null]);
thread(3, 1, "$mon 09:00:00");

// C4: note-only. A NOTE must NOT count as a first response.
conv(4, "$mon 09:00:00", ['status' => 1]);
thread(4, 1, "$mon 09:00:00");
thread(4, 3, "$mon 09:15:00", ['created_by_user_id' => 2]);       // note

// C5: spam - excluded everywhere, INCLUDING its messages. The agent reply
//     here must not inflate Ann's reply count or the outbound total.
conv(5, "$mon 09:00:00", ['status' => 4]);
thread(5, 1, "$mon 09:00:00");
thread(5, 2, "$mon 09:20:00", ['created_by_user_id' => 1]);

// C6: deleted - excluded everywhere.
conv(6, "$mon 09:00:00", ['state' => 3]);

// C7: imported - excluded everywhere.
conv(7, "$mon 09:00:00", ['imported' => 1]);

// C8: reopened - closed then reopened.
conv(8, "$tue 09:00:00", ['status' => 1]);
thread(8, 1, "$tue 09:00:00");
thread(8, 4, "$tue 10:00:00", ['action_type' => 1, 'status' => 3]);  // closed
thread(8, 4, "$tue 11:00:00", ['action_type' => 1, 'status' => 1]);  // reopened

// C9: THE OPPOSITE TRAP - an auto-reply Triage closed 20 seconds after it
//     arrived. closed_at is stamped, closed_by_user_id is not. It must NOT
//     enter the resolution median (which it would drag to ~2 min), but must
//     be counted and reported as an automatic close.
conv(9, "$mon 09:00:00", [
    'status'    => 3,
    'closed_at' => "$mon 09:00:20",
]);
thread(9, 1, "$mon 09:00:00");
thread(9, 3, "$mon 09:00:20", ['body' => 'Closed automatically — Auto-reply.']);

// C10: swept closed for inactivity 3 days later, again with no user. Also
//      excluded from the headline, but reported under a different reason.
conv(10, "$mon 09:00:00", [
    'status'    => 3,
    'closed_at' => '2026-08-06 09:00:00',
]);
thread(10, 1, "$mon 09:00:00");
thread(10, 2, "$mon 09:10:00", ['created_by_user_id' => 2]);

// C11: Triage closed it as noise, Ann reopened and closed it herself at
//      Mon 13:00. closed_by_user_id is set, so it is hers: 240 min.
conv(11, "$mon 09:00:00", [
    'status'            => 3,
    'closed_at'         => "$mon 13:00:00",
    'closed_by_user_id' => 1,
]);
thread(11, 1, "$mon 09:00:00");
thread(11, 2, "$mon 12:00:00", ['created_by_user_id' => 1]);

// Triage decisions: 4 applied (1 overridden), 1 suggestion, 1 error.
$dec = function ($conv, $opts) use ($mon) {
    Capsule::table('triage_decisions')->insert(array_merge([
        'conversation_id' => $conv,
        'mailbox_id'      => 1,
        'method'          => 'model',
        'model'           => 'claude-haiku-4-5',
        'tokens_in'       => 500,
        'tokens_out'      => 50,
        'duration_ms'     => 1200,
        'applied'         => 1,
        'created_at'      => "$mon 09:01:00",
        'updated_at'      => "$mon 09:01:00",
    ], $opts));
};

$dec(1, ['suggested_user_id' => 1, 'confidence' => 0.95]);
$dec(2, ['suggested_user_id' => 1, 'confidence' => 0.92]);
$dec(3, ['suggested_user_id' => 2, 'confidence' => 0.88]);
$dec(4, ['suggested_user_id' => 1, 'confidence' => 0.55,
         'overridden_by_user_id' => 2, 'overridden_to_user_id' => 2,
         'overridden_at' => "$mon 10:00:00"]);
$dec(8, ['suggested_user_id' => 2, 'confidence' => 0.60, 'applied' => 0]);

// Automatic closes. C9's is the arrival-time noise close (method headers,
// created by TriageConversation); C10's the inactivity sweep; C11's was a
// noise close that Ann overturned. C10 has an earlier routing decision too,
// so the report must pick the latest CLOSED row, not any row.
$dec(9,  ['method' => 'headers', 'applied' => 0, 'closed' => 1, 'close_reason' => 'noise',
          'noise_category' => 'auto_reply', 'created_at' => "$mon 09:00:20"]);
$dec(10, ['suggested_user_id' => null, 'confidence' => null, 'applied' => 0]);  // routed nobody
$dec(10, ['method' => 'headers', 'applied' => 0, 'closed' => 1, 'close_reason' => 'inactivity',
          'created_at' => '2026-08-06 09:00:00']);
$dec(11, ['method' => 'headers', 'applied' => 0, 'closed' => 1, 'close_reason' => 'noise',
          'noise_category' => 'auto_reply', 'reopened_by_user_id' => 1,
          'reopened_at' => "$mon 12:00:00"]);
$dec(5, ['suggested_user_id' => null, 'applied' => 0, 'error' => 'API timeout',
         'method' => 'model']);

// ── Load the services ──────────────────────────────────────────────────
foreach ([
    'Services/DateRange.php', 'Services/Trend.php', 'Services/Format.php',
    'Services/Stats.php', 'Services/VolumeReport.php',
    'Services/ResolutionReport.php', 'Services/TriageReport.php',
    'Services/TeamReport.php',
] as $f) {
    require $modules.'/DOTReports/'.$f;
}
require $modules.'/DOTTriage/Services/BusinessTime.php';

use Modules\DOTReports\Services\DateRange;
use Modules\DOTReports\Services\VolumeReport;
use Modules\DOTReports\Services\ResolutionReport;
use Modules\DOTReports\Services\TriageReport;
use Modules\DOTReports\Services\TeamReport;

$range = new DateRange(
    \Carbon\Carbon::parse('2026-08-01'),
    \Carbon\Carbon::parse('2026-08-31'),
    'custom'
);

// ── Assertions ─────────────────────────────────────────────────────────
$pass = 0;
$fail = 0;

function check($label, $actual, $expected) {
    global $pass, $fail;

    if ($actual === $expected) {
        echo "  ✓ $label\n";
        $pass++;
    } else {
        echo "  ✗ $label\n      expected: ".var_export($expected, true)
            ."\n      actual:   ".var_export($actual, true)."\n";
        $fail++;
    }
}

echo "\nVOLUME\n";
$v = new VolumeReport($range, 1);
// C1,C2,C3,C4,C8,C9,C10,C11 = 8. Excludes spam(C5), deleted(C6), imported(C7).
check('received excludes spam/deleted/imported', $v->received(), 8);
// C1,C2,C3,C4,C8,C9,C10,C11 have customer threads. C5 is spam - its thread must NOT count.
check('inbound messages exclude spam conversation', $v->inboundMessages(), 8);
// C1, C2, C10, C11. C4's is a note; C5's reply is on a spam conversation.
check('outbound replies exclude notes and spam', $v->outboundReplies(), 4);

echo "\nRESOLUTION — the closed_at trap\n";
$r = new ResolutionReport($range, 1);
$res = $r->resolutionTimes();

check('closed conversations counted (incl. automatic)', $res['closed_total'], 6);
check('timed successfully (C1 + C2 + C11)', $res['timed'], 3);
check('C2 needed the line-item fallback', $res['from_fallback'], 1);
check('C3 untimeable and reported', $res['untimed'], 1);
// C1 = 120 min, C2 = 180 min, C11 = 240 min -> median 180. C9 (20 s) and
// C10 (3 days) are Triage's closes and must not be in here.
check('median resolution = 180 min, automatic closes excluded', (int) $res['elapsed']['median'], 180);

echo "\nRESOLUTION — the automatic-close trap\n";
check('C9 + C10 reported as automatic closes', $res['auto_closed'], 2);
check('C9 was a noise close', $res['auto_reasons']['noise'], 1);
check('C10 was an inactivity close (latest closed row wins)', $res['auto_reasons']['inactivity'], 1);
check('C11 is Ann\'s, not Triage\'s, despite the decision row', $res['auto_reasons']['resolved'], 0);
check('automatic closes timed separately', $res['auto_elapsed']['count'], 2);
check('fastest automatic close = 20 s', round($res['auto_elapsed']['min'], 2), 0.33);
// 0.33, 120, 180, 240, 4320 -> the figure the page used to show as "the" median.
check('combined median (what the old page showed) = 180', (int) $res['all_median'], 180);

echo "\nFIRST RESPONSE — notes must not count\n";
$frt = $r->firstResponseTimes();
// C1 (60), C2 (30), C10 (10), C11 (180) have real agent replies. C4 has only a NOTE.
check('answered count excludes note-only C4', $frt['answered'], 4);
check('median FRT = 45 min', (int) $frt['elapsed']['median'], 45);
// 8 received, minus C9 and C10 which Triage closed itself = 6 support
// requests; 4 answered -> 2 awaiting. C3 and C4 and C8: C3 closed untimed,
// C4 note-only, C8 reopened - all genuinely unanswered.
check('automatic closes counted', $v->autoClosed(), 2);
check('FRT denominator excludes automatic closes', [$frt['received'], $frt['received_all']], [6, 8]);
check('unanswered = 2, not 4', $frt['unanswered'], 2);

echo "\nREOPENED\n";
check('C8 detected as reopened', $r->reopened()['count'], 1);

echo "\nTRIAGE\n";
$t = new TriageReport($range, 1);
$f = $t->funnel();
check('triaged total', $f['triaged'], 10);
check('auto-applied', $f['applied'], 4);
check('errors surfaced', $f['errors'], 1);
$acc = $t->accuracy();
check('applied decisions', $acc['applied'], 4);
check('overridden', $acc['overridden'], 1);
check('accuracy = 75%', $acc['accuracy'], 75.0);

// Calibration: 0.95, 0.92, 0.88 correct; 0.55 overridden.
$cal = $t->confidenceCalibration();
$band9 = null; $band5 = null;
foreach ($cal as $b) {
    if ($b['lower'] == 0.9) { $band9 = $b; }
    if ($b['lower'] == 0.5) { $band5 = $b; }
}
check('0.90+ band has 2, both correct', [$band9['total'], $band9['correct']], [2, 2]);
check('0.50-0.60 band has 1, none correct', [$band5['total'], $band5['correct']], [1, 0]);

echo "\nTEAM\n";
$team = new TeamReport($range, 1);
$agents = $team->agents();
$ann = null;
foreach ($agents as $a) { if ($a['user_id'] == 1) { $ann = $a; } }
check('Ann sent 3 replies', $ann['replies'], 3);
check('Ann resolved 2 (C1 and C11 carry her closed_by_user_id)', $ann['resolved'], 2);
check('unattributed closures = the 2 automatic ones', $team->unattributedClosures(), 2);

echo "\nPERIOD PICKER — a preset must beat stale From/To inputs\n";
class FakeRequest {
    private $q;
    public function __construct($q) { $this->q = $q; }
    public function get($k, $d = null) { return $this->q[$k] ?? $d; }
}
$today = \Carbon\Carbon::now()->toDateString();
$stale = ['from' => '2026-07-29', 'to' => '2026-08-27'];

$dr = DateRange::fromRequest(new FakeRequest(['period' => 'today'] + $stale));
check('period=today wins over pre-filled dates', [$dr->preset, $dr->start->toDateString()], ['today', $today]);

$dr = DateRange::fromRequest(new FakeRequest(['period' => 'custom'] + $stale));
check('period=custom applies the dates', [$dr->preset, $dr->start->toDateString(), $dr->end->toDateString()],
      ['custom', '2026-07-29', '2026-08-27']);

$dr = DateRange::fromRequest(new FakeRequest($stale));
check('no period at all applies the dates', $dr->preset, 'custom');

$dr = DateRange::fromRequest(new FakeRequest(['period' => 'bogus']));
check('unknown period falls back to the default', [$dr->preset, $dr->days()], ['30', 30]);

$dr = DateRange::fromRequest(new FakeRequest(['period' => '7', 'from' => '', 'to' => '']));
check('preset with cleared dates (the JS path)', [$dr->preset, $dr->days()], ['7', 7]);

echo "\n".str_repeat('─', 50)."\n";
echo $fail ? "FAILED: $fail assertion(s), $pass passed\n"
           : "✓ ALL $pass ASSERTIONS PASSED\n";

exit($fail ? 1 : 0);
