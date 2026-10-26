<?php

declare(strict_types=1);

namespace Wps\Template;

/**
 * What a substitution of a missing value (no variable, no field, null variable) gives.
 */
enum MissingPolicy: string
{
    /** empty string, the path is reported in RenderResult::$missing */
    case Empty = 'empty';
    /** the step fails: Template references missing value "v1.email" */
    case Error = 'error';
    /** the tag stays in the text as is (debugging) */
    case Keep = 'keep';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(static fn (self $p) => $p->value, self::cases());
    }
}
