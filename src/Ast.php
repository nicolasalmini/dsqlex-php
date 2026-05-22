<?php

declare(strict_types=1);

namespace Dsqlex;

final class BinOp
{
    public const PLUS     = 0;
    public const MINUS    = 1;
    public const MULTIPLY = 2;
    public const DIVIDE   = 3;
    public const EQ       = 4;
    public const NEQ      = 5;
    public const LT       = 6;
    public const GT       = 7;
    public const LTE      = 8;
    public const GTE      = 9;
    public const AND_     = 10;
    public const OR_      = 11;
}

final class NodeKind
{
    public const SELECT        = 0;
    public const NUMBER_LIT    = 1;
    public const STRING_LIT    = 2;
    public const BOOL_LIT      = 3;
    public const NULL_LIT      = 4;
    public const IDENTIFIER    = 5;
    public const BINARY_OP     = 6;
    public const CASE_EXPR     = 7;
    public const FUNCTION_CALL = 8;
    public const IN_EXPR       = 9;
    public const NOT_IN_EXPR   = 10;
    public const LIKE_EXPR     = 11;
    public const NOT_LIKE_EXPR = 12;
}

final class WhenClause
{
    public AstNode $condition;
    public AstNode $result;

    public function __construct(AstNode $condition, AstNode $result)
    {
        $this->condition = $condition;
        $this->result = $result;
    }
}

final class AstNode
{
    public int $kind;

    // NumberLit - stored as bcmath string for precision
    public string $decVal = '0';

    // StringLit, Identifier, FunctionCall name
    public string $strVal = '';

    // BoolLit
    public bool $boolVal = false;

    // BinaryOp
    public int $op = 0;
    public ?AstNode $left = null;
    public ?AstNode $right = null;

    // CaseExpr
    /** @var WhenClause[] */
    public array $whens = [];
    public ?AstNode $elseClause = null;

    // FunctionCall args, InExpr/NotInExpr items
    /** @var AstNode[] */
    public array $args = [];

    // InExpr/NotInExpr/LikeExpr/NotLikeExpr subject, Select inner
    public ?AstNode $expr = null;

    // LikeExpr/NotLikeExpr pattern
    public ?AstNode $pattern = null;

    public function __construct(int $kind)
    {
        $this->kind = $kind;
    }
}
