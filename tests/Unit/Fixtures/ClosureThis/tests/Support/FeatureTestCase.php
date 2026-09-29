<?php

declare(strict_types=1);

namespace PestClosureThisFixture;

abstract class FeatureTestCase extends \PHPUnit\Framework\TestCase
{
    protected string $featureOnly = '';

    /** @psalm-pure */
    protected function signIn(): int
    {
        return 1;
    }
}
