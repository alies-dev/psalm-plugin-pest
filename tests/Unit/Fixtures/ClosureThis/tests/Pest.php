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
        /** @var User|null */
        $this->maybe = null;
    })
    ->in('Feature');
