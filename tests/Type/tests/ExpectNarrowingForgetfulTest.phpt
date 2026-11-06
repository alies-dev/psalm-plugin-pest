--ARGS--
--config=tests/Type/psalm-forget-properties.xml
--FILE--
<?php declare(strict_types=1);

namespace PestExpectNarrowingForgetfulFixture;

final class Holder
{
    public ?string $prop = null;
    public int $int = 0;
}

final class Other {}

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

// An assertion that cannot hold keeps the declared type, as it does for a path still in scope.
function impossibleDeclared(Holder $holder): void
{
    expect($holder->int)->toBeFloat();
    /** @psalm-check-type-exact $holder->int = int */
    echo seen($holder->int);

    expect($holder->prop)->toBeInstanceOf(Other::class);
    /** @psalm-check-type-exact $holder->prop = string|null */
    echo seen($holder->prop);
}
?>
--EXPECTF--
PestImpossibleExpectation on line 35: Pest's toBeInt() is a strict type check that always fails for null|string; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line 43: Pest's toBeFloat() is a strict type check that always fails for int; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line 47: Pest's toBeInstanceOf() is a strict type check that always fails for null|string; assert the type the value has or remove the assertion.
