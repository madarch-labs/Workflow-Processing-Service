<?php

declare(strict_types=1);

namespace Wps\Template;

final class RenderResult
{
    /**
     * @param list<string> $missing paths without a value
     * @param list<string> $warnings e.g. removed line breaks in a header
     */
    public function __construct(
        public readonly mixed $value,
        public readonly array $missing = [],
        public readonly array $warnings = [],
    ) {
    }
}
