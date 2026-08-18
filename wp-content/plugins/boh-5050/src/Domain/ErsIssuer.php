<?php
declare(strict_types=1);

namespace BOH\Fifty\Domain;

/**
 * Adapter for an AGLC-approved Electronic Raffle System.
 *
 * Deliberately unimplemented: the provider is not chosen yet, and inventing an
 * API shape now would be guesswork that later has to be unpicked. issue()
 * throws rather than silently falling back to internal numbering, so a
 * half-configured live raffle fails closed instead of minting unlicensed
 * tickets.
 */
final class ErsIssuer implements TicketIssuer
{
    public function __construct(private string $provider) {}

    public function name(): string { return 'ers:' . $this->provider; }

    public function isLicensed(): bool { return true; }

    public function issue(int $raffleId, int $orderId, int $quantity): array
    {
        throw new \RuntimeException(
            'ERS issuance is not configured. Select an AGLC-approved provider and '
            . 'implement ErsIssuer::issue() before enabling live sales.'
        );
    }
}
