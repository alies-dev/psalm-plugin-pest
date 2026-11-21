<?php

declare(strict_types=1);

namespace PestCliOnlyFixture;

abstract class AppTestCase extends \PHPUnit\Framework\TestCase
{
    protected function helper(): string
    {
        return 'helper';
    }

    public function publicHelper(): string
    {
        return 'public helper';
    }

    public static function staticHelper(): string
    {
        return 'static helper';
    }
}
