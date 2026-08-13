<?php

namespace Modules\DOTMCP\Services\Tools;

use Illuminate\Support\Facades\DB;
use Modules\DOTMCP\Services\AccessLevel;

/**
 * Tools that return individual conversations.
 *
 * Every customer-identifying value passes through redact() before leaving this
 * class. Doing it in one place means a new tool cannot leak PII by forgetting
 * to check the access level - the alternative, redacting per tool, fails
 * silently the first time someone adds a field.
 */
class DetailTools
{
    protected $level;
    protected $maxPage;

    public function __construct($effectiveLevel)
    {
        $this->level   = AccessLevel::normalise($effectiveLevel);
        $this->maxPage = (int) config('dotmcp.max_page_size', 100);
    }

    protected function pii()
    {
        return AccessLevel::allowsPii($this->level);
    }

    /** Mask anything identifying unless the caller has high access. */
    protected function redact($value, $type = 'text')
    {
        if ($this->pii() || $value === null || $value === '') {
            return $value;
        }

        if ($type === 'email') {
            // Keep the domain: "which organisation" is often the reportable
            // question, and the domain alone does not identify a person.
            $parts = explode('@', (string) $value);
            return count($parts) === 2 ? '[redacted]@'.$parts[1] : '[redacted]';
        }

        if ($type === 'name') {
            return '[redacted]';
        }

        // Free text: strip embedded addresses and phone numbers.
        $value = preg_replace('/[\w.+-]+@[\w.-]+\.\w+/', '[email redacted]', (string) $value);
        $value = preg_replace('/(?<!\d)(\+?\d[\d\s().-]{7,}\d)(?!\d)/', '[phone redacted]', $value);

        return $value;
    }

    protected function limit($requested, $default = 25)
    {
        return max(1, min($this->maxPage, (int) ($requested ?: $default)));
    }

    protected function since($days, $default = 30)
    {
        $days = max(1, min(730, (int) ($days ?: $default)));

        return date('Y-m-d H:i:s', strtotime("-{$days} days"));
    }

    public function listConversations(array $args)
    {
        $limit  = $this->limit($args['limit'] ?? null);
        $since  = $this->since($args['days'] ?? null);

        $q = DB::table('conversations as c')
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->select('c.id', 'c.number', 'c.subject', 'c.status', 'c.created_at',
                     'c.closed_at', 'c.customer_email')
            ->selectRaw("CONCAT(IFNULL(u.first_name,''),' ',IFNULL(u.last_name,'')) AS assignee")
            ->where('c.created_at', '>=', $since);

        $statusMap = ['active' => 1, 'pending' => 2, 'closed' => 3];
        if (!empty($args['status']) && isset($statusMap[$args['status']])) {
            $q->where('c.status', $statusMap[$args['status']]);
        }

        if (!empty($args['unassigned'])) {
            $q->whereNull('c.user_id');
        }

        if (!empty($args['assignee'])) {
            $needle = '%'.$args['assignee'].'%';
            $q->where(function ($sub) use ($needle) {
                $sub->where('u.first_name', 'like', $needle)
                    ->orWhere('u.last_name', 'like', $needle)
                    ->orWhere('u.email', 'like', $needle);
            });
        }

        $total = (clone $q)->count();
        $rows  = $q->orderBy('c.created_at', 'desc')->limit($limit)->get();

        return [
            'total_matching' => $total,
            'returned'       => $rows->count(),
            'truncated'      => $total > $rows->count(),
            'redacted'       => !$this->pii(),
            'conversations'  => $rows->map(function ($r) {
                return $this->summarise($r);
            })->all(),
        ];
    }

    public function searchConversations(array $args)
    {
        $query = trim((string) ($args['query'] ?? ''));

        if ($query === '') {
            return ['error' => 'A search query is required.'];
        }

        $limit = $this->limit($args['limit'] ?? null);
        $since = $this->since($args['days'] ?? null, 90);
        $like  = '%'.$query.'%';

        $ids = DB::table('threads')
            ->where('created_at', '>=', $since)
            ->where('body', 'like', $like)
            ->distinct()
            ->limit($this->maxPage)
            ->pluck('conversation_id');

        $rows = DB::table('conversations as c')
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->select('c.id', 'c.number', 'c.subject', 'c.status', 'c.created_at',
                     'c.closed_at', 'c.customer_email')
            ->selectRaw("CONCAT(IFNULL(u.first_name,''),' ',IFNULL(u.last_name,'')) AS assignee")
            ->where('c.created_at', '>=', $since)
            ->where(function ($q) use ($like, $ids) {
                $q->where('c.subject', 'like', $like);
                if ($ids->count()) {
                    $q->orWhereIn('c.id', $ids);
                }
            })
            ->orderBy('c.created_at', 'desc')
            ->limit($limit)
            ->get();

        return [
            'query'         => $query,
            'returned'      => $rows->count(),
            'redacted'      => !$this->pii(),
            'conversations' => $rows->map(function ($r) {
                return $this->summarise($r);
            })->all(),
        ];
    }

    public function getConversation(array $args)
    {
        $number = (int) ($args['number'] ?? 0);

        if (!$number) {
            return ['error' => 'A conversation number is required.'];
        }

        $c = DB::table('conversations as c')
            ->leftJoin('users as u', 'u.id', '=', 'c.user_id')
            ->select('c.*')
            ->selectRaw("CONCAT(IFNULL(u.first_name,''),' ',IFNULL(u.last_name,'')) AS assignee")
            ->where('c.number', $number)
            ->first();

        if (!$c) {
            return ['error' => 'Conversation '.$number.' not found.'];
        }

        $threads = DB::table('threads')
            ->where('conversation_id', $c->id)
            ->orderBy('created_at', 'asc')
            ->limit($this->maxPage)
            ->get();

        $typeMap = [1 => 'customer', 2 => 'agent reply', 3 => 'note', 4 => 'line item'];

        return array_merge($this->summarise($c), [
            'messages' => $threads->map(function ($t) use ($typeMap) {
                $body = strip_tags((string) $t->body);
                $body = html_entity_decode($body, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $body = trim(preg_replace('/\s+/u', ' ', $body));

                return [
                    'type'    => $typeMap[$t->type] ?? 'other',
                    'at'      => $t->created_at,
                    'body'    => $this->redact(mb_substr($body, 0, 4000)),
                ];
            })->all(),
        ]);
    }

    /** One conversation, with identity handled according to access level. */
    protected function summarise($r)
    {
        $statusMap = [1 => 'active', 2 => 'pending', 3 => 'closed', 4 => 'spam'];

        return [
            'number'    => (int) $r->number,
            'subject'   => $this->redact($r->subject),
            'status'    => $statusMap[$r->status] ?? 'unknown',
            'customer'  => $this->redact($r->customer_email ?? null, 'email'),
            'assignee'  => trim($r->assignee ?? '') ?: null,
            'opened_at' => $r->created_at,
            'closed_at' => $r->closed_at ?? null,
        ];
    }
}
