# big0nia

A standalone static analyzer that detects nested loops which are secretly
an expensive collection join, and suggests indexing the inner collection
instead. No PHPStan required.

## The problem it finds

```php
foreach ($users as $user) {
    foreach ($orders as $order) {
        if ($user->getId() === $order->getUserId()) {
            // ...
        }
    }
}
```

This is O(users × orders): for every user, the whole `$orders` collection is
scanned. `big0nia` reports:

```
tests/data/example.php:2
  Potential O(n × m) algorithm: every user is compared against every order
  using getId() vs getUserId(). Estimated complexity: O(users × orders).
  Tip: Index orders by userId before the loop, then look up matches instead
  of scanning. Possible complexity after optimization: O(users + orders).
```

## Install

```bash
composer require --dev doloto/big0nia
```

## Usage

```bash
vendor/bin/big0nia analyse src/
```

Exits `0` with "No issues found." when clean, `1` with a diagnostic per
finding otherwise.

## Status

v0 ships a single rule, `NestedLoopJoinRule`. More performance-anti-pattern
rules (Doctrine N+1, `array_merge()` in loops, repeated sorts) are planned.

## License

MIT
