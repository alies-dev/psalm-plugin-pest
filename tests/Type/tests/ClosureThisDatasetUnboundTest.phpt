--FILE--
<?php declare(strict_types=1);

// Pest binds a dataset row to the test that consumes it, which is not known here: with no TestCase declared for this file the row stays unbound.
dataset('rows', ['a' => fn (): string => $this->token]);
?>
--EXPECTF--
InvalidScope on line %d: Invalid reference to $this in a non-class context
