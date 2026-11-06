--FILE--
<?php declare(strict_types=1);

// No TestCase is known for this file (no uses(), and psalm-tester runs it outside any tests/ tree), so a dataset row stays unbound.
dataset('rows', ['a' => fn (): string => $this->token]);
?>
--EXPECTF--
InvalidScope on line %d: Invalid reference to $this in a non-class context
