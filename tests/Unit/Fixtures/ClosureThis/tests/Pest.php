<?php

declare(strict_types=1);

use PestClosureThisFixture\CreatesUsers;
use PestClosureThisFixture\FeatureTestCase;
use PestClosureThisFixture\User;

// The trait and the hook's `$this->shared` reach the files `in()` targets, and only those.
pest()
    ->extend(FeatureTestCase::class)
    ->use(CreatesUsers::class)
    ->beforeEach(fn() => $this->shared = new User())
    ->beforeEach(function (): void {
        $_id = $this->signIn();
        $_user = $this->seed()->createUser();
        /** @var User|null */
        $this->maybe = null;
    })
    ->in('Feature');

// Declared in the boot file: shared like a Datasets.php, so with only the fallback TestCase its rows stay unbound.
dataset('boot rows', ['row' => fn(): mixed => $this->bootOnly]);
