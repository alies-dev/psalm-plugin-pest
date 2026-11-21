--SKIPIF--
<?php
// pest()->tia() exists since Pest 4.7.
if (!is_file(getcwd() . '/vendor/pestphp/pest/src/Plugins/Tia/Configuration.php')) {
    echo 'skip Pest without TIA';
}
?>
--FILE--
<?php declare(strict_types=1);

// Pest marks Pest\Plugins\Tia\Configuration @internal, yet pest()->tia() is its documented test impact analysis setup.
pest()->tia()->always();
pest()->tia()->filtered()->locally();
pest()->tia()->baselined();
pest()->tia()->watch(['src/**' => 'tests']);
?>
--EXPECTF--
