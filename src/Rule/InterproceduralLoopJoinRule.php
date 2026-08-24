<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Rule;

use Doloto\Big0nia\Ast\CallSiteFinder;
use Doloto\Big0nia\Ast\CanonicalForLoopMatcher;
use Doloto\Big0nia\Ast\CollectionSize;
use Doloto\Big0nia\Ast\CollectionSizeClassifier;
use Doloto\Big0nia\Ast\ForLoopBinding;
use Doloto\Big0nia\Ast\JoinSignatureMatcher;
use Doloto\Big0nia\Ast\NestedForeachFinder;
use Doloto\Big0nia\Ast\NestedForFinder;
use Doloto\Big0nia\Ast\PrecedingStatementsFinder;
use Doloto\Big0nia\Complexity\ComplexityLabel;
use Doloto\Big0nia\Project\CallTarget;
use Doloto\Big0nia\Project\CallTargetResolver;
use Doloto\Big0nia\Project\ProjectIndex;
use PhpParser\Node;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\Node\Stmt\Function_;

final class InterproceduralLoopJoinRule implements LoopRule
{
    private const MAX_HOPS = 20;

    private CallSiteFinder $callSiteFinder;
    private CallTargetResolver $targetResolver;
    private NestedForeachFinder $foreachFinder;
    private NestedForFinder $forFinder;
    private CanonicalForLoopMatcher $forLoopMatcher;
    private JoinSignatureMatcher $joinMatcher;
    private CollectionSizeClassifier $sizeClassifier;
    private PrecedingStatementsFinder $precedingStatementsFinder;

    public function __construct(ProjectIndex $index)
    {
        $this->callSiteFinder = new CallSiteFinder();
        $this->targetResolver = new CallTargetResolver($index);
        $this->foreachFinder = new NestedForeachFinder();
        $this->forFinder = new NestedForFinder();
        $this->forLoopMatcher = new CanonicalForLoopMatcher();
        $this->joinMatcher = new JoinSignatureMatcher();
        $this->sizeClassifier = new CollectionSizeClassifier();
        $this->precedingStatementsFinder = new PrecedingStatementsFinder();
    }

    public function check(Stmt $loopNode, array $precedingStmts): ?Finding
    {
        if ($loopNode instanceof Foreach_) {
            return $this->checkForeach($loopNode, $precedingStmts);
        }

        if ($loopNode instanceof For_) {
            return $this->checkFor($loopNode, $precedingStmts);
        }

        return null;
    }

    /**
     * @param Stmt[] $precedingStmts
     */
    private function checkForeach(Foreach_ $loopNode, array $precedingStmts): ?Finding
    {
        if (!$loopNode->valueVar instanceof Variable || !is_string($loopNode->valueVar->name)) {
            return null;
        }

        $outerCollectionName = $this->exprLabel($loopNode->expr) ?? $loopNode->valueVar->name;
        $outerClass = $this->sizeClassifier->classify($loopNode->expr, $precedingStmts);

        $result = $this->followChain($loopNode->stmts, $loopNode->valueVar->name, '', [], [], null, $precedingStmts);

        return $result === null ? null : $this->buildFinding($loopNode->getLine(), $outerCollectionName, $outerClass, $result);
    }

    /**
     * @param Stmt[] $precedingStmts
     */
    private function checkFor(For_ $loopNode, array $precedingStmts): ?Finding
    {
        $binding = $this->forLoopMatcher->match($loopNode);
        if ($binding === null) {
            return null;
        }

        $outerCollectionName = $binding->collectionVarName;
        $outerClass = $this->sizeClassifier->classify(new Variable($binding->collectionVarName), $precedingStmts);

        $result = $this->followChain($loopNode->stmts, '', '', [], [], $binding, $precedingStmts);

        return $result === null ? null : $this->buildFinding($loopNode->getLine(), $outerCollectionName, $outerClass, $result);
    }

    /**
     * @param Stmt[] $stmts
     * @param string[] $visited
     * @param string[] $chainLabels
     * @param Stmt[] $outerPrecedingStmts Statements preceding the loop itself in its own
     *        enclosing scope — needed only at hop 0, to resolve a local `new`-assigned
     *        variable declared before the loop starts (CallSiteFinder's own precedingStmts
     *        only sees statements within the body being searched, not the outer scope
     *        surrounding it). Left at its default `[]` on every recursive call: once a hop
     *        crosses into a callee's body, that callee's own local scope is unrelated to the
     *        caller's, so there is no outer context to carry forward.
     */
    private function followChain(
        array $stmts,
        string $trackedVar,
        string $filePath,
        array $visited,
        array $chainLabels,
        ?ForLoopBinding $indexedOrigin = null,
        array $outerPrecedingStmts = []
    ): ?InterproceduralJoinResult {
        if ($chainLabels !== []) {
            $found = $this->findJoinedLoopInBody($stmts, $trackedVar, $filePath, $chainLabels);
            if ($found !== null) {
                return $found;
            }
        }

        if (count($chainLabels) >= self::MAX_HOPS) {
            return null;
        }

        foreach ($this->callSiteFinder->findAll($stmts) as $site) {
            $argPosition = $indexedOrigin !== null
                ? $this->findIndexedArgPosition($site->call, $indexedOrigin)
                : $this->findRootedArgPosition($site->call, $trackedVar);

            if ($argPosition === null) {
                continue;
            }

            $target = $this->targetResolver->resolve($site->call, [...$outerPrecedingStmts, ...$site->precedingStmts]);
            if ($target === null) {
                continue;
            }

            $visitKey = $this->visitKey($target);
            if (in_array($visitKey, $visited, true)) {
                continue;
            }

            $param = $target->node->params[$argPosition] ?? null;
            if ($param === null || $param->variadic || !$param->var instanceof Variable || !is_string($param->var->name)) {
                continue;
            }

            $result = $this->followChain(
                $target->node->stmts ?? [],
                $param->var->name,
                $target->filePath,
                [...$visited, $visitKey],
                [...$chainLabels, $this->callLabel($target)]
            );

            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @param Stmt[] $stmts
     * @param string[] $chainLabels
     */
    private function findJoinedLoopInBody(array $stmts, string $trackedVar, string $filePath, array $chainLabels): ?InterproceduralJoinResult
    {
        $foreachLoop = $this->foreachFinder->find($stmts);
        if ($foreachLoop instanceof Foreach_ && $foreachLoop->valueVar instanceof Variable && is_string($foreachLoop->valueVar->name)) {
            $signature = $this->joinMatcher->find($foreachLoop->stmts, $trackedVar, $foreachLoop->valueVar->name);
            if ($signature !== null) {
                $innerCollectionName = $this->exprLabel($foreachLoop->expr) ?? $foreachLoop->valueVar->name;
                $innerClass = $this->sizeClassifier->classify($foreachLoop->expr, $this->precedingStatementsFinder->find($foreachLoop));

                return new InterproceduralJoinResult($signature, $innerCollectionName, $innerClass, $filePath, $foreachLoop->getLine(), $chainLabels);
            }
        }

        $forLoop = $this->forFinder->find($stmts);
        if ($forLoop instanceof For_) {
            $binding = $this->forLoopMatcher->match($forLoop);
            if ($binding !== null) {
                $signature = $this->joinMatcher->findVariableAgainstIndexed($forLoop->stmts, $trackedVar, $binding);
                if ($signature !== null) {
                    $innerClass = $this->sizeClassifier->classify(new Variable($binding->collectionVarName), $this->precedingStatementsFinder->find($forLoop));

                    return new InterproceduralJoinResult($signature, $binding->collectionVarName, $innerClass, $filePath, $forLoop->getLine(), $chainLabels);
                }
            }
        }

        return null;
    }

    private function findRootedArgPosition(MethodCall|StaticCall|FuncCall $call, string $trackedVar): ?int
    {
        foreach ($call->args as $position => $arg) {
            if ($arg instanceof Arg && $this->joinMatcher->isRootedInVar($arg->value, $trackedVar)) {
                return $position;
            }
        }

        return null;
    }

    private function findIndexedArgPosition(MethodCall|StaticCall|FuncCall $call, ForLoopBinding $binding): ?int
    {
        foreach ($call->args as $position => $arg) {
            if ($arg instanceof Arg && $this->joinMatcher->isRootedInIndexedAccess($arg->value, $binding)) {
                return $position;
            }
        }

        return null;
    }

    private function visitKey(CallTarget $target): string
    {
        if ($target->node instanceof Function_) {
            return 'function:' . ($target->node->namespacedName?->toString() ?? $target->node->name->toString());
        }

        return $target->ownerFqcn . '::' . $target->node->name->toString();
    }

    private function callLabel(CallTarget $target): string
    {
        if ($target->node instanceof Function_) {
            return $target->node->name->toString() . '()';
        }

        $shortName = $target->ownerFqcn !== null ? $this->shortClassName($target->ownerFqcn) : '?';

        return $shortName . '::' . $target->node->name->toString() . '()';
    }

    private function shortClassName(string $fqcn): string
    {
        $pos = strrpos($fqcn, '\\');

        return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
    }

    private function buildFinding(int $line, string $outerCollectionName, CollectionSize $outerClass, InterproceduralJoinResult $result): ?Finding
    {
        if ($outerClass === CollectionSize::FixedSmall || $result->innerCollectionSize === CollectionSize::FixedSmall) {
            return null;
        }

        $sameCollection = $outerCollectionName === $result->innerCollectionName;
        $before = ComplexityLabel::forJoin($outerCollectionName, $result->innerCollectionName, $sameCollection);
        $after = ComplexityLabel::indexedForm($outerCollectionName, $result->innerCollectionName, $sameCollection);
        $chain = implode(' → ', $result->chainLabels);

        $message = sprintf(
            'Potential %s algorithm: every item is compared against every %s using %s vs %s, via %s. Estimated complexity: %s.',
            $sameCollection ? 'O(n²)' : 'O(n × m)',
            $result->innerCollectionName,
            $result->signature->outerDisplay,
            $result->signature->innerDisplay,
            $chain,
            $before
        );

        $tip = sprintf(
            'Index %s by %s before the loop, then look up matches instead of scanning (inner loop at %s:%d). Possible complexity after optimization: %s.',
            $result->innerCollectionName,
            $result->signature->innerKey,
            $result->innerFilePath,
            $result->innerLine,
            $after
        );

        return new Finding($line, $message, $tip);
    }

    private function exprLabel(Node\Expr $expr): ?string
    {
        if ($expr instanceof Variable && is_string($expr->name)) {
            return $expr->name;
        }

        if ($expr instanceof PropertyFetch && $expr->name instanceof Identifier) {
            return $this->exprLabel($expr->var) . '->' . $expr->name->toString();
        }

        return null;
    }
}
