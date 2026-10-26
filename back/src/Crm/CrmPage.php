<?php

declare(strict_types=1);

namespace Wps\Crm;

/**
 * One page of a CRM search or of a relationship list.
 */
final class CrmPage
{
    /**
     * @param list<array<string, mixed>> $items flat records {id, ...attributes}
     * @param array<string, array<string, string>> $relationshipFields related id => fields of the link
     * @param string|null $module CRM module of the records ("type" of the resources), known for relationship lists
     */
    public function __construct(
        public readonly array $items,
        public readonly ?string $nextCursor = null,
        public readonly ?int $total = null,
        public readonly array $relationshipFields = [],
        public readonly ?string $module = null,
    ) {
    }
}
