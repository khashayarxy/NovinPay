<?php

namespace App\Support;

use Carbon\CarbonImmutable;
use Symfony\Component\HttpKernel\Exception\HttpException;

final readonly class PostexQuote
{
    public function __construct(
        public int $amountRial,
        public string $requestDigest,
        public CarbonImmutable $quotedAt,
        public CarbonImmutable $expiresAt,
        public string $service,
        public bool $independentPostageVerified,
        public string $tariffEvidence,
    ) {}

    public function billingMinor(string $expectedRequestDigest): int
    {
        if ($this->amountRial < 1 || $this->amountRial > 999999999999 || ! $this->independentPostageVerified || $this->tariffEvidence === '' || ! hash_equals($this->requestDigest, $expectedRequestDigest) || ! $this->expiresAt->isFuture() || $this->quotedAt->isFuture()) {
            throw new HttpException(409, 'اعتبار و کاربرد هزینهٔ پست باید دوباره تأیید شود.');
        }

        // The ledger stores hundredths of a toman: 1 rial = 10 ledger units.
        return $this->amountRial * 10;
    }
}
