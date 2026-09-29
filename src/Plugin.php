<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;

/**
 * Both handlers self-gate at AfterCodebasePopulated on Pest being scanned, so the plugin is inert
 * in a project without Pest.
 *
 * @psalm-api
 */
final class Plugin implements PluginEntryPointInterface
{
    #[\Override]
    public function __invoke(RegistrationInterface $registration, ?\SimpleXMLElement $config = null): void
    {
        // Psalm's registerHooksFromClass() refuses to autoload (class_exists($handler, false)), so
        // every handler is loaded explicitly; collaborators first, since the handlers reference them.
        require_once __DIR__ . '/UsesParser.php';
        require_once __DIR__ . '/TestCaseResolver.php';
        require_once __DIR__ . '/ClosureThisHandler.php';
        require_once __DIR__ . '/InternalDslHandler.php';

        // A reused process (language server, tests) must not see the previous run's answers.
        TestCaseResolver::reset();

        $registration->registerHooksFromClass(InternalDslHandler::class);
        $registration->registerHooksFromClass(ClosureThisHandler::class);
    }
}
