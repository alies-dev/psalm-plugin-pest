--ARGS--
--config=tests/Type/psalm-forget-properties.xml
--FILE--
<?php declare(strict_types=1);

namespace PestExpectNarrowingForgetfulFixture;

final class Holder
{
    public ?string $prop = null;
    public int $int = 0;

    public function clear(): string
    {
        $this->prop = null;

        return '';
    }
}

final class Service
{
    public function fill(Holder $holder): void
    {
        $holder->prop = 'filled';
    }
}

final class Other {}

// With `rememberPropertyAssignmentsAfterCall="false"` Psalm forgets a property path after every call, Pest's own
// assertion calls included. The narrowing is rebuilt from the declared type.
function narrowed(Holder $holder): string
{
    /** @psalm-check-type-exact $holder->prop = string */
    expect($holder->prop)->not->toBeNull();

    return $holder->prop;
}

// A call in the chain runs after Pest captured the subject: no property path is narrowed.
function callInChain(Holder $holder): void
{
    expect($holder->prop)->toBeString($holder->clear());
    /** @psalm-check-type-exact $holder->prop = string|null */
    echo $holder->prop;
}

// Arrange, act, assert: the act step ends the narrowing, as Psalm forgets the path itself.
function act(Holder $holder, Service $service): void
{
    expect($holder->prop)->toBeNull();
    $service->fill($holder);
    expect($holder->prop)->toBeString();
}

function impossible(Holder $holder): void
{
    /** @psalm-check-type-exact $holder->prop = string|null */
    expect($holder->prop)->toBeInt();
}

// An assertion that cannot hold keeps the declared type, as it does for a path still in scope.
function impossibleDeclared(Holder $holder): void
{
    /** @psalm-check-type-exact $holder->int = int */
    expect($holder->int)->toBeFloat();

    /** @psalm-check-type-exact $holder->prop = string|null */
    expect($holder->prop)->toBeInstanceOf(Other::class);
}
?>
--EXPECTF--
PestImpossibleExpectation on line 60: Pest's toBeInt() is a strict type check that always fails for null|string; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line 67: Pest's toBeFloat() is a strict type check that always fails for int; assert the type the value has or remove the assertion.
PestImpossibleExpectation on line 70: Pest's toBeInstanceOf() is a strict type check that always fails for null|string; assert the type the value has or remove the assertion.
