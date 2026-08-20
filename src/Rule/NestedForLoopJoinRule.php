<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Rule;

use Doloto\Big0nia\Ast\CanonicalForLoopMatcher;
use Doloto\Big0nia\Ast\CollectionSize;
use Doloto\Big0nia\Ast\CollectionSizeClassifier;
use Doloto\Big0nia\Ast\JoinSignatureMatcher;
use Doloto\Big0nia\Ast\NestedForFinder;
use Doloto\Big0nia\Complexity\ComplexityLabel;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\For_;

final class NestedForLoopJoinRule implements LoopRule
{
    private NestedForFinder $forFinder;
    private CanonicalForLoopMatcher $forLoopMatcher;
    private JoinSignatureMatcher $joinMatcher;
    private CollectionSizeClassifier $sizeClassifier;

    public function __construct()
    {
        $this->forFinder = new NestedForFinder();
        $this->forLoopMatcher = new CanonicalForLoopMatcher();
        $this->joinMatcher = new JoinSignatureMatcher();
        $this->sizeClassifier = new CollectionSizeClassifier();
    }

    /**
     * @param Stmt[] $precedingStmts Statements preceding the outer loop in its own enclosing statement list.
     */
    public function check(Stmt $loopNode, array $precedingStmts): ?Finding
    {
        if (!$loopNode instanceof For_) {
            return null;
        }

        $outerBinding = $this->forLoopMatcher->match($loopNode);
        if ($outerBinding === null) {
            return null;
        }

        $inner = $this->forFinder->find($loopNode->stmts);
        if ($inner === null) {
            return null;
        }

        $innerBinding = $this->forLoopMatcher->match($inner);
        if ($innerBinding === null) {
            return null;
        }

        $signature = $this->joinMatcher->findIndexed($inner->stmts, $outerBinding, $innerBinding);
        if ($signature === null) {
            return null;
        }

        $outerClass = $this->sizeClassifier->classify(new Variable($outerBinding->collectionVarName), $precedingStmts);
        $innerClass = $this->sizeClassifier->classify(new Variable($innerBinding->collectionVarName), $precedingStmts);

        if ($outerClass === CollectionSize::FixedSmall || $innerClass === CollectionSize::FixedSmall) {
            return null;
        }

        $outerCollectionName = $outerBinding->collectionVarName;
        $innerCollectionName = $innerBinding->collectionVarName;
        $sameCollection = $outerCollectionName === $innerCollectionName;

        $before = ComplexityLabel::forJoin($outerCollectionName, $innerCollectionName, $sameCollection);
        $after = ComplexityLabel::indexedForm($outerCollectionName, $innerCollectionName, $sameCollection);

        $message = sprintf(
            'Potential %s algorithm: every %s[%s] is compared against every %s[%s] using %s vs %s. Estimated complexity: %s.',
            $sameCollection ? 'O(n²)' : 'O(n × m)',
            $outerCollectionName,
            $outerBinding->indexVarName,
            $innerCollectionName,
            $innerBinding->indexVarName,
            $signature->outerDisplay,
            $signature->innerDisplay,
            $before
        );

        $tip = sprintf(
            'Index %s by %s before the loop, then look up matches instead of scanning. Possible complexity after optimization: %s.',
            $innerCollectionName,
            $signature->innerKey,
            $after
        );

        return new Finding($loopNode->getLine(), $message, $tip);
    }
}
