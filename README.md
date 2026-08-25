# big0nia

A standalone static analyzer that detects algorithmic-complexity
anti-patterns in PHP: nested loops which are secretly an expensive
collection join, `array_merge()` calls that silently turn a loop into
O(n²) by rebuilding the same array on every iteration, and sorts that
redundantly re-sort data nothing in the loop ever changes.

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

## array_merge() in a loop

```php
$result = [];
foreach ($items as $item) {
    $result = array_merge($result, [$item]);
}
```

`array_merge()` copies its entire first argument on every call, so this is
O(n²) — for every item, the whole (growing) `$result` array is rebuilt.
`big0nia` reports:

```
tests/Analysis/data/array-merge-in-loop.php:17
  Potential O(n²) algorithm: array_merge() rebuilds $result from scratch
  on every iteration.
  Tip: Replace array_merge($result, [...]) with $result[] = ... (or an
  equivalent append), or build the pieces separately and merge once after
  the loop.
```

Only the self-referential accumulation pattern is flagged — the assigned
variable must also appear as one of `array_merge()`'s own arguments, inside
a `foreach` or canonical `for` loop (through `if`/`elseif`/`else`, but not
into a nested loop, which is checked independently on its own). An
`array_merge()` call that builds an unrelated value from other variables is
not flagged, and the finding is suppressed under the same size-based rules
as the nested-loop-join detector when the loop provably iterates a small
fixed collection.

## Repeated sorts in a loop

```php
foreach ($items as $item) {
    usort($data, $cmp);
    // ... use $data ...
}
```

If `$data` is never modified inside the loop, sorting it again on every
iteration is pure waste — it's already sorted after the first call.
`big0nia` reports:

```
tests/Analysis/data/repeated-sort-in-loop.php:16
  Potential wasted work: usort($data, ...) re-sorts $data on every
  iteration, but $data is never modified inside this loop.
  Tip: Move usort($data, ...) above the loop — sorting an already-sorted,
  unchanged array repeatedly wastes work per iteration for no benefit.
```

Only `usort()`, `uasort()`, and `uksort()` (the custom-comparator sorts)
are checked, inside a `foreach` or canonical `for` loop (through
`if`/`elseif`/`else`, but not into a nested loop, which is checked
independently on its own). If the sorted variable *is* reassigned anywhere
in the loop — including inside a nested loop — the sort is legitimate and
not flagged, and the finding is suppressed under the same size-based rules
as the other two detectors when the loop provably iterates a small fixed
collection.

## How nested-loop-join detection works

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

## Interprocedural nested-loop joins

When the inner loop lives not in the outer loop's own body, but inside a
method called from there — possibly in a different class or file — `big0nia`
traces the call chain and reports the join:

```php
class UserService {
    public function __construct(private OrderMatcher $matcher) {}

    public function process(array $users): void {
        foreach ($users as $user) {
            $this->matcher->matchAll($user);
        }
    }
}

class OrderMatcher {
    public function matchAll($user): void {
        foreach ($this->orders() as $order) {
            if ($user->getId() === $order->getUserId()) {
                // ...
            }
        }
    }

    private function orders(): array {
        return [];
    }
}
```

`big0nia` reports:

```
tests/Console/data/interprocedural/UserService.php:15
  Potential O(n × m) algorithm: every item is compared against every order
  using getId() vs getUserId(), via OrderMatcher::matchAll(). Estimated
  complexity: O(users × order).
  Tip: Index order by userId before the loop, then look up matches instead
  of scanning (inner loop at tests/Console/data/interprocedural/OrderMatcher.php:11).
  Possible complexity after optimization: O(users + order).
```

The detector resolves four call patterns: typed properties
(`$this->service->method()`), local variables assigned via `new`
(`$x = new Foo(); $x->method()`), static calls (`Foo::method()`), and free
functions (`helper()`). For interface-typed properties, the project must have
exactly one class that directly implements the interface. Local `new`-assigned
types are resolved only from same-level preceding statements, not from inside
conditional branches. Only positional call arguments are matched — named
arguments or spread/unpacked arguments break the chain entirely. The call chain
can span multiple hops (up to a bounded depth), and the reported message names
the full chain; the tip always names the file and line of the actual inner
loop. Scope is `foreach` loops and canonical indexed `for` loops only — the
same narrow set the intra-procedural detector already documents — and `while`
loops are not yet supported for cross-call detection. Suppressions apply: a
finding is reported only when both the outer and inner collections are not
provably small (5 items or fewer by array literal or default value).

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
- Memory usage scales with total analysed project size: every file is parsed
  and its AST retained up front to build the cross-file symbol index. On very
  large codebases, pass a higher memory limit to PHP if you hit an
  out-of-memory error, e.g. `php -d memory_limit=1G vendor/bin/big0nia
  analyse src/`.

## Status

v0 ships the nested-loop-join detector for `foreach` (`NestedLoopJoinRule`)
and canonical indexed `for` loops (`NestedForLoopJoinRule`), including
interprocedural detection when the inner loop lives across a method/function
call boundary (`InterproceduralLoopJoinRule`), the self-referential
`array_merge()`-in-a-loop detector (`ArrayMergeInLoopRule`), and the
loop-invariant repeated-sort detector (`RepeatedSortInLoopRule`). Cross-call
detection for `while` loops and more performance-anti-pattern rules (Doctrine
N+1) are planned.

## License

MIT
