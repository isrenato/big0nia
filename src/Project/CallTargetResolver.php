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

        // `self::` always refers to the class where the code is written,
        // never subject to runtime override the way `static::` (late
        // static binding) or `parent::` (a different, unindexed class) are
        // — safe to resolve directly to the enclosing class. `parent`/
        // `static` are deliberately left unhandled: NameResolver never
        // rewrites them, so classMethodTarget()'s lookup of the literal
        // string "parent"/"static" as a class name naturally fails to
        // resolve, without needing an explicit early return here.
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

        foreach ($stmts as $stmt) {
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
        }

        if ($found !== null && $this->isReassignedInNestedBlock($stmts, $varName)) {
            // A conditional reassignment inside an if/loop body could hold
            // by the time this variable is actually used — the top-level
            // scan above can't see it, so the type it found can't be
            // trusted. Under-resolving (null) is safer than mis-resolving
            // to whichever assignment happened to be last at the top level.
            return null;
        }

        return $found;
    }

    /**
     * @param Stmt[] $stmts
     */
    private function isReassignedInNestedBlock(array $stmts, string $varName): bool
    {
        $isTarget = static fn (Node $target): bool => $target instanceof Variable && $target->name === $varName;

        foreach ($stmts as $stmt) {
            if ($stmt instanceof Expression && $stmt->expr instanceof Assign) {
                // A plain top-level `$var = ...;` is already accounted for
                // by findLastNewAssignmentTypeFqcn's own last-wins scan —
                // only a reassignment this method can't see (inside a
                // nested if/loop body, or a top-level compound/reference
                // assignment it doesn't track) should invalidate the result.
                continue;
            }

            if ($this->assignmentFinder->anyAssigns([$stmt], $isTarget)) {
                return true;
            }
        }

        return false;
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
