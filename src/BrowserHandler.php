<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Type\Atomic\TNamedObject;

/**
 * Lets `visit('/')->click('Login')->assertSee('Welcome')` type with pest-plugin-browser installed.
 *
 * `visit()` returns `PendingAwaitablePage`, documented `@mixin Webpage|AwaitableWebpage`. Psalm reads a union
 * mixin as no mixin at all, so every assertion on the page is an `UndefinedMagicMethod`. At run time its
 * `__call()` forwards to `AwaitableWebpage`, whose `__call()` runs the method on `Webpage` and hands the
 * fluent result back, so `Webpage` is the class the call lands on and the one its methods are checked against.
 * The browser plugin is not required: without it there is nothing to patch.
 */
final class BrowserHandler implements AfterCodebasePopulatedInterface
{
    private const PENDING_PAGE = 'Pest\Browser\Api\PendingAwaitablePage';

    private const WEBPAGE = 'Pest\Browser\Api\Webpage';

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $provider = $event->getCodebase()->classlike_storage_provider;

        if (!$provider->has(self::PENDING_PAGE) || !$provider->has(self::WEBPAGE)) {
            return;
        }

        $pendingPage = $provider->get(self::PENDING_PAGE);
        $webpage = $provider->get(self::WEBPAGE)->name;

        foreach ($pendingPage->namedMixins as $mixin) {
            if ($mixin->value === $webpage) {
                return;
            }
        }

        $pendingPage->namedMixins[] = new TNamedObject($webpage);
    }
}
