<?php

declare(strict_types=1);

use Fixture\Engine\Engine;
use Fixture\Tests\Stubs\TextDividerRenderer;

it('uses the test suite stub', function () {
    $renderer = new TextDividerRenderer();
    echo $renderer->render(), $renderer->divider;
    echo TextDividerRenderer::make()->render();
});

it('still reports the @internal class of src', function () {
    $engine = new Engine();
    $engine->start();
    echo $engine->power;
});
