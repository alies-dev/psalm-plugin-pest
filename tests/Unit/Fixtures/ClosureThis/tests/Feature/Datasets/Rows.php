<?php

declare(strict_types=1);

// A shared dataset below a directory mapped by Pest.php is bound to that TestCase.
dataset('feature rows', ['row' => fn(): int => $this->signIn()]);
