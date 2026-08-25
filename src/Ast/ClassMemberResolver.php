<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Ast;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ArrayDimFetch;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\AssignOp;
use PhpParser\Node\Expr\AssignRef;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassLike;
use PhpParser\Node\Stmt\Return_;

final class ClassMemberResolver
{
    public function findPropertyDefaultArray(Node $contextNode, string $propertyName): ?Array_
    {
        $class = $this->findEnclosingClass($contextNode);
        if ($class === null) {
            return null;
        }

        $default = $this->findDeclaredPropertyDefault($class, $propertyName)
            ?? $this->findPromotedPropertyDefault($class, $propertyName);

        if ($default === null || $this->isReassignedElsewhere($class, $propertyName)) {
            return null;
        }

        return $default;
    }

    public function findPropertyTypeFqcn(Node $contextNode, string $propertyName): ?string
    {
        $class = $this->findEnclosingClass($contextNode);
        if ($class === null) {
            return null;
        }

        return $this->declaredPropertyTypeFqcn($class, $propertyName)
            ?? $this->promotedPropertyTypeFqcn($class, $propertyName);
    }

    private function declaredPropertyTypeFqcn(Class_ $class, string $propertyName): ?string
    {
        $property = $class->getProperty($propertyName);
        if ($property === null || $property->type === null) {
            return null;
        }

        return $this->simpleClassTypeFqcn($property->type);
    }

    private function promotedPropertyTypeFqcn(Class_ $class, string $propertyName): ?string
    {
        $constructor = $class->getMethod('__construct');
        if ($constructor === null) {
            return null;
        }

        foreach ($constructor->params as $param) {
            if ($param->flags === 0 || !$param->var instanceof Variable || $param->var->name !== $propertyName) {
                continue;
            }

            return $param->type !== null ? $this->simpleClassTypeFqcn($param->type) : null;
        }

        return null;
    }

    private function simpleClassTypeFqcn(Node $type): ?string
    {
        return $type instanceof Name ? $type->toString() : null;
    }

    private function findDeclaredPropertyDefault(Class_ $class, string $propertyName): ?Array_
    {
        $property = $class->getProperty($propertyName);
        if ($property === null) {
            return null;
        }

        foreach ($property->props as $prop) {
            if ($prop->name->toString() === $propertyName && $prop->default instanceof Array_) {
                return $prop->default;
            }
        }

        return null;
    }

    private function findPromotedPropertyDefault(Class_ $class, string $propertyName): ?Array_
    {
        $constructor = $class->getMethod('__construct');
        if ($constructor === null) {
            return null;
        }

        foreach ($constructor->params as $param) {
            if ($param->flags === 0 || !$param->var instanceof Variable || $param->var->name !== $propertyName) {
                continue;
            }

            return $param->default instanceof Array_ ? $param->default : null;
        }

        return null;
    }

    private function isReassignedElsewhere(Class_ $class, string $propertyName): bool
    {
        foreach ($class->getMethods() as $method) {
            if ($method->stmts !== null && $this->subtreeReassigns($method->stmts, $propertyName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param Node[] $nodes
     */
    private function subtreeReassigns(array $nodes, string $propertyName): bool
    {
        foreach ($nodes as $node) {
            if ($this->isReassignmentOf($node, $propertyName)) {
                return true;
            }

            if ($node instanceof ClassLike) {
                continue;
            }

            foreach ($node->getSubNodeNames() as $subNodeName) {
                $subNode = $node->$subNodeName;

                if ($subNode instanceof Node && $this->subtreeReassigns([$subNode], $propertyName)) {
                    return true;
                }

                if (is_array($subNode)) {
                    $childNodes = array_filter($subNode, static fn ($item): bool => $item instanceof Node);
                    if ($this->subtreeReassigns($childNodes, $propertyName)) {
                        return true;
                    }
                }
            }
        }

        return false;
    }

    private function isReassignmentOf(Node $node, string $propertyName): bool
    {
        if (!$node instanceof Assign && !$node instanceof AssignOp && !$node instanceof AssignRef) {
            return false;
        }

        return $this->isRootedInThisProperty($node->var, $propertyName);
    }

    private function isRootedInThisProperty(Node $node, string $propertyName): bool
    {
        if ($this->isThisPropertyFetch($node, $propertyName)) {
            return true;
        }

        return $node instanceof ArrayDimFetch && $this->isRootedInThisProperty($node->var, $propertyName);
    }

    private function isThisPropertyFetch(Node $node, string $propertyName): bool
    {
        return $node instanceof PropertyFetch
            && $node->var instanceof Variable
            && $node->var->name === 'this'
            && $node->name instanceof Identifier
            && $node->name->toString() === $propertyName;
    }

    public function findMethodReturnArray(Node $contextNode, string $methodName): ?Array_
    {
        $class = $this->findEnclosingClass($contextNode);
        if ($class === null) {
            return null;
        }

        $method = $class->getMethod($methodName);
        if ($method === null || $method->stmts === null || count($method->stmts) !== 1) {
            return null;
        }

        $onlyStmt = $method->stmts[0];
        if (!$onlyStmt instanceof Return_ || !$onlyStmt->expr instanceof Array_) {
            return null;
        }

        return $onlyStmt->expr;
    }

    private function findEnclosingClass(Node $node): ?Class_
    {
        $current = $node;

        while (true) {
            $parent = $current->getAttribute('parent');
            if (!$parent instanceof Node) {
                return null;
            }

            if ($parent instanceof Class_) {
                return $parent;
            }

            $current = $parent;
        }
    }
}
