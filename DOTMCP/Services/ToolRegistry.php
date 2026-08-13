<?php

namespace Modules\DOTMCP\Services;

/**
 * Declares every tool and the minimum access level it requires.
 *
 * Kept as data rather than scattered across handler classes so the whole
 * permission surface is auditable in one place - and so tools/list can filter
 * by level without instantiating anything.
 */
class ToolRegistry
{
    public static function all()
    {
        return [
            // ── Volume ───────────────────────────────────────────────
            'conversation_volume' => [
                'level'       => AccessLevel::LOW,
                'description' => 'How many support conversations arrived, grouped by day, week or month. Use for "how busy have we been".',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'days'     => ['type' => 'integer', 'description' => 'How many days back to look. Default 30.'],
                        'group_by' => ['type' => 'string', 'enum' => ['day', 'week', 'month'], 'description' => 'Bucket size. Default day.'],
                    ],
                ],
            ],

            'volume_trend' => [
                'level'       => AccessLevel::LOW,
                'description' => 'Compare conversation volume against the preceding period. Use for "are we busier than last month".',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'days' => ['type' => 'integer', 'description' => 'Length of each period in days. Default 30.'],
                    ],
                ],
            ],

            // ── Triage ───────────────────────────────────────────────
            'triage_summary' => [
                'level'       => AccessLevel::LOW,
                'description' => 'How incoming mail was triaged: routed by keyword or model, auto-assigned or only suggested, and how much was closed as non-support.',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'days' => ['type' => 'integer', 'description' => 'How many days back. Default 30.'],
                    ],
                ],
            ],

            'triage_accuracy' => [
                'level'       => AccessLevel::LOW,
                'description' => 'How often a human reassigned a conversation that triage had routed. This is the measure of whether automatic routing is trustworthy.',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'days' => ['type' => 'integer', 'description' => 'How many days back. Default 30.'],
                    ],
                ],
            ],

            'noise_summary' => [
                'level'       => AccessLevel::LOW,
                'description' => 'How much inbound mail was auto-replies, newsletters, system notifications or delivery failures rather than genuine support requests.',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'days' => ['type' => 'integer', 'description' => 'How many days back. Default 30.'],
                    ],
                ],
            ],

            // ── Topics ───────────────────────────────────────────────
            'topic_summary' => [
                'level'       => AccessLevel::LOW,
                'description' => 'Recurring themes in support requests, derived from triage reasoning and matched keywords. Use for "what are people asking about".',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'days'  => ['type' => 'integer', 'description' => 'How many days back. Default 30.'],
                        'limit' => ['type' => 'integer', 'description' => 'How many themes to return. Default 15.'],
                    ],
                ],
            ],

            // ── Speed ────────────────────────────────────────────────
            'response_times' => [
                'level'       => AccessLevel::LOW,
                'description' => 'How quickly conversations get a first reply and how long they take to resolve. Reports both elapsed time and working hours, excluding weekends.',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'days' => ['type' => 'integer', 'description' => 'How many days back. Default 30.'],
                    ],
                ],
            ],

            'agent_workload' => [
                'level'       => AccessLevel::LOW,
                'description' => 'Open conversations per agent, and how long the oldest unanswered one has been waiting.',
                'schema'      => ['type' => 'object', 'properties' => (object) []],
            ],

            // ── Detail ───────────────────────────────────────────────
            'list_conversations' => [
                'level'       => AccessLevel::MEDIUM,
                'description' => 'List individual conversations with subject, status, assignee and timestamps. Customer identity is included only at high access.',
                'schema'      => [
                    'type'       => 'object',
                    'properties' => [
                        'days'      => ['type' => 'integer', 'description' => 'How many days back. Default 30.'],
                        'status'    => ['type' => 'string', 'enum' => ['active', 'pending', 'closed', 'any'], 'description' => 'Filter by status. Default any.'],
                        'assignee'  => ['type' => 'string', 'description' => 'Filter to an agent by name or email. Optional.'],
                        'unassigned'=> ['type' => 'boolean', 'description' => 'Only conversations with no assignee.'],
                        'limit'     => ['type' => 'integer', 'description' => 'Maximum rows. Default 25, capped by server config.'],
                    ],
                ],
            ],

            'search_conversations' => [
                'level'       => AccessLevel::MEDIUM,
                'description' => 'Find conversations whose subject or body matches a phrase. Customer identity is included only at high access.',
                'schema'      => [
                    'type'       => 'object',
                    'required'   => ['query'],
                    'properties' => [
                        'query' => ['type' => 'string', 'description' => 'Text to search for.'],
                        'days'  => ['type' => 'integer', 'description' => 'How many days back. Default 90.'],
                        'limit' => ['type' => 'integer', 'description' => 'Maximum rows. Default 25.'],
                    ],
                ],
            ],

            'get_conversation' => [
                'level'       => AccessLevel::MEDIUM,
                'description' => 'Read one conversation in full, including its messages. Customer identity is included only at high access.',
                'schema'      => [
                    'type'       => 'object',
                    'required'   => ['number'],
                    'properties' => [
                        'number' => ['type' => 'integer', 'description' => 'The conversation number shown in the help desk.'],
                    ],
                ],
            ],
        ];
    }

    public static function get($name)
    {
        $all = self::all();

        return $all[$name] ?? null;
    }

    /**
     * Tools this user may call. Filtering here matters: a user who sees a tool
     * they cannot call will read the refusal as a bug rather than a boundary.
     */
    public static function forUser($user)
    {
        if (!$user) {
            return [];
        }

        $level = AccessLevel::normalise($user->mcp_access_level);
        $out   = [];

        foreach (self::all() as $name => $tool) {
            if (AccessLevel::permits($level, $tool['level'])) {
                $out[$name] = $tool;
            }
        }

        return $out;
    }

    /** MCP tools/list payload. */
    public static function listFor($user)
    {
        $tools = [];

        foreach (self::forUser($user) as $name => $tool) {
            $tools[] = [
                'name'        => $name,
                'description' => $tool['description'],
                'inputSchema' => $tool['schema'],
            ];
        }

        return $tools;
    }
}
