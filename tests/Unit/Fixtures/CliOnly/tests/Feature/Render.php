<?php

declare(strict_types=1);

// plugin-phpunit marks every TestCase subclass CLI-only; Pest runs only from the command line.
it('calls a TestCase method through $this', function (): void {
    expect($this->helper())->toBe('helper');
    expect(test()->publicHelper())->toBe('public helper');
});

beforeEach(function (): void {
    $this->helper();
});

// Top-level code of a test file runs in the same process.
\PestCliOnlyFixture\AppTestCase::staticHelper();
