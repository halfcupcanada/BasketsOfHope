<?php
declare(strict_types=1);

namespace BOH\Fifty\Domain;

/**
 * The ERS boundary.
 *
 * AGLC requires online raffle sales and the draw to run on an approved
 * Electronic Raffle System. Ticket issuance therefore sits behind this
 * interface so the licensed path can be delegated to a certified provider
 * without the rest of the plugin knowing or caring.
 */
interface TicketIssuer
{
    /**
     * Issue $quantity ticket numbers for a paid order.
     *
     * Implementations must be idempotent on $orderId: a replayed webhook must
     * return the already-issued numbers, never mint a second set.
     *
     * @return string[] ticket numbers
     */
    public function issue(int $raffleId, int $orderId, int $quantity): array;

    /** Identifier recorded on each ticket row, e.g. "internal" or "ers:acme". */
    public function name(): string;

    /** Whether this issuer may be used for real, licensed sales. */
    public function isLicensed(): bool;
}
