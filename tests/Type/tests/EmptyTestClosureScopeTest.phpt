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

// A beforeEach() inside a describe() reaches the tests created after it in that describe() only.
describe('todo hook', function (): void {
    it('before the hook', function (): void {}); // reported
    beforeEach()->todo();
    it('after the hook', function (): void {});
    describe('nested', function (): void {
        it('below the hook', function (): void {});
    });
});
it('outside the hooked group', function (): void {}); // reported
describe('todo hook', function (): void {
    it('in a later group of the same name', function (): void {}); // reported
});

describe('not parked', function (): void {
    it('in a plain group', function (): void {}); // reported
})->group('a');

// A beforeEach() later in a loop body reaches the tests before it on the next iteration.
describe('rounds', function (): void {
    foreach ([1, 2] as $round) {
        it("round {$round}", function (): void {});
        beforeEach()->skip();
    }
});

// A file-level beforeEach() reaches every test created after it in the file.
it('before the file-level hook', function (): void {}); // reported
beforeEach(function (): void {})->skip();
it('after a file-level skip', function (): void {});
describe('after a file-level skip', function (): void {
    it('in a group', function (): void {});
});
?>
--EXPECTF--
PestEmptyTestClosure on line 20: The closure passed to it() is empty, so the test checks nothing of its own; add assertions or mark it ->todo().
PestEmptyTestClosure on line 27: The closure passed to it() is empty, so the test checks nothing of its own; add assertions or mark it ->todo().
PestEmptyTestClosure on line 29: The closure passed to it() is empty, so the test checks nothing of its own; add assertions or mark it ->todo().
PestEmptyTestClosure on line 33: The closure passed to it() is empty, so the test checks nothing of its own; add assertions or mark it ->todo().
PestEmptyTestClosure on line 45: The closure passed to it() is empty, so the test checks nothing of its own; add assertions or mark it ->todo().
