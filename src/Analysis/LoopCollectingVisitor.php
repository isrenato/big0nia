<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Analysis;

use PhpParser\Node;
use PhpParser\Node\Stmt\For_;
use PhpParser\Node\Stmt\Foreach_;
use PhpParser\NodeVisitorAbstract;

final class LoopCollectingVisitor extends NodeVisitorAbstract
{
    /** @var array<Foreach_|For_> */
    private array $loopNodes = [];

    public function enterNode(Node $node): null
    {
        if ($node instanceof Foreach_ || $node instanceof For_) {
            $this->loopNodes[] = $node;
        }

        return null;
    }

    /**
     * @return array<Foreach_|For_>
     */
    public function getLoopNodes(): array
    {
        return $this->loopNodes;
    }
}
