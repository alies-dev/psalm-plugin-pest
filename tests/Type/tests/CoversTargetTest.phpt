--FILE--
<?php declare(strict_types=1);

namespace PestCoversFixture {
    final class Post {}
    trait Loggable {}
    function helper(): void {}
}

namespace {
    use PestCoversFixture\Post;

    it('unknown literals', function (): void {})
        ->coversClass('PestCoversFixture\Missing')
        ->coversTrait('PestCoversFixture\MissingTrait')
        ->coversFunction('missing_helper');

    it('unknown covers', function (): void {})->covers('PestCoversFixture\Missing', 'missing_helper');
    it('unknown with a leading backslash', function (): void {})->covers('\PestCoversFixture\Missing');
    it('unknown among known', function (): void {})->coversClass(Post::class, 'PestCoversFixture\Missing2');

    it('unknown ::class is Psalm\'s to report', function (): void {})->coversClass(\PestCoversFixture\Absent::class);

    it('empty names', function (): void {})
        ->coversClass('')
        ->coversTrait('\\')
        ->coversFunction('')
        ->covers('');

    it('methods are not covers targets', function (): void {})
        ->coversFunction('DateTime::format')
        ->covers('DateTime::format');

    it('known targets', function (): void {})
        ->coversClass(Post::class, 'PestCoversFixture\Post', '\pestcoversfixture\POST', \stdClass::class, 'PHPUnit\Framework\Assert', 'Composer\Semver\VersionParser')
        ->coversTrait('PestCoversFixture\Loggable')
        ->coversFunction('PestCoversFixture\helper', '\strlen', 'STRLEN', 'xdebug_start_trace')
        ->covers(Post::class, 'PestCoversFixture\Loggable', 'PestCoversFixture\helper', 'strlen');

    function dynamic(string $name): void {
        it('dynamic targets are not read', function (): void {})
            ->coversClass($name)
            ->coversFunction($name);
    }
}
?>
--EXPECTF--
PestUnknownCoversTarget on line %d: No class or trait named "PestCoversFixture\Missing" exists.
PestUnknownCoversTarget on line %d: No class or trait named "PestCoversFixture\MissingTrait" exists.
PestUnknownCoversTarget on line %d: No function named "missing_helper" exists.
PestUnknownCoversTarget on line %d: No class, trait or function named "PestCoversFixture\Missing" exists.
PestUnknownCoversTarget on line %d: No class, trait or function named "missing_helper" exists.
PestUnknownCoversTarget on line %d: No class, trait or function named "\PestCoversFixture\Missing" exists.
PestUnknownCoversTarget on line %d: No class or trait named "PestCoversFixture\Missing2" exists.
MixedArgument on line %d: Argument 1 of Pest\PendingCalls\TestCall::coversClass cannot be mixed, expecting string
UndefinedClass on line %d: Class, interface or enum named PestCoversFixture\Absent does not exist
PestUnknownCoversTarget on line %d: No class or trait named "" exists.
PestUnknownCoversTarget on line %d: No class or trait named "\" exists.
PestUnknownCoversTarget on line %d: No function named "" exists.
PestUnknownCoversTarget on line %d: No class, trait or function named "" exists.
PestUnknownCoversTarget on line %d: No function named "DateTime::format" exists.
PestUnknownCoversTarget on line %d: No class, trait or function named "DateTime::format" exists.
