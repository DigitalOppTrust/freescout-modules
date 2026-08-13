<?php

namespace Modules\DOTMCP\Services;

/**
 * The RBAC core.
 *
 * Three gates must all pass before any data is returned:
 *   1. the user has mcp_enabled  (admin status grants nothing)
 *   2. the user's access level
 *   3. the tool's required level
 *
 * Effective access is the LOWER of the user's level and the tool's
 * requirement, so a high-access user calling an aggregate tool still gets
 * aggregate output, and a low-access user calling a detail tool is refused
 * with a reason rather than silently receiving an empty result.
 *
 * Kept in one class deliberately: a new tool must not be able to widen access
 * by forgetting to check something.
 */
class AccessLevel
{
    const LOW    = 'low';     // aggregates only - counts, trends, averages
    const MEDIUM = 'medium';  // + individual conversations, PII redacted
    const HIGH   = 'high';    // + customer names, emails, phone numbers

    /** Ordered weakest to strongest. Comparison uses the index. */
    const ORDER = [self::LOW, self::MEDIUM, self::HIGH];

    public static function isValid($level)
    {
        return in_array($level, self::ORDER, true);
    }

    public static function rank($level)
    {
        $i = array_search($level, self::ORDER, true);

        // Unknown values rank lowest rather than highest: a typo in the
        // database must not silently grant more access than intended.
        return $i === false ? 0 : $i;
    }

    /** Does $userLevel meet or exceed $required? */
    public static function permits($userLevel, $required)
    {
        return self::rank($userLevel) >= self::rank($required);
    }

    /** The lower of two levels. */
    public static function effective($userLevel, $toolLevel)
    {
        return self::rank($userLevel) <= self::rank($toolLevel)
            ? self::normalise($userLevel)
            : self::normalise($toolLevel);
    }

    public static function normalise($level)
    {
        return self::isValid($level) ? $level : self::LOW;
    }

    /**
     * Gate 1: may this user use MCP at all?
     *
     * Admin status is deliberately not consulted. MCP access is a separate
     * decision from helpdesk administration, so an admin without the flag has
     * no access and cannot see the module.
     *
     * @return array{allowed: bool, reason: ?string}
     */
    public static function checkUser($user)
    {
        if (!$user) {
            return ['allowed' => false, 'reason' => 'Not authenticated.'];
        }

        if ((int) $user->status !== (int) \App\User::STATUS_ACTIVE) {
            return ['allowed' => false, 'reason' => 'This account is not active.'];
        }

        if (!$user->mcp_enabled) {
            return [
                'allowed' => false,
                'reason'  => 'This account is not enabled for MCP access. '
                            .'An administrator must enable it in Manage → MCP.',
            ];
        }

        return ['allowed' => true, 'reason' => null];
    }

    /**
     * Gates 2 and 3 together.
     *
     * @return array{allowed: bool, effective: ?string, reason: ?string}
     */
    public static function checkTool($user, $requiredLevel)
    {
        $userCheck = self::checkUser($user);
        if (!$userCheck['allowed']) {
            return ['allowed' => false, 'effective' => null, 'reason' => $userCheck['reason']];
        }

        $userLevel = self::normalise($user->mcp_access_level);

        if (!self::permits($userLevel, $requiredLevel)) {
            return [
                'allowed'   => false,
                'effective' => null,
                'reason'    => sprintf(
                    'This tool requires %s access; your access level is %s.',
                    $requiredLevel,
                    $userLevel
                ),
            ];
        }

        return [
            'allowed'   => true,
            'effective' => self::effective($userLevel, $requiredLevel),
            'reason'    => null,
        ];
    }

    /** May PII be included at this effective level? */
    public static function allowsPii($level)
    {
        return self::normalise($level) === self::HIGH;
    }

    /** May individual records be returned, or only aggregates? */
    public static function allowsRecords($level)
    {
        return self::rank(self::normalise($level)) >= self::rank(self::MEDIUM);
    }

    public static function label($level)
    {
        $labels = [
            self::LOW    => 'Low — aggregate data only',
            self::MEDIUM => 'Medium — conversations without personal details',
            self::HIGH   => 'High — full access including personal details',
        ];

        return $labels[$level] ?? $labels[self::LOW];
    }
}
