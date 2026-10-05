--FILE--
<?php declare(strict_types=1);

namespace PestTraitsFixture {
    final class User
    {
    }

    trait CreatesUsers
    {
        public int $createdUsers = 0;

        protected function helper(): int
        {
            return 1;
        }

        public function createUser(): User
        {
            return new User();
        }
    }

    abstract class TestCase extends \PHPUnit\Framework\TestCase
    {
    }

    /** @psalm-pure */
    function needsTestCase(\PHPUnit\Framework\TestCase $case): string
    {
        return $case::class;
    }
}

namespace {
    // Traits next to the class: their members resolve on `$this`, which stays the TestCase.
    uses(PestTraitsFixture\TestCase::class, PestTraitsFixture\CreatesUsers::class);

    test('binds the traits', function (): void {
        $_user = $this->createUser();
        /** @psalm-check-type-exact $_user = PestTraitsFixture\User */
        $_count = $this->createdUsers;
        /** @psalm-check-type-exact $_count = int */
        // A protected trait method is reachable, as in the TestCase Pest generates; a trait property keeps its type.
        $_helped = $this->helper();
        /** @psalm-check-type-exact $_helped = int */
        $this->createdUsers = 'wrong';
        $this->assertTrue(true);
        $_class = PestTraitsFixture\needsTestCase($this);
        /** @psalm-check-type-exact $_class = string */
    });

    // A `@var` on a beforeEach() assignment sets the property's type.
    beforeEach(function (): void {
        /** @var PestTraitsFixture\User|null */
        $this->current = $this->createUser();
    });

    test('reads the annotated property', function (): void {
        $_current = $this->current;
        /** @psalm-check-type-exact $_current = PestTraitsFixture\User|null */
    });
}
?>
--EXPECTF--
InvalidPropertyAssignmentValue on line %d: $this->createdUsers with declared type 'int' cannot be assigned type ''wrong''
