<?php

declare(strict_types=1);

namespace Wps\Services\Validation;

/**
 * Results shared by a DefinitionScope and all its branch forks.
 */
final class ValidationLog
{
    /** @var list<array{field: string, message: string}> */
    public array $errors = [];

    /** @var list<array{field: string, message: string}> */
    public array $warnings = [];

    /** @var array<string, string> step id => path of the step */
    public array $stepIds = [];

    /** @var array<int, string> child process id => field path (where it is referenced) */
    public array $childReferences = [];
}
