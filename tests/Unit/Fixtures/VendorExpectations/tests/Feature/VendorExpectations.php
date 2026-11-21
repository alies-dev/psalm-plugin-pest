<?php

declare(strict_types=1);

test('an expectation a package registers in its Composer files autoload is known', function (): void {
    expect('x')->toBeSnapshotLike('label');
    expect('x')->not->toBeSnapshotLike('label');
});

test('it is checked against the closure of the package', function (): void {
    expect('x')->toBeSnapshotLike();
});

test('a misspelled name is still reported', function (): void {
    expect('x')->toBeSnapshotLikee('label');
});
