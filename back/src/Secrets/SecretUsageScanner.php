<?php

declare(strict_types=1);

namespace Wps\Secrets;

/**
 * Where a secret is referenced: exact walk of the definitions found by a LIKE pre-filter.
 */
final class SecretUsageScanner
{
    public function __construct(private readonly SecretRepository $secrets)
    {
    }

    /**
     * @return list<array{process_id: int, process_name: string, path: string}>
     */
    public function usages(string $name): array
    {
        $result = [];
        foreach ($this->secrets->processesMentioning($name) as $process) {
            foreach (self::paths($process['definition'], $name) as $path) {
                $result[] = ['process_id' => $process['id'], 'process_name' => $process['name'], 'path' => $path];
            }
        }
        return $result;
    }

    /**
     * Paths of references to the secret in one definition ("secret://abc" does not match the secret "ab").
     *
     * @param array<mixed> $definition
     * @return list<string>
     */
    public static function paths(array $definition, string $name): array
    {
        $paths = [];
        foreach (SecretReference::findAll($definition) as $found) {
            $reference = SecretReference::parse($found['value']);
            if ($reference !== null && $reference->name === $name) {
                $paths[] = $found['path'];
            }
        }
        return $paths;
    }
}
