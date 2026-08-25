<?php

namespace Modules\DOTHelp\Services;

/**
 * The handbook's table of contents.
 *
 * Topics are data, not routes: each entry names a Blade partial under
 * Resources/views/topics, and the controller renders whichever one the URL
 * asks for. Adding a page means adding a file and one line here.
 *
 * Ordering is the reading order for someone on their first day, not
 * alphabetical - "what is this system" before "how do I read a report".
 *
 * 'audience' marks who a topic is written for. Everything is readable by any
 * logged-in agent (the handbook holds no customer data), but topics marked
 * 'admin' describe screens an agent cannot open, so they are visually
 * separated rather than hidden - knowing the capability exists is useful even
 * when you cannot use it.
 */
class Handbook
{
    public static function topics()
    {
        return [
            'quick-start' => [
                'title'    => 'The five-minute version',
                'summary'  => 'Enough to answer your first ticket safely. Read this if you read nothing else.',
                'icon'     => 'flash',
                'audience' => 'all',
            ],
            'start' => [
                'title'    => 'Start here',
                'summary'  => 'What this system is, who is on it, and what to do in your first week.',
                'icon'     => 'flag',
                'audience' => 'all',
            ],
            'ticket-lifecycle' => [
                'title'    => 'The life of a ticket',
                'summary'  => 'From the customer pressing send to the conversation closing itself.',
                'icon'     => 'refresh',
                'audience' => 'all',
            ],
            'replying' => [
                'title'    => 'Replying to customers',
                'summary'  => 'Always from inside the ticket — never your own inbox, never a CC.',
                'icon'     => 'envelope',
                'audience' => 'all',
            ],
            'folders' => [
                'title'    => 'Folders and statuses',
                'summary'  => 'What Unassigned, Assigned, Mine, Closed and the statuses actually mean.',
                'icon'     => 'folder-open',
                'audience' => 'all',
            ],
            'triage' => [
                'title'    => 'How tickets reach you (DOTTriage)',
                'summary'  => 'Automatic routing, the note it leaves, and how to correct it.',
                'icon'     => 'random',
                'audience' => 'all',
            ],
            'escalation' => [
                'title'    => 'Escalation and SLA clocks',
                'summary'  => 'What happens when a ticket sits too long, and how working time is counted.',
                'icon'     => 'time',
                'audience' => 'all',
            ],
            'auto-close' => [
                'title'    => 'Tickets that close themselves',
                'summary'  => 'The three automatic closing rules and why a ticket you expected to close has not.',
                'icon'     => 'ok-circle',
                'audience' => 'all',
            ],
            'daily-work' => [
                'title'    => 'Your daily routine',
                'summary'  => 'A practical checklist for working the queue well.',
                'icon'     => 'check',
                'audience' => 'all',
            ],
            'modules' => [
                'title'    => 'The DOT modules at a glance',
                'summary'  => 'What each custom module adds, and which ones you will ever touch.',
                'icon'     => 'th-large',
                'audience' => 'all',
            ],
            'reports' => [
                'title'    => 'Reporting (DOTReports)',
                'summary'  => 'The four report tabs and how to read the numbers honestly.',
                'icon'     => 'stats',
                'audience' => 'admin',
            ],
            'dotlog' => [
                'title'    => 'Debugging mail (DOTLog)',
                'summary'  => 'The event timeline that answers "was an email actually sent?"',
                'icon'     => 'list-alt',
                'audience' => 'admin',
            ],
            'mcp' => [
                'title'    => 'AI access to the desk (DOTMCP)',
                'summary'  => 'Connecting an AI client to the help desk, and what it may read.',
                'icon'     => 'link',
                'audience' => 'admin',
            ],
            'admin' => [
                'title'    => 'Settings and switches',
                'summary'  => 'Every knob the DOT modules expose and where it lives.',
                'icon'     => 'cog',
                'audience' => 'admin',
            ],
            'troubleshooting' => [
                'title'    => 'When something looks wrong',
                'summary'  => 'The questions to ask before escalating to whoever maintains this.',
                'icon'     => 'wrench',
                'audience' => 'all',
            ],
            'glossary' => [
                'title'    => 'Glossary',
                'summary'  => 'The vocabulary this desk uses, in plain terms.',
                'icon'     => 'book',
                'audience' => 'all',
            ],
        ];
    }

    /** Does this slug name a real topic? Guards the route against path tricks. */
    public static function has($slug)
    {
        return array_key_exists((string) $slug, self::topics());
    }

    public static function get($slug)
    {
        $topics = self::topics();

        return isset($topics[$slug]) ? $topics[$slug] + ['slug' => $slug] : null;
    }

    /** Topics for a given audience, keyed by slug, in reading order. */
    public static function forAudience($isAdmin)
    {
        if ($isAdmin) {
            return self::topics();
        }

        return array_filter(self::topics(), function ($t) {
            return $t['audience'] !== 'admin';
        });
    }

    /**
     * The previous and next topic around $slug, so a reader can move through
     * the handbook in order without returning to the index each time.
     */
    public static function neighbours($slug, $isAdmin)
    {
        $slugs = array_keys(self::forAudience($isAdmin));
        $i     = array_search($slug, $slugs, true);

        if ($i === false) {
            return ['prev' => null, 'next' => null];
        }

        return [
            'prev' => $i > 0 ? self::get($slugs[$i - 1]) : null,
            'next' => $i < count($slugs) - 1 ? self::get($slugs[$i + 1]) : null,
        ];
    }
}
