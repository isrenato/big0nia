<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Project;

final class ProjectIndex
{
    /**
     * @param array<string, ClassIndexEntry> $classesByFqcn
     * @param array<string, string[]> $interfaceImplementors
     * @param array<string, FunctionIndexEntry> $functionsByFqcn
     */
    public function __construct(
        private readonly array $classesByFqcn,
        private readonly array $interfaceImplementors,
        private readonly array $functionsByFqcn,
    ) {
    }

    public function findClass(string $fqcn): ?ClassIndexEntry
    {
        return $this->classesByFqcn[$fqcn] ?? null;
    }

    /**
     * @return string[]
     */
    public function findImplementors(string $interfaceFqcn): array
    {
        return $this->interfaceImplementors[$interfaceFqcn] ?? [];
    }

    public function findFunction(string $fqcn): ?FunctionIndexEntry
    {
        return $this->functionsByFqcn[$fqcn] ?? null;
    }
}
