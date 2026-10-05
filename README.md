# Psalm plugin for Pest

Makes [Psalm](https://psalm.dev) understand [Pest](https://pestphp.com) test files.

- **`$this` in test closures.** Pest binds every `test()`, `it()`, `beforeEach()` and `afterEach()` closure to a TestCase, so `$this->user` or `$this->actingAs()` is valid code. Pest documents that binding only as `@param-closure-this TestCall`, which Psalm reads literally: every `$this->...` in a test becomes `UndefinedThisPropertyFetch` or `UndefinedMethod` on `TestCall`, plus a `Mixed*` cascade. The plugin binds `$this` to the class Pest actually uses.
- **Pest's public API.** Pest marks the classes behind `expect()->toBe()`, `uses()->in()`, `pest()->extend()` and `test()->group()` as `@internal`. The plugin stops reporting `InternalMethod` on them. Pest classes outside that API keep reporting.
- **Expectation chains.** Pest hides its assertions one `@mixin` deeper than Psalm looks, so `expect($x)->not->toBeNull()` and `expect($xs)->each->toBeInt()` reported `UndefinedMagicMethod`. Assertions now resolve through `->not`, `->each` and higher-order expectations, `->not` and `->each` work after an assertion (`expect($xs)->toBeArray()->not->toBeEmpty()`), and higher-order members are accepted and typed from the value: `expect($user)->name->toBe('Ada')` and `expect($user)->getName()->toBe('Ada')` both resolve. `expect($v)` is `Expectation<T>` rather than `T|null`, and type matchers narrow inside the chain: `expect($intOrString)->toBeInt()` is `Expectation<int>`. An unknown assertion such as `->not->toBeBananas()`, or a method the value does not have, is still reported.
- **`test()` without arguments.** Inside a test, `test()` is typed as the TestCase the test is bound to, so `test()->get('/')->assertOk()` stops reporting `UndefinedMagicMethod` on Pest's `HigherOrderTapProxy`.
- **Properties from `beforeEach()`.** Properties assigned to `$this` in a `beforeEach()` are known in the tests they apply to, typed from the assigned value or a `/** @var T */` on the assignment. That covers the test file's own `beforeEach()` and hooks on a `pest()` / `uses()` chain in `tests/Pest.php`. They are declared per file and do not leak to other files that share the TestCase.
- **Type narrowing after `expect()`.** After a statement such as `expect($ad)->not->toBeNull();`, `$ad` is narrowed for the code that follows. Supported: `toBeNull`, `toBeString`, `toBeInt`, `toBeFloat`, `toBeBool`, `toBeArray`, `toBeTrue`, `toBeFalse`, `toBeObject`, `toBeCallable`, `toBeIterable` and `toBeInstanceOf(X::class)`, with `->not` and `->and($other)`.
- **Traits.** `uses(TestCase::class, SomeTrait::class)` and `pest()->extend(TestCase::class)->use(SomeTrait::class)->in(...)` make the trait's methods and properties available on `$this`.
- **More bound closures.** `$this` is also bound in `->with(function () { ... })` dataset closures and in the closures passed to `->beforeEach()` / `->afterEach()` on a `uses()` / `pest()` chain.
- **Higher-order tests.** `it('logs in')->actingAsAdmin()` resolves the method on the bound TestCase, checks its arguments and returns the `TestCall`, so `->with()`, `->group()`, `->expect()` and `->and()` keep chaining.

## Installation

```bash
composer require --dev alies-dev/psalm-plugin-pest
vendor/bin/psalm-plugin enable alies-dev/psalm-plugin-pest
```

Requirements: PHP 8.3+ (Pest 4's floor), Psalm 7.0.0-rc1+, Pest 4 or 5. Your test directory must be part of `<projectFiles>`.

## How the TestCase is resolved

For each test file, first match wins:

1. The class passed to `uses(...)` or `pest()->extend(...)` in the test file itself.
2. The class assigned to the file's directory through `uses(...)->in(...)` or `pest()->extend(...)->in(...)` in `tests/Pest.php`, or in any other file Pest loads at boot: `tests/Helpers.php`, `tests/Expectations.php`, the `tests/Helpers/` and `tests/Expectations/` trees, every `Datasets.php`, and every file under a `Datasets/` directory. `in()` accepts string literals, globs, `__DIR__` and `__DIR__ . '/Feature'`.
3. `PHPUnit\Framework\TestCase`, Pest's default, for files inside `tests/`.

Traits passed to `uses()` never decide the class: they are collected from the test file and from every matching `uses()` / `pest()->extend()->use()` entry, and their methods and properties resolve on `$this` next to the TestCase's own. `beforeEach()` closures attached to a `pest()` / `uses()` chain in a boot file declare the `$this->name = ...` properties they assign for the files that chain targets (typed from `/** @var T */` on the assignment, `new X`, or a scalar literal, else `mixed`). `use function uses as x` style aliases are followed. The files are parsed, never executed.

When the answer is uncertain the plugin keeps Pest's own `TestCall` binding rather than guess.

## Known limitations

- `tests/Pest.php` is looked up next to your Psalm config. A custom test directory (Pest's `--test-directory`) is not detected, and its tests keep Pest's binding.
- A test file outside `tests/` (for example a monorepo package with its own suite) keeps Pest's binding.
- A boot file the plugin cannot read statically disables resolution for the whole suite. That covers a variable argument, a call inside a condition or a closure, an `include`, a top-level `return` or `exit`, and a symlinked file.
- `uses()->in()` written in one test file to configure other files is ignored.
- Analyzing a single test file on its own declines when its TestCase class was not scanned; full project runs are unaffected.
- The plugin keys on Pest's class names and its `@param-closure-this TestCall` tag. A Pest release that changes them disables the affected feature.
- Custom expectations registered with `expect()->extend()` are invisible to static analysis and are still reported.
- `describe()->beforeEach()` closures are not bound: Pest exposes only `__call` on `describe()`'s return value.
- Closures nested in arrays inside `->with([...])` are not bound; only a closure passed directly to `->with()` is.
- In a hook chain, the TestCase is taken only from literal `X::class` arguments that come before the hook.
- With traits bound, a genuinely undefined `$this->member` also reports `UndefinedClass` for the trait, because Psalm walks the whole intersection.
- In a higher-order test, `->expect()` on the `TestCall` is typed `mixed`, and private TestCase methods are not usable as higher-order test calls.
- `beforeEach()` properties: only plain `$this->name = ...` assignments count, not `??=`, list destructuring or `$this->items[] = ...`. Properties assigned inside `describe()` are visible to the whole file. A test analysed before the `beforeEach()` that assigns the property sees it as `mixed`.
- Narrowing applies to plain variables only (`$var`, not `$this->prop` or `$a['k']`), and `toBeInstanceOf()` needs a `::class` argument.

## License

MIT, see [LICENSE](LICENSE).
