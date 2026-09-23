<?php

declare(strict_types=1);

namespace Dsqlex;

final class Dsqlex
{
    /**
     * Parse an expression string into an AST.
     */
    public static function parse(string $expression): AstNode
    {
        $tokens = Lexer::tokenize($expression);
        return Parser::parseTokens($tokens);
    }

    /**
     * Evaluate a pre-parsed AST with the given context.
     */
    public static function eval(AstNode $ast, Context $ctx, ?EvalOptions $opts = null): Value
    {
        return Evaluator::evaluate($ast, $ctx, $opts);
    }

    /**
     * Parse and evaluate in one call.
     */
    public static function evalString(string $expression, Context $ctx, ?EvalOptions $opts = null): Value
    {
        $ast = self::parse($expression);
        return self::eval($ast, $ctx, $opts);
    }
}
