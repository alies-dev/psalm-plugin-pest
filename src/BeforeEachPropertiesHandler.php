<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticPropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Psalm\Codebase;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\BeforeFileAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\BeforeFileAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\PropertyExistenceProviderEvent;
use Psalm\Plugin\EventHandler\Event\PropertyTypeProviderEvent;
use Psalm\Plugin\EventHandler\Event\PropertyVisibilityProviderEvent;
use Psalm\StatementsSource;
use Psalm\Type;
use Psalm\Type\Atomic\TBool;
use Psalm\Type\Atomic\TFalse;
use Psalm\Type\Atomic\TFloat;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TLiteralFloat;
use Psalm\Type\Atomic\TLiteralInt;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Atomic\TTrue;
use Psalm\Type\Union;

/**
 * Declares the properties a test file assigns as `$this->name = ...` in its `beforeEach()` closures
 * on the TestCase `$this` is bound to ({@see ClosureThisHandler}), for that file only.
 *
 * `beforeEach()` is Pest's setup hook: its `$this->parser = new Parser` is read by every test of the
 * file (`$this->parser->parse(...)`), which Psalm reports as `UndefinedThisPropertyAssignment` /
 * `UndefinedThisPropertyFetch` on a class that declares no such property. Declaring them on the shared
 * TestCase would leak one file's fixtures into every other file bound to it, so the answer comes from
 * property existence / type / visibility providers that look at the file asking and decline (null) for everything
 * else: declared properties, other files and other names are untouched.
 *
 * Existence comes from a parse of the file (order-independent: a test may precede the `beforeEach()`
 * that sets its state, exactly as at runtime). The `beforeEach` call is recognised by its resolved name
 * (`use function beforeEach as setup` counts; a namespaced `Other\beforeEach()` does not), because the
 * last name segment alone cannot tell Pest's function from a lookalike. The type is the union of what
 * Psalm inferred for the assigned expressions, recorded while the closures are analysed; a read analysed
 * before any assignment, or of an untyped value, is `mixed`. Scalar literals are widened
 * (`$this->count = 0` is `int`, not `0`), as a declared property would be. Assignments accept any value:
 * a second `beforeEach()` may legitimately assign a different type, and the union is only known after the
 * fact.
 *
 * Fixtures are instance properties only. Psalm resolves `self::$name` / `static::$name` through the same
 * existence provider and, once it answers true, reads a static property record the TestCase does not
 * have (an undefined-key crash). The provider event carries neither the access kind nor a reliable node,
 * so the parse also records the names the file reads or writes statically on the TestCase, and the
 * provider declines those: Psalm then reports its usual undefined static property issue.
 *
 * State hangs off the {@see Codebase} (a `WeakMap`), so a new run starts clean with no reset hook and a
 * long-lived codebase re-parses a file whose contents changed. The inferred types are different: they are
 * unioned across analyses, and the parse cache only drops on a content change, so a reused codebase
 * (language server, watch mode) whose helper changed its return type would keep `Old|New` in an unchanged
 * test. {@see self::beforeAnalyzeFile()} therefore empties a file's types before every analysis of it,
 * and keeps the parse.
 *
 * @psalm-type BeforeEachFile = array{
 *     contents: string,
 *     assignments: array<int, string>,
 *     statics: array<string, true>,
 *     types: array<string, Union>
 * }
 */
final class BeforeEachPropertiesHandler implements AfterExpressionAnalysisInterface, BeforeFileAnalysisInterface
{
    private const BEFORE_EACH = 'beforeeach';

    /** @var \WeakMap<Codebase, \ArrayObject<string, BeforeEachFile>>|null */
    private static ?\WeakMap $files = null;

    /** @var \WeakMap<Codebase, \ArrayObject<string, true>>|null */
    private static ?\WeakMap $registered = null;

    /**
     * Asks for the provider on one bound TestCase; idempotent, because Psalm keeps providers per
     * class name and every Pest call in the project reaches here.
     */
    public static function register(Codebase $codebase, string $testCase): void
    {
        $registered = self::registered($codebase);
        if (isset($registered[$testCase])) {
            return;
        }

        $registered[$testCase] = true;
        $codebase->properties->property_existence_provider->registerClosure(
            $testCase,
            static fn(PropertyExistenceProviderEvent $event): ?bool => self::doesPropertyExist($event, $codebase),
        );
        $codebase->properties->property_type_provider->registerClosure(
            $testCase,
            static fn(PropertyTypeProviderEvent $event): ?Union => self::getPropertyType($event, $codebase),
        );
        $codebase->properties->property_visibility_provider->registerClosure(
            $testCase,
            static fn(PropertyVisibilityProviderEvent $event): ?bool => self::isPropertyVisible($event, $codebase),
        );
    }

    /** Records the type of each `$this->name = <expr>` that sits directly in a `beforeEach()` closure. */
    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();
        if (!$expr instanceof Assign || self::thisPropertyName($expr) === null) {
            return null;
        }

        $source = $event->getStatementsSource();
        $codebase = $event->getCodebase();
        $file = self::file($codebase, $source->getFilePath());
        $name = $file['assignments'][$expr->getStartFilePos()] ?? null;
        if ($name === null) {
            return null;
        }

        $assigned = self::widen($source->getNodeTypeProvider()->getType($expr->expr) ?? Type::getMixed());
        $known = $file['types'][$name] ?? null;
        $file['types'][$name] = $known === null ? $assigned : Type::combineUnionTypes($known, $assigned, $codebase);
        self::files($codebase)[$source->getFilePath()] = $file;

        return null;
    }

    /**
     * Forgets the types inferred in the previous analysis of this file; the parse stays cached. The
     * types are a union across assignments, so without this a reused codebase would add the new
     * analysis to the old one's.
     */
    #[\Override]
    public static function beforeAnalyzeFile(BeforeFileAnalysisEvent $event): void
    {
        $path = $event->getStatementsSource()->getFilePath();
        $files = self::files($event->getCodebase());

        $file = $files[$path] ?? null;
        if ($file !== null) {
            $file['types'] = [];
            $files[$path] = $file;
        }
    }

    public static function doesPropertyExist(PropertyExistenceProviderEvent $event, Codebase $codebase): ?bool
    {
        return self::answers($codebase, $event->getSource(), $event->getFqClasslikeName(), $event->getPropertyName()) === null
            ? null
            : true;
    }

    public static function isPropertyVisible(PropertyVisibilityProviderEvent $event, Codebase $codebase): ?bool
    {
        return self::answers($codebase, $event->getSource(), $event->getFqClasslikeName(), $event->getPropertyName()) === null
            ? null
            : true;
    }

    public static function getPropertyType(PropertyTypeProviderEvent $event, Codebase $codebase): ?Union
    {
        $name = $event->getPropertyName();
        $file = self::answers($codebase, $event->getSource(), $event->getFqClasslikeName(), $name);
        if ($file === null) {
            return null;
        }

        return $event->isReadMode() ? ($file['types'][$name] ?? Type::getMixed()) : Type::getMixed();
    }

    /**
     * The file's record when the provider speaks for this property, else null. Every answer declines
     * (null, never false) for a property the TestCase declares itself: those keep resolving through
     * their own storage, including their declared type and visibility.
     *
     * Psalm asks some follow-up questions without a source (`getDeclaringClassForProperty()` on an
     * assignment), after the sourced existence check already passed. Those are answered from any file
     * parsed so far that assigns the name.
     *
     * @return BeforeEachFile|null
     */
    private static function answers(Codebase $codebase, ?StatementsSource $source, string $testCase, string $name): ?array
    {
        if (isset($codebase->classlike_storage_provider->get($testCase)->appearing_property_ids[$name])) {
            return null;
        }

        if ($source instanceof StatementsSource) {
            $file = self::file($codebase, $source->getFilePath());

            return self::declares($file, $testCase, $name) ? $file : null;
        }

        foreach (self::files($codebase) as $file) {
            if (self::declares($file, $testCase, $name)) {
                return $file;
            }
        }

        return null;
    }

    /**
     * @param BeforeEachFile $file
     * @psalm-pure
     */
    private static function declares(array $file, string $testCase, string $name): bool
    {
        return \in_array($name, $file['assignments'], true) && !self::isStaticAccess($file, $testCase, $name);
    }

    /**
     * @return BeforeEachFile
     */
    private static function file(Codebase $codebase, string $path): array
    {
        $contents = $codebase->file_provider->getContents($path);
        $files = self::files($codebase);

        $file = $files[$path] ?? null;
        if ($file === null || $file['contents'] !== $contents) {
            $file = self::parse($contents) + ['contents' => $contents, 'types' => []];
            $files[$path] = $file;
        }

        return $file;
    }

    /**
     * What the file's source says: the start offset of every `$this->name = ...` inside a
     * `beforeEach()` closure to its name (Psalm parses the same bytes, so the offset identifies the very
     * node it analyses), and the static property accesses on the TestCase as `self::name` or
     * `lowercase\fq\class::name`.
     *
     * @return array{assignments: array<int, string>, statics: array<string, true>}
     */
    private static function parse(string $contents): array
    {
        // Cheap bail: most files never call beforeEach().
        if (\stripos($contents, self::BEFORE_EACH) === false) {
            return ['assignments' => [], 'statics' => []];
        }

        try {
            $statements = (new ParserFactory())->createForHostVersion()->parse($contents) ?? [];
            $statements = (new NodeTraverser(new NameResolver(null, ['replaceNodes' => false])))->traverse($statements);
        } catch (\PhpParser\Error) {
            return ['assignments' => [], 'statics' => []];
        }

        $finder = new NodeFinder();

        $declared = [];
        foreach ($finder->findInstanceOf($statements, Stmt\Function_::class) as $function) {
            if (isset($function->namespacedName)) {
                $declared[\strtolower($function->namespacedName->toString())] = true;
            }
        }

        $assignments = [];
        foreach ($finder->findInstanceOf($statements, FuncCall::class) as $call) {
            $closure = self::beforeEachClosure($call, $declared);
            if ($closure === null) {
                continue;
            }

            // A nested class, function or static closure has a `$this` of its own.
            $foreign = [];
            foreach ($finder->find($closure->getStmts(), self::rebindsThis(...)) as $scope) {
                foreach ($finder->findInstanceOf($scope, Assign::class) as $assign) {
                    $foreign[\spl_object_id($assign)] = true;
                }
            }

            foreach ($finder->findInstanceOf($closure->getStmts(), Assign::class) as $assign) {
                $name = self::thisPropertyName($assign);
                if ($name !== null && !isset($foreign[\spl_object_id($assign)])) {
                    $assignments[$assign->getStartFilePos()] = $name;
                }
            }
        }

        $statics = [];
        if ($assignments !== []) {
            foreach ($finder->findInstanceOf($statements, StaticPropertyFetch::class) as $fetch) {
                $key = self::staticAccessKey($fetch);
                if ($key !== null) {
                    $statics[$key] = true;
                }
            }
        }

        return ['assignments' => $assignments, 'statics' => $statics];
    }

    /**
     * `self::name` for `self::$name`, `static::$name` and `$this::$name` (all the bound TestCase), else
     * `lowercase\fq\class::name` for a named class; null for a dynamic class or property name.
     */
    private static function staticAccessKey(StaticPropertyFetch $fetch): ?string
    {
        if (!$fetch->name instanceof Identifier) {
            return null;
        }

        $class = $fetch->class;
        if ($class instanceof Name) {
            $named = \strtolower(NameResolution::resolved($class) ?? $class->toString());

            return ($named === 'static' ? 'self' : $named) . '::' . $fetch->name->name;
        }

        return $class instanceof Variable && $class->name === 'this' ? 'self::' . $fetch->name->name : null;
    }

    /**
     * Whether the file reads or writes `$name` statically on the TestCase, which no instance fixture
     * can answer.
     *
     * @param BeforeEachFile $file
     * @psalm-pure
     */
    private static function isStaticAccess(array $file, string $testCase, string $name): bool
    {
        return isset($file['statics']['self::' . $name]) || isset($file['statics'][\strtolower($testCase) . '::' . $name]);
    }

    /**
     * The closure handed to a `beforeEach(...)` call that keeps the bound `$this`, if any.
     *
     * @param array<string, true> $declared lowercase names of the functions the file declares
     */
    private static function beforeEachClosure(FuncCall $call, array $declared): Expr\Closure|Expr\ArrowFunction|null
    {
        if (!$call->name instanceof Name
            || !self::isBeforeEach($call->name, $declared)
            || $call->isFirstClassCallable()
        ) {
            return null;
        }

        $closure = $call->getArgs()[0]->value ?? null;

        return ($closure instanceof Expr\Closure || $closure instanceof Expr\ArrowFunction) && !$closure->static
            ? $closure
            : null;
    }

    /**
     * Whether a call name, as {@see NameResolver} annotated it, is the global `beforeEach()`. An
     * unqualified name inside a namespace only falls back to the global function at runtime when the
     * namespace has no function of that name: one declared in this file wins. A function declared in
     * another file cannot be seen from here, so the fallback is assumed.
     *
     * @param array<string, true> $declared lowercase names of the functions the file declares
     */
    private static function isBeforeEach(Name $name, array $declared): bool
    {
        [$function, $shadow] = NameResolution::functionName($name);

        return $function === self::BEFORE_EACH && ($shadow === null || !isset($declared[$shadow]));
    }

    /** @psalm-mutation-free */
    private static function rebindsThis(Node $node): bool
    {
        return $node instanceof Stmt\ClassLike
            || $node instanceof Stmt\Function_
            || (($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) && $node->static);
    }

    /**
     * `name` for `$this->name = ...`; dynamic names (`$this->$name`) cannot be declared.
     *
     * @psalm-mutation-free
     */
    private static function thisPropertyName(Assign $assign): ?string
    {
        $target = $assign->var;

        return $target instanceof PropertyFetch
            && $target->var instanceof Variable
            && $target->var->name === 'this'
            && $target->name instanceof Identifier
                ? $target->name->name
                : null;
    }

    /** @psalm-mutation-free */
    private static function widen(Union $type): Union
    {
        $atomics = [];
        foreach ($type->getAtomicTypes() as $atomic) {
            $atomics[] = match (true) {
                $atomic instanceof TLiteralInt => new TInt(),
                $atomic instanceof TLiteralString => new TString(),
                $atomic instanceof TLiteralFloat => new TFloat(),
                $atomic instanceof TTrue, $atomic instanceof TFalse => new TBool(),
                default => $atomic,
            };
        }

        return new Union($atomics);
    }

    /** @return \ArrayObject<string, BeforeEachFile> */
    private static function files(Codebase $codebase): \ArrayObject
    {
        if (!self::$files instanceof \WeakMap) {
            /** @var \WeakMap<Codebase, \ArrayObject<string, BeforeEachFile>> $map */
            $map = new \WeakMap();
            self::$files = $map;
        }

        $files = self::$files[$codebase] ?? null;
        if ($files === null) {
            $files = new \ArrayObject();
            self::$files[$codebase] = $files;
        }

        return $files;
    }

    /** @return \ArrayObject<string, true> */
    private static function registered(Codebase $codebase): \ArrayObject
    {
        if (!self::$registered instanceof \WeakMap) {
            /** @var \WeakMap<Codebase, \ArrayObject<string, true>> $map */
            $map = new \WeakMap();
            self::$registered = $map;
        }

        $registered = self::$registered[$codebase] ?? null;
        if ($registered === null) {
            $registered = new \ArrayObject();
            self::$registered[$codebase] = $registered;
        }

        return $registered;
    }
}
