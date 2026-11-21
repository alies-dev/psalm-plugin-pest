<?php

declare(strict_types=1);

namespace Other\Delivery;

use Fixture\Tests\Stubs\TextDividerRenderer;

final class Factory
{
    public static function divider(): string
    {
        return (new TextDividerRenderer())->render();
    }
}
