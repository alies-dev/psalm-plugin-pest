<?php

declare(strict_types=1);

use Rector\CodingStyle\Rector\String_\UseClassKeywordForClassNameResolutionRector;
use Rector\Config\RectorConfig;
use Rector\Php55\Rector\String_\StringClassNameToClassConstantRector;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
    ->withPaths(['src', 'tests'])
    ->withRootFiles()
    ->withCache(__DIR__ . '/.cache/rector')
    ->withSkip([
        // Fixture projects analysed by a Psalm subprocess, kept in the shape a Pest suite has.
        'tests/Unit/Fixtures',
        // Rewrites `assert($x instanceof Foo)` in tests to Assert::assertInstanceOf(); not equivalent.
        \Rector\PHPUnit\CodeQuality\Rector\FuncCall\AssertFuncCallToPHPUnitAssertRector::class => ['tests/*'],
        // Analysed class names (Pest's, fixtures') are not always autoloadable.
        StringClassNameToClassConstantRector::class,
        UseClassKeywordForClassNameResolutionRector::class,
    ])
    ->withPhpVersion(PhpVersion::PHP_83)
    ->withPreparedSets(deadCode: true, codeQuality: true, codingStyle: true, typeDeclarations: true, phpunitCodeQuality: true);
