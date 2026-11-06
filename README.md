<p align="center">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="art/lockup-dark.svg">
    <img src="art/lockup-light.svg" alt="psalm-plugin-pest: makes Psalm understand Pest tests" width="560">
  </picture>
</p>

<p align="center">
  <strong>Your Pest tests, fully typed. Bugs contained.</strong>
</p>

<p align="center">
  <a href="https://packagist.org/packages/alies-dev/psalm-plugin-pest"><img src="https://img.shields.io/packagist/v/alies-dev/psalm-plugin-pest" alt="Latest version"></a>
  <a href="https://packagist.org/packages/alies-dev/psalm-plugin-pest"><img src="https://img.shields.io/packagist/dt/alies-dev/psalm-plugin-pest" alt="Downloads"></a>
  <a href="LICENSE"><img src="https://img.shields.io/packagist/l/alies-dev/psalm-plugin-pest" alt="License"></a>
</p>

This plugin teaches Psalm how [Pest](https://pestphp.com) really works. Keep your tests in the analysis, get real types and catch broken tests before you run them.

## Install

Requires Psalm 7 and Pest 4 or 5.

```bash
composer require --dev alies-dev/psalm-plugin-pest
vendor/bin/psalm-plugin enable alies-dev/psalm-plugin-pest
```

That's it. Make sure `tests/` is in `<projectFiles>` of your `psalm.xml`.



## Before and after

```php
uses(Tests\TestCase::class);

beforeEach(function () {
    $this->cart = new Cart();
});

it('totals the cart', function () {
    $this->actingAs(admin());       // before: TestCall::__call(), unchecked
                                    // after:  Tests\TestCase::actingAs()

    $total = $this->cart->total();  // before: TestCall, so $total is TestCall
                                    // after:  Cart from beforeEach(), int

    expect($total)->toBeString();   // after:  PestImpossibleExpectation,
});                                 //         total() returns int
```

Found a false positive? [Open an issue](https://github.com/alies-dev/psalm-plugin-pest/issues) with a snippet.

## License

MIT, see [LICENSE](LICENSE).
