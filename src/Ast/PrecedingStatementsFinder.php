<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Ast;

use PhpParser\Node;
use PhpParser\Node\Stmt;

final class PrecedingStatementsFinder
{
    /**
     * @return Stmt[]
     */
    public function find(Stmt $node): array
    {
        $parent = $node->getAttribute('parent');
        if (!$parent instanceof Node) {
            return [];
        }

        foreach ($parent->getSubNodeNames() as $subNodeName) {
            $subNode = $parent->$subNodeName;
            if (!is_array($subNode)) {
                continue;
            }

            $index = array_search($node, $subNode, true);
            if (!is_int($index)) {
                continue;
            }

            return array_slice($subNode, 0, $index);
        }

        return [];
    }
}
