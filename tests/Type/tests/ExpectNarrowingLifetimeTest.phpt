--FILE--
<?php declare(strict_types=1);

namespace PestExpectNarrowingLifetimeFixture;

final class Response
{
    public bool $success = false;
}

final class Cart
{
    public ?Response $response = null;
}

final class Order
{
    public ?string $status = null;

    public function ship(): void
    {
        $this->status = 'shipped';
    }

    public function shipThroughHelper(): void
    {
        $this->mark('shipped');
    }

    private function mark(string $status): void
    {
        $this->status = $status;
    }
}

final class Shipper
{
    public function ship(Order $order): void
    {
        $order->status = 'shipped';
    }

    public function label(Order $order): string
    {
        $order->status = 'labelled';

        return 'labelled';
    }
}

function shipOrder(Order $order): void
{
    $order->status = 'shipped';
}

/** @property ?\DateTimeImmutable $verified_at */
final class User
{
    /** @var array<string, mixed> */
    private array $attributes = [];

    public function __get(string $name): mixed
    {
        return $this->attributes[$name] ?? null;
    }

    public function markVerified(): void
    {
        $this->attributes['verified_at'] = new \DateTimeImmutable();
    }
}

// The issue: consecutive expect() statements keep the narrowing of a property path.
function consecutive(Cart $cart): void
{
    expect($cart->response)->toBeInstanceOf(Response::class);
    expect($cart->response->success)->toBeTrue();
    /** @psalm-check-type-exact $cart->response = Response */
    /** @psalm-check-type-exact $cart->response->success = true */
    expect($cart->response->success)->not->toBeFalse();
}

// Arrange, act, assert: the act step may write the property in ways the analysis does not see, so a narrowing made
// before it ends there and the assertion after it meets the declared type.
function service(Order $order, Shipper $shipper): void
{
    expect($order->status)->toBeNull();
    $shipper->ship($order);
    expect($order->status)->toBeString();
}

function ownMethodThroughHelper(Order $order): void
{
    expect($order->status)->toBeNull();
    $order->shipThroughHelper();
    expect($order->status)->toBeString();
}

function freeFunction(Order $order): void
{
    expect($order->status)->toBeNull();
    shipOrder($order);
    expect($order->status)->toBeString();
}

function closure(Order $order): void
{
    expect($order->status)->toBeNull();
    (static function () use ($order): void {
        $order->status = 'shipped';
    })();
    expect($order->status)->toBeString();
}

function magicProperty(User $user): void
{
    expect($user->verified_at)->toBeNull();
    $user->markVerified();
    expect($user->verified_at)->toBeInstanceOf(\DateTimeImmutable::class);
}

// A property write through another variable may reach the same object.
function alias(Order $order, Order $alias): void
{
    expect($order->status)->toBeNull();
    $alias->status = 'shipped';
    expect($order->status)->toBeString();
}

// What Psalm rewrites in scope meanwhile (a read for the data flow, branches merging) does not keep the narrowing.
function readBeforeAct(Order $order, Shipper $shipper): void
{
    expect($order->status)->toBeNull();
    echo $order->status;
    $shipper->ship($order);
    expect($order->status)->toBeString();
}

function narrowedInBranches(Order $order, Shipper $shipper, bool $flag): void
{
    if ($flag) {
        expect($order->status)->toBeNull();
    } else {
        expect($order->status)->toBeNull();
    }
    $shipper->ship($order);
    expect($order->status)->toBeString();
}

function actInBranch(Order $order, Shipper $shipper, bool $flag): void
{
    expect($order->status)->toBeNull();
    if ($flag) {
        $shipper->ship($order);
    }
    expect($order->status)->toBeString();
}

/** @param list<int> $items */
function narrowedInLoop(Order $order, Shipper $shipper, array $items): void
{
    foreach ($items as $_) {
        expect($order->status)->toBeNull();
    }
    $shipper->ship($order);
    expect($order->status)->toBeString();
}

// The narrowing goes back to the type the path had before the assertion, not to the declared one.
function previousType(Order $order, Shipper $shipper): string
{
    $order->status = 'new';
    expect($order->status)->toBeString();
    $shipper->ship($order);
    /** @psalm-check-type-exact $order->status = 'new' */
    return $order->status;
}

// A path the code writes holds Psalm's type for the write, which Psalm keeps across calls as without the plugin.
function writtenAfterNarrowing(Order $order, Shipper $shipper): string
{
    expect($order->status)->toBeString();
    echo $order->status;
    /** @psalm-check-type-exact $order->status = string */
    $order->status = $shipper->label($order);
    /** @psalm-check-type-exact $order->status = string */
    $shipper->ship($order);

    return $order->status;
}

// An expect() statement that runs code ends the narrowing too: an argument calling something, a callable Pest calls
// (`toThrow()` calls the subject), a member Pest does not declare (a custom expectation, a higher-order call).
function actInsideExpect(Order $order, Shipper $shipper): void
{
    expect($order->status)->toBeNull();
    expect(static fn() => $shipper->ship($order))->not->toThrow(\Exception::class);
    expect($order->status)->toBeString();
}

function actAsSubject(Order $order, \Closure $act): void
{
    expect($order->status)->toBeNull();
    expect($act)->not->toThrow(\Exception::class);
    expect($order->status)->toBeString();
}

function actAsArgument(Order $order, Shipper $shipper): void
{
    expect($order->status)->toBeNull();
    expect($order)->toBeInstanceOf(Order::class, $shipper->label($order));
    expect($order->status)->toBeString();
}

/** @param \Closure(\Pest\Expectation<Order>): mixed $act */
function actAsCallableArgument(Order $order, \Closure $act): void
{
    expect($order->status)->toBeNull();
    expect($order)->when(true, $act);
    expect($order->status)->toBeString();
}

function actAsHigherOrderCall(Order $order): void
{
    expect($order->status)->toBeNull();
    expect($order)->ship();
    expect($order->status)->toBeString();
}

// A plain variable lives on: a call cannot reassign it.
function plainVariable(?string $status, Shipper $shipper, Order $order): void
{
    expect($status)->toBeNull();
    $shipper->ship($order);
    /** @psalm-check-type-exact $status = null */
    $shipper->ship($order);
}

final class Holder
{
    public ?string $prop = null;
    public static ?string $static = null;
    /** @var array{k: ?string} */
    public array $arr = ['k' => null];
}

// Never narrowed: a nullsafe fetch, a static property, an array offset, a dynamic name.
function nullsafe(?Holder $maybe): string
{
    expect($maybe?->prop)->toBeString();

    return $maybe?->prop;
}

function staticProperty(): string
{
    expect(Holder::$static)->toBeString();

    return Holder::$static;
}

function offset(Holder $holder): string
{
    expect($holder->arr['k'])->toBeString();

    return $holder->arr['k'];
}

/** @param 'prop' $name */
function dynamicName(Holder $holder, string $name): string
{
    expect($holder->{$name})->toBeString();

    return $holder->{$name};
}
?>
--EXPECTF--
InvalidNullableReturnType on line 248: The declared return type 'string' for PestExpectNarrowingLifetimeFixture\nullsafe is not nullable, but 'null|string' contains null
NullableReturnStatement on line 252: The declared return type 'string' for PestExpectNarrowingLifetimeFixture\nullsafe is not nullable, but the function returns 'null|string'
InvalidNullableReturnType on line 255: The declared return type 'string' for PestExpectNarrowingLifetimeFixture\staticProperty is not nullable, but 'null|string' contains null
NullableReturnStatement on line 259: The declared return type 'string' for PestExpectNarrowingLifetimeFixture\staticProperty is not nullable, but the function returns 'null|string'
InvalidNullableReturnType on line 262: The declared return type 'string' for PestExpectNarrowingLifetimeFixture\offset is not nullable, but 'null|string' contains null
NullableReturnStatement on line 266: The declared return type 'string' for PestExpectNarrowingLifetimeFixture\offset is not nullable, but the function returns 'null|string'
InvalidNullableReturnType on line 270: The declared return type 'string' for PestExpectNarrowingLifetimeFixture\dynamicName is not nullable, but 'null|string' contains null
NullableReturnStatement on line 274: The declared return type 'string' for PestExpectNarrowingLifetimeFixture\dynamicName is not nullable, but the function returns 'null|string'
