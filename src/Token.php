<?php

declare(strict_types=1);

namespace Dsqlex;

final class TokenType
{
    public const PLUS       = 0;
    public const MINUS      = 1;
    public const MULTIPLY   = 2;
    public const DIVIDE     = 3;
    public const EQ         = 4;
    public const NEQ        = 5;
    public const LT         = 6;
    public const GT         = 7;
    public const LTE        = 8;
    public const GTE        = 9;
    public const SELECT     = 10;
    public const CASE_      = 11;
    public const WHEN       = 12;
    public const THEN       = 13;
    public const ELSE_      = 14;
    public const END        = 15;
    public const AND_       = 16;
    public const OR_        = 17;
    public const NOT_       = 18;
    public const NULL_      = 19;
    public const TRUE_      = 20;
    public const FALSE_     = 21;
    public const IS         = 22;
    public const IN         = 23;
    public const LIKE       = 24;
    public const FN_UPPER   = 25;
    public const FN_LOWER   = 26;
    public const FN_ROUND   = 27;
    public const FN_COALESCE = 28;
    public const FN_ABS     = 29;
    public const FN_CONCAT  = 30;
    public const FN_EVENT   = 31;
    public const NUMBER     = 32;
    public const STRING     = 33;
    public const IDENTIFIER = 34;
    public const LPAREN     = 35;
    public const RPAREN     = 36;
    public const COMMA      = 37;
}

final class Token
{
    public int $type;
    public string $text;

    public function __construct(int $type, string $text = '')
    {
        $this->type = $type;
        $this->text = $text;
    }
}
