--FILE--
<?php declare(strict_types=1);

class GroupFixtureTestCase extends \PHPUnit\Framework\TestCase {}

final class Groups
{
    public const EMPTY = '';
    public const BLANK = ' ';
    public const FEATURE = 'feature';
}

it('empty', function (): void { expect(true)->toBeTrue(); })->group('');
it('blank', function (): void { expect(true)->toBeTrue(); })->group('  ');
it('none', function (): void { expect(true)->toBeTrue(); })->group();
it('one of several', function (): void { expect(true)->toBeTrue(); })->group('feature', '');
it('chained', function (): void { expect(true)->toBeTrue(); })->group('a')->group('');
test('test()', function (): void { expect(true)->toBeTrue(); })->group("\t");
uses(GroupFixtureTestCase::class)->group('');
pest()->group('');
pest()->extend(GroupFixtureTestCase::class)->group('');
pest()->extend(GroupFixtureTestCase::class)->in('Feature')->group('');
describe('block', function (): void {})->group('');
beforeEach(function (): void {})->group('');
todo('later')->group('');
it('mixed case', function (): void { expect(true)->toBeTrue(); })->GROUP('');
it('named argument', function (): void { expect(true)->toBeTrue(); })->group(groups: '');
it('empty constant', function (): void { expect(true)->toBeTrue(); })->group(Groups::EMPTY);
it('blank constant', function (): void { expect(true)->toBeTrue(); })->group(Groups::BLANK);
$_blank = '';
it('literal variable', function (): void { expect(true)->toBeTrue(); })->group($_blank);

it('named', function (): void { expect(true)->toBeTrue(); })->group('feature');
it('several', function (): void { expect(true)->toBeTrue(); })->group('feature', 'slow');
function named(string $name): void { it('variable', function (): void {})->group($name); }
it('named constant', function (): void { expect(true)->toBeTrue(); })->group(Groups::FEATURE);
it('PHP_EOL is not a literal type', function (): void { expect(true)->toBeTrue(); })->group(PHP_EOL);
it('spread', function (): void { expect(true)->toBeTrue(); })->group(...['']);
uses(GroupFixtureTestCase::class)->group('feature');
pest()->group('feature');
$_callable = pest()->group(...);
describe('block', function (): void {})->group('feature');
beforeEach(function (): void {})->group('feature');
function conditional(bool $b): void
{
    $g = '';
    if ($b) {
        $g = 'x';
    }
    it('conditional union', function (): void {})->group($g);
}
/** @param list<string> $parts */
function concatenated(array $parts): void
{
    $name = '';
    foreach ($parts as $p) {
        $name .= $p;
    }
    it('loop concatenation', function (): void {})->group($name);
}
// Deliberate false negative: $g is typed '' | 'b', not a single literal, so the empty element is not reported.
foreach (['', 'b'] as $g) {
    it('foreach literal list', function (): void { expect(true)->toBeTrue(); })->group($g);
}
/** @psalm-suppress PestInvalidGroupName */
it('suppressed', function (): void { expect(true)->toBeTrue(); })->group('');

class NotPest { public function group(string ...$_names): void {} }
(new NotPest())->group('');
?>
--EXPECTF--
PestInvalidGroupName on line %d: A group name must not be empty or whitespace only.
PestInvalidGroupName on line %d: A group name must not be empty or whitespace only.
PestInvalidGroupName on line %d: group() needs at least one group name.
PestInvalidGroupName on line %d: A group name must not be empty or whitespace only.
PestInvalidGroupName on line %d: A group name must not be empty or whitespace only.
PestInvalidGroupName on line %d: A group name must not be empty or whitespace only.
PestInvalidGroupName on line %d: A group name must not be empty or whitespace only.
PestInvalidGroupName on line %d: A group name must not be empty or whitespace only.
PestInvalidGroupName on line %d: A group name must not be empty or whitespace only.
PestInvalidGroupName on line %d: A group name must not be empty or whitespace only.
PestInvalidGroupName on line %d: A group name must not be empty or whitespace only.
PestInvalidGroupName on line %d: A group name must not be empty or whitespace only.
PestInvalidGroupName on line %d: A group name must not be empty or whitespace only.
PestInvalidGroupName on line %d: A group name must not be empty or whitespace only.
PestInvalidGroupName on line %d: A group name must not be empty or whitespace only.
PestInvalidGroupName on line %d: A group name must not be empty or whitespace only.
PestInvalidGroupName on line %d: A group name must not be empty or whitespace only.
PestInvalidGroupName on line %d: A group name must not be empty or whitespace only.
