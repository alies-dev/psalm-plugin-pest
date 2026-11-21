<?php

declare(strict_types=1);

namespace Fixture\Tests\Stubs;

class TextDividerRenderer
{
    public string $divider = '---';

    public function render(): string
    {
        return $this->divider;
    }

    public static function make(): self
    {
        return new self();
    }
}
