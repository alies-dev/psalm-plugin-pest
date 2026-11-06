--FILE--
<?php declare(strict_types=1);

// pest-plugin-browser needs PHP ^8.4, so these stand in for its classes, declared as in v4.3 and v5.2.
// Pest itself scans `visit()` and `pest()->browser()` from its own sources.
namespace Pest\Browser {
    /**
     * @internal
     */
    final readonly class Configuration
    {
        public function inChrome(): self
        {
            return $this;
        }

        public function timeout(int $milliseconds): self
        {
            return $this;
        }
    }
}

namespace Pest\Browser\Playwright {
    final readonly class Locator
    {
        public function __construct(public string $selector)
        {
        }
    }
}

namespace Pest\Browser\Api {
    final readonly class Webpage
    {
        public function click(string $text): self
        {
            return $this;
        }

        public function assertSee(string|int|float $text): Webpage
        {
            return $this;
        }

        public function assertNoSmoke(): Webpage
        {
            return $this;
        }

        public function url(): string
        {
            return '';
        }

        private function guessLocator(string $selector, ?string $value = null): \Pest\Browser\Playwright\Locator
        {
            return new \Pest\Browser\Playwright\Locator($selector . ($value ?? ""));
        }
    }

    /**
     * @mixin Webpage
     */
    final readonly class AwaitableWebpage
    {
        /**
         * @param array<int, mixed> $arguments
         */
        public function __call(string $name, array $arguments): mixed
        {
            return $this;
        }
    }

    /**
     * @mixin Webpage|AwaitableWebpage
     */
    final class PendingAwaitablePage
    {
        /**
         * @param array<int, mixed> $arguments
         */
        public function __call(string $name, array $arguments): mixed
        {
            return $this;
        }

        public function inDarkMode(): self
        {
            return $this;
        }
    }

    /**
     * @mixin PendingAwaitablePage
     */
    final class ArrayablePendingAwaitablePage
    {
        /**
         * @param array<int, mixed> $arguments
         */
        public function __call(string $name, array $arguments): self
        {
            return $this;
        }
    }
}

namespace {
    // Pest marks its browser configuration @internal like the rest of its DSL: documented calls stay clean.
    pest()->browser()->inChrome()->timeout(10000);

    // `@mixin Webpage|AwaitableWebpage` is a union Psalm drops: the chain still reaches Webpage.
    visit('/admin')->click('button')->assertSee('Saved');
    visit('/')->inDarkMode()->assertNoSmoke();

    $page = visit('/');
    $page->assertSee('Welcome');
    $page->click('Login')->assertSee('Dashboard');

    // A Webpage returned from its own method comes back as the AwaitableWebpage that ran it.
    $_chain = visit('/')->click('x');
    /** @psalm-check-type-exact $_chain = \Pest\Browser\Api\AwaitableWebpage */

    $_asserted = visit('/')->click('x')->assertSee('y')->assertNoSmoke();
    /** @psalm-check-type-exact $_asserted = \Pest\Browser\Api\AwaitableWebpage */

    $_reply = visit('/')->inDarkMode();
    /** @psalm-check-type-exact $_reply = \Pest\Browser\Api\PendingAwaitablePage */

    // Any other result keeps the type Webpage declares.
    $_url = visit('/')->url();
    /** @psalm-check-type-exact $_url = string */

    function awaitable(\Pest\Browser\Api\AwaitableWebpage $page): void
    {
        $_chain = $page->click('x');
        /** @psalm-check-type-exact $_chain = \Pest\Browser\Api\AwaitableWebpage */

        $_url = $page->url();
        /** @psalm-check-type-exact $_url = string */
    }

    function takesAwaitable(\Pest\Browser\Api\AwaitableWebpage $page): void
    {
        $page->assertSee('x');
    }

    function takesWebpage(\Pest\Browser\Api\Webpage $page): void
    {
        $page->assertSee('x');
    }

    takesAwaitable(visit('/')->click('Login'));
    takesWebpage(visit('/')->click('Login'));

    // Names that Webpage does not have are still reported, and so is a private one.
    visit('/')->nope();
    visit('/')->guessLocator('a');
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of takesWebpage expects Pest\Browser\Api\Webpage, but Pest\Browser\Api\AwaitableWebpage provided
UndefinedMagicMethod on line %d: Magic method Pest\Browser\Api\PendingAwaitablePage::nope does not exist
InaccessibleMethod on line %d: Cannot access private method Pest\Browser\Api\Webpage::guessLocator%A
