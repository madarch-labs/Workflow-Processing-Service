<?php

declare(strict_types=1);

namespace Wps\Template;

/**
 * A parsed template. Nodes:
 *  - ['text', string]
 *  - ['tag', operand, filters, source]: operand ['path', string] | ['lit', mixed]; filters list<[name, list<operand>]>
 *  - ['expr', string expression, source]
 */
final class Template
{
    /**
     * @param list<array<int, mixed>> $nodes
     */
    public function __construct(public readonly array $nodes)
    {
    }

    /**
     * The whole template is exactly one substitution ("{{ object.amount }}"): JSON mode keeps the value type.
     *
     * @return array<int, mixed>|null
     */
    public function single(): ?array
    {
        return count($this->nodes) === 1 && $this->nodes[0][0] !== 'text' ? $this->nodes[0] : null;
    }
}
