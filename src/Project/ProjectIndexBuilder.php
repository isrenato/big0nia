<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Project;

use Doloto\Big0nia\Analysis\ParsedFile;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\Function_;
use PhpParser\NodeFinder;

final class ProjectIndexBuilder
{
    /**
     * @param ParsedFile[] $files
     */
    public function build(array $files): ProjectIndex
    {
        $finder = new NodeFinder();
        $classesByFqcn = [];
        $interfaceImplementors = [];
        $functionsByFqcn = [];

        foreach ($files as $file) {
            /** @var Class_[] $classes */
            $classes = $finder->findInstanceOf($file->ast, Class_::class);
            foreach ($classes as $class) {
                if ($class->namespacedName === null) {
                    continue;
                }

                $fqcn = $class->namespacedName->toString();
                $classesByFqcn[$fqcn] = new ClassIndexEntry($fqcn, $class, $file->filePath);

                foreach ($class->implements as $interfaceName) {
                    $interfaceImplementors[$interfaceName->toString()][] = $fqcn;
                }
            }

            /** @var Function_[] $functions */
            $functions = $finder->findInstanceOf($file->ast, Function_::class);
            foreach ($functions as $function) {
                if ($function->namespacedName === null) {
                    continue;
                }

                $functionsByFqcn[$function->namespacedName->toString()] = new FunctionIndexEntry($function, $file->filePath);
            }
        }

        return new ProjectIndex($classesByFqcn, $interfaceImplementors, $functionsByFqcn);
    }
}
