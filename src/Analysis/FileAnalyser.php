<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Analysis;

use Doloto\Big0nia\Ast\PrecedingStatementsFinder;
use Doloto\Big0nia\Rule\LoopRule;
use PhpParser\NodeTraverser;

final class FileAnalyser
{
    private PrecedingStatementsFinder $precedingStatementsFinder;

    /**
     * @param LoopRule[] $rules
     */
    public function __construct(
        private readonly array $rules,
    ) {
        $this->precedingStatementsFinder = new PrecedingStatementsFinder();
    }

    /**
     * @return Diagnostic[]
     */
    public function analyse(ParsedFile $parsedFile): array
    {
        $traverser = new NodeTraverser();
        $collector = new LoopCollectingVisitor();
        $traverser->addVisitor($collector);
        $traverser->traverse($parsedFile->ast);

        $diagnostics = [];

        foreach ($collector->getLoopNodes() as $loopNode) {
            $precedingStmts = $this->precedingStatementsFinder->find($loopNode);

            foreach ($this->rules as $rule) {
                $finding = $rule->check($loopNode, $precedingStmts);
                if ($finding !== null) {
                    $diagnostics[] = new Diagnostic($parsedFile->filePath, $finding->line, $finding->message, $finding->tip);
                }
            }
        }

        return $diagnostics;
    }
}
