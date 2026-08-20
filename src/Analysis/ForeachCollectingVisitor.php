<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Analysis;

use PhpParser\Node;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\NodeVisitorAbstract;

final class ForeachCollectingVisitor extends NodeVisitorAbstract
{
    /** @var Foreach_[] */
    private array $foreachNodes = [];

    public function enterNode(Node $node): null
    {
        if ($node instanceof Foreach_) {
            $this->foreachNodes[] = $node;
        }

        return null;
    }

    /**
     * @return Foreach_[]
     */
    public function getForeachNodes(): array
    {
        return $this->foreachNodes;
    }
}
