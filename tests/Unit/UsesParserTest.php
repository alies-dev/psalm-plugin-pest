<?php

declare(strict_types=1);

namespace Tests\AliesDev\PsalmPluginPest\Unit;

use AliesDev\PsalmPluginPest\UsesParser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(UsesParser::class)]
final class UsesParserTest extends TestCase
{
    private string $root;

    private string $pestFile;

    #[\Override]
    protected function setUp(): void
    {
        $this->root = \sys_get_temp_dir() . '/pest-uses-parser-' . \bin2hex(\random_bytes(4));
        \mkdir($this->root . '/tests/Feature/Api', 0o777, true);
        \mkdir($this->root . '/tests/Unit', 0o777, true);
        \touch($this->root . '/tests/Feature/Api/UserTest.php');
        $this->root = (string) \realpath($this->root);
        $this->pestFile = $this->root . '/tests/Pest.php';
    }

    #[\Override]
    protected function tearDown(): void
    {
        (new \Symfony\Component\Filesystem\Filesystem())->remove($this->root);
    }

    #[Test]
    public function uses_in_relative_directory(): void
    {
        $this->assertSame(
            [['classes' => ['Tests\TestCase', 'Illuminate\Foundation\Testing\RefreshDatabase'], 'targets' => [$this->root . '/tests/Feature'], 'properties' => []]],
            $this->parsePest(<<<'PHP'
                use Tests\TestCase;
                uses(TestCase::class, Illuminate\Foundation\Testing\RefreshDatabase::class)->in('Feature');
                PHP),
        );
    }

    #[Test]
    public function pest_extend_chain_with_several_targets_and_ignored_calls(): void
    {
        $this->assertSame(
            [['classes' => ['Tests\TestCase', 'Tests\Concerns\Seeds'], 'targets' => [$this->root . '/tests/Feature', $this->root . '/tests/Unit'], 'properties' => []]],
            $this->parsePest(<<<'PHP'
                namespace Tests;
                pest()->extend(TestCase::class)->use(Concerns\Seeds::class)->group('db')->in('Feature', 'Unit');
                PHP),
        );
    }

    #[Test]
    public function dir_magic_constant_and_concatenation(): void
    {
        $this->assertSame(
            [
                ['classes' => ['Tests\TestCase'], 'targets' => [$this->root . '/tests'], 'properties' => []],
                ['classes' => ['Tests\ApiCase'], 'targets' => [$this->root . '/tests/Feature/Api'], 'properties' => []],
            ],
            $this->parsePest(<<<'PHP'
                uses(Tests\TestCase::class)->in(__DIR__);
                pest()->extends('\Tests\ApiCase')->in(__DIR__ . '/Feature/Api');
                PHP),
        );
    }

    #[Test]
    public function glob_targets_are_expanded_and_missing_ones_dropped(): void
    {
        $this->assertSame(
            [['classes' => ['Tests\TestCase'], 'targets' => [$this->root . '/tests/Feature/Api/UserTest.php'], 'properties' => []]],
            $this->parsePest("uses(Tests\\TestCase::class)->in('Feature/*/*Test.php', 'Missing');"),
        );
    }

    #[Test]
    public function pest_without_in_covers_the_pest_php_directory_but_uses_targets_only_itself(): void
    {
        $this->assertSame(
            [
                ['classes' => ['Tests\TestCase'], 'targets' => [$this->root . '/tests'], 'properties' => []],
                ['classes' => ['Tests\Other'], 'targets' => [$this->pestFile], 'properties' => []],
            ],
            $this->parsePest("pest()->extend(Tests\\TestCase::class);\nuses(Tests\\Other::class);"),
        );
    }

    #[Test]
    public function in_file_calls_target_the_file_itself(): void
    {
        $testFile = $this->root . '/tests/Feature/Api/UserTest.php';

        $this->assertSame(
            [
                ['classes' => ['Tests\ApiCase'], 'targets' => [$testFile], 'properties' => []],
                ['classes' => ['Tests\Other'], 'targets' => [$testFile], 'properties' => []],
            ],
            UsesParser::parse($testFile, "<?php\nuses(Tests\\ApiCase::class);\npest()->extend(Tests\\Other::class);\ntest('x', fn () => 1);"),
        );
    }

    #[Test]
    public function test_files_ignore_includes_inside_closures_but_boot_files_do_not(): void
    {
        // A test closure runs after Pest resolved the file's TestCase, so what it includes cannot
        // configure it; boot files stay strict, any include there may carry configuration.
        $testFile = $this->root . '/tests/Feature/Api/UserTest.php';
        $source = "<?php\nuses(Tests\\ApiCase::class);\ntest('x', function () { \$m = require 'migration.php'; });";

        $this->assertSame(
            [['classes' => ['Tests\ApiCase'], 'targets' => [$testFile], 'properties' => []]],
            UsesParser::parse($testFile, $source, bootFile: false),
        );
        $this->assertNull(UsesParser::parse($testFile, $source));
        $this->assertNull(UsesParser::parse($testFile, "<?php\nrequire 'config.php';", bootFile: false));
    }

    #[Test]
    public function aliased_function_import_is_resolved(): void
    {
        $this->assertSame(
            [['classes' => ['Tests\TestCase'], 'targets' => [$this->pestFile], 'properties' => []]],
            $this->parsePest("use function uses as bindCase;\nbindCase(Tests\\TestCase::class);"),
        );
    }

    #[Test]
    public function before_each_hooks_declare_typed_properties(): void
    {
        $this->assertSame(
            [[
                'classes' => ['Tests\TestCase', 'Tests\Concerns\CreatesUsers'],
                'targets' => [$this->root . '/tests/Feature'],
                'properties' => [
                    'user' => ['Tests\Models\User'],
                    'admin' => ['Tests\Models\Admin'],
                    'absolute' => ['App\User'],
                    'count' => ['int'],
                    'flag' => ['bool'],
                    'other' => ['mixed', 'string'],
                    'nested' => ['int'],
                ],
            ]],
            $this->parsePest(<<<'PHP'
                namespace Tests;
                use Tests\Models as M;
                pest()->extend(TestCase::class)->use(Concerns\CreatesUsers::class)->beforeEach(function () {
                    $this->user = new Models\User();
                    /** @var M\Admin */
                    $this->admin = $this->make();
                    /** @var \App\User */
                    $this->absolute = $this->make();
                    $this->count = 0;
                    $this->flag = true;
                    $this->other = foo();
                    (function () { $this->nested = 1; })();
                    $static = static function () { $this->ignored = 1; };
                })->beforeEach(fn () => $this->other = 'x')->in('Feature');
                PHP),
        );
    }

    #[Test]
    public function comments_between_name_and_arguments_still_parse(): void
    {
        $this->assertSame(
            [['classes' => ['Tests\TestCase'], 'targets' => [$this->pestFile], 'properties' => []]],
            $this->parsePest('uses /* comment */ (Tests\TestCase::class);'),
        );
    }

    #[Test]
    public function returns_inside_function_bodies_do_not_decline(): void
    {
        $this->assertSame(
            [['classes' => ['Tests\TestCase'], 'targets' => [$this->root . '/tests'], 'properties' => []]],
            $this->parsePest("function helper(): int { return 1; }\npest()->extend(Tests\\TestCase::class);"),
        );
    }

    #[Test]
    public function files_without_uses_or_pest_calls_have_no_entries(): void
    {
        $this->assertSame([], $this->parsePest("expect()->extend('toBeOne', fn () => \$this->toBe(1));"));
    }

    /** @return iterable<string, array{string}> */
    public static function unreadableSources(): iterable
    {
        yield 'variable class' => ['uses($case)->in("Feature");'];
        yield 'variable target' => ['uses(Tests\TestCase::class)->in($dir);'];
        yield 'unpacked classes' => ['uses(...$cases)->in("Feature");'];
        yield 'named argument' => ['pest()->extend(classAndTraits: Tests\TestCase::class);'];
        yield 'static class' => ['uses(static::class);'];
        yield 'dynamic method' => ['pest()->{$method}(Tests\TestCase::class);'];
        yield 'dynamic target call' => ['uses(Tests\TestCase::class)->in(dirname(__DIR__));'];
        yield 'loop' => ['foreach (["Feature"] as $dir) { uses(Tests\TestCase::class)->in($dir); }'];
        yield 'describe with an arrow function' => ["describe('x', fn () => uses(Tests\\TestCase::class));"];
        yield 'string callee' => ["'uses'(Tests\\TestCase::class);"];
        yield 'string callable argument' => ["array_map('pest', []);"];
        yield 'include with a relative path' => ["require 'bindings.php';"];
        yield 'assigned' => ['$config = pest()->extend(Tests\TestCase::class);'];
        yield 'nested in a closure' => ['beforeEach(function () { uses(Tests\TestCase::class); });'];
        yield 'parse error' => ['uses(Tests\TestCase::class)->in('];
        // External review findings: every shape Pest honors but this parser cannot model declines.
        yield 'function name as string' => ["call_user_func('uses', Tests\\TestCase::class);"];
        yield 'early return before config' => ["if (getenv('PEST_USE_DEFAULT')) {\n    return;\n}\npest()->extend(Tests\\TestCase::class);"];
        yield 'exit before config' => ["getenv('CI') or exit;\npest()->extend(Tests\\TestCase::class);"];
        yield 'include of more config' => ["require __DIR__ . '/bindings.php';"];
        yield 'first-class callable method' => ['pest()->extend(...)->__invoke(Tests\TestCase::class);'];
        yield 'first-class callable root' => ['uses(...);'];
    }

    #[Test]
    public function includes_of_inert_files_do_not_decline_but_config_or_cycles_do(): void
    {
        \file_put_contents($this->root . '/tests/helpers.php', "<?php\n// this file uses() nothing\nfunction help(): int { return 1; }");
        \file_put_contents($this->root . '/tests/config.php', "<?php\nuses(Tests\\TestCase::class);");
        \file_put_contents($this->root . '/tests/loop.php', "<?php\nrequire_once __DIR__ . '/Pest.php';");
        $uses = "\nuses(Tests\\TestCase::class)->in('Feature');";
        $expected = [['classes' => ['Tests\TestCase'], 'targets' => [$this->root . '/tests/Feature'], 'properties' => []]];

        $this->assertSame($expected, $this->parsePest("require_once __DIR__ . '/helpers.php';" . $uses));
        $this->assertNull($this->parsePest("require __DIR__ . '/config.php';"));
        $this->assertNull($this->parsePest("require __DIR__ . '/loop.php';"));
        $this->assertNull($this->parsePest("require __DIR__ . '/missing.php';"));
        $this->assertNull($this->parsePest("require dirname(__DIR__) . '/tests/helpers.php';"));
    }

    #[Test]
    public function strings_named_uses_or_pest_as_data_do_not_decline(): void
    {
        $testFile = $this->root . '/tests/Feature/Api/UserTest.php';

        $this->assertSame(
            [['classes' => ['Tests\ApiCase'], 'targets' => [$testFile], 'properties' => []]],
            UsesParser::parse($testFile, "<?php\nuses(Tests\\ApiCase::class);\ntest('x', function () { \$data = ['uses' => 10, 'pest' => \$route['uses']]; });", bootFile: false),
        );
    }

    #[Test]
    public function includes_in_function_and_method_bodies_do_not_decline(): void
    {
        $testFile = $this->root . '/tests/Feature/Api/UserTest.php';
        $source = "<?php\nuses(Tests\\ApiCase::class);\nfunction config(): array { return require __DIR__ . '/config.php'; }\n"
            . "final class Migration { public function up(): void { include 'x.php'; } }";
        $expected = [['classes' => ['Tests\ApiCase'], 'targets' => [$testFile], 'properties' => []]];

        $this->assertSame($expected, UsesParser::parse($testFile, $source, bootFile: false));
        $this->assertSame($expected, UsesParser::parse($testFile, $source));
    }

    #[Test]
    public function uses_in_describe_closures_and_if_branches_are_read_like_top_level_ones(): void
    {
        $testFile = $this->root . '/tests/Feature/Api/UserTest.php';
        $source = <<<'PHP'
            <?php
            describe('Boarding', function (): void {
                uses(Tests\ApiCase::class);
                describe('nested', function (): void { uses(Tests\Nested::class); })->skip();
                it('works', fn () => 1);
            });
            if (getenv('WORKER') !== false) {
                uses(Tests\WorkerCase::class);
            }
            PHP;

        $this->assertSame(
            [
                ['classes' => ['Tests\ApiCase'], 'targets' => [$testFile], 'properties' => []],
                ['classes' => ['Tests\Nested'], 'targets' => [$testFile], 'properties' => []],
                ['classes' => ['Tests\WorkerCase'], 'targets' => [$testFile], 'properties' => []],
            ],
            UsesParser::parse($testFile, $source, bootFile: false),
        );
    }

    #[Test]
    #[DataProvider('unreadableSources')]
    public function unreadable_sources_are_unknown(string $source): void
    {
        $this->assertNull($this->parsePest($source));
    }

    /** @return list<array{classes: list<string>, targets: list<string>}>|null */
    private function parsePest(string $source): ?array
    {
        return UsesParser::parse($this->pestFile, "<?php\n" . $source);
    }
}
