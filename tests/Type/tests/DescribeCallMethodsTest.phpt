--FILE--
<?php declare(strict_types=1);

// Pest forwards these from describe() to the TestCall of every test in the block.
$_grouped = describe('grouped', function (): void {})->group('slow')->skip('wip');
/** @psalm-check-type-exact $_grouped = Pest\PendingCalls\DescribeCall */
describe('skipped', function (): void {})->skip();
describe('with data', function (): void {})->with([1, 2])->only();

// TestCall's params apply.
describe('wrong params', function (): void {})->group(1);

// Only the public methods of TestCall are forwarded.
describe('unknown', function (): void {})->nope();
describe('magic', function (): void {})->__get('x');
describe('private', function (): void {})->skipOnOs('Linux', 'x');
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of Pest\PendingCalls\DescribeCall::group expects string, but 1 provided
UndefinedMagicMethod on line %d: Magic method Pest\PendingCalls\DescribeCall::nope does not exist
UndefinedMagicMethod on line %d: Magic method Pest\PendingCalls\DescribeCall::__get does not exist
UndefinedMagicMethod on line %d: Magic method Pest\PendingCalls\DescribeCall::skiponos does not exist
