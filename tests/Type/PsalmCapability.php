<?php

declare(strict_types=1);

namespace Tests\AliesDev\PsalmPluginPest\Type;

use Psalm\Storage\FunctionLikeParameter;

/**
 * Capability probes for phpt `--SKIPIF--` sections that depend on a Psalm feature rather than a
 * Psalm version. Each method echoes a `skip ...` message (which the runner detects) when the
 * installed Psalm lacks the feature.
 */
final class PsalmCapability
{
    /** `@param-closure-this`: Psalm 6.19+ and vimeo/psalm master, not Psalm 7.0.0-beta22. */
    public static function skipWithoutClosureThis(): void
    {
        if (!\property_exists(FunctionLikeParameter::class, 'closure_this_type')) {
            echo 'skip needs Psalm @param-closure-this support (FunctionLikeParameter::$closure_this_type)';
        }
    }
}
