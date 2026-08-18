<?php
declare(strict_types=1);

namespace BOH\Fifty\Domain;

/**
 * The nine feature states and the transitions allowed between them.
 *
 * Transitions are whitelisted rather than validated ad hoc at each call site,
 * so an accidental jump (say Archived back to Live) is impossible even if a
 * future admin screen forgets to check.
 */
final class RaffleStatus
{
    public const DISABLED         = 'disabled';
    public const PREVIEW          = 'preview';
    public const SCHEDULED        = 'scheduled';
    public const LIVE             = 'live';
    public const SALES_PAUSED     = 'sales_paused';
    public const SALES_CLOSED     = 'sales_closed';
    public const DRAW_PENDING     = 'draw_pending';
    public const WINNER_PUBLISHED = 'winner_published';
    public const ARCHIVED         = 'archived';

    /** States in which a checkout may be created at all. */
    public const SELLING = [self::LIVE, self::PREVIEW];

    /** @return array<string,string> */
    public static function labels(): array
    {
        return [
            self::DISABLED         => 'Disabled',
            self::PREVIEW          => 'Preview / Test Mode',
            self::SCHEDULED        => 'Scheduled',
            self::LIVE             => 'Live',
            self::SALES_PAUSED     => 'Sales Paused',
            self::SALES_CLOSED     => 'Sales Closed',
            self::DRAW_PENDING     => 'Draw Pending',
            self::WINNER_PUBLISHED => 'Winner Published',
            self::ARCHIVED         => 'Archived',
        ];
    }

    /** @return array<string,string[]> */
    public static function transitions(): array
    {
        return [
            self::DISABLED         => [self::PREVIEW, self::SCHEDULED],
            self::PREVIEW          => [self::DISABLED, self::SCHEDULED, self::LIVE],
            self::SCHEDULED        => [self::DISABLED, self::PREVIEW, self::LIVE],
            self::LIVE             => [self::SALES_PAUSED, self::SALES_CLOSED],
            self::SALES_PAUSED     => [self::LIVE, self::SALES_CLOSED],
            self::SALES_CLOSED     => [self::DRAW_PENDING],
            self::DRAW_PENDING     => [self::WINNER_PUBLISHED],
            self::WINNER_PUBLISHED => [self::ARCHIVED],
            self::ARCHIVED         => [],
        ];
    }

    public static function canTransition(string $from, string $to): bool
    {
        return in_array($to, self::transitions()[$from] ?? [], true);
    }

    public static function isSelling(string $status): bool
    {
        return in_array($status, self::SELLING, true);
    }

    /** Public components render only from these states. */
    public static function isPubliclyVisible(string $status): bool
    {
        return !in_array($status, [self::DISABLED, self::PREVIEW, self::ARCHIVED], true);
    }
}
