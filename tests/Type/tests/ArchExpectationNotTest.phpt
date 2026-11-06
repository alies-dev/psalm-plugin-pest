--FILE--
<?php declare(strict_types=1);

// Arch matchers such as `toUseStrictTypes()` return the `ArchExpectation` interface, whose `@mixin Expectation`
// cannot supply `->not`: the chain stays an `ArchExpectation`, so `ignoring()` after it still resolves.
arch('strict and safe')
    ->expect('App')
    ->toUseStrictTypes()
    ->not->toUse(['die', 'dd'])
    ->ignoring('App\\Legacy');

arch('extends nothing')->expect('App\\Http')->toExtendNothing()->not->toBeUsed()->ignoringGlobalFunctions();
arch('ignored first')->expect('App')->toUseStrictTypes()->ignoring('App\\Legacy')->ignoringGlobalFunctions()->not->toUse('dd');

// A pending arch expectation keeps its own `->not`.
arch('abstract')->expect('App')->classes()->not->toBeAbstract()->ignoring('App\\Legacy');

// A misspelled call on an arch result is still reported.
arch('typo')->expect('App')->toUseStrictTypes()->ignorin('App\\Legacy');
arch('typo after not')->expect('App')->toUseStrictTypes()->not->toBeFianl();
?>
--EXPECTF--
UndefinedInterfaceMethod on line %d: Method Pest\Arch\Contracts\ArchExpectation::ignorin does not exist
UndefinedMagicMethod on line %d: Magic method Pest\Expectations\OppositeExpectation::tobefianl does not exist
