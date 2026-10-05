<?php

declare(strict_types=1);

// Mapped by tests/Pest.php: its trait and its beforeEach() property, with no declaration here.
test('reads the trait and the hook property from Pest.php', function (): void {
    $_user = $this->seed()->createUser();
    /** @psalm-check-type-exact $_user = PestClosureThisFixture\User */
    $_count = $this->createdUsers;
    /** @psalm-check-type-exact $_count = int */
    $_shared = $this->shared;
    /** @psalm-check-type-exact $_shared = PestClosureThisFixture\User */
    // A `@var` on the Pest.php assignment sets the type.
    $_maybe = $this->maybe;
    /** @psalm-check-type-exact $_maybe = PestClosureThisFixture\User|null */
});
