<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Issue\UndefinedMagicMethod;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\StatementsSource;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TCallable;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TMixed;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNever;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TObject;
use Psalm\Type\Atomic\TTemplateParam;
use Psalm\Type\Union;

/**
 * Makes `expect($x)` chains type the way Pest runs them (`->not->toBeNull()`, `->each->toBeInt()`,
 * `->formatId->toBe('meta')`). Pest hides its assertions one `@mixin` deeper than Psalm follows (`Expectation`
 * mixes in `Mixins\Expectation<TValue>`; the proxies behind `->not` / `->each` / `->someProperty` only mix in
 * `Expectation`), so this patches class storage once population is done, from what Psalm scanned:
 *
 * 1. Assertions return `Expectation<TValue>`, not `Mixins\Expectation<TValue>` (`__call()` returns the outer `$this`).
 * 2. `Expectation`'s `$not` / `$each` pseudo properties carry the value type, so the proxies get template arguments.
 * 3. Each proxy gets a pseudo method per assertion, cloned so argument checking works (`->not->toBe()` is a
 *    `TooFewArguments`). A pseudo method, not a mixin, keeps unknown names sealed: `->not->toBeBananas()` is reported.
 * 4. `Expectation::__get()` / `__call()` and `HigherOrderExpectation`'s accept any name: `$expect->formatId` is a
 *    `HigherOrderExpectation` over that member of the value.
 * 5. `expect($x)` is `Expectation<T>` for an argument of type `T`, not Pest's `Expectation<T|null>`.
 *
 * What storage cannot say is the value: {@see self::afterExpressionAnalysis()} types a narrowing assertion as the
 * expectation of the narrowed value and a forwarded member as the value's property or method return type.
 *
 * Runs after {@see InternalDslHandler}, so the cloned methods carry no `@internal` marker. Known limits:
 * `expect()->extend()` assertions are invisible to a scan; `->not` / `->each` read through a proxy resolve from the
 * `Expectation` mixin (which wins over the proxy's own pseudo properties), so the value type is not tracked there.
 */
final class ExpectationHandler implements AfterCodebasePopulatedInterface, AfterExpressionAnalysisInterface
{
    /** Each proxy and the class its `__call()` hands an assertion back as, on the proxy's own template arguments. */
    private const PROXY_RESULTS = [
        PestApi::OPPOSITE_EXPECTATION => PestApi::EXPECTATION,
        PestApi::EACH_EXPECTATION => PestApi::EACH_EXPECTATION,
        PestApi::HIGHER_ORDER_EXPECTATION => PestApi::HIGHER_ORDER_EXPECTATION,
    ];

    /** The value method the call being checked forwards to; handed from the return type to the params provider. */
    private static ?string $forwarded = null;

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $provider = $event->getCodebase()->classlike_storage_provider;

        $storages = [];
        foreach ([PestApi::EXPECTATION, PestApi::MIXIN_EXPECTATION, ...\array_keys(self::PROXY_RESULTS)] as $class) {
            // Without one of them (no Pest, or a Pest of another shape) there is nothing to patch.
            if (!$provider->has($class)) {
                return;
            }

            $storages[$class] = $provider->get($class);
        }

        $expectation = $storages[PestApi::EXPECTATION];
        $assertions = $storages[PestApi::MIXIN_EXPECTATION];

        self::returnOuterExpectation($assertions);
        self::carryValueTypeIntoProxyProperties($expectation);
        self::typeIterationCallbacks($expectation);

        foreach (self::PROXY_RESULTS as $proxy => $result) {
            self::forwardAssertions($storages[$proxy], $result, $assertions, $expectation);
        }

        self::acceptAnyHigherOrderMember($expectation, $storages[PestApi::HIGHER_ORDER_EXPECTATION]);
        self::typeExpectedValue($event->getCodebase());
        self::checkForwardedArguments($event->getCodebase());
    }

    /**
     * Pest hands the `each()` / `sequence()` callbacks an expectation of each item. Its signatures leave the first
     * untyped and make the second an expectation of the whole iterable, which `member()` would report on. The item
     * type stays `mixed`: `value-of<TValue>` only resolves for arrays, so a collection or `mixed` value would fail.
     */
    private static function typeIterationCallbacks(ClassLikeStorage $expectation): void
    {
        $item = new FunctionLikeParameter('item', false, self::generic(PestApi::EXPECTATION, Type::getMixed()), is_optional: false);
        $key = new FunctionLikeParameter('key', false, Type::getArrayKey(), is_optional: false);
        // Named arguments: Psalm 6's constructor takes a leading `$value`, Psalm 7's does not.
        $expectation->methods['each']->params[0]->type = new Union([new TCallable(params: [$item, $key]), new TNull()]);

        $sequence = $expectation->methods['sequence']->params[0];
        $atomics = [];
        foreach ($sequence->type?->getAtomicTypes() ?? [] as $atomic) {
            if ($atomic instanceof TCallable && $atomic->params !== null) {
                $atomic = new TCallable(params: [$item, ...\array_slice($atomic->params, 1)], return_type: $atomic->return_type);
            }

            $atomics[] = $atomic;
        }

        \assert($atomics !== []);
        $sequence->type = new Union($atomics);
    }

    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();
        $source = $event->getStatementsSource();
        // Through the interface: the analyzer's own accessor is @internal.
        $nodeTypes = $source->getNodeTypeProvider();
        if (!$expr instanceof MethodCall && !$expr instanceof PropertyFetch
            || $expr instanceof MethodCall && $expr->isFirstClassCallable()
            || !$expr->name instanceof Identifier
            || !$source instanceof StatementsAnalyzer
        ) {
            return null;
        }

        $receiver = ExpectNarrowingHandler::atomic($nodeTypes->getType($expr->var));
        $type = $receiver instanceof TGenericObject
            ? self::resolve($receiver, $expr, $expr->name, ExpectNarrowingHandler::atomic($nodeTypes->getType($expr)), $source, $event->getCodebase())
            : null;

        if ($type instanceof Union) {
            $nodeTypes->setType($expr, $type);
        }

        return null;
    }

    /**
     * The type of a member read off an expectation, or null to keep Psalm's. `$result` is Psalm's own: a
     * `HigherOrderExpectation<_, mixed>` means the member fell through to the patched `__get()` / `__call()`.
     * An assertion on a `HigherOrderExpectation` returns to the original value, as Pest does.
     */
    private static function resolve(
        TGenericObject $receiver,
        MethodCall|PropertyFetch $expr,
        Identifier $member,
        ?Atomic $result,
        StatementsAnalyzer $source,
        Codebase $codebase,
    ): ?Union {
        if (!\in_array($receiver->value, [PestApi::EXPECTATION, PestApi::OPPOSITE_EXPECTATION, PestApi::HIGHER_ORDER_EXPECTATION], true)) {
            return null;
        }

        $value = $receiver->type_params[0];
        $fellThrough = $result instanceof TGenericObject
            && $result->value === PestApi::HIGHER_ORDER_EXPECTATION
            && $result->type_params[1]->isMixed();

        if ($receiver->value === PestApi::HIGHER_ORDER_EXPECTATION) {
            if ($expr instanceof MethodCall && $codebase->methodExists(PestApi::MIXIN_EXPECTATION . '::' . $member->name)) {
                $original = ExpectNarrowingHandler::atomic($value);

                return $original instanceof TGenericObject && $original->value === PestApi::EXPECTATION
                    ? self::generic(PestApi::HIGHER_ORDER_EXPECTATION, $value, $original->type_params[0])
                    : null;
            }

            return $fellThrough
                ? self::generic(PestApi::HIGHER_ORDER_EXPECTATION, $value, self::member($receiver->type_params[1], $expr, $member, $source))
                : null;
        }

        $asserted = $expr instanceof MethodCall ? ExpectNarrowingHandler::assertedType($member->toLowerString(), $expr, $source) : null;
        if ($asserted instanceof Atomic) {
            return self::generic(
                PestApi::EXPECTATION,
                ExpectNarrowingHandler::narrow($value, $asserted, $receiver->value === PestApi::OPPOSITE_EXPECTATION, $source),
            );
        }

        return $fellThrough
            ? self::generic(PestApi::HIGHER_ORDER_EXPECTATION, self::generic(PestApi::EXPECTATION, $value), self::member($value, $expr, $member, $source))
            : null;
    }

    /**
     * What `->name` / `->name()` is on `$value`: `mixed` when the value does not say (no such property, the
     * class's own `__call()`, a type that depends on the class's templates), `null` members skipped. A method
     * no atomic of the value has (`expect(1)->toBeIntt()`) is reported, since the open `__call()` hides it from Psalm.
     */
    private static function member(Union $value, MethodCall|PropertyFetch $expr, Identifier $member, StatementsSource $source): Union
    {
        $codebase = $source->getCodebase();
        $types = [];
        foreach ($value->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TNull) {
                continue;
            }

            $type = self::memberOf($atomic, $expr instanceof MethodCall, $member->name, $codebase);
            if (!$type instanceof Union) {
                $id = $atomic->getId() . '::' . $member->toLowerString();
                IssueBuffer::maybeAdd(
                    new UndefinedMagicMethod("Magic method {$id} does not exist", new CodeLocation($source, $member), $id),
                    $source->getSuppressedIssues(),
                );

                return Type::getMixed();
            }

            $types[] = $type;
        }

        return $types === [] ? Type::getMixed() : Type::combineUnionTypeArray($types, $codebase);
    }

    /** @return Union|null null: a method that `$atomic` does not have */
    private static function memberOf(Atomic $atomic, bool $isMethod, string $name, Codebase $codebase): ?Union
    {
        if (!$atomic instanceof TNamedObject) {
            // `never` is what a value Psalm already failed on is: no second report for it.
            $open = $atomic instanceof TMixed || $atomic instanceof TNever || $atomic instanceof TObject || $atomic instanceof TTemplateParam;

            return $isMethod && !$open ? null : Type::getMixed();
        }

        $class = $atomic->value;
        if ($isMethod) {
            $id = $class . '::' . $name;
            if (!$codebase->methodExists($id)) {
                return $codebase->methodExists($class . '::__call') ? Type::getMixed() : null;
            }

            $type = $codebase->getMethodReturnType($id, $class);
        } else {
            $classes = $codebase->classlike_storage_provider;
            $declaring = $classes->has($class) ? ($classes->get($class)->declaring_property_ids[$name] ?? null) : null;
            $type = $declaring === null ? null : $classes->get($declaring)->properties[$name]->type;
        }

        return !$type instanceof Union || $type->hasTemplate() ? Type::getMixed() : ($type->isVoid() ? Type::getNull() : $type);
    }

    /** @psalm-pure */
    private static function generic(string $class, Union $first, Union ...$rest): Union
    {
        /** @var non-empty-list<Union> $arguments */
        $arguments = [$first, ...$rest];

        return new Union([new TGenericObject($class, $arguments)]);
    }

    /** `self<TValue>` in Mixins\Expectation would be the mixin; the template arguments are resolved against it as declared. */
    private static function returnOuterExpectation(ClassLikeStorage $assertions): void
    {
        foreach ($assertions->methods as $method) {
            $method->return_type = $method->return_type?->replaceClassLike(PestApi::MIXIN_EXPECTATION, PestApi::EXPECTATION);
            $method->signature_return_type = $method->signature_return_type?->replaceClassLike(PestApi::MIXIN_EXPECTATION, PestApi::EXPECTATION);
        }
    }

    /** `@property OppositeExpectation $not` names the class bare, which would make `expect(1)->not` `OppositeExpectation<mixed>`. */
    private static function carryValueTypeIntoProxyProperties(ClassLikeStorage $expectation): void
    {
        $arguments = self::templateArguments($expectation);

        foreach ($expectation->pseudo_property_get_types as $property => $type) {
            $atomic = ExpectNarrowingHandler::atomic($type);

            if ($atomic instanceof TNamedObject && isset(self::PROXY_RESULTS[$atomic->value])) {
                $expectation->pseudo_property_get_types[$property] = self::generic($atomic->value, ...$arguments);
            }
        }
    }

    /**
     * Gives `$proxy` a pseudo method for every assertion it does not reach yet. The parameters are shared with
     * the original: they mention no class template (only `__construct()` does).
     */
    private static function forwardAssertions(
        ClassLikeStorage $proxy,
        string $result,
        ClassLikeStorage $assertions,
        ClassLikeStorage $expectation,
    ): void {
        $returnType = self::generic($result, ...self::templateArguments($proxy));

        foreach ($assertions->methods as $name => $assertion) {
            // Public only (`export()` is not an assertion); every proxy declares `__construct()` itself.
            if ($assertion->visibility !== \ReflectionMethod::IS_PUBLIC
                || isset($proxy->declaring_method_ids[$name])
                || isset($expectation->declaring_method_ids[$name])
            ) {
                continue;
            }

            $forwarded = clone $assertion;
            $forwarded->defining_fqcln = $proxy->name;
            $forwarded->return_type = $returnType;
            $forwarded->signature_return_type = null;

            $proxy->pseudo_methods[$name] = $forwarded;
        }
    }

    /**
     * Pest declares `__get()` / `__call()` as unions of everything they can return; with the assertions covered,
     * the member case replaces them, typed `mixed` until {@see self::afterExpressionAnalysis()} looks the member
     * up on the value (`mixed` is also how that hook tells a fall-through from a real method).
     */
    private static function acceptAnyHigherOrderMember(ClassLikeStorage $expectation, ClassLikeStorage $higherOrder): void
    {
        $ofExpectation = self::generic($higherOrder->name, self::generic($expectation->name, ...self::templateArguments($expectation)), Type::getMixed());
        $ofHigherOrder = self::generic($higherOrder->name, self::templateArguments($higherOrder)[0], Type::getMixed());

        foreach ([[$expectation, $ofExpectation], [$higherOrder, $ofHigherOrder]] as [$storage, $returnType]) {
            $storage->sealed_properties = false;
            $storage->sealed_methods = false;
            $storage->methods['__get']->return_type = $returnType;
            $storage->methods['__call']->return_type = $returnType;
        }
    }

    /**
     * `expect($obj)->number('x')` runs `$obj->number('x')`, which `__call()` hides from argument checking: for a
     * value of one class that has the method, answering the call here makes Psalm check its arguments against it.
     */
    private static function checkForwardedArguments(Codebase $codebase): void
    {
        foreach ([PestApi::EXPECTATION, PestApi::HIGHER_ORDER_EXPECTATION] as $class) {
            $codebase->methods->return_type_provider->registerClosure(
                $class,
                static function (MethodReturnTypeProviderEvent $event) use ($codebase, $class): ?Union {
                    $call = $event->getStmt();
                    $name = $event->getMethodNameLowercase();
                    $receiver = $call instanceof MethodCall
                        ? ExpectNarrowingHandler::atomic($event->getSource()->getNodeTypeProvider()->getType($call->var))
                        : null;
                    if (!$receiver instanceof TGenericObject
                        || $codebase->methodExists($class . '::' . $name)
                        || $codebase->methodExists(PestApi::MIXIN_EXPECTATION . '::' . $name)
                    ) {
                        return null;
                    }

                    $atomics = \array_filter(
                        $receiver->type_params[$class === PestApi::EXPECTATION ? 0 : 1]->getAtomicTypes(),
                        static fn(Atomic $atomic): bool => !$atomic instanceof TNull,
                    );
                    $value = \count($atomics) === 1 ? \reset($atomics) : null;
                    $id = $value instanceof TNamedObject ? $value->value . '::' . $name : null;
                    if ($id === null || !$codebase->methodExists($id)) {
                        return null;
                    }

                    self::$forwarded = $id;

                    return $codebase->classlike_storage_provider->get($class)->methods['__call']->return_type;
                },
            );
            $codebase->methods->params_provider->registerClosure(
                $class,
                static function () use ($codebase): ?array {
                    $id = self::$forwarded;
                    self::$forwarded = null;

                    return $id === null ? null : $codebase->getMethodParams($id);
                },
            );
        }
    }

    /** Pest's `@param TValue|null` / `@return Expectation<TValue|null>` would make `expect($x)` `Expectation<T|null>`. */
    private static function typeExpectedValue(Codebase $codebase): void
    {
        if (!$codebase->functions->hasStubbedFunction('expect')) {
            return;
        }

        $expect = $codebase->functions->getStorage(null, 'expect');
        foreach ($expect->params[0]->type?->getAtomicTypes() ?? [] as $atomic) {
            if ($atomic instanceof TTemplateParam) {
                $value = new Union([$atomic]);
                $expect->params[0]->type = $value;
                $expect->return_type = self::generic(PestApi::EXPECTATION, $value);

                return;
            }
        }
    }

    /**
     * A class's own template parameters as type arguments; every Pest class patched here has at least one.
     *
     * @return non-empty-list<Union>
     *
     * @psalm-mutation-free
     */
    private static function templateArguments(ClassLikeStorage $storage): array
    {
        $arguments = [];
        foreach ($storage->template_types ?? [] as $name => $definedAs) {
            $arguments[] = new Union([new TTemplateParam($name, \current($definedAs), $storage->name)]);
        }

        \assert($arguments !== []);

        return $arguments;
    }
}
