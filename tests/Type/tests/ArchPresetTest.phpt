--FILE--
<?php declare(strict_types=1);

// `arch()->preset()` is Pest's documented way to apply architecture presets. Pest replays `preset()` on its
// generated test class (the `Testable` trait), and the Preset classes it returns are `@internal` DSL.
arch('php')->preset()->php();
arch('laravel')->preset()->laravel()->ignoring('App\\Legacy');
arch('security')->preset()->security()->ignoring(['md5', 'sha1']);
arch('strict')->preset()->strict();
arch('relaxed')->preset()->relaxed();

// Members Pest marks @internal itself keep reporting.
Pest\Preset::custom('ddd', static function (): void {
});

// Psalm checks the Preset objects themselves only where a class holds them.
final class PresetReader
{
    public function apply(Pest\Preset $preset): void
    {
        $preset->php();
        $preset->laravel();
    }

    public function ignore(Pest\ArchPresets\AbstractPreset $preset): void
    {
        $preset->ignoring('App\\Legacy');
        $preset->execute();
    }
}
?>
--EXPECTF--
InternalMethod on line %d: The method Pest\Preset::custom is internal to Pest but called from root namespace
InternalMethod on line %d: The method Pest\ArchPresets\AbstractPreset::execute is internal to Pest but called from PresetReader::ignore
