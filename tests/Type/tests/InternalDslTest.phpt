--FILE--
<?php declare(strict_types=1);

// Pest marks its DSL classes @internal, yet expect()->toX(), uses()->in() and pest()->extend()
// are the documented way to write tests: no InternalMethod on those.
expect(true)->toBeTrue();
uses()->in('Feature');
pest()->extend(PHPUnit\Framework\TestCase::class)->in('Unit');
test('x')->group('g');

pest()->printer()->compact();
pest()->project()->github('spatie/calendar-links');
arch('a')->expect('App')->toUseStrictTypes()->ignoring('App\\Legacy');
arch('b')->expect('App')->classes()->not->toBeAbstract();

// Members Pest marks @internal itself keep reporting.
$archExpectation = arch('c')->expect('App')->toUseStrictTypes();
$archExpectation->excludeCallbacks();

// Psalm checks property fetches only inside a class.
final class ProjectReader
{
    public function assignees(): string
    {
        return pest()->project()->assignees;
    }
}

// A genuinely internal Pest class keeps reporting.
if (Pest\Support\Str::startsWith('pest', 'p')) {
    echo 'internal';
}
?>
--EXPECTF--
InternalMethod on line %d: The method Pest\Arch\Contracts\ArchExpectation::excludeCallbacks is internal to Pest but called from root namespace
InternalProperty on line %d: Pest\Configuration\Project::$assignees is internal to Pest but called from ProjectReader
InternalMethod on line %d: The method Pest\Support\Str::startsWith is internal to Pest but called from root namespace
