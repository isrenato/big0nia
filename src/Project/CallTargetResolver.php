<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Project;

use Doloto\Big0nia\Ast\ClassMemberResolver;
use Doloto\Big0nia\Ast\SubtreeAssignmentFinder;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Expression;

final class CallTargetResolver
{
    private ClassMemberResolver $memberResolver;
    private SubtreeAssignmentFinder $assignmentFinder;

    public function __construct(
        private readonly ProjectIndex $index,
    ) {
        $this->memberResolver = new ClassMemberResolver();
        $this->assignmentFinder = new SubtreeAssignmentFinder();
    }

    /**
     * @param Stmt[] $precedingStmts
     */
    public function resolve(Expr $call, array $precedingStmts): ?CallTarget
    {
        if ($call instanceof StaticCall) {
            return $this->resolveStaticCall($call);
        }

        if ($call instanceof FuncCall) {
            return $this->resolveFuncCall($call);
        }

        if ($call instanceof MethodCall) {
            return $this->resolveMethodCall($call, $precedingStmts);
        }

        return null;
    }

    private function resolveStaticCall(StaticCall $call): ?CallTarget
    {
        if (!$call->class instanceof Name || !$call->name instanceof Identifier) {
            return null;
        }

        // Only `self::` is handled: it always names the class where the
        // code is written, so resolving it to the enclosing class is exact.
        // `static::` (late static binding) and `parent::` could each name a
        // class that isn't in the enclosing class itself — the former a
        // subclass override we don't index by, the latter a different,
        // separately-indexed class — so both are deliberately left
        // unhandled. NameResolver never rewrites either, so
        // classMethodTarget()'s lookup of the literal string
        // "parent"/"static" as a class name naturally fails to resolve,
        // without needing an explicit early return here.
        if ($call->class->toLowerString() === 'self') {
            $fqcn = $this->memberResolver->findEnclosingClassFqcn($call);
            if ($fqcn === null) {
                return null;
            }

            return $this->classMethodTarget($fqcn, $call->name->toString());
        }

        return $this->classMethodTarget($call->class->toString(), $call->name->toString());
    }

    private function resolveFuncCall(FuncCall $call): ?CallTarget
    {
        if (!$call->name instanceof Name) {
            return null;
        }

        // For an unqualified call inside a namespace, NameResolver cannot
        // rewrite the Name itself to FQCN (PHP falls back to the global
        // function at runtime if the namespaced one doesn't exist) — it
        // instead attaches the would-be FQCN as the `namespacedName`
        // attribute. Prefer that; toString() alone is only correct for a
        // global-namespace call or an already fully-qualified call.
        $resolvedName = $call->name->getAttribute('namespacedName');
        $fqcn = $resolvedName instanceof Name ? $resolvedName->toString() : $call->name->toString();

        $entry = $this->index->findFunction($fqcn);
        if ($entry === null) {
            return null;
        }

        return new CallTarget($entry->node, null, $entry->filePath);
    }

    /**
     * @param Stmt[] $precedingStmts
     */
    private function resolveMethodCall(MethodCall $call, array $precedingStmts): ?CallTarget
    {
        if (!$call->name instanceof Identifier) {
            return null;
        }

        $typeFqcn = $this->resolveVariableTypeFqcn($call->var, $precedingStmts);
        if ($typeFqcn === null) {
            return null;
        }

        return $this->classMethodTarget($typeFqcn, $call->name->toString());
    }

    /**
     * @param Stmt[] $precedingStmts
     */
    private function resolveVariableTypeFqcn(Expr $var, array $precedingStmts): ?string
    {
        if ($var instanceof PropertyFetch && $var->var instanceof Variable && $var->var->name === 'this' && $var->name instanceof Identifier) {
            return $this->memberResolver->findPropertyTypeFqcn($var, $var->name->toString());
        }

        if ($var instanceof Variable && $var->name === 'this') {
            return $this->memberResolver->findEnclosingClassFqcn($var);
        }

        if ($var instanceof Variable && is_string($var->name)) {
            return $this->findLastNewAssignmentTypeFqcn($var->name, $precedingStmts);
        }

        return null;
    }

    /**
     * @param Stmt[] $stmts
     */
    private function findLastNewAssignmentTypeFqcn(string $varName, array $stmts): ?string
    {
        $found = null;
        $foundAt = -1;

        foreach ($stmts as $index => $stmt) {
            if (!$stmt instanceof Expression || !$stmt->expr instanceof Assign) {
                continue;
            }

            $assign = $stmt->expr;
            if (!$assign->var instanceof Variable || $assign->var->name !== $varName) {
                continue;
            }

            $found = $assign->expr instanceof New_ && $assign->expr->class instanceof Name
                ? $assign->expr->class->toString()
                : null;
            $foundAt = $index;
        }

        if ($found === null) {
            return null;
        }

        // Only statements AFTER the winning assignment can invalidate it —
        // anything before is superseded by construction (the loop above
        // already takes the last top-level match), and re-scanning them
        // would falsely bail out on the very assignment we just picked.
        // Any reassignment of $varName found in this tail — nested inside
        // an if/loop body, or hidden inside a compound expression like
        // `$x = ($var = new Y());` at the top level — means the value
        // could differ from what we found by the time the variable is
        // actually used. Under-resolving (null) is safer than
        // mis-resolving to a type that's no longer accurate.
        $tail = array_slice($stmts, $foundAt + 1);
        $isTarget = static fn (Node $target): bool => $target instanceof Variable && $target->name === $varName;

        return $this->assignmentFinder->anyAssigns($tail, $isTarget) ? null : $found;
    }

    private function classMethodTarget(string $classFqcn, string $methodName): ?CallTarget
    {
        $entry = $this->index->findClass($classFqcn);

        if ($entry === null) {
            $implementors = $this->index->findImplementors($classFqcn);
            if (count($implementors) !== 1) {
                return null;
            }

            $entry = $this->index->findClass($implementors[0]);
        }

        if ($entry === null) {
            return null;
        }

        $method = $entry->node->getMethod($methodName);
        if ($method === null || $method->stmts === null) {
            return null;
        }

        return new CallTarget($method, $entry->fqcn, $entry->filePath);
    }
}
