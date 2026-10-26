<?php

declare(strict_types=1);

namespace Wps\Crm;

/**
 * What the CRM plugin implements (GET /capabilities). An old plugin without this endpoint is contract 1.0:
 * read by id, search by equality (≤ 100 records), create, update.
 */
final class CrmCapabilities
{
    public const LEGACY_VERSION = '1.0';

    /**
     * @param list<string> $features
     * @param array<string, int> $limits
     * @param array<string, mixed> $crm
     */
    public function __construct(
        public readonly string $contractVersion,
        public readonly array $features = [],
        public readonly array $limits = [],
        public readonly array $crm = [],
    ) {
    }

    public static function legacy(): self
    {
        return new self(self::LEGACY_VERSION);
    }

    /**
     * @param array<string, mixed> $data "data" of GET /capabilities
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (string)($data['contract_version'] ?? self::LEGACY_VERSION),
            array_values(array_map('strval', (array)($data['features'] ?? []))),
            array_map('intval', (array)($data['limits'] ?? [])),
            (array)($data['crm'] ?? []),
        );
    }

    public function isLegacy(): bool
    {
        return $this->contractVersion === self::LEGACY_VERSION;
    }

    public function has(string $feature): bool
    {
        return in_array($feature, $this->features, true);
    }

    public function limit(string $name, int $default): int
    {
        return isset($this->limits[$name]) && $this->limits[$name] > 0 ? $this->limits[$name] : $default;
    }

    /**
     * @throws CrmCapabilityMissingException
     */
    public function require(string $feature): void
    {
        if (!$this->has($feature)) {
            throw CrmCapabilityMissingException::forFeature($feature, $this->contractVersion);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return ['contract_version' => $this->contractVersion, 'features' => $this->features, 'limits' => (object)$this->limits, 'crm' => (object)$this->crm];
    }
}
