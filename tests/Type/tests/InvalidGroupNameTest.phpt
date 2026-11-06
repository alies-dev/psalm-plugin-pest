--FILE--
<?php declare(strict_types=1);

class GroupFixtureTestCase extends \PHPUnit\Framework\TestCase {}

it('empty', function (): void {})->group('');
it('blank', function (): void {})->group('  ');
it('none', function (): void {})->group();
it('one of several', function (): void {})->group('feature', '');
it('chained', function (): void {})->group('a')->group('');
test('test()', function (): void {})->group("\t");
uses(GroupFixtureTestCase::class)->group('');
pest()->group('');
pest()->extend(GroupFixtureTestCase::class)->group('');
describe('block', function (): void {})->group('');

it('named', function (): void {})->group('feature');
it('several', function (): void {})->group('feature', 'slow');
function named(string $name): void { it('variable', function (): void {})->group($name); }
it('constant', function (): void {})->group(PHP_EOL);
it('spread', function (): void {})->group(...['']);
uses(GroupFixtureTestCase::class)->group('feature');
pest()->group('feature');
$_callable = pest()->group(...);
$_unpacked = pest()->group(...'');
describe('block', function (): void {})->group('feature');

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
InvalidArgument on line %d: Tried to unpack non-iterable ''
