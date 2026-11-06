<?php

declare(strict_types=1);

// A shared dataset runs on the test that consumes it, unknown here: with only the fallback TestCase,
// its rows are bound to it, as every consumer is some PHPUnit TestCase.
dataset('shared rows', ['row' => fn(): object => $this->createMock(\Countable::class)]);
