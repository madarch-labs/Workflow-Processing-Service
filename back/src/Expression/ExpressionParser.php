<?php

declare(strict_types=1);

namespace Wps\Expression;

use Wps\Exceptions\ConfigurationException;

/**
 * Recursive-descent parser producing a small array-based AST (see ExpressionEngine).
 */
final class ExpressionParser
{
    private int $pos = 0;

    /**
     * @param list<array{0: string, 1: mixed, 2: int}> $tokens
     */
    public function __construct(private readonly array $tokens, private readonly string $source)
    {
    }

    /**
     * @return array<int, mixed>
     */
    public function parse(): array
    {
        if ($this->peek()[0] === 'eof') {
            throw new ConfigurationException('Expression is empty');
        }
        $node = $this->expr();
        $token = $this->peek();
        if ($token[0] !== 'eof') {
            throw $this->error($token, 'Unexpected "' . $token[1] . '"');
        }
        return $node;
    }

    /**
     * @return array<int, mixed>
     */
    private function expr(): array
    {
        $node = $this->term();
        while ($this->isOp('+') || $this->isOp('-')) {
            $op = $this->next()[1];
            $node = ['bin', $op, $node, $this->term()];
        }
        return $node;
    }

    /**
     * @return array<int, mixed>
     */
    private function term(): array
    {
        $node = $this->unary();
        while ($this->isOp('*') || $this->isOp('/') || $this->isOp('%')) {
            $op = $this->next()[1];
            $node = ['bin', $op, $node, $this->unary()];
        }
        return $node;
    }

    /**
     * @return array<int, mixed>
     */
    private function unary(): array
    {
        if ($this->isOp('-')) {
            $this->next();
            return ['neg', $this->unary()];
        }
        if ($this->isOp('+')) {
            $this->next();
            return $this->unary();
        }
        return $this->primary();
    }

    /**
     * @return array<int, mixed>
     */
    private function primary(): array
    {
        $token = $this->next();
        switch ($token[0]) {
            case 'num':
                return ['num', $token[1]];
            case 'str':
                return ['str', $token[1]];
            case 'path':
                return ['path', $token[1]];
            case 'ident':
                if (!$this->isOp('(')) {
                    throw $this->error($token, "Unknown identifier \"{$token[1]}\" (use object.<field> or <variable>.<field>)");
                }
                $this->next();
                $args = [];
                if (!$this->isOp(')')) {
                    $args[] = $this->expr();
                    while ($this->isOp(',')) {
                        $this->next();
                        $args[] = $this->expr();
                    }
                }
                $this->expect(')');
                return ['call', strtolower((string)$token[1]), $args];
            case 'op':
                if ($token[1] === '(') {
                    $node = $this->expr();
                    $this->expect(')');
                    return $node;
                }
                throw $this->error($token, 'Unexpected "' . $token[1] . '"');
        }
        throw $this->error($token, 'Unexpected end of expression');
    }

    /**
     * @return array{0: string, 1: mixed, 2: int}
     */
    private function peek(): array
    {
        return $this->tokens[$this->pos];
    }

    /**
     * @return array{0: string, 1: mixed, 2: int}
     */
    private function next(): array
    {
        $token = $this->tokens[$this->pos];
        if ($token[0] !== 'eof') {
            $this->pos++;
        }
        return $token;
    }

    private function isOp(string $op): bool
    {
        $token = $this->peek();
        return $token[0] === 'op' && $token[1] === $op;
    }

    private function expect(string $op): void
    {
        $token = $this->next();
        if ($token[0] !== 'op' || $token[1] !== $op) {
            throw $this->error($token, "Expected \"{$op}\"");
        }
    }

    /**
     * @param array{0: string, 1: mixed, 2: int} $token
     */
    private function error(array $token, string $message): ConfigurationException
    {
        return new ConfigurationException(sprintf('%s at position %d in "%s"', $message, $token[2], $this->source));
    }
}
