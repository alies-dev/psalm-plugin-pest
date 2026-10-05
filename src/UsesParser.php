<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use PhpParser\NameContext;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\MagicConst\Dir;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use Psalm\Exception\TypeParseTreeException;
use Psalm\Type;
use Psalm\Type\Atomic\TClosure;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\TypeNode;
use Psalm\Type\TypeVisitor;

/**
 * Statically reads Pest's `uses(...)` / `pest()->extend(...)` chains from one file, never executing it.
 * `pest()` in `Pest.php` is rooted at that file's directory (`Pest\Configuration::__construct()`); any
 * other chain without `in()` targets its own file. `in()` paths are relative to the file's directory,
 * glob-expanded and realpath'd (`UsesCall::in()`).
 *
 * All-or-nothing: an unreadable `uses()` / `pest()` call (non-literal argument, nested outside a
 * top-level statement, first-class callable, name as a string) or a non-linear file (include, top-level
 * `return` / `exit`) yields null, as a guessed TestCase is worse than Pest's own `TestCall` binding.
 *
 * `properties`: per `$this->name = ...` in a chain's `beforeEach()` closure, the assignment's `@var`
 * (class names resolved to FQCNs), else the class of a `new`, a scalar literal's type, else `mixed`.
 *
 * @psalm-type PestUsesEntry = array{classes: list<string>, targets: list<string>, properties: array<string, non-empty-list<string>>}
 */
final class UsesParser
{
    private const CLASS_METHODS = ['extend' => true, 'extends' => true, 'use' => true, 'uses' => true];

    private const ROOTS = ['uses', 'pest'];

    private const ABSOLUTE = '__Absolute__';

    /**
     * @param bool $bootFile false for a test file: its closures run after Pest resolved the file's
     *                       TestCase, so an include inside one cannot configure it
     * @return list<PestUsesEntry>|null
     */
    public static function parse(string $filePath, string $contents, bool $bootFile = true): ?array
    {
        // Cheap bail; any mention at all (a comment before the parenthesis, an alias import) takes the AST path.
        if (\preg_match('/\b(?:uses|pest|include|require|include_once|require_once)\b/i', $contents) !== 1) {
            return [];
        }

        try {
            $statements = (new ParserFactory())->createForHostVersion()->parse($contents) ?? [];
        } catch (\PhpParser\Error) {
            return null;
        }

        $resolver = new NameResolver();
        $statements = (new NodeTraverser($resolver))->traverse($statements);

        if (!self::isLinear($statements, $bootFile)) {
            return null;
        }

        $names = $resolver->getNameContext();
        $entries = [];
        foreach (self::topLevelExpressions($statements) as $expression) {
            $chain = [];
            while ($expression instanceof MethodCall) {
                if (!$expression->name instanceof Identifier || $expression->isFirstClassCallable()) {
                    return null;
                }

                \array_unshift($chain, [\strtolower($expression->name->name), $expression->getArgs()]);
                $expression = $expression->var;
            }

            if (!self::isRootCall($expression)) {
                continue;
            }

            $entry = $expression->isFirstClassCallable() ? null : self::readChain($filePath, $expression, $chain, $names);
            if ($entry === null) {
                return null;
            }

            $entries[] = $entry;
        }

        // A root that is not the head of a top-level statement (inside a closure, a condition, an
        // assignment) may or may not run: the file cannot be read reliably.
        return \count((new NodeFinder())->find($statements, self::isRootCall(...))) === \count($entries) ? $entries : null;
    }

    /**
     * False when Pest could run config this parser does not see (an include, a string name) or skip
     * config it does see (top-level `return` / `exit`).
     *
     * @param array<Node> $statements
     */
    private static function isLinear(array $statements, bool $bootFile): bool
    {
        $finder = new NodeFinder();
        $deferredIncludes = [];
        if (!$bootFile) {
            foreach ($finder->find($statements, static fn(Node $node): bool => $node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) as $closure) {
                foreach ($finder->findInstanceOf($closure, Expr\Include_::class) as $include) {
                    $deferredIncludes[\spl_object_id($include)] = true;
                }
            }
        }

        $opaque = $finder->findFirst($statements, static fn(Node $node): bool => match (true) {
            $node instanceof String_ => \in_array(\strtolower(\ltrim($node->value, '\\')), self::ROOTS, true),
            $node instanceof Expr\Include_ => !isset($deferredIncludes[\spl_object_id($node)]),
            default => false,
        });
        if ($opaque instanceof Node) {
            return false;
        }

        $visitor = new class extends \PhpParser\NodeVisitorAbstract {
            public bool $terminates = false;

            /** @psalm-external-mutation-free */
            #[\Override]
            public function enterNode(Node $node): ?int
            {
                // Bodies of functions and classes do not run while the file loads.
                if ($node instanceof Node\FunctionLike || $node instanceof Stmt\ClassLike) {
                    return \PhpParser\NodeVisitor::DONT_TRAVERSE_CHILDREN;
                }

                if ($node instanceof Stmt\Return_ || $node instanceof Expr\Exit_
                    || $node instanceof Stmt\HaltCompiler || $node instanceof Stmt\Goto_
                ) {
                    $this->terminates = true;
                }

                return null;
            }
        };
        (new NodeTraverser($visitor))->traverse($statements);

        return !$visitor->terminates;
    }

    /** @psalm-assert-if-true FuncCall $node */
    private static function isRootCall(Node $node): bool
    {
        return $node instanceof FuncCall
            && $node->name instanceof Name
            && \in_array($node->name->toLowerString(), self::ROOTS, true);
    }

    /**
     * @param array<Node> $statements
     * @return \Generator<int, Expr>
     */
    private static function topLevelExpressions(array $statements): \Generator
    {
        foreach ($statements as $statement) {
            if ($statement instanceof Stmt\Namespace_) {
                yield from self::topLevelExpressions($statement->stmts);
            } elseif ($statement instanceof Stmt\Expression) {
                yield $statement->expr;
            }
        }
    }

    /**
     * @param list<array{string, array<Node\Arg>}> $chain method calls in call order
     * @return PestUsesEntry|null
     */
    private static function readChain(string $filePath, FuncCall $root, array $chain, NameContext $names): ?array
    {
        $dir = \dirname($filePath);
        $isUses = $root->name instanceof Name && $root->name->toLowerString() === 'uses';
        $classes = $isUses ? self::readStrings($root->getArgs(), $dir) : [];
        $targets = null;
        $properties = [];

        foreach ($chain as [$method, $args]) {
            if (isset(self::CLASS_METHODS[$method])) {
                $read = self::readStrings($args, $dir);
                $classes = $classes === null || $read === null ? null : [...$classes, ...$read];
            } elseif ($method === 'beforeeach') {
                $hook = $args[0]->value ?? null;
                foreach ($hook instanceof Expr\Closure || $hook instanceof Expr\ArrowFunction ? self::thisAssignments($hook) : [] as $name => [$assign, $doc]) {
                    $properties[$name][] = self::propertyType($assign, $doc, $names);
                }
            } elseif ($method === 'in') {
                $paths = self::readStrings($args, $dir);
                if ($paths === null) {
                    return null;
                }

                $targets = [];
                foreach ($paths as $path) {
                    $matches = \glob(\str_starts_with($path, \DIRECTORY_SEPARATOR) ? $path : $dir . \DIRECTORY_SEPARATOR . $path);
                    foreach ($matches === false ? [] : $matches as $match) {
                        $targets[] = self::realpath($match);
                    }
                }
            }
        }

        if ($classes === null) {
            return null;
        }

        // Without `in()`: `pest()` in Pest.php covers its directory, any other root only its file.
        $default = !$isUses && \basename($filePath) === 'Pest.php' ? $dir : $filePath;

        return [
            'classes' => \array_map(static fn(string $class): string => \ltrim($class, '\\'), $classes),
            'targets' => $targets ?? [self::realpath($default)],
            'properties' => $properties,
        ];
    }

    /**
     * Each `$this->name = ...` that is the closure's own `$this` (a nested class, function or static
     * closure has another one), with the docblock of its statement.
     *
     * @return \Generator<string, array{Expr\Assign, string}>
     */
    public static function thisAssignments(Expr\Closure|Expr\ArrowFunction $closure): \Generator
    {
        $finder = new NodeFinder();
        $foreign = [];
        foreach ($finder->find($closure->getStmts(), static fn(Node $node): bool => $node instanceof Stmt\ClassLike
            || $node instanceof Stmt\Function_
            || (($node instanceof Expr\Closure || $node instanceof Expr\ArrowFunction) && $node->static)) as $scope) {
            foreach ($finder->findInstanceOf($scope, Expr\Assign::class) as $assign) {
                $foreign[\spl_object_id($assign)] = true;
            }
        }

        $docs = [];
        foreach ($finder->findInstanceOf($closure->getStmts(), Stmt\Expression::class) as $statement) {
            $docs[\spl_object_id($statement->expr)] = $statement->getDocComment()?->getText() ?? '';
        }

        foreach ($finder->findInstanceOf($closure->getStmts(), Expr\Assign::class) as $assign) {
            $target = $assign->var;
            if ($target instanceof Expr\PropertyFetch
                && $target->var instanceof Expr\Variable
                && $target->var->name === 'this'
                && $target->name instanceof Identifier
                && !isset($foreign[\spl_object_id($assign)])
            ) {
                yield $target->name->name => [$assign, $docs[\spl_object_id($assign)] ?? ''];
            }
        }
    }

    private static function propertyType(Expr\Assign $assign, string $doc, NameContext $names): string
    {
        if (\preg_match('/@(?:psalm-)?var\s+([^$*]+?)\s*(?:[$*]|$)/', $doc, $var) === 1) {
            try {
                // parseString() drops a leading `\`; marking absolute names keeps them from being namespaced below.
                $type = Type::parseString(\preg_replace('/(?<![\w\\\\])\\\\(?=\w)/', '\\\\' . self::ABSOLUTE . '\\\\', $var[1]) ?? '', \PHP_VERSION_ID);
            } catch (TypeParseTreeException) {
                return 'mixed';
            }

            $collector = new class extends TypeVisitor {
                /** @var list<string> */
                public array $classes = [];

                /** @psalm-external-mutation-free */
                #[\Override]
                protected function enterNode(TypeNode $type): ?int
                {
                    // Psalm's `Closure` is the built-in type, whatever namespace the file declares.
                    if ($type instanceof TNamedObject && !$type instanceof TClosure) {
                        $this->classes[] = $type->value;
                    }

                    return null;
                }
            };
            $collector->traverse($type);
            foreach ($collector->classes as $class) {
                $type = $type->replaceClassLike($class, \str_starts_with($class, self::ABSOLUTE . '\\')
                    ? \substr($class, \strlen(self::ABSOLUTE) + 1)
                    : $names->getResolvedClassName(new Name($class))->toString());
            }

            return $type->getId();
        }

        $value = $assign->expr;

        return match (true) {
            $value instanceof Expr\New_ && $value->class instanceof Name => $value->class->toString(),
            $value instanceof Node\Scalar\Int_ => 'int',
            $value instanceof Node\Scalar\Float_ => 'float',
            $value instanceof String_ => 'string',
            $value instanceof Expr\ConstFetch && \in_array($value->name->toLowerString(), ['true', 'false'], true) => 'bool',
            default => 'mixed',
        };
    }

    /**
     * @param array<Node\Arg> $args
     * @return list<string>|null
     *
     * @psalm-mutation-free
     */
    private static function readStrings(array $args, string $dir): ?array
    {
        $values = [];
        foreach ($args as $arg) {
            $value = $arg->unpack || $arg->name instanceof Identifier ? null : self::readValue($arg->value, $dir);
            if ($value === null) {
                return null;
            }

            $values[] = $value;
        }

        return $values;
    }

    /**
     * A class name or path made of literals, `__DIR__` and `Foo::class`.
     *
     * @psalm-mutation-free
     */
    private static function readValue(Expr $expr, string $dir): ?string
    {
        if ($expr instanceof Expr\BinaryOp\Concat) {
            $left = self::readValue($expr->left, $dir);
            $right = self::readValue($expr->right, $dir);

            return $left === null || $right === null ? null : $left . $right;
        }

        return match (true) {
            $expr instanceof String_ => $expr->value,
            $expr instanceof Dir => $dir,
            $expr instanceof Expr\ClassConstFetch
                && $expr->class instanceof Name\FullyQualified
                && $expr->name instanceof Identifier
                && \strtolower($expr->name->name) === 'class' => $expr->class->toString(),
            default => null,
        };
    }

    public static function realpath(string $path): string
    {
        $real = \realpath($path);

        return $real === false ? $path : $real;
    }
}
