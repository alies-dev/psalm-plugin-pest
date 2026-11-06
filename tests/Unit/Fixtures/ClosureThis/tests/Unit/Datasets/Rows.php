<?php

declare(strict_types=1);

// A shared dataset runs on the test that consumes it: outside every in() target, it gets the
// fallback TestCase, a member of every consumer.
dataset('shared rows', ['row' => fn(): object => $this->createMock(Countable::class)]);

dataset('shared lazy rows', fn(): array => [[fn(): object => test()->createMock(Countable::class), 1]]);
