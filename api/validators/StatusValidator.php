<?php
declare(strict_types=1);

class StatusValidator
{
    private const ALLOWED_TRANSITIONS = [
        'backlog'     => ['todo', 'blocked'],
        'todo'        => ['in_progress', 'blocked'],
        'in_progress' => ['review', 'blocked'],
        'review'      => ['testing', 'blocked'],
        'testing'     => ['done', 'blocked'],
        'done'        => [],
        'blocked'     => [],  // resolved dynamically from blocked_from
    ];

    private const STATUS_ORDER = [
        'backlog' => 0, 'todo' => 1, 'in_progress' => 2,
        'review' => 3, 'testing' => 4, 'done' => 5, 'blocked' => -1,
    ];

    public static function canTransition(string $from, string $to): bool
    {
        if ($from === 'blocked') {
            return true;  // can always leave blocked
        }
        if ($to === 'blocked') {
            return true;  // can always go to blocked
        }
        return in_array($to, self::ALLOWED_TRANSITIONS[$from] ?? [], true);
    }

    public static function getNextStatuses(string $current, ?string $blockedFrom = null): array
    {
        if ($current === 'blocked') {
            return [$blockedFrom ?? 'todo'];
        }
        if ($current === 'done') {
            return [];
        }
        $statuses = self::ALLOWED_TRANSITIONS[$current] ?? [];
        return array_values(array_filter($statuses, fn($s) => $s !== 'blocked'));
    }

    public static function isValidStatus(string $status): bool
    {
        return isset(self::STATUS_ORDER[$status]);
    }
}
