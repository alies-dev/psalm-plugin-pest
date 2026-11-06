<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use PhpParser\Node\Expr\MethodCall;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Union;

/**
 * Lets `visit('/')->click('Login')->assertSee('Welcome')` type with pest-plugin-browser installed.
 *
 * `visit()` returns `PendingAwaitablePage`, documented `@mixin Webpage|AwaitableWebpage`. Psalm reads a union
 * mixin as no mixin at all, so every assertion on the page is an `UndefinedMagicMethod`. At run time its
 * `__call()` forwards to `AwaitableWebpage`, whose `__call()` runs the method on `Webpage` and hands the
 * fluent result back, so `Webpage` is the class the call lands on and the one its methods are checked against.
 *
 * What comes back is not a `Webpage`: `AwaitableWebpage::__call()` returns itself when the method returned the
 * `Webpage` it ran on. {@see self::afterExpressionAnalysis()} types such a result as `AwaitableWebpage`, on a
 * `PendingAwaitablePage` and on an `AwaitableWebpage` alike (its own `@mixin Webpage` reads the same way);
 * any other result keeps the type `Webpage` declares. The browser plugin is not required: without it
 * there is nothing to patch.
 */
final class BrowserHandler implements AfterCodebasePopulatedInterface, AfterExpressionAnalysisInterface
{
    private const PENDING_PAGE = 'Pest\Browser\Api\PendingAwaitablePage';

    private const AWAITABLE_PAGE = 'Pest\Browser\Api\AwaitableWebpage';

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

    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();
        if (!$expr instanceof MethodCall || $expr->isFirstClassCallable()) {
            return null;
        }

        // Through the interface: the analyzer's own accessor is @internal.
        $nodeTypes = $event->getStatementsSource()->getNodeTypeProvider();
        $receiver = $nodeTypes->getType($expr->var);
        $result = $nodeTypes->getType($expr);

        if (!$receiver instanceof Union || !$result instanceof Union || !self::isPage($receiver)) {
            return null;
        }

        $nodeTypes->setType($expr, $result->replaceClassLike(self::WEBPAGE, self::AWAITABLE_PAGE));

        return null;
    }

    /** Every non-null part is one of the two wrappers: `$page = $page->check('x')` leaves a union of them. */
    private static function isPage(Union $receiver): bool
    {
        $isPage = false;
        foreach ($receiver->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TNull) {
                continue;
            }

            if (!$atomic instanceof TNamedObject || !\in_array($atomic->value, [self::PENDING_PAGE, self::AWAITABLE_PAGE], true)) {
                return false;
            }

            $isPage = true;
        }

        return $isPage;
    }
}
