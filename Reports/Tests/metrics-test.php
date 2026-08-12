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
 *     FREESCOUT_PATH=/var/www/freescout php Reports/Tests/metrics-test.php
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
$dec(5, ['suggested_user_id' => null, 'applied' => 0, 'error' => 'API timeout',
         'method' => 'model']);

// ── Load the services ──────────────────────────────────────────────────
foreach ([
    'Services/DateRange.php', 'Services/Trend.php', 'Services/Format.php',
    'Services/Stats.php', 'Services/VolumeReport.php',
    'Services/ResolutionReport.php', 'Services/TriageReport.php',
    'Services/TeamReport.php',
] as $f) {
    require $modules.'/Reports/'.$f;
}
require $modules.'/Triage/Services/BusinessTime.php';

use Modules\Reports\Services\DateRange;
use Modules\Reports\Services\VolumeReport;
use Modules\Reports\Services\ResolutionReport;
use Modules\Reports\Services\TriageReport;
use Modules\Reports\Services\TeamReport;

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
// C1,C2,C3,C4,C8 = 5. Excludes spam(C5), deleted(C6), imported(C7).
check('received excludes spam/deleted/imported', $v->received(), 5);
// C1,C2,C3,C4,C8 have customer threads. C5 is spam - its thread must NOT count.
check('inbound messages exclude spam conversation', $v->inboundMessages(), 5);
// C1 and C2 only. C4's is a note; C5's reply is on a spam conversation.
check('outbound replies exclude notes and spam', $v->outboundReplies(), 2);

echo "\nRESOLUTION — the closed_at trap\n";
$r = new ResolutionReport($range, 1);
$res = $r->resolutionTimes();

check('closed conversations counted', $res['closed_total'], 3);
check('timed successfully (C1 + C2)', $res['timed'], 2);
check('C2 needed the line-item fallback', $res['from_fallback'], 1);
check('C3 untimeable and reported', $res['untimed'], 1);
// C1 = 120 min, C2 = 180 min -> median 150
check('median resolution = 150 min', (int) $res['elapsed']['median'], 150);

echo "\nFIRST RESPONSE — notes must not count\n";
$frt = $r->firstResponseTimes();
// Only C1 (60) and C2 (30) have real agent replies. C4 has only a NOTE.
check('answered count excludes note-only C4', $frt['answered'], 2);
check('median FRT = 45 min', (int) $frt['elapsed']['median'], 45);
check('unanswered = 3', $frt['unanswered'], 3);

echo "\nREOPENED\n";
check('C8 detected as reopened', $r->reopened()['count'], 1);

echo "\nTRIAGE\n";
$t = new TriageReport($range, 1);
$f = $t->funnel();
check('triaged total', $f['triaged'], 6);
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
check('Ann sent 2 replies', $ann['replies'], 2);
check('Ann resolved 1 (only C1 has closed_by_user_id)', $ann['resolved'], 1);
check('unattributed closures reported', $team->unattributedClosures(), 0);

echo "\n".str_repeat('─', 50)."\n";
echo $fail ? "FAILED: $fail assertion(s), $pass passed\n"
           : "✓ ALL $pass ASSERTIONS PASSED\n";

exit($fail ? 1 : 0);
