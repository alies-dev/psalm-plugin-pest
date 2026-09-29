# Psalm plugin for Pest

Makes [Psalm](https://psalm.dev) understand [Pest](https://pestphp.com) test files.

- **`$this` in test closures.** Pest binds every `test()`, `it()`, `beforeEach()` and `afterEach()` closure to a TestCase, so `$this->user` or `$this->actingAs()` is valid code. Pest documents that binding only as `@param-closure-this TestCall`, which Psalm 6.19+ reads literally: every `$this->...` in a test becomes `UndefinedThisPropertyFetch` or `UndefinedMethod` on `TestCall`, plus a `Mixed*` cascade. The plugin binds `$this` to the class Pest actually uses.
- **Pest's public API.** Pest marks the classes behind `expect()->toBe()`, `uses()->in()`, `pest()->extend()` and `test()->group()` as `@internal`. The plugin stops reporting `InternalMethod` on them. Pest classes outside that API keep reporting.

## Installation

```bash
composer require --dev alies-dev/psalm-plugin-pest
vendor/bin/psalm-plugin enable alies-dev/psalm-plugin-pest
```

Requirements: PHP 8.3+ (Pest 4's floor), Psalm 6.19+ or 7, Pest 4. Your test directory must be part of `<projectFiles>`.

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
- Properties a test assigns dynamically (`$this->user = ...` in `beforeEach()`) are reported, because Psalm now sees the real TestCase. Declare them on the TestCase.
- Psalm 7.0.0-beta22 has no `@param-closure-this` support, so there `$this` in a test closure is still `InvalidScope`. Binding activates automatically on a Psalm 7 release that carries it (vimeo/psalm master does). The `InternalMethod` relief works on every supported version.
- Pest 5 is untested. The plugin keys on Pest's `@param-closure-this TestCall` tag and class names, and does nothing if those change.

## License

MIT, see [LICENSE](LICENSE).
