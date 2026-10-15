<?php

declare(strict_types=1);

namespace Wps\Kernel;

use Sidalex\SwooleApp\Classes\Builder\RoutesCollectionBuilder;

/**
 * Route matching that requires the same number of URI segments as the pattern.
 * The stock matcher compares only the pattern's length, so GET /api/processes/5 would be
 * served by the GET /api/processes controller. The framework itself is not modified.
 */
final class WpsRoutesCollectionBuilder extends RoutesCollectionBuilder
{
    protected function findMatchingElement(array $array1, array $array2, string $method): array|null
    {
        // tolerate a trailing slash: /api/processes/ == /api/processes
        if (count($array2) > 2 && end($array2) === '') {
            array_pop($array2);
        }
        foreach ($array1 as $element) {
            if (strcasecmp($method, (string)$element['method']) !== 0) {
                continue;
            }
            $pattern = $element['route_pattern_list'];
            if (count($pattern) !== count($array2)) {
                continue;
            }
            $match = true;
            foreach ($pattern as $i => $segment) {
                if ($segment === '*') {
                    if ($array2[$i] === '') {
                        $match = false;
                        break;
                    }
                    continue;
                }
                if ($segment !== $array2[$i]) {
                    $match = false;
                    break;
                }
            }
            if ($match) {
                return $element;
            }
        }
        return null;
    }
}
