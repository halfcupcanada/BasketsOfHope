<?php
/**
 * Standalone tests for the pure-domain logic — the money split and the status
 * machine. No PHPUnit on this host, and these classes deliberately have no
 * WordPress dependency, so they can be exercised directly.
 *
 * Run: php tests/run-tests.php
 */
declare(strict_types=1);

require __DIR__ . '/../src/Domain/Money.php';
require __DIR__ . '/../src/Domain/RaffleStatus.php';

use BOH\Fifty\Domain\Money;
use BOH\Fifty\Domain\RaffleStatus;

$pass = 0; $fail = 0;

function check(string $name, $actual, $expected): void
{
    global $pass, $fail;
    if ($actual === $expected) {
        $pass++;
        printf("  ok   %s\n", $name);
    } else {
        $fail++;
        printf("  FAIL %s — expected %s, got %s\n", $name,
            var_export($expected, true), var_export($actual, true));
    }
}

echo "\nAcceptance: 50/50 split\n";
// $1,000 settled => $1,000 total, $500 winner, $500 WIN House.
[$w, $c] = Money::splitFiftyFifty(100000);
check('$1,000 gross -> winner $500', Money::format($w), '$500.00');
check('$1,000 gross -> charity $500', Money::format($c), '$500.00');
check('halves reconstruct gross', $w + $c, 100000);

// Refunding $20 => $980 / $490 / $490.
[$w2, $c2] = Money::splitFiftyFifty(100000 - 2000);
check('after $20 refund gross is $980', Money::format(98000), '$980.00');
check('after $20 refund winner $490', Money::format($w2), '$490.00');
check('after $20 refund charity $490', Money::format($c2), '$490.00');

echo "\nOdd cents never overstate the prize\n";
[$w3, $c3] = Money::splitFiftyFifty(1001);   // $10.01
check('odd cent goes to charity, not winner', [$w3, $c3], [500, 501]);
check('no cent invented or lost', $w3 + $c3, 1001);

echo "\nZero and negative gross\n";
check('zero gross', Money::splitFiftyFifty(0), [0, 0]);
check('negative gross clamps', Money::splitFiftyFifty(-500), [0, 0]);

echo "\nNo float arithmetic: integer in, integer out\n";
[$w4, $c4] = Money::splitFiftyFifty(3333);
check('winner is int', is_int($w4), true);
check('charity is int', is_int($c4), true);
// 0.1+0.2 style error cannot appear because there is no float path at all.
check('penny-exact on an awkward value', $w4 + $c4, 3333);

echo "\nMoney::parse rejects junk rather than coercing to zero\n";
check('parses 20', Money::parse('20'), 2000);
check('parses 20.50', Money::parse('20.50'), 2050);
check('parses $1,234.56', Money::parse('$1,234.56'), 123456);
check('rejects empty', Money::parse(''), null);
check('rejects words', Money::parse('twenty'), null);
check('rejects 3 decimals', Money::parse('1.234'), null);

echo "\nStatus machine\n";
check('disabled -> live is not allowed directly',
    RaffleStatus::canTransition(RaffleStatus::DISABLED, RaffleStatus::LIVE), false);
check('scheduled -> live allowed',
    RaffleStatus::canTransition(RaffleStatus::SCHEDULED, RaffleStatus::LIVE), true);
check('live -> paused allowed',
    RaffleStatus::canTransition(RaffleStatus::LIVE, RaffleStatus::SALES_PAUSED), true);
check('paused -> live allowed (resume)',
    RaffleStatus::canTransition(RaffleStatus::SALES_PAUSED, RaffleStatus::LIVE), true);
check('archived is terminal',
    RaffleStatus::transitions()[RaffleStatus::ARCHIVED], []);
check('closed cannot reopen to live',
    RaffleStatus::canTransition(RaffleStatus::SALES_CLOSED, RaffleStatus::LIVE), false);
check('winner_published cannot go back to draw_pending',
    RaffleStatus::canTransition(RaffleStatus::WINNER_PUBLISHED, RaffleStatus::DRAW_PENDING), false);

echo "\nSelling and visibility gates\n";
check('live sells', RaffleStatus::isSelling(RaffleStatus::LIVE), true);
check('preview sells (test only)', RaffleStatus::isSelling(RaffleStatus::PREVIEW), true);
check('paused does not sell', RaffleStatus::isSelling(RaffleStatus::SALES_PAUSED), false);
check('closed does not sell', RaffleStatus::isSelling(RaffleStatus::SALES_CLOSED), false);
check('disabled is not publicly visible',
    RaffleStatus::isPubliclyVisible(RaffleStatus::DISABLED), false);
check('preview is not publicly visible',
    RaffleStatus::isPubliclyVisible(RaffleStatus::PREVIEW), false);
check('sales_closed is publicly visible',
    RaffleStatus::isPubliclyVisible(RaffleStatus::SALES_CLOSED), true);

printf("\n%d passed, %d failed\n\n", $pass, $fail);
exit($fail === 0 ? 0 : 1);
