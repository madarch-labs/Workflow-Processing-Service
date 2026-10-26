<?php

declare(strict_types=1);

namespace Wps\Expression;

use Wps\Exceptions\ConfigurationException;

/**
 * Recursive-descent parser producing a small array-based AST (see ExpressionEngine).
 *
 *   expression  = or_expr
 *   or_expr     = and_expr { ("or" | "||") and_expr }
 *   and_expr    = not_expr { ("and" | "&&") not_expr }
 *   not_expr    = ("not" | "!") not_expr | comparison
 *   comparison  = additive [ comp_op additive ]            (not chainable: a < b < c is an error)
 *   comp_op     = "==" | "=" | "!=" | "<>" | "<" | "<=" | ">" | ">="
 *   additive    = term { ("+" | "-") term }
 *   term        = unary { ("*" | "/" | "%") unary }
 *   unary       = ("-" | "+") unary | primary
 *   primary     = NUMBER | STRING | PATH | "true" | "false" | "null" | "[" [ expression { "," expression } ] "]"
 *               | IDENT "(" [ expression { "," expression } ] ")" | "(" expression ")"
 */
final class ExpressionParser
{
    private const COMPARISON_OPS = ['==' => '==', '=' => '==', '!=' => '!=', '<>' => '!=', '<' => '<', '<=' => '<=', '>' => '>', '>=' => '>='];

    private int $pos = 0;
    private int $nodes = 0;
    private int $depth = 0;

    /**
     * @param list<array{0: string, 1: mixed, 2: int}> $tokens
     */
    public function __construct(
        private readonly array $tokens,
        private readonly string $source,
        private readonly int $maxNodes = ExpressionEngine::MAX_NODES,
        private readonly int $maxDepth = ExpressionEngine::MAX_DEPTH,
    ) {
    }

    /**
     * @return array<int, mixed>
     */
    public function parse(): array
    {
        if ($this->peek()[0] === 'eof') {
            throw new ConfigurationException('Expression is empty');
        }
        $node = $this->orExpr();
        $token = $this->peek();
        if ($token[0] !== 'eof') {
            throw $this->error($token, 'Unexpected "' . $token[1] . '"');
        }
        return $node;
    }

    /**
     * @return array<int, mixed>
     */
    private function orExpr(): array
    {
        $node = $this->andExpr();
        while ($this->isKeyword('or') || $this->isOp('||')) {
            $this->next();
            $node = $this->node(['or', $node, $this->andExpr()]);
        }
        return $node;
    }

    /**
     * @return array<int, mixed>
     */
    private function andExpr(): array
    {
        $node = $this->notExpr();
        while ($this->isKeyword('and') || $this->isOp('&&')) {
            $this->next();
            $node = $this->node(['and', $node, $this->notExpr()]);
        }
        return $node;
    }

    /**
     * @return array<int, mixed>
     */
    private function notExpr(): array
    {
        if ($this->isKeyword('not') || $this->isOp('!')) {
            $this->next();
            $this->enter();
            $node = $this->node(['not', $this->notExpr()]);
            $this->depth--;
            return $node;
        }
        return $this->comparison();
    }

    /**
     * @return array<int, mixed>
     */
    private function comparison(): array
    {
        $node = $this->additive();
        $op = $this->comparisonOp();
        if ($op === null) {
            return $node;
        }
        $this->next();
        $node = $this->node(['cmp', $op, $node, $this->additive()]);
        if ($this->comparisonOp() !== null) {
            throw $this->error($this->peek(), 'Comparison operators cannot be chained');
        }
        return $node;
    }

    private function comparisonOp(): ?string
    {
        $token = $this->peek();
        return $token[0] === 'op' && is_string($token[1]) ? (self::COMPARISON_OPS[$token[1]] ?? null) : null;
    }

    /**
     * @return array<int, mixed>
     */
    private function additive(): array
    {
        $node = $this->term();
        while ($this->isOp('+') || $this->isOp('-')) {
            $op = $this->next()[1];
            $node = $this->node(['bin', $op, $node, $this->term()]);
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
            $node = $this->node(['bin', $op, $node, $this->unary()]);
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
            $this->enter();
            $node = $this->node(['neg', $this->unary()]);
            $this->depth--;
            return $node;
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
                return $this->node(['num', $token[1]]);
            case 'str':
                return $this->node(['str', $token[1]]);
            case 'path':
                return $this->node(['path', $token[1]]);
            case 'kw':
                return match ($token[1]) {
                    'true' => $this->node(['bool', true]),
                    'false' => $this->node(['bool', false]),
                    'null' => $this->node(['null']),
                    default => throw $this->error($token, 'Unexpected "' . $token[1] . '"'),
                };
            case 'ident':
                if (!$this->isOp('(')) {
                    throw $this->error($token, "Unknown identifier \"{$token[1]}\" (use object.<field> or <variable>.<field>)");
                }
                $this->next();
                $this->enter();
                $args = $this->arguments(')');
                $this->depth--;
                return $this->node(['call', strtolower((string)$token[1]), $args]);
            case 'op':
                if ($token[1] === '(') {
                    $this->enter();
                    $node = $this->orExpr();
                    $this->expect(')');
                    $this->depth--;
                    return $node;
                }
                if ($token[1] === '[') {
                    $this->enter();
                    $items = $this->arguments(']');
                    $this->depth--;
                    return $this->node(['list', $items]);
                }
                throw $this->error($token, 'Unexpected "' . $token[1] . '"');
        }
        throw $this->error($token, 'Unexpected end of expression');
    }

    /**
     * @return list<array<int, mixed>>
     */
    private function arguments(string $close): array
    {
        $args = [];
        if (!$this->isOp($close)) {
            $args[] = $this->orExpr();
            while ($this->isOp(',')) {
                $this->next();
                $args[] = $this->orExpr();
            }
        }
        $this->expect($close);
        return $args;
    }

    /**
     * @param array<int, mixed> $node
     * @return array<int, mixed>
     */
    private function node(array $node): array
    {
        if (++$this->nodes > $this->maxNodes) {
            throw new ConfigurationException("Expression is too complex (max {$this->maxNodes} nodes)");
        }
        return $node;
    }

    private function enter(): void
    {
        if (++$this->depth > $this->maxDepth) {
            throw new ConfigurationException("Expression is nested too deeply (max {$this->maxDepth} levels)");
        }
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

    private function isKeyword(string $keyword): bool
    {
        $token = $this->peek();
        return $token[0] === 'kw' && $token[1] === $keyword;
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
