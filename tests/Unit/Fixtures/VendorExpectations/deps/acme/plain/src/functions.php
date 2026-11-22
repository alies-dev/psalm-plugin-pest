<?php

declare(strict_types=1);

namespace Acme\Plain;

/** A package without any expectation: its autoload file is only read, never parsed. */
function shout(string $text): string
{
    return \strtoupper($text);
}
