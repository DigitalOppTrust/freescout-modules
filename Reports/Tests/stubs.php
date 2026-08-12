<?php
/**
 * Minimal stand-ins for the FreeScout/Laravel surface the Reports services
 * touch, so they can run against a bare SQLite database.
 *
 * The constants MUST match core exactly - they were read from the dist branch
 * source, and a wrong value here would make the tests prove the wrong thing.
 */

namespace App {
    class Conversation
    {
        const STATUS_ACTIVE  = 1;
        const STATUS_PENDING = 2;
        const STATUS_CLOSED  = 3;
        const STATUS_SPAM    = 4;

        const STATE_DRAFT     = 1;
        const STATE_PUBLISHED = 2;
        const STATE_DELETED   = 3;

        const TYPE_EMAIL  = 1;
        const TYPE_PHONE  = 2;
        const TYPE_CHAT   = 3;
        const TYPE_CUSTOM = 4;
    }

    class Thread
    {
        const TYPE_CUSTOMER = 1;
        const TYPE_MESSAGE  = 2;
        const TYPE_NOTE     = 3;
        const TYPE_LINEITEM = 4;
        const TYPE_CHAT     = 8;

        const STATUS_ACTIVE  = 1;
        const STATUS_PENDING = 2;
        const STATUS_CLOSED  = 3;
        const STATUS_SPAM    = 4;

        const STATE_DRAFT     = 1;
        const STATE_PUBLISHED = 2;
        const STATE_HIDDEN    = 3;

        const ACTION_TYPE_STATUS_CHANGED = 1;
        const ACTION_TYPE_USER_CHANGED   = 2;
    }

    class User
    {
        const STATUS_ACTIVE = 1;
    }
}

namespace Illuminate\Support\Facades {
    class DB
    {
        public static function __callStatic($method, $args)
        {
            return \Illuminate\Database\Capsule\Manager::$method(...$args);
        }
    }
}

namespace {
    if (!function_exists('config')) {
        function config($key, $default = null)
        {
            return $GLOBALS['__config'][$key] ?? $default;
        }
    }

    if (!function_exists('now')) {
        function now()
        {
            return \Carbon\Carbon::now();
        }
    }

    /** Schema facade shim - only hasTable() is used by the services. */
    class Schema
    {
        public static function hasTable($table)
        {
            return \Illuminate\Database\Capsule\Manager::schema()->hasTable($table);
        }
    }
}
