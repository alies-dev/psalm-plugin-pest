<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use PhpParser\Node\Name;

/**
 * Reads the `resolvedName` / `namespacedName` attributes a name resolver leaves on a {@see Name}.
 *
 * Which resolver ran decides the attribute's type: Psalm's own one stores plain strings, php-parser's
 * `NameResolver` stores {@see Name} objects. Both are accepted, anything else is treated as absent.
 *
 * @internal
 */
final class NameResolution
{
    /**
     * The fully qualified name the resolver attached to `$name` (an import, a fully qualified name, or an
     * unqualified one outside any namespace), or null when it could not resolve it on its own.
     *
     * @return non-empty-string|null
     */
    public static function resolved(Name $name): ?string
    {
        return self::asString($name->getAttribute('resolvedName'));
    }

    /**
     * The function a call reaches at runtime: the lowercased name it has when nothing shadows it and,
     * for an unqualified name inside a namespace, the lowercased namespaced function that takes
     * precedence over it whenever it exists (PHP falls back to the global function only when the
     * namespace declares none of that name). Whether the shadowing function exists is for the caller to
     * look up.
     *
     * @return array{lowercase-string, non-empty-lowercase-string|null}
     */
    public static function functionName(Name $name): array
    {
        $resolved = self::resolved($name);
        if ($resolved !== null) {
            return [\strtolower($resolved), null];
        }

        $namespaced = self::asString($name->getAttribute('namespacedName'));

        return [\strtolower($name->toString()), $namespaced === null ? null : \strtolower($namespaced)];
    }

    /**
     * @return non-empty-string|null
     *
     * @psalm-pure
     */
    private static function asString(mixed $attribute): ?string
    {
        if ($attribute instanceof Name) {
            $attribute = $attribute->toString();
        }

        return \is_string($attribute) && $attribute !== '' ? $attribute : null;
    }
}
