<?php

declare(strict_types=1);

namespace Wps\Controllers\Dictionaries;

use Sidalex\SwooleApp\Classes\Controllers\Route;
use Swoole\Http\Response;
use Wps\Controllers\BaseController;
use Wps\Expression\FunctionSpec;

/**
 * Functions and operators of the expression language (md/tasks_list/done/5.2.1-expr-conditionals.md §7).
 */
#[Route(uri: '/api/dictionaries/functions', method: 'GET')]
final class FunctionsController extends BaseController
{
    private const OPERATORS = [
        ['op' => 'or', 'synonyms' => ['||'], 'precedence' => 1],
        ['op' => 'and', 'synonyms' => ['&&'], 'precedence' => 2],
        ['op' => 'not', 'synonyms' => ['!'], 'precedence' => 3],
        ['op' => '==', 'synonyms' => ['='], 'precedence' => 4],
        ['op' => '!=', 'synonyms' => ['<>'], 'precedence' => 4],
        ['op' => '<', 'synonyms' => [], 'precedence' => 4],
        ['op' => '<=', 'synonyms' => [], 'precedence' => 4],
        ['op' => '>', 'synonyms' => [], 'precedence' => 4],
        ['op' => '>=', 'synonyms' => [], 'precedence' => 4],
        ['op' => '+', 'synonyms' => [], 'precedence' => 5],
        ['op' => '-', 'synonyms' => [], 'precedence' => 5],
        ['op' => '*', 'synonyms' => [], 'precedence' => 6],
        ['op' => '/', 'synonyms' => [], 'precedence' => 6],
        ['op' => '%', 'synonyms' => [], 'precedence' => 6],
    ];

    protected function handle(): Response
    {
        $functions = array_map(static fn (FunctionSpec $s) => $s->toArray(), $this->container()->expressions()->functionSpecs());
        usort($functions, static fn (array $a, array $b) => [$a['category'], $a['name']] <=> [$b['category'], $b['name']]);
        return $this->ok($functions, 200, ['operators' => self::OPERATORS]);
    }
}
