--FILE--
<?php declare(strict_types=1);

namespace PestBeforeEachFixture {
    final class Parser
    {
        /** @psalm-pure */
        public function parse(string $input): string
        {
            return $input;
        }
    }

    /**
     * @template TKey of array-key
     * @template TValue
     */
    final class Bag
    {
        /**
         * @param array<TKey, TValue> $items
         * @psalm-pure
         */
        public function __construct(public array $items = [])
        {
        }
    }

    abstract class TestCase extends \PHPUnit\Framework\TestCase
    {
        protected string $token = '';
    }
}

namespace OtherNs {
    // Not Pest's: its closures declare nothing on a TestCase.
    function beforeEach(\Closure $setup): void
    {
        $setup();
    }

    beforeEach(function (): void {
        $this->unrelated = 1;
    });

    // Nor is a beforeEach() method of another class.
    final class Service
    {
        public int $foreign = 0;

        /** @param-closure-this Service $setup */
        public function beforeEach(\Closure $setup): void
        {
            $setup();
        }
    }

    (new Service())->beforeEach(function (): void {
        $this->foreign = 1;
    });
}

namespace {
    use function beforeEach as setup;

    uses(PestBeforeEachFixture\TestCase::class);

    // Existence does not depend on order: a test may precede the beforeEach() that sets its state.
    it('knows a property assigned later in the file', function (): void {
        $_known = isset($this->late);
    });

    beforeEach(function (): void {
        $this->parser = new PestBeforeEachFixture\Parser();
        $this->count = 0;
        $this->token = 'set';
        $this->late = true;
        // Filled elsewhere, so the empty initial value is not the type.
        $this->commands = [];
        $this->bag = new PestBeforeEachFixture\Bag();
    });

    // A nested closure keeps the bound `$this`.
    beforeEach(function (): void {
        array_map(function (string $name): string {
            $this->fromClosure = $name;

            return $name;
        }, ['a']);
    });

    describe('group', function (): void {
        beforeEach(function (): void {
            $this->nested = new PestBeforeEachFixture\Parser();
        });

        it('sees file-level and describe-level properties', function (): void {
            $_parser = $this->parser;
            /** @psalm-check-type-exact $_parser = PestBeforeEachFixture\Parser */
            $_nested = $this->nested;
            /** @psalm-check-type-exact $_nested = PestBeforeEachFixture\Parser */
        });
    });

    test('reads the recorded type', function (): void {
        $_parser = $this->parser;
        /** @psalm-check-type-exact $_parser = PestBeforeEachFixture\Parser */
        $_parsed = $this->parser->parse('x');
        /** @psalm-check-type-exact $_parsed = string */

        // Scalar literals widen, as a declared property would.
        $_count = $this->count;
        /** @psalm-check-type-exact $_count = int */

        // A declared property keeps its declared type.
        $_token = $this->token;
        /** @psalm-check-type-exact $_token = string */

        $_commands = $this->commands;
        /** @psalm-check-type-exact $_commands = array<array-key, mixed> */
        $_bag = $this->bag;
        /** @psalm-check-type-exact $_bag = PestBeforeEachFixture\Bag<mixed, mixed> */
    });

    afterEach(function (): void {
        // beforeEach() may have thrown before assigning, so teardown reads are nullable.
        $_parser = $this->parser;
        /** @psalm-check-type-exact $_parser = PestBeforeEachFixture\Parser|null */
        $_guarded = $this->parser?->parse('x');
        /** @psalm-check-type-exact $_guarded = null|string */
        if ($this->parser !== null && isset($this->count)) {
            $_set = true;
        }
        array_map(function (int $_n): void {
            $_nested = $this->parser;
            /** @psalm-check-type-exact $_nested = PestBeforeEachFixture\Parser|null */
        }, [1]);
        // A declared property keeps its declared type.
        $_token = $this->token;
        /** @psalm-check-type-exact $_token = string */
    });

    // A property set to `null` only is filled elsewhere in the file (#58): it reads as `mixed`.
    beforeEach(function (): void {
        $this->server = null;
        $this->bin = '/tmp/bin';
        $this->maybe = rand(0, 1) ? new PestBeforeEachFixture\Parser() : null;
        $this->maybeBin = rand(0, 1) ? '/tmp' : null;
    });

    test('assigns the null-only property', function (): void {
        $this->server = new PestBeforeEachFixture\Parser();
    });

    test('reads the null-only property', function (): void {
        $_server = $this->server;
        /** @psalm-check-type-exact $_server = mixed */
    });

    afterEach(function (): void {
        // `mixed` (the file assigns it elsewhere), so only the nullsafe call itself is reported.
        $_stopped = $this->server?->parse('x');
        if ($this->server !== null) {
            $_stopped = true;
        }

        // Plain teardown reads are not reported as possibly null (#59), guards stay meaningful.
        rmdir($this->bin);
        $_length = strlen($this->bin);
        $_parsed = $this->parser->parse('x');
        $_items = $this->bag->items;
        $_local = $this->bin;
        rmdir($_local);
        array_map(function (int $_n): void {
            rmdir($this->bin);
            $_inner = $this->parser->parse('x');
        }, [1]);
        $_guarded = $this->bin ?? 'x';
        if (isset($this->bin) && $this->count !== null) {
            $_set = true;
        }

        // A beforeEach() value that can be null keeps reporting.
        rmdir($this->maybeBin);
        $_missing = $this->maybe->parse('x');
    });

    test('a name never assigned in beforeEach is still undefined', function (): void {
        $_nope = $this->nope;
        $_nested = $this->fromClosure;
        /** @psalm-check-type-exact $_nested = string */
        $this->assigned = 1;
    });

    // An import alias still names Pest's global beforeEach().
    setup(function (): void {
        $this->aliased = 1;
    });

    test('resolves function names', function (): void {
        $_aliased = $this->aliased;
        /** @psalm-check-type-exact $_aliased = int */
        $_unrelated = $this->unrelated;
        $_foreign = $this->foreign;
    });

    // Fixtures are instance properties: a static access to one stays undefined, and never crashes. The
    // name is then not a fixture of this file at all, so the beforeEach() assignment reports too.
    beforeEach(function (): void {
        $this->fixture = 1;
    });

    test('static access to a fixture is undefined', function (): void {
        $_self = self::$fixture;
        $_static = static::$fixture;
        self::$fixture = 2;
        $class = PestBeforeEachFixture\TestCase::class;
        $_dynamic = $class::$fixture;
    });
}
?>
--EXPECTF--
InvalidScope on line %d: Invalid reference to $this in a non-class context
MixedMethodCall on line %d: Cannot determine the type of $__tmp_nullsafe__%d when calling method parse
PossiblyNullArgument on line %d: Argument 1 of rmdir cannot be null, possibly null value provided
PossiblyNullReference on line %d: Cannot call method parse on possibly null value
UndefinedThisPropertyFetch on line %d: Instance property PestBeforeEachFixture\TestCase::$nope is not defined
UndefinedThisPropertyAssignment on line %d: Instance property PestBeforeEachFixture\TestCase::$assigned is not defined
UndefinedThisPropertyFetch on line %d: Instance property PestBeforeEachFixture\TestCase::$unrelated is not defined
UndefinedThisPropertyFetch on line %d: Instance property PestBeforeEachFixture\TestCase::$foreign is not defined
UndefinedThisPropertyAssignment on line %d: Instance property PestBeforeEachFixture\TestCase::$fixture is not defined
UndefinedPropertyFetch on line %d: Static property PestBeforeEachFixture\TestCase::$fixture is not defined
UndefinedPropertyFetch on line %d: Static property PestBeforeEachFixture\TestCase::$fixture is not defined
UndefinedPropertyAssignment on line %d: Static property PestBeforeEachFixture\TestCase::$fixture is not defined
UndefinedPropertyFetch on line %d: Static property PestBeforeEachFixture\TestCase::$fixture is not defined
UndefinedPropertyFetch on line %d: Static property PestBeforeEachFixture\TestCase::$fixture is not defined
