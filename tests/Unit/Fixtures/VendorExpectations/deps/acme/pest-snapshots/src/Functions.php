<?php

declare(strict_types=1);

namespace Acme\Snapshots;

// As spatie/pest-plugin-snapshots does in its `files` autoload.
expect()->extend('toBeSnapshotLike', function (string $label): void {
    expect($label)->toBeString();
});
