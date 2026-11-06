--FILE--
<?php declare(strict_types=1);

namespace PestToThrowFixture {
    function throwing(): void
    {
        // Pest reads the exception class off the closure's parameter, so a subclass of Throwable is the documented form.
        expect(fn () => throw new \RuntimeException('x'))
            ->toThrow(fn (\RuntimeException $e) => expect($e->getMessage())->toBe('x'));
        // Limitation: Pest throws at runtime for a closure whose parameter is a union, an intersection or untyped
        // (it needs one named class). The widened `Closure` type does not look at the parameter, so none is reported.

        // The class-string and instance forms are unaffected.
        expect(fn () => throw new \RuntimeException('x'))->toThrow(\RuntimeException::class);
        expect(fn () => throw new \RuntimeException('x'))->toThrow(\RuntimeException::class, 'x');
        expect(fn () => throw new \RuntimeException('x'))->toThrow(new \RuntimeException('x'));
        expect(fn () => throw new \RuntimeException('x'))->not->toThrow(fn (\LogicException $e) => $e);
    }

    function wrong(): void
    {
        expect(fn () => throw new \RuntimeException('x'))->toThrow(new \stdClass());
    }
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of Pest\Mixins\Expectation::toThrow expects %s, but stdClass provided
