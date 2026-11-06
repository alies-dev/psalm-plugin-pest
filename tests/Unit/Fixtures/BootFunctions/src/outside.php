<?php

declare(strict_types=1);

// Outside the tests directory Pest has not loaded the boot files.
buildThing();
expect(1.0)->toBePrice(1.0);
