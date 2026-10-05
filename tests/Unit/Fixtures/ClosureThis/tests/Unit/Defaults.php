<?php

declare(strict_types=1);

// Outside every in() target: Pest's default PHPUnit TestCase, so the Feature-only property is
// reported against it (the single expected finding).
test('falls back to the PHPUnit TestCase', function (): void {
    /** @psalm-check-type-exact $this = PHPUnit\Framework\TestCase */
    $this->assertTrue(true);
    $_value = $this->featureOnly;
});

test('does not get the Feature trait or hook property', function (): void {
    $_user = $this->createUser();
    $_shared = $this->shared;
});
