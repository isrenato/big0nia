<?php

declare(strict_types=1);

namespace Doloto\Big0nia\Ast;

enum CollectionSize: string
{
    case FixedSmall = 'fixed_small';
    case Unbounded = 'unbounded';
    case Unknown = 'unknown';
}
