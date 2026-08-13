<?php

namespace Modules\DOTTriage\Services;

/**
 * Triage settings, stored in FreeScout's own options table.
 *
 * Previously everything lived in .env, which meant changing a threshold
 * needed shell access to the server. These are operational decisions an
 * administrator should be able to make from the UI.
 *
 * Resolution order: database value, then .env, then the built-in default.
 * That means an existing .env stays authoritative until someone saves the
 * settings page, so deploying this changes no behaviour on its own.
 */
class Settings
{
    const PREFIX = 'triage_';

    /**
     * Every setting, with its type, default and the .env key it falls back to.
     * Kept as data so the settings form and the readers cannot drift apart.
     */
    public static function schema()
    {
        return [
            // ── Closing: non-support mail ────────────────────────────
            'close_noise_enabled' => [
                'type'    => 'bool',
                'default' => true,
                'env'     => null,
                'group'   => 'closing',
                'label'   => 'Close non-support mail',
                'help'    => 'Auto-replies, newsletters, system notifications, delivery failures '
                            .'and mail this address sends to itself. Closed with a note; the '
                            .'customer is never emailed.',
            ],

            // ── Closing: inactivity ──────────────────────────────────
            'close_inactive_enabled' => [
                'type'    => 'bool',
                'default' => false,
                'env'     => null,
                'group'   => 'closing',
                'label'   => 'Close tickets the customer stopped replying to',
                'help'    => 'Only applies where an agent has already replied. A ticket nobody '
                            .'answered is not "waiting on the customer" - it is unanswered, and '
                            .'escalation handles that instead.',
            ],
            'close_after_inactive_minutes' => [
                'type'    => 'choice',
                'default' => 7200,
                'env'     => 'TRIAGE_CLOSE_INACTIVE_AFTER',
                'group'   => 'closing',
                'label'   => 'Close after',
                'help'    => 'Working time since the last agent reply. Weekends are not counted.',
                'choices' => [
                    1440  => '1 working day',
                    2880  => '2 working days',
                    4320  => '3 working days',
                    7200  => '5 working days',
                    14400 => '10 working days',
                    21600 => '15 working days',
                ],
            ],

            // ── Closing: AI resolution judgement ─────────────────────
            'close_resolved_enabled' => [
                'type'    => 'bool',
                'default' => false,
                'env'     => 'TRIAGE_CLOSE_RESOLVED',
                'group'   => 'closing',
                'label'   => 'Close tickets that look resolved',
                'help'    => 'Asks the model to judge whether a conversation is finished. '
                            .'Closing an unresolved issue makes a customer think they were '
                            .'ignored, and nobody notices because the ticket leaves the queue - '
                            .'so this is the riskiest of the three and is off by default.',
            ],
            'resolved_min_quiet_minutes' => [
                'type'    => 'choice',
                'default' => 1440,
                'env'     => 'TRIAGE_RESOLVED_QUIET',
                'group'   => 'closing',
                'label'   => 'Only judge tickets quiet for',
                'help'    => 'A conversation must be this quiet before the model is asked, so an '
                            .'active exchange is never judged mid-flight.',
                'choices' => [
                    480   => '8 working hours',
                    1440  => '1 working day',
                    2880  => '2 working days',
                    4320  => '3 working days',
                ],
            ],
            'resolved_confidence' => [
                'type'    => 'choice',
                'default' => '0.85',
                'env'     => 'TRIAGE_RESOLVED_CONFIDENCE',
                'group'   => 'closing',
                'label'   => 'Minimum confidence',
                'help'    => 'How certain the model must be before closing. Higher means fewer '
                            .'closures but fewer mistakes.',
                'choices' => [
                    '0.70' => '0.70 — more closures, more risk',
                    '0.85' => '0.85 — balanced (recommended)',
                    '0.95' => '0.95 — only very clear cases',
                ],
            ],

            // ── Closing: safety ──────────────────────────────────────
            'close_max_per_run' => [
                'type'    => 'choice',
                'default' => 50,
                'env'     => null,
                'group'   => 'closing',
                'label'   => 'Maximum closures per run',
                'help'    => 'A safety valve. If a rule misfires, this bounds the damage to one '
                            .'batch rather than the whole queue.',
                'choices' => [
                    10  => '10',
                    25  => '25',
                    50  => '50',
                    100 => '100',
                    500 => '500 (no practical limit)',
                ],
            ],
            'close_protect_assigned' => [
                'type'    => 'bool',
                'default' => false,
                'env'     => null,
                'group'   => 'closing',
                'label'   => 'Never close a ticket that is assigned to someone',
                'help'    => 'Strictest option: if an agent owns it, only they close it. Useful '
                            .'while you are still building trust in automatic closing.',
            ],
        ];
    }

    /** Read a setting: database, then .env, then default. */
    public static function get($key)
    {
        $schema = self::schema();
        if (!isset($schema[$key])) {
            return null;
        }

        $spec = $schema[$key];

        $stored = \Option::get(self::PREFIX.$key, null);
        if ($stored !== null && $stored !== '') {
            return self::cast($stored, $spec['type']);
        }

        if (!empty($spec['env'])) {
            $env = env($spec['env']);
            if ($env !== null && $env !== '') {
                return self::cast($env, $spec['type']);
            }
        }

        return self::cast($spec['default'], $spec['type']);
    }

    public static function set($key, $value)
    {
        $schema = self::schema();
        if (!isset($schema[$key])) {
            return false;
        }

        // Choice settings only accept a value from their own list, so a
        // tampered form cannot set an arbitrary threshold.
        if ($schema[$key]['type'] === 'choice'
            && !array_key_exists((string) $value, array_change_key_case(
                array_combine(
                    array_map('strval', array_keys($schema[$key]['choices'])),
                    $schema[$key]['choices']
                )
            ))) {
            return false;
        }

        \Option::set(self::PREFIX.$key, (string) $value);

        return true;
    }

    /** All settings in a group, for rendering the form. */
    public static function group($name)
    {
        $out = [];
        foreach (self::schema() as $key => $spec) {
            if ($spec['group'] === $name) {
                $spec['value'] = self::get($key);
                $out[$key] = $spec;
            }
        }

        return $out;
    }

    protected static function cast($value, $type)
    {
        switch ($type) {
            case 'bool':
                return filter_var($value, FILTER_VALIDATE_BOOLEAN);
            case 'choice':
            case 'int':
                return is_numeric($value) && strpos((string) $value, '.') === false
                    ? (int) $value
                    : $value;
            default:
                return $value;
        }
    }
}
