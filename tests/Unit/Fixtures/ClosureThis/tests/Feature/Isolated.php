<?php

declare(strict_types=1);

// Same TestCase as Feature/BeforeEachState.php, which assigns `$setupState` in its beforeEach():
// that property must stay undefined here (the second expected finding).
test('does not inherit another file\'s beforeEach properties', function (): void {
    $_state = $this->setupState;
});
