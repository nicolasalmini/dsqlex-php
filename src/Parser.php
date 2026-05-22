<?php

declare(strict_types=1);

namespace Dsqlex;

final class Parser
{
    /** @var Token[] */
    private array $tokens;
    private int $pos = 0;
    private int $count;

    /**
     * @param Token[] $tokens
     */
    private function __construct(array $tokens)
    {
        $this->tokens = $tokens;
        $this->count = \count($tokens);
    }

    /**
     * @param Token[] $tokens
     */
    public static function parseTokens(array $tokens): AstNode
    {
        if (\count($tokens) === 0) {
            throw new \RuntimeException('Empty expression');
        }
        $p = new self($tokens);
        return $p->parseProgram();
    }

    private function peekType(): int
    {
        return $this->pos < $this->count ? $this->tokens[$this->pos]->type : -1;
    }

    private function advance(): Token
    {
        return $this->tokens[$this->pos++];
    }

    private function expect(int $type): Token
    {
        if ($this->pos >= $this->count) {
            throw new \RuntimeException("Expected token type {$type}, got end of input");
        }
        $t = $this->tokens[$this->pos];
        if ($t->type !== $type) {
            throw new \RuntimeException("Expected token type {$type}, got {$t->type}");
        }
        $this->pos++;
        return $t;
    }

    private function atEnd(): bool
    {
        return $this->pos >= $this->count;
    }

    private function parseProgram(): AstNode
    {
        if ($this->peekType() === TokenType::SELECT) {
            $this->advance();
        }
        $expr = $this->parseLogical();
        if (!$this->atEnd()) {
            throw new \RuntimeException('Unexpected token after expression');
        }
        $node = new AstNode(NodeKind::SELECT);
        $node->expr = $expr;
        return $node;
    }

    private function parseLogical(): AstNode
    {
        $left = $this->parseComparison();

        $tt = $this->peekType();
        if ($tt !== TokenType::AND_ && $tt !== TokenType::OR_) {
            return $left;
        }

        $expectedTT = $tt;
        while ($this->peekType() === $expectedTT) {
            $this->advance();
            $right = $this->parseComparison();
            $op = $expectedTT === TokenType::OR_ ? BinOp::OR_ : BinOp::AND_;
            $node = new AstNode(NodeKind::BINARY_OP);
            $node->op = $op;
            $node->left = $left;
            $node->right = $right;
            $left = $node;

            $next = $this->peekType();
            if (($next === TokenType::AND_ && $expectedTT === TokenType::OR_) ||
                ($next === TokenType::OR_ && $expectedTT === TokenType::AND_)) {
                throw new \RuntimeException('Ambiguous expression: mix of AND and OR requires parentheses');
            }
        }

        return $left;
    }

    private function parseComparison(): AstNode
    {
        $left = $this->parseArithmetic();

        $tt = $this->peekType();
        switch ($tt) {
            case TokenType::EQ:
            case TokenType::NEQ:
            case TokenType::LT:
            case TokenType::GT:
            case TokenType::LTE:
            case TokenType::GTE:
                $opTok = $this->advance();
                $right = $this->parseArithmetic();

                $opMap = [
                    TokenType::EQ  => BinOp::EQ,
                    TokenType::NEQ => BinOp::NEQ,
                    TokenType::LT  => BinOp::LT,
                    TokenType::GT  => BinOp::GT,
                    TokenType::LTE => BinOp::LTE,
                    TokenType::GTE => BinOp::GTE,
                ];

                $nextTT = $this->peekType();
                if ($nextTT === TokenType::EQ || $nextTT === TokenType::NEQ ||
                    $nextTT === TokenType::LT || $nextTT === TokenType::GT ||
                    $nextTT === TokenType::LTE || $nextTT === TokenType::GTE) {
                    throw new \RuntimeException('Cannot chain comparison operators');
                }

                $node = new AstNode(NodeKind::BINARY_OP);
                $node->op = $opMap[$opTok->type];
                $node->left = $left;
                $node->right = $right;
                return $node;

            case TokenType::IS:
                $this->advance();
                $negated = false;
                if ($this->peekType() === TokenType::NOT_) {
                    $this->advance();
                    $negated = true;
                }
                switch ($this->peekType()) {
                    case TokenType::NULL_:
                        $this->advance();
                        $right = new AstNode(NodeKind::NULL_LIT);
                        break;
                    case TokenType::TRUE_:
                        $this->advance();
                        $right = new AstNode(NodeKind::BOOL_LIT);
                        $right->boolVal = true;
                        break;
                    case TokenType::FALSE_:
                        $this->advance();
                        $right = new AstNode(NodeKind::BOOL_LIT);
                        break;
                    default:
                        throw new \RuntimeException('Expected NULL, TRUE, or FALSE after IS [NOT]');
                }
                $node = new AstNode(NodeKind::BINARY_OP);
                $node->op = $negated ? BinOp::NEQ : BinOp::EQ;
                $node->left = $left;
                $node->right = $right;
                return $node;

            case TokenType::NOT_:
                $this->advance();
                if ($this->peekType() === TokenType::IN) {
                    $this->advance();
                    $items = $this->parseInList();
                    $node = new AstNode(NodeKind::NOT_IN_EXPR);
                    $node->expr = $left;
                    $node->args = $items;
                    return $node;
                }
                if ($this->peekType() === TokenType::LIKE) {
                    $this->advance();
                    $pat = $this->parsePrimary();
                    $node = new AstNode(NodeKind::NOT_LIKE_EXPR);
                    $node->expr = $left;
                    $node->pattern = $pat;
                    return $node;
                }
                throw new \RuntimeException('Expected IN or LIKE after NOT');

            case TokenType::IN:
                $this->advance();
                $items = $this->parseInList();
                $node = new AstNode(NodeKind::IN_EXPR);
                $node->expr = $left;
                $node->args = $items;
                return $node;

            case TokenType::LIKE:
                $this->advance();
                $pat = $this->parsePrimary();
                $node = new AstNode(NodeKind::LIKE_EXPR);
                $node->expr = $left;
                $node->pattern = $pat;
                return $node;
        }

        return $left;
    }

    /**
     * @return AstNode[]
     */
    private function parseInList(): array
    {
        $this->expect(TokenType::LPAREN);
        $items = [$this->parseLogical()];
        while ($this->peekType() === TokenType::COMMA) {
            $this->advance();
            $items[] = $this->parseLogical();
        }
        $this->expect(TokenType::RPAREN);
        return $items;
    }

    private function parseArithmetic(): AstNode
    {
        $left = $this->parsePrimary();

        $group = self::arithGroup($this->peekType());
        if ($group === 0) {
            return $left;
        }

        $expectedGroup = $group;
        while (true) {
            $g = self::arithGroup($this->peekType());
            if ($g === 0) {
                break;
            }
            if ($g !== $expectedGroup) {
                throw new \RuntimeException('Ambiguous expression: mix of +/- and */÷ requires parentheses');
            }
            $opTok = $this->advance();
            $right = $this->parsePrimary();

            $opMap = [
                TokenType::PLUS     => BinOp::PLUS,
                TokenType::MINUS    => BinOp::MINUS,
                TokenType::MULTIPLY => BinOp::MULTIPLY,
                TokenType::DIVIDE   => BinOp::DIVIDE,
            ];

            $node = new AstNode(NodeKind::BINARY_OP);
            $node->op = $opMap[$opTok->type];
            $node->left = $left;
            $node->right = $right;
            $left = $node;
        }

        return $left;
    }

    private static function arithGroup(int $tt): int
    {
        if ($tt === TokenType::PLUS || $tt === TokenType::MINUS) {
            return 1;
        }
        if ($tt === TokenType::MULTIPLY || $tt === TokenType::DIVIDE) {
            return 2;
        }
        return 0;
    }

    private function parsePrimary(): AstNode
    {
        $tt = $this->peekType();

        switch ($tt) {
            case TokenType::NUMBER:
                $tok = $this->advance();
                $node = new AstNode(NodeKind::NUMBER_LIT);
                $node->decVal = $tok->text;
                return $node;

            case TokenType::STRING:
                $tok = $this->advance();
                $node = new AstNode(NodeKind::STRING_LIT);
                $node->strVal = $tok->text;
                return $node;

            case TokenType::TRUE_:
                $this->advance();
                $node = new AstNode(NodeKind::BOOL_LIT);
                $node->boolVal = true;
                return $node;

            case TokenType::FALSE_:
                $this->advance();
                return new AstNode(NodeKind::BOOL_LIT);

            case TokenType::NULL_:
                $this->advance();
                return new AstNode(NodeKind::NULL_LIT);

            case TokenType::IDENTIFIER:
                $tok = $this->advance();
                $node = new AstNode(NodeKind::IDENTIFIER);
                $node->strVal = $tok->text;
                return $node;

            case TokenType::LPAREN:
                $this->advance();
                $expr = $this->parseLogical();
                $this->expect(TokenType::RPAREN);
                return $expr;

            case TokenType::CASE_:
                return $this->parseCase();

            case TokenType::FN_UPPER:
            case TokenType::FN_LOWER:
            case TokenType::FN_ROUND:
            case TokenType::FN_COALESCE:
            case TokenType::FN_ABS:
            case TokenType::FN_CONCAT:
            case TokenType::FN_EVENT:
                return $this->parseFunctionCall();

            default:
                if ($this->atEnd()) {
                    throw new \RuntimeException('Unexpected end of input');
                }
                throw new \RuntimeException("Unexpected token type: {$tt}");
        }
    }

    private function parseCase(): AstNode
    {
        $this->expect(TokenType::CASE_);
        $whens = [];
        while ($this->peekType() === TokenType::WHEN) {
            $this->advance();
            $cond = $this->parseLogical();
            $this->expect(TokenType::THEN);
            $result = $this->parseLogical();
            $whens[] = new WhenClause($cond, $result);
        }
        if (\count($whens) === 0) {
            throw new \RuntimeException('CASE requires at least one WHEN clause');
        }
        $elseClause = null;
        if ($this->peekType() === TokenType::ELSE_) {
            $this->advance();
            $elseClause = $this->parseLogical();
        }
        $this->expect(TokenType::END);
        $node = new AstNode(NodeKind::CASE_EXPR);
        $node->whens = $whens;
        $node->elseClause = $elseClause;
        return $node;
    }

    private function parseFunctionCall(): AstNode
    {
        $tok = $this->advance();
        $nameMap = [
            TokenType::FN_UPPER    => 'UPPER',
            TokenType::FN_LOWER    => 'LOWER',
            TokenType::FN_ROUND    => 'ROUND',
            TokenType::FN_COALESCE => 'COALESCE',
            TokenType::FN_ABS      => 'ABS',
            TokenType::FN_CONCAT   => 'CONCAT',
            TokenType::FN_EVENT    => 'EVENT',
        ];
        $name = $nameMap[$tok->type];
        $this->expect(TokenType::LPAREN);
        $args = [];
        if ($this->peekType() !== TokenType::RPAREN) {
            $args[] = $this->parseLogical();
            while ($this->peekType() === TokenType::COMMA) {
                $this->advance();
                $args[] = $this->parseLogical();
            }
        }
        $this->expect(TokenType::RPAREN);
        $node = new AstNode(NodeKind::FUNCTION_CALL);
        $node->strVal = $name;
        $node->args = $args;
        return $node;
    }
}
