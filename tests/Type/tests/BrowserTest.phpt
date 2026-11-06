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

        public function assertNoSmoke(): static
        {
            return $this;
        }

        /**
         * @internal
         */
        public function guessLocator(string $selector): string
        {
            return $selector;
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

    $_chain = $page->click('Login');
    /** @psalm-check-type-exact $_chain = \Pest\Browser\Api\Webpage */

    // Names that Webpage does not have are still reported.
    visit('/')->nope();

    // A member Webpage marks @internal itself keeps reporting.
    visit('/')->guessLocator('a');
}
?>
--EXPECTF--
UndefinedMagicMethod on line %d: Magic method Pest\Browser\Api\PendingAwaitablePage::nope does not exist
InternalMethod on line %d: The method Pest\Browser\Api\Webpage::guessLocator is internal to Pest but called from root namespace
