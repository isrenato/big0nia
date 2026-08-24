# Changelog

All notable changes to this project will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Detects nested-loop joins when the inner loop lives across a method or
  function call boundary (not directly in the outer loop's body) via a new
  `InterproceduralLoopJoinRule`. Resolves call chains through four patterns:
  typed properties (`$this->service->method()`), local variables assigned via
  `new` (`$x = new Foo(); $x->method()`), static calls (`Foo::method()`), and
  free functions (`helper()`). For interface-typed properties, resolves to a
  concrete class only when exactly one class directly implements the interface.
  The call chain can be arbitrarily transitive (multiple hops); the reported
  message names the full chain and the tip names the file and line of the inner
  loop when it differs from the outer loop. Positional arguments only — named
  arguments or spread/unpacked arguments break the chain entirely. Scope is
  `foreach` loops and canonical indexed `for` loops only; `while`-based
  interprocedural detection is planned. Suppressions apply under the same
  collection-size rules as the intra-procedural detectors.

## [0.4.0] - 2026-08-21

### Added

- Detects loop-invariant repeated sorting (`usort($data, ...)`,
  `uasort($data, ...)`, `uksort($data, ...)` inside a `foreach` or
  canonical `for` loop where `$data` is never modified anywhere in the
  loop, including inside a nested loop) via a new `RepeatedSortInLoopRule`.
  A sort whose target variable does change per iteration is legitimate and
  not flagged, and the finding is suppressed under the same size-based
  rules as the other detectors when the loop provably iterates a small
  fixed collection.

## [0.3.0] - 2026-08-20

### Added

- Detects the self-referential `array_merge()`-in-a-loop anti-pattern
  (`$result = array_merge($result, [...])` inside a `foreach` or canonical
  `for` loop) via a new `ArrayMergeInLoopRule`, only flagging the case where
  the assigned variable also appears as one of `array_merge()`'s own
  arguments — an unrelated `array_merge()` call building a different value
  is not flagged. Reuses the same collection-size suppression as the
  nested-loop-join rules when the loop provably iterates a small fixed
  collection.
- Renamed the `LoopJoinRule` interface to `LoopRule`, since it now has an
  implementer (`ArrayMergeInLoopRule`) that isn't a join rule. Mechanical
  rename only, no behavior change.

## [0.2.0] - 2026-08-20

### Added

- `CollectionSizeClassifier` now resolves `$this->property` (via the
  property's declared or constructor-promoted default array literal) and
  `$this->method()` (via a method body that is exactly one
  `return <array literal>;`) through a new `ClassMemberResolver` helper,
  reducing false positives on collections whose fixed size is declared on
  the class rather than as a local literal. A property's default is only
  trusted if it is never reassigned anywhere else in the class — including
  through append writes (`$this->prop[] = ...`), compound assignment
  (`$this->prop += ...`), and reference assignment (`$this->prop = &...`),
  while assignments inside a nested anonymous class are correctly not
  attributed to the outer class's property of the same name.
- Detects the nested-loop-join anti-pattern in canonical indexed `for` loops
  (`for ($i = 0; $i < count($users); $i++) { ... $users[$i] ... }`), not just
  `foreach`, via a new `NestedForLoopJoinRule` sharing the same size
  classification and complexity-labeling as the `foreach` rule. Only the
  exact canonical form is recognized (init to 0, strict `<` against a bare
  `count($var)`, `++`/pre-increment by 1) and only `for`-in-`for` nesting is
  detected — anything else, including mixed `foreach`/`for` nesting, falls
  through unreported, same narrow-but-honest discipline as the rest of the
  tool.
- `FileAnalyser` now takes a list of rules (`LoopJoinRule[]`) instead of a
  single hardcoded rule, and collects both `foreach` and canonical `for`
  loop nodes, so additional loop-shape rules can be added without changing
  its constructor again.

### Fixed

- Suppressed the `SplObjectStorage::attach()` deprecation notice raised by
  `nikic/php-parser` internals on PHP 8.5+, scoped narrowly to the parser's
  `parse()` call only (safe because this tool never executes analysed code,
  so any deprecation surfacing during a run can only be parser-internal
  noise, never something about the target codebase).

## [0.1.0] - 2026-08-20

### Added

- Initial release: a standalone static-analysis CLI (`bin/big0nia analyse
  <path>`) that detects nested `foreach` loops secretly performing an
  O(n×m) or O(n²) join, and suggests indexing the inner collection instead.
- AST helpers (`NestedForeachFinder`, `JoinSignatureMatcher`,
  `CollectionSizeClassifier`) built on `nikic/php-parser`, with no
  PHPStan dependency.
- `CollectionSize` enum-backed size classification (`FixedSmall` /
  `Unbounded` / `Unknown`), suppressing findings only on collections
  provably small via a local array literal.
- `JoinSignatureMatcher` detects a join comparison combined with `&&`
  (e.g. `if ($user->getId() === $order->getUserId() && $order->isPaid())`),
  descending into `BooleanAnd` at any depth to find the comparison operand.
- `NestedLoopJoinRule`, `FileAnalyser`, and `AnalyseCommand`, with
  per-file error isolation (a single unparseable or missing file no
  longer aborts the whole run) and clear exit-code semantics.
- Full test suite, CI workflow (PHPUnit, PHPStan at level 8, PHP-CS-Fixer).
- `composer.json` `authors` and `version` metadata.
