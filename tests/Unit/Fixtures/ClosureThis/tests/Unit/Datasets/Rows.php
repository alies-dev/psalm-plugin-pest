<?php

declare(strict_types=1);

// A shared dataset runs on the test that consumes it, unknown here: with only the fallback TestCase, its rows stay unbound.
dataset('shared rows', ['row' => fn(): mixed => $this->anything]);
