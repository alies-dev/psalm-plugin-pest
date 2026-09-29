<?php

declare(strict_types=1);

namespace AliesDev\PsalmPluginPest;

use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;

/**
 * Every handler self-gates at AfterCodebasePopulated on Pest being scanned, so the plugin is inert
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
        // every handler is loaded explicitly.
        require_once __DIR__ . '/InternalDslHandler.php';

        $registration->registerHooksFromClass(InternalDslHandler::class);
    }
}
