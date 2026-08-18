<?php
declare(strict_types=1);

namespace BOH\Fifty\Domain;

/**
 * Integer-cent money. Every amount in this plugin is an int of cents; there is
 * no float path at all, because a 50/50 split on an odd number of cents has to
 * be decided deliberately rather than by binary rounding.
 */
final class Money
{
    public const CURRENCY = 'CAD';

    /**
     * Split gross into the winner's half and the charity's half.
     *
     * An odd cent cannot be halved. It is assigned to the charity rather than
     * the winner: the prize is then never overstated, and "the other half
     * supports WIN House" stays literally true. Returns [winner, charity].
     *
     * @return array{0:int,1:int}
     */
    public static function splitFiftyFifty(int $grossCents): array
    {
        if ($grossCents <= 0) {
            return [0, 0];
        }
        $winner = intdiv($grossCents, 2);          // floor — never rounds up
        return [$winner, $grossCents - $winner];   // remainder to the charity
    }

    /** Format cents for display, e.g. 123456 => "$1,234.56". */
    public static function format(int $cents, bool $symbol = true): string
    {
        $neg  = $cents < 0;
        $abs  = abs($cents);
        $out  = number_format(intdiv($abs, 100)) . '.' . str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);
        $out  = ($symbol ? '$' : '') . $out;
        return $neg ? '-' . $out : $out;
    }

    /**
     * Parse an admin-entered amount ("20", "20.50", "$1,234.56") to cents.
     * Rejects anything that is not a plain currency amount rather than
     * silently coercing it to 0.
     */
    public static function parse(string $input): ?int
    {
        $clean = preg_replace('/[\s$,]/', '', trim($input)) ?? '';
        if ($clean === '' || !preg_match('/^-?\d+(\.\d{1,2})?$/', $clean)) {
            return null;
        }
        [$whole, $frac] = array_pad(explode('.', ltrim($clean, '-')), 2, '0');
        $cents = ((int) $whole) * 100 + (int) str_pad($frac, 2, '0');
        return str_starts_with($clean, '-') ? -$cents : $cents;
    }
}
