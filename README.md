# Psalm plugin for Pest

Makes [Psalm](https://psalm.dev) understand [Pest](https://pestphp.com) test files.

- **`$this` in test closures.** Pest binds every `test()`, `it()`, `beforeEach()` and `afterEach()` closure to a TestCase, so `$this->user` or `$this->actingAs()` is valid code. Pest documents that binding only as `@param-closure-this TestCall`, which Psalm reads literally: every `$this->...` in a test becomes `UndefinedThisPropertyFetch` or `UndefinedMethod` on `TestCall`, plus a `Mixed*` cascade. The plugin binds `$this` to the class Pest actually uses.
- **Pest's public API.** Pest marks the classes behind `expect()->toBe()`, `uses()->in()`, `pest()->extend()` and `test()->group()` as `@internal`. The plugin stops reporting `InternalMethod` on them. Pest classes outside that API keep reporting.
- **Expectation chains.** Pest hides its assertions one `@mixin` deeper than Psalm looks, so `expect($x)->not->toBeNull()` and `expect($xs)->each->toBeInt()` reported `UndefinedMagicMethod`. Assertions now resolve through `->not`, `->each` and higher-order expectations, `->not` and `->each` work after an assertion (`expect($xs)->toBeArray()->not->toBeEmpty()`), and higher-order property access (`expect($user)->name->toBe('Ada')`) is accepted. An unknown assertion such as `->not->toBeBananas()` is still reported.
- **`test()` without arguments.** Inside a test, `test()` is typed as the TestCase the test is bound to, so `test()->get('/')->assertOk()` stops reporting `UndefinedMagicMethod` on Pest's `HigherOrderTapProxy`.
- **`beforeEach()` properties.** Properties assigned to `$this` in a file's `beforeEach()` (`$this->parser = new Parser`) are known in that file's tests, typed from the assigned value. They are declared for that file only and do not leak to other files that share the TestCase.
- **Type narrowing after `expect()`.** After a statement such as `expect($ad)->not->toBeNull();`, `$ad` is narrowed for the code that follows. Supported: `toBeNull`, `toBeString`, `toBeInt`, `toBeFloat`, `toBeBool`, `toBeArray`, `toBeTrue`, `toBeFalse`, `toBeObject`, `toBeCallable`, `toBeIterable` and `toBeInstanceOf(X::class)`, with `->not` and `->and($other)`.

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

Traits passed to `uses()` never decide the class. The files are parsed, never executed.

When the answer is uncertain the plugin keeps Pest's own `TestCall` binding rather than guess.

## Known limitations

- `tests/Pest.php` is looked up next to your Psalm config. A custom test directory (Pest's `--test-directory`) is not detected, and its tests keep Pest's binding.
- A test file outside `tests/` (for example a monorepo package with its own suite) keeps Pest's binding.
- A boot file the plugin cannot read statically disables resolution for the whole suite. That covers a variable argument, a call inside a condition or a closure, an `include`, a top-level `return` or `exit`, an aliased `use function uses`, and a symlinked file.
- `uses()->in()` written in one test file to configure other files is ignored.
- Analyzing a single test file on its own declines when its TestCase class was not scanned; full project runs are unaffected.
- The plugin keys on Pest's class names and its `@param-closure-this TestCall` tag. A Pest release that changes them disables the affected feature.
- Custom expectations registered with `expect()->extend()` are invisible to static analysis and are still reported.
- `expect($obj)->someMethod()`, a higher-order method call on `Pest\Expectation`, is still reported. Higher-order property access works. Higher-order members are typed `mixed`.
- `beforeEach()` properties: only plain `$this->name = ...` assignments count, not `??=`, list destructuring or `$this->items[] = ...`. Properties assigned inside `describe()` are visible to the whole file. A test analysed before the `beforeEach()` that assigns the property sees it as `mixed`.
- Narrowing applies to plain variables only (`$var`, not `$this->prop` or `$a['k']`), and `toBeInstanceOf()` needs a `::class` argument.

## License

MIT, see [LICENSE](LICENSE).
