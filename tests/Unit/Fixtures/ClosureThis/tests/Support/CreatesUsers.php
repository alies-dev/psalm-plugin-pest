<?php

declare(strict_types=1);

namespace PestClosureThisFixture;

trait CreatesUsers
{
    public int $createdUsers = 0;

    /** @psalm-pure */
    public function createUser(): User
    {
        return new User();
    }
}
