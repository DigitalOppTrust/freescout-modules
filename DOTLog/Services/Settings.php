<?php

namespace Modules\DOTLog\Services;

/**
 * DOTLog settings, stored in FreeScout's own options table.
 *
 * Same shape as the Triage module's Settings service: schema as data,
 * resolution order database value → .env → built-in default, choice values
 * validated against their own list.
 */
class Settings
{
    const PREFIX = 'dotlog_';

    public static function schema()
    {
        return [
            'retention_days' => [
                'type'    => 'choice',
                'default' => 21,
                'env'     => 'DOTLOG_RETENTION_DAYS',
                'group'   => 'retention',
                'label'   => 'Keep log entries for',
                'help'    => 'Entries older than this are deleted by the daily prune. '
                            .'These are debugging records, not ticket data — nothing '
                            .'about the conversations themselves is touched.',
                'choices' => [
                    7   => '7 days',
                    14  => '14 days',
                    21  => '21 days (recommended)',
                    30  => '30 days',
                    60  => '60 days',
                    90  => '90 days',
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

        if ($schema[$key]['type'] === 'choice'
            && !array_key_exists((string) $value, array_combine(
                array_map('strval', array_keys($schema[$key]['choices'])),
                $schema[$key]['choices']
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
