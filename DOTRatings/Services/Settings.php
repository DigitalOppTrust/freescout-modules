<?php

namespace Modules\DOTRatings\Services;

/**
 * Ratings settings, stored in FreeScout's own options table.
 *
 * Same shape as the Triage module's settings service: schema-as-data so the
 * form and the readers cannot drift apart, and resolution order of database,
 * then .env, then the built-in default.
 *
 * Everything that emails a customer defaults to off. Installing this module
 * must not, on its own, cause a single message to be sent to a real person.
 */
class Settings
{
    const PREFIX = 'dotratings_';

    public static function schema()
    {
        return [
            // ── Sending ──────────────────────────────────────────────
            'send_enabled' => [
                'type'    => 'bool',
                'default' => false,
                'env'     => null,
                'group'   => 'sending',
                'label'   => 'Email customers when their ticket is closed',
                'help'    => 'The master switch for this module. Off by default: everything '
                            .'below is inert until this is on, so installing the module emails '
                            .'nobody until someone decides otherwise.',
            ],
            'send_on_manual' => [
                'type'    => 'bool',
                'default' => true,
                'env'     => null,
                'group'   => 'sending',
                'label'   => 'When an agent closes a ticket',
                'help'    => 'Covers both ways an agent can close: the status dropdown, and '
                            .'replying with the status set to Closed.',
            ],
            'send_on_auto' => [
                'type'    => 'bool',
                'default' => true,
                'env'     => null,
                'group'   => 'sending',
                'label'   => 'When triage closes a ticket automatically',
                'help'    => 'Applies to tickets closed for inactivity or because they looked '
                            .'resolved. Mail closed as non-support - newsletters, auto-replies, '
                            .'bounces - is never emailed, whatever this is set to: replying to '
                            .'a spammer confirms the address is real.',
            ],
            'require_agent_reply' => [
                'type'    => 'bool',
                'default' => true,
                'env'     => null,
                'group'   => 'sending',
                'label'   => 'Only email if an agent actually replied',
                'help'    => 'Stops the module asking somebody to rate support they never '
                            .'received. Turning this off means tickets closed without any '
                            .'answer also get a closure email, which is rarely what you want.',
            ],
            'resend_guard_days' => [
                'type'    => 'choice',
                'default' => 7,
                'env'     => null,
                'group'   => 'sending',
                'label'   => 'At most one closure email per ticket every',
                'help'    => 'A loop guard. A closure email can trigger an out-of-office, which '
                            .'reopens the ticket, which gets closed again - without this, that '
                            .'cycle emails the customer every time round.',
                'choices' => [
                    1  => '1 day',
                    3  => '3 days',
                    7  => '7 days (recommended)',
                    14 => '14 days',
                    30 => '30 days',
                ],
            ],

            // ── The rating link ──────────────────────────────────────
            'token_valid_days' => [
                'type'    => 'choice',
                'default' => 30,
                'env'     => null,
                'group'   => 'link',
                'label'   => 'Rating links stay valid for',
                'help'    => 'After this the link shows a neutral "no longer available" page. '
                            .'The customer can still reply to the email to reopen the ticket, '
                            .'which never expires.',
                'choices' => [
                    7  => '7 days',
                    14 => '14 days',
                    30 => '30 days (recommended)',
                    60 => '60 days',
                ],
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
        // tampered form cannot set an arbitrary value.
        if ($schema[$key]['type'] === 'choice') {
            $allowed = array_map('strval', array_keys($schema[$key]['choices']));
            if (!in_array((string) $value, $allowed, true)) {
                return false;
            }
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
