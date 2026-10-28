<?php

declare(strict_types=1);

namespace Wps\Template;

/**
 * Collects missing paths and warnings during one render (a fresh object per call: renders run concurrently).
 */
final class RenderState
{
    /** @var list<string> */
    public array $missing = [];

    /** @var list<string> */
    public array $warnings = [];
}
