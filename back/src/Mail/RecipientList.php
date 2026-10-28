<?php

declare(strict_types=1);

namespace Wps\Mail;

use Symfony\Component\Mime\Address;

final class RecipientList
{
    /**
     * @param list<Address> $addresses
     * @param list<array{value: string, reason: string}> $warnings invalid / missing / duplicate / rate_limited
     */
    public function __construct(
        public readonly array $addresses = [],
        public readonly array $warnings = [],
    ) {
    }

    /**
     * @return list<string>
     */
    public function emails(): array
    {
        return array_map(static fn (Address $a) => $a->getAddress(), $this->addresses);
    }
}
