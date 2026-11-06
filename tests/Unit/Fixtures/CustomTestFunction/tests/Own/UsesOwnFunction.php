<?php

declare(strict_types=1);

namespace Tests\Own;

// Resolves to Tests\Own\it(), not Pest's global function.
it('has an empty callback of its own', function (): void {});
