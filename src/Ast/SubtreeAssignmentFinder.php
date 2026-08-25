<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Ast;

use PhpParser\Node;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Stmt\ClassLike;

final class SubtreeAssignmentFinder
{
    /**
     * @param Node[] $nodes
     * @param callable(Node): bool $isTarget Returns true when the given
     *        assignment's LHS node is the thing being searched for.
     */
    public function anyAssigns(array $nodes, callable $isTarget): bool
    {
        foreach ($nodes as $node) {
            if ($this->isAssignmentTo($node, $isTarget)) {
                return true;
            }

            if ($node instanceof ClassLike) {
                continue;
            }

            foreach ($node->getSubNodeNames() as $subNodeName) {
                $subNode = $node->$subNodeName;

                if ($subNode instanceof Node && $this->anyAssigns([$subNode], $isTarget)) {
                    return true;
                }

                if (is_array($subNode)) {
                    $childNodes = array_filter($subNode, static fn ($item): bool => $item instanceof Node);
                    if ($this->anyAssigns($childNodes, $isTarget)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    /**
     * @param callable(Node): bool $isTarget
     */
    private function isAssignmentTo(Node $node, callable $isTarget): bool
    {
        if (!$node instanceof Assign && !$node instanceof AssignOp && !$node instanceof AssignRef) {
            return false;
        }

        return $isTarget($node->var);
    }
}
