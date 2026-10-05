--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\AliesDev\PsalmPluginPest\Type\PsalmCapability::skipWithoutClosureThis();
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
    });

    afterEach(function (): void {
        $_parser = $this->parser;
        /** @psalm-check-type-exact $_parser = PestBeforeEachFixture\Parser */
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
    });
}
?>
--EXPECTF--
InvalidScope on line %d: Invalid reference to $this in a non-class context
UndefinedThisPropertyFetch on line %d: Instance property PestBeforeEachFixture\TestCase::$nope is not defined
UndefinedThisPropertyAssignment on line %d: Instance property PestBeforeEachFixture\TestCase::$assigned is not defined
UndefinedThisPropertyFetch on line %d: Instance property PestBeforeEachFixture\TestCase::$unrelated is not defined
UndefinedThisPropertyAssignment on line %d: Instance property PestBeforeEachFixture\TestCase::$fixture is not defined
UndefinedPropertyFetch on line %d: Static property PestBeforeEachFixture\TestCase::$fixture is not defined
UndefinedPropertyFetch on line %d: Static property PestBeforeEachFixture\TestCase::$fixture is not defined
UndefinedPropertyAssignment on line %d: Static property PestBeforeEachFixture\TestCase::$fixture is not defined
UndefinedPropertyFetch on line %d: Static property PestBeforeEachFixture\TestCase::$fixture is not defined
