--ARGS--
--config=tests/Type/psalm-forget-properties.xml
--FILE--
<?php declare(strict_types=1);

namespace PestExpectNarrowingForgetfulFixture;

final class Holder
{
    public ?string $prop = null;
}

/** @psalm-pure */
function seen(mixed $_value): int
{
    return 0;
}

// With `rememberPropertyAssignmentsAfterCall="false"` Psalm forgets a property path after every call, Pest's own
// assertion calls included. The narrowing is rebuilt from the declared type.
function narrowed(Holder $holder): string
{
    expect($holder->prop)->not->toBeNull();
    /** @psalm-check-type-exact $holder->prop = string */
    echo seen($holder->prop);

    return $holder->prop;
}

function impossible(Holder $holder): void
{
    expect($holder->prop)->toBeInt();
    /** @psalm-check-type-exact $holder->prop = string|null */
    echo seen($holder->prop);
}
?>
--EXPECTF--
PestImpossibleExpectation on line 32: Pest's toBeInt() is a strict type check that always fails for null|string; assert the type the value has or remove the assertion.
