# Psalm plugin for Pest

Makes [Psalm](https://psalm.dev) understand [Pest](https://pestphp.com) tests.

```bash
composer require --dev alies-dev/psalm-plugin-pest
vendor/bin/psalm-plugin enable alies-dev/psalm-plugin-pest
```

Requires PHP 8.3+, Psalm 6.19+ or 7 (including `dev-master`) and Pest 4 or 5. Your `tests/` directory must be in `<projectFiles>`.

## What it does

- Types `$this` in test, hook and dataset closures as the TestCase from `uses()` / `pest()->extend()`, including traits from `uses()` / `->use()`.
- Declares `$this->x` assigned in `beforeEach()` (in the file or in `tests/Pest.php` hooks) for the tests it applies to.
- Types `test()` with no arguments as the bound TestCase, and resolves higher-order tests like `it('x')->actingAsAdmin()`.
- Types expectation chains: `->not`, `->each`, higher-order members (`expect($user)->name->toBe('Ada')`), and `expect($v)` as `Expectation<T>`.
- Narrows variables after type assertions: `expect($user)->not->toBeNull()` makes `$user` non-null.
- Stops reporting `InternalMethod` on Pest's public API.

## Known limitations

- `tests/Pest.php` is looked up next to your Psalm config, and a test file outside `tests/` keeps Pest's own types.
- Files are parsed, never executed: a boot file with dynamic `uses()` calls disables TestCase resolution for the suite.
- Custom expectations from `expect()->extend()` are still reported.
- Narrowing works on plain variables only, not `$this->prop`.

## License

MIT, see [LICENSE](LICENSE).
