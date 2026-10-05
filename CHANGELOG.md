# Changelog

All notable changes to this project are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Fixed

- Stop reporting `InaccessibleMethod` for protected and private TestCase methods called on `test()`, which Pest forwards through reflection ([#50](https://github.com/alies-dev/psalm-plugin-pest/issues/50)).

## [0.2.1] - 2026-10-05

### Fixed

- Type `test()` called without arguments inside helper functions of a test file as the bound TestCase ([#29](https://github.com/alies-dev/psalm-plugin-pest/issues/29)).
- Stop reporting `UndefinedClass` for traits passed to `uses()` after a fluent `$this` / `static` call, and for undefined members; `$this` is the plain TestCase and trait methods resolve on it, so `test()->traitMethod()` works too ([#28](https://github.com/alies-dev/psalm-plugin-pest/issues/28)).
- Keep bound trait methods scoped: an aliased trait method no longer crashes Psalm, chains binding one TestCase to different traits no longer share methods, and an included file's traits no longer leak into the including file.

## [0.2.0] - 2026-10-05

### Added

- Support Pest 5.
- Type expectation chains: assertions resolve through `->not`, `->each` and higher-order expectations, and `->not` / `->each` work after an assertion.
- Type `test()` called without arguments inside a test as the bound TestCase ([#6](https://github.com/alies-dev/psalm-plugin-pest/issues/6)).
- Declare properties assigned to `$this` in a file's `beforeEach()` for that file's tests ([#7](https://github.com/alies-dev/psalm-plugin-pest/issues/7)).
- Narrow variables after `expect($var)` assertions such as `toBeNull()`, `toBeString()` and `toBeInstanceOf(X::class)`, including `->not` and `->and($other)` ([#8](https://github.com/alies-dev/psalm-plugin-pest/issues/8)).
- Resolve methods and properties of traits passed to `uses(TestCase::class, SomeTrait::class)` or `pest()->extend(...)->use(...)` on `$this`.
- Declare properties assigned in `beforeEach()` hooks of a `pest()` / `uses()` chain in `Pest.php` for the test files they target; types come from `@var`, `new X` or scalar literals, otherwise `mixed`. `/** @var T */` on in-file `beforeEach()` assignments is honoured.
- Bind `$this` in `->with(closure)` dataset closures and in `->beforeEach()` / `->afterEach()` closures of `uses()` / `pest()` chains.
- Resolve higher-order test calls such as `it('...')->actingAsAdmin()` against the bound TestCase, checking arguments and returning the `TestCall`; `->expect()` and `->and()` work on it.
- Type `expect($value)` as `Expectation<T>` instead of `T|null`, narrow it through type matchers (`expect($intOrString)->toBeInt()` is `Expectation<int>`), type higher-order members from the value and resolve `expect($obj)->someMethod()`.
- Read aliased imports such as `use function uses as x` in test and boot files instead of declining.

### Changed

- Require Psalm 7.0.0-rc1 or later (or `dev-master`); Psalm 6 is no longer supported.

### Fixed

- `UndefinedMagicMethod` on assertions called through `->not` and `->each` ([#3](https://github.com/alies-dev/psalm-plugin-pest/issues/3)).
- `UndefinedPropertyFetch` on `->not` and `->each` after an assertion ([#4](https://github.com/alies-dev/psalm-plugin-pest/issues/4)).
- `UndefinedMagicPropertyFetch` on higher-order properties such as `expect($user)->name` ([#5](https://github.com/alies-dev/psalm-plugin-pest/issues/5)).

## [0.1.0] - 2026-09-30

### Added

- Bind `$this` in Pest's `test()`, `it()`, `beforeEach()` and `afterEach()` closures to the configured TestCase.
- Stop reporting `InternalMethod` on Pest's public test-writing API.

[0.2.1]: https://github.com/alies-dev/psalm-plugin-pest/releases/tag/0.2.1
[0.2.0]: https://github.com/alies-dev/psalm-plugin-pest/releases/tag/0.2.0
[0.1.0]: https://github.com/alies-dev/psalm-plugin-pest/releases/tag/0.1.0
