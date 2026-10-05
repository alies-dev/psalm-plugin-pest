<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use PhpParser\Node\Name;

/**
 * @internal
 */
final class NameResolution
{
    /**
     * The string attribute Psalm's own name resolver left on `$name`: `resolvedName` (an import, a
     * fully qualified name, or an unqualified one outside any namespace) or, when it could not
     * resolve the name on its own, `namespacedName`.
     */
    public static function resolved(Name $name, string $attribute = 'resolvedName'): ?string
    {
        /** @psalm-suppress MixedAssignment */
        $value = $name->getAttribute($attribute);

        return \is_string($value) ? $value : null;
    }
}
