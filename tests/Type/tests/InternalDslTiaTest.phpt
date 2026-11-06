--SKIPIF--
<?php
// pest()->tia() exists since Pest 5.
if (!is_file(getcwd() . '/vendor/pestphp/pest/src/Plugins/Tia/Configuration.php')) {
    echo 'skip Pest 5 only';
}
?>
--FILE--
<?php declare(strict_types=1);

// Pest marks Pest\Plugins\Tia\Configuration @internal, yet pest()->tia() is its documented test impact analysis setup.
pest()->tia()->always();
pest()->tia()->directory('storage/tia')->locally();
pest()->tia()->baselined();
pest()->tia()->watch(['src/**' => 'tests']);
?>
--EXPECTF--
