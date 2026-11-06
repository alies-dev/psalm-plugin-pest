--FILE--
<?php declare(strict_types=1);

namespace PestCoversFixture {
    final class Post {}
    trait Loggable {}
    interface Publishable {}
    enum Status {}
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
    it('unknown as a named argument', function (): void {})->coversClass(classes: 'PestCoversFixture\Missing4');
    it('unknown vendor class Composer does not know', function (): void {})->coversClass('Composer\Semver\VersionParserTypo');

    it('unknown ::class is Psalm\'s to report', function (): void {})->coversClass(\PestCoversFixture\Absent::class);

    it('empty names', function (): void {})
        ->coversClass('')
        ->coversTrait('\\')
        ->coversFunction('')
        ->covers('');

    it('methods are not covers targets', function (): void {})
        ->coversFunction('DateTime::format')
        ->covers('DateTime::format');

    it('leading backslashes', function (): void {})
        ->coversClass('\PestCoversFixture\Post', '\Composer\Semver\VersionParser')
        ->coversTrait('\PestCoversFixture\Loggable')
        ->coversFunction('\strlen')
        ->covers('\PestCoversFixture\Post', '\PestCoversFixture\helper');

    it('wrong kinds', function (): void {})
        ->coversClass('PestCoversFixture\Loggable', 'PestCoversFixture\Publishable', 'PestCoversFixture\helper')
        ->coversTrait('PestCoversFixture\Post', 'PestCoversFixture\Status')
        ->coversFunction('PestCoversFixture\Post', 'PestCoversFixture\Loggable')
        ->covers('PestCoversFixture\Publishable');

    it('known targets', function (): void {})
        ->coversClass(Post::class, 'PestCoversFixture\Post', 'pestcoversfixture\POST', 'PestCoversFixture\Status', \stdClass::class, 'PHPUnit\Framework\Assert', 'Composer\Semver\VersionParser')
        ->coversTrait('PestCoversFixture\Loggable')
        ->coversFunction('PestCoversFixture\helper', 'strlen', 'STRLEN', 'xdebug_start_trace')
        ->covers(Post::class, 'PestCoversFixture\Loggable', 'PestCoversFixture\Status', 'PestCoversFixture\helper', 'strlen', 'Composer\Semver\Comparator');

    // Documented limitation: calls chained from describe() go through DescribeCall::__call and are not checked.
    describe('group', function (): void {})->coversClass('PestCoversFixture\Missing5');

    function dynamic(string $name): void {
        it('dynamic targets are not read', function (): void {})
            ->coversClass($name)
            ->coversFunction($name);
    }
}
?>
--EXPECTF--
PestUnknownCoversTarget on line %d: No class or enum named "PestCoversFixture\Missing" exists; PHPUnit reports it as an invalid code coverage target on coverage runs.
PestUnknownCoversTarget on line %d: No trait named "PestCoversFixture\MissingTrait" exists; PHPUnit reports it as an invalid code coverage target on coverage runs.
PestUnknownCoversTarget on line %d: No function named "missing_helper" exists; PHPUnit reports it as an invalid code coverage target on coverage runs.
PestUnknownCoversTarget on line %d: No class, trait or function named "PestCoversFixture\Missing" exists; Pest throws "No class, trait or method named [PestCoversFixture\Missing] has been found" while loading the file.
PestUnknownCoversTarget on line %d: No class, trait or function named "missing_helper" exists; Pest throws "No class, trait or method named [missing_helper] has been found" while loading the file.
PestUnknownCoversTarget on line %d: No class, trait or function named "\PestCoversFixture\Missing" exists; Pest throws "No class, trait or method named [\PestCoversFixture\Missing] has been found" while loading the file.
PestUnknownCoversTarget on line %d: No class or enum named "PestCoversFixture\Missing2" exists; PHPUnit reports it as an invalid code coverage target on coverage runs.
PestUnknownCoversTarget on line %d: No class or enum named "PestCoversFixture\Missing4" exists; PHPUnit reports it as an invalid code coverage target on coverage runs.
PestUnknownCoversTarget on line %d: No class or enum named "Composer\Semver\VersionParserTypo" exists; PHPUnit reports it as an invalid code coverage target on coverage runs.
MixedArgument on line %d: Argument 1 of Pest\PendingCalls\TestCall::coversClass cannot be mixed, expecting string
UndefinedClass on line %d: Class, interface or enum named PestCoversFixture\Absent does not exist
PestUnknownCoversTarget on line %d: No class or enum named "" exists; PHPUnit reports it as an invalid code coverage target on coverage runs.
PestUnknownCoversTarget on line %d: No trait named "\" exists; PHPUnit reports it as an invalid code coverage target on coverage runs.
PestUnknownCoversTarget on line %d: No function named "" exists; PHPUnit reports it as an invalid code coverage target on coverage runs.
PestUnknownCoversTarget on line %d: No class, trait or function named "" exists; Pest throws "No class, trait or method named [] has been found" while loading the file.
PestUnknownCoversTarget on line %d: No function named "DateTime::format" exists; PHPUnit reports it as an invalid code coverage target on coverage runs.
PestUnknownCoversTarget on line %d: No class, trait or function named "DateTime::format" exists; Pest throws "No class, trait or method named [DateTime::format] has been found" while loading the file.
PestUnknownCoversTarget on line %d: PHPUnit does not strip the leading backslash in "\PestCoversFixture\Post" and reports it as an invalid code coverage target on coverage runs; drop the backslash.
PestUnknownCoversTarget on line %d: PHPUnit does not strip the leading backslash in "\Composer\Semver\VersionParser" and reports it as an invalid code coverage target on coverage runs; drop the backslash.
PestUnknownCoversTarget on line %d: PHPUnit does not strip the leading backslash in "\PestCoversFixture\Loggable" and reports it as an invalid code coverage target on coverage runs; drop the backslash.
PestUnknownCoversTarget on line %d: PHPUnit does not strip the leading backslash in "\strlen" and reports it as an invalid code coverage target on coverage runs; drop the backslash.
PestUnknownCoversTarget on line %d: PHPUnit does not strip the leading backslash in "\PestCoversFixture\Post" and reports it as an invalid code coverage target on coverage runs; drop the backslash.
PestUnknownCoversTarget on line %d: PHPUnit does not strip the leading backslash in "\PestCoversFixture\helper" and reports it as an invalid code coverage target on coverage runs; drop the backslash.
PestUnknownCoversTarget on line %d: "PestCoversFixture\Loggable" is a trait, so PHPUnit reports it as an invalid code coverage target on coverage runs; use coversTrait().
PestUnknownCoversTarget on line %d: "PestCoversFixture\Publishable" is an interface, so PHPUnit reports it as an invalid code coverage target on coverage runs; cover the classes that implement it instead.
PestUnknownCoversTarget on line %d: "PestCoversFixture\helper" is a function, so PHPUnit reports it as an invalid code coverage target on coverage runs; use coversFunction().
PestUnknownCoversTarget on line %d: "PestCoversFixture\Post" is a class, so PHPUnit reports it as an invalid code coverage target on coverage runs; use coversClass().
PestUnknownCoversTarget on line %d: "PestCoversFixture\Status" is an enum, so PHPUnit reports it as an invalid code coverage target on coverage runs; use coversClass().
PestUnknownCoversTarget on line %d: "PestCoversFixture\Post" is a class, so PHPUnit reports it as an invalid code coverage target on coverage runs; use coversClass().
PestUnknownCoversTarget on line %d: "PestCoversFixture\Loggable" is a trait, so PHPUnit reports it as an invalid code coverage target on coverage runs; use coversTrait().
PestUnknownCoversTarget on line %d: "PestCoversFixture\Publishable" is an interface, so PHPUnit reports it as an invalid code coverage target on coverage runs; cover the classes that implement it instead.
