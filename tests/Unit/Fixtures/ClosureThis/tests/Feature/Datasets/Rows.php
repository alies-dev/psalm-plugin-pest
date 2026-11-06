<?php

declare(strict_types=1);

use PestClosureThisFixture\User;

// A shared dataset below a directory Pest.php maps gets that TestCase and its trait.
dataset('feature rows', [
    'row' => function (): int {
        $_this = $this;
        /** @psalm-check-type-exact $_this = PestClosureThisFixture\FeatureTestCase */
        return $this->signIn();
    },
    'array row' => [fn(): User => test()->createUser(), 'x'],
]);

dataset('feature lazy rows', function (): Generator {
    yield fn(): int => test()->signIn();
    yield 'k' => [fn(): User => $this->seed()->createUser()];
});
