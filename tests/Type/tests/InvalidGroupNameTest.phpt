--FILE--
<?php declare(strict_types=1);

class GroupFixtureTestCase extends \PHPUnit\Framework\TestCase {}

final class Groups
{
    public const EMPTY = '';
    public const BLANK = ' ';
    public const FEATURE = 'feature';
}

it('empty', function (): void {})->group('');
it('blank', function (): void {})->group('  ');
it('none', function (): void {})->group();
it('one of several', function (): void {})->group('feature', '');
it('chained', function (): void {})->group('a')->group('');
test('test()', function (): void {})->group("\t");
uses(GroupFixtureTestCase::class)->group('');
pest()->group('');
pest()->extend(GroupFixtureTestCase::class)->group('');
pest()->extend(GroupFixtureTestCase::class)->in('Feature')->group('');
describe('block', function (): void {})->group('');
beforeEach(function (): void {})->group('');
todo('later')->group('');
it('mixed case', function (): void {})->GROUP('');
it('named argument', function (): void {})->group(groups: '');
it('empty constant', function (): void {})->group(Groups::EMPTY);
it('blank constant', function (): void {})->group(Groups::BLANK);
$_blank = '';
it('literal variable', function (): void {})->group($_blank);

it('named', function (): void {})->group('feature');
it('several', function (): void {})->group('feature', 'slow');
function named(string $name): void { it('variable', function (): void {})->group($name); }
it('named constant', function (): void {})->group(Groups::FEATURE);
it('PHP_EOL is not a literal type', function (): void {})->group(PHP_EOL);
it('spread', function (): void {})->group(...['']);
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
    it('foreach literal list', function (): void {})->group($g);
}
/** @psalm-suppress PestInvalidGroupName */
it('suppressed', function (): void {})->group('');

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
