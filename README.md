# big0nia

A standalone static analyzer that detects nested loops which are secretly
an expensive collection join, and suggests indexing the inner collection
instead.

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

## How detection works

For every `foreach` loop, and every canonical indexed `for` loop
(`for ($i = 0; $i < count($users); $i++) { ... $users[$i] ... }` — exactly
this form; anything else, like `<=`, a precomputed bound variable, or
decrementing, is not recognized), `big0nia`:

1. **Finds a directly nested loop of the same kind** — a `foreach` inside
   another `foreach`'s body, or a canonical `for` inside another canonical
   `for`'s body, seen through simple `if` guards but not through other
   loops, closures, or `elseif`/`else` branches. Mixed nesting (a `for`
   inside a `foreach`, or vice versa) is not detected.
2. **Finds a join comparison** — an equality comparison (`===`, `==`,
   `!==`, `!=`) between something rooted in the outer loop's item (the
   `foreach` value variable, or `$collection[$index]` for a `for` loop) and
   the inner loop's item, either as the whole `if` condition or combined
   with `&&` at any depth, e.g.
   `$user->getId() === $order->getUserId() && $order->isPaid()`.
3. **Classifies the size of both compared collections** — an array
   literal used directly, a variable last assigned an array literal
   earlier in the same statement list, or `$this->property` /
   `$this->method()` resolved to a declared or constructor-promoted
   default array literal, or to a method body that is exactly one
   `return <array literal>;`. A property's default is only trusted if
   the property is never reassigned anywhere else in the class.
4. **Suppresses only when provably small** — a finding is suppressed
   only when one of the two collections classifies as a small fixed-size
   array literal (5 items or fewer). Everything else, including a
   collection it can't classify at all, is reported.

## Install

```bash
composer require --dev doloto/big0nia
```

## CLI reference

```bash
vendor/bin/big0nia analyse <path> [<path> ...]
```

- Accepts any mix of files and directories; directories are scanned
  recursively for `.php` files.
- Exit code `0`: every given path was analysed and no issues were found.
- Exit code `1`: a finding was reported, a given path doesn't exist, or a
  file couldn't be parsed or read.
- A path that doesn't exist prints `Path not found: <path>` to stderr and
  the rest of the run continues.
- A file that fails to parse or can't be read prints
  `Skipping <path>: <message>` to stderr and the rest of the run continues.

## Status

v0 ships the nested-loop-join detector for `foreach` (`NestedLoopJoinRule`)
and canonical indexed `for` loops (`NestedForLoopJoinRule`). More
performance-anti-pattern rules (Doctrine N+1, `array_merge()` in loops,
repeated sorts) are planned.

## License

MIT
