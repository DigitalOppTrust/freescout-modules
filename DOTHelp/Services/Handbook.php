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

    /**
     * The two ways in, chosen by how long the reader actually has.
     *
     * Someone about to answer their first ticket does not have half an hour, and a
     * list of sixteen pages gets read as "later". So the index asks one
     * question - how much time - and each answer leads to a single page that
     * is complete in itself.
     *
     * The hour is deliberately ONE page rather than eight links: a reader who
     * has set time aside should scroll, not navigate, and should be able to
     * see how far through they are.
     */
    public static function courses()
    {
        return [
            'five-minutes' => [
                'minutes' => 5,
                'label'   => 'I have five minutes',
                'blurb'   => 'Enough to answer your first ticket without causing a problem.',
                'covers'  => [
                    'The one rule you can get wrong',
                    'Where the queue is',
                    'What the automatic notes mean',
                    'Why nothing you do is irreversible',
                ],
                'cta'     => 'Start the five minutes',
                // Renders the quick-start topic on its own.
                'parts'   => ['quick-start'],
            ],
            'one-hour' => [
                'minutes' => 35,
                'label'   => 'I have half an hour',
                'blurb'   => 'The whole desk, in reading order, as one page you scroll through.',
                'covers'  => [
                    'Everything in the five-minute version',
                    'How a ticket moves from arrival to closing',
                    'Routing, escalation and the automatic closing rules',
                    'How to work the queue well',
                ],
                'cta'     => 'Start the walkthrough',
                'parts'   => [
                    'start', 'ticket-lifecycle', 'replying', 'folders',
                    'triage', 'auto-close', 'daily-work', 'escalation',
                ],
            ],
        ];
    }

    public static function hasCourse($key)
    {
        return array_key_exists((string) $key, self::courses());
    }

    public static function course($key)
    {
        $courses = self::courses();

        return isset($courses[$key]) ? $courses[$key] + ['key' => $key] : null;
    }

    /**
     * Per-part reading estimates for the longer route, so the reader can see where
     * they are. Rough by design - a minute either way does not matter, and a
     * false precision would.
     */
    public static function partMinutes()
    {
        // Measured from word count at a deliberately unhurried pace, rounded
        // up, with slack for the tables and the diagram. Overstating these
        // would be the easy mistake: a reader who is told "an hour" and
        // finishes in twenty minutes stops trusting the other numbers on the
        // page, and one who only has twenty minutes never starts.
        return [
            'quick-start'      => 4,
            'start'            => 4,
            'ticket-lifecycle' => 6,
            'replying'         => 5,
            'folders'          => 3,
            'triage'           => 5,
            'auto-close'       => 5,
            'daily-work'       => 3,
            'escalation'       => 3,
        ];
    }

    /**
     * The stylesheet URL, cache-busted by the file's own modification time.
     *
     * Without this a deploy changes the CSS but every browser that has
     * already loaded the page keeps its cached copy, and the handbook renders
     * as unstyled text - which looks like the module is broken rather than
     * stale. Deriving the token from filemtime() means it changes exactly
     * when the file does, with nothing to remember to bump by hand.
     */
    public static function stylesheet()
    {
        $url  = asset('modules/dothelp/css/module.css');
        $path = __DIR__.'/../Public/css/module.css';

        $version = @filemtime($path) ?: '1';

        return $url.'?v='.$version;
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
