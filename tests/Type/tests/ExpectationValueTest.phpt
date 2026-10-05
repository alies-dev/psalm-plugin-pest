--FILE--
<?php declare(strict_types=1);

namespace PestExpectationValueFixture {
    final class User
    {
        public string $name = 'x';

        public string $email = 'x@y';

        public ?int $age = null;

        public function getName(): string
        {
            return $this->name;
        }

        /** @psalm-pure */
        public function add(int $_n): int
        {
            return $_n;
        }
    }

    function value(int|string $intOrString, ?User $maybe, User $user): void
    {
        // The expectation is of the argument, not of the argument or null.
        $_value = expect($intOrString);
        /** @psalm-check-type-exact $_value = \Pest\Expectation<int|string> */

        $_none = expect();
        /** @psalm-check-type-exact $_none = \Pest\Expectation<null> */

        $_nullable = expect($maybe);
        /** @psalm-check-type-exact $_nullable = \Pest\Expectation<User|null> */

        // A type assertion hands back the expectation of the narrowed value.
        $_int = expect($intOrString)->toBeInt();
        /** @psalm-check-type-exact $_int = \Pest\Expectation<int> */

        $_notString = expect($intOrString)->not->toBeString();
        /** @psalm-check-type-exact $_notString = \Pest\Expectation<int> */

        $_notNull = expect($maybe)->not->toBeNull();
        /** @psalm-check-type-exact $_notNull = \Pest\Expectation<User> */

        $_instance = expect($maybe)->toBeInstanceOf(User::class);
        /** @psalm-check-type-exact $_instance = \Pest\Expectation<User> */

        $_chain = expect($maybe)->not->toBeNull()->toBe($user)->not->toBeNull();
        /** @psalm-check-type-exact $_chain = \Pest\Expectation<User> */

        // A higher-order expectation is typed from the value's member ...
        $_property = expect($user)->name;
        /** @psalm-check-type-exact $_property = \Pest\Expectations\HigherOrderExpectation<\Pest\Expectation<User>, string> */

        $_method = expect($user)->getName();
        /** @psalm-check-type-exact $_method = \Pest\Expectations\HigherOrderExpectation<\Pest\Expectation<User>, string> */

        $_nullableMember = expect($maybe)->age;
        /** @psalm-check-type-exact $_nullableMember = \Pest\Expectations\HigherOrderExpectation<\Pest\Expectation<User|null>, int|null> */

        // ... and an assertion hands the chain back to the original value.
        $_back = expect($user)->name->toBe('x');
        /** @psalm-check-type-exact $_back = \Pest\Expectations\HigherOrderExpectation<\Pest\Expectation<User>, User> */

        $_next = expect($user)->name->toBe('x')->email;
        /** @psalm-check-type-exact $_next = \Pest\Expectations\HigherOrderExpectation<\Pest\Expectation<User>, string> */

        expect($user)->name->toBe('x')->email->toContain('@')->getName()->toBe('x');

        // A member the value does not have is mixed for a property, reported for a method.
        $_unknown = expect($user)->nothing;
        /** @psalm-check-type-exact $_unknown = \Pest\Expectations\HigherOrderExpectation<\Pest\Expectation<User>, mixed> */

        expect($user)->nothing();
        expect($intOrString)->toBeIntt();

        // A forwarded method checks the arguments it is called with.
        expect($user)->add(1)->toBe(2);
        expect($user)->add('x');
        expect($user)->add();

        // A first-class callable stays a Closure.
        $_callable = expect($intOrString)->toBeInt(...);
        $_callable();
    }
}
?>
--EXPECTF--
UndefinedMagicMethod on line %d: Magic method PestExpectationValueFixture\User::nothing does not exist
UndefinedMagicMethod on line %d: Magic method string::tobeintt does not exist
InvalidScalarArgument on line %d: Argument 1 of Pest\Expectation::add expects int, but 'x' provided
TooFewArguments on line %d: Too few arguments for Pest\Expectation::add - expecting _n to be passed
