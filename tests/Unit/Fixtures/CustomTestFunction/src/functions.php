<?php

declare(strict_types=1);

namespace Tests\Own;

/** The project's own it(), autoloaded like a Composer `files` entry and called from another file. */
function it(string $description, \Closure $closure): void
{
    $closure($description);
}
