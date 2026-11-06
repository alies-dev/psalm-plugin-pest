--FILE--
<?php declare(strict_types=1);

// Pest replays the TestCall methods of a describe() chain on every test inside it, at any depth.
describe('parked', function (): void {
    it('skipped with its group', function (): void {});
    describe('nested', function (): void {
        it('skipped with the outer group', function (): void {});
    });
})->skip();
describe('placeholders', function (): void {
    test('todo with its group', function (): void {});
})->todo();
describe('per platform', function (): void {
    it('skipped on windows with its group', function (): void {});
})->group('a')->skipOnWindows();

// A beforeEach() inside a describe() reaches the tests registered after it in that describe() only.
describe('todo hook', function (): void {
    it('before the hook', function (): void {});
    beforeEach()->todo();
    it('after the hook', function (): void {});
    describe('nested', function (): void {
        it('below the hook', function (): void {});
    });
});
it('outside the hooked group', function (): void {});

describe('not parked', function (): void {
    it('in a plain group', function (): void {});
})->group('a');

$test = it('assigned', function (): void {});
$test->group('a');
if (\getenv('CI') !== false) {
    it('in a condition', function (): void {});
}
if (\getenv('PEST_FIXTURE') === 'early') {
    return it('returned', function (): void {});
}

// A file-level beforeEach() reaches every test registered after it in the file.
beforeEach(function (): void {})->skip();
it('after a file-level skip', function (): void {});
describe('after a file-level skip', function (): void {
    it('in a group', function (): void {});
});
it('later in the file', function (): void {});

?>
--EXPECTF--
PestEmptyTestClosure on line %d: The closure passed to it() is empty, so the test checks nothing of its own; add assertions or mark it ->todo().
PestEmptyTestClosure on line %d: The closure passed to it() is empty, so the test checks nothing of its own; add assertions or mark it ->todo().
PestEmptyTestClosure on line %d: The closure passed to it() is empty, so the test checks nothing of its own; add assertions or mark it ->todo().
PestEmptyTestClosure on line %d: The closure passed to it() is empty, so the test checks nothing of its own; add assertions or mark it ->todo().
PestEmptyTestClosure on line %d: The closure passed to it() is empty, so the test checks nothing of its own; add assertions or mark it ->todo().
PestEmptyTestClosure on line %d: The closure passed to it() is empty, so the test checks nothing of its own; add assertions or mark it ->todo().
