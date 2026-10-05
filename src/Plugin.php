<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;

/**
 * Every handler self-gates on Pest being scanned, so the plugin is inert in a project without Pest.
 *
 * @psalm-api
 */
final class Plugin implements PluginEntryPointInterface
{
    /** Registration order is hook order where two handlers share an event. */
    private const HANDLERS = [
        InternalDslHandler::class,
        ExpectationHandler::class,
        ClosureThisHandler::class,
        HigherOrderTestHandler::class,
        CurrentTestHandler::class,
        BeforeEachPropertiesHandler::class,
        ExpectNarrowingHandler::class,
    ];

    #[\Override]
    public function __invoke(RegistrationInterface $registration, ?\SimpleXMLElement $config = null): void
    {
        // A reused process (language server, tests) must not see the previous run's answers.
        TestCaseResolver::reset();

        foreach (self::HANDLERS as $handler) {
            // registerHooksFromClass() checks class_exists($handler, false) and never autoloads.
            \class_exists($handler);
            $registration->registerHooksFromClass($handler);
        }
    }
}
