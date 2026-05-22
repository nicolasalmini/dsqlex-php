<?php

declare(strict_types=1);

namespace Dsqlex;

final class Lexer
{
    private const KEYWORDS = [
        'SELECT'   => TokenType::SELECT,
        'CASE'     => TokenType::CASE_,
        'WHEN'     => TokenType::WHEN,
        'THEN'     => TokenType::THEN,
        'ELSE'     => TokenType::ELSE_,
        'END'      => TokenType::END,
        'AND'      => TokenType::AND_,
        'OR'       => TokenType::OR_,
        'NOT'      => TokenType::NOT_,
        'NULL'     => TokenType::NULL_,
        'TRUE'     => TokenType::TRUE_,
        'FALSE'    => TokenType::FALSE_,
        'IS'       => TokenType::IS,
        'IN'       => TokenType::IN,
        'LIKE'     => TokenType::LIKE,
        'UPPER'    => TokenType::FN_UPPER,
        'LOWER'    => TokenType::FN_LOWER,
        'ROUND'    => TokenType::FN_ROUND,
        'COALESCE' => TokenType::FN_COALESCE,
        'NVL'      => TokenType::FN_COALESCE,
        'ABS'      => TokenType::FN_ABS,
        'CONCAT'   => TokenType::FN_CONCAT,
        'EVENT'    => TokenType::FN_EVENT,
    ];

    /**
     * @return Token[]
     * @throws \RuntimeException
     */
    public static function tokenize(string $input): array
    {
        $tokens = [];
        $i = 0;
        $n = \strlen($input);

        while ($i < $n) {
            $c = $input[$i];

            // Whitespace
            if ($c === ' ' || $c === "\t" || $c === "\n" || $c === "\r") {
                $i++;
                continue;
            }

            // Line comments
            if ($c === '#' || ($c === '-' && $i + 1 < $n && $input[$i + 1] === '-')) {
                while ($i < $n && $input[$i] !== "\n") {
                    $i++;
                }
                continue;
            }

            // Block comments
            if ($c === '/' && $i + 1 < $n && $input[$i + 1] === '*') {
                $i += 2;
                while (true) {
                    if ($i + 1 >= $n) {
                        throw new \RuntimeException('Unterminated block comment');
                    }
                    if ($input[$i] === '*' && $input[$i + 1] === '/') {
                        $i += 2;
                        break;
                    }
                    $i++;
                }
                continue;
            }

            // Two-char operators
            if ($i + 1 < $n) {
                $two = $input[$i] . $input[$i + 1];
                if ($two === '!=') { $tokens[] = new Token(TokenType::NEQ); $i += 2; continue; }
                if ($two === '<=') { $tokens[] = new Token(TokenType::LTE); $i += 2; continue; }
                if ($two === '>=') { $tokens[] = new Token(TokenType::GTE); $i += 2; continue; }
            }

            // Single-char operators
            switch ($c) {
                case '+': $tokens[] = new Token(TokenType::PLUS);     $i++; continue 2;
                case '-': $tokens[] = new Token(TokenType::MINUS);    $i++; continue 2;
                case '*': $tokens[] = new Token(TokenType::MULTIPLY); $i++; continue 2;
                case '/': $tokens[] = new Token(TokenType::DIVIDE);   $i++; continue 2;
                case '=': $tokens[] = new Token(TokenType::EQ);       $i++; continue 2;
                case '<': $tokens[] = new Token(TokenType::LT);       $i++; continue 2;
                case '>': $tokens[] = new Token(TokenType::GT);       $i++; continue 2;
                case '(': $tokens[] = new Token(TokenType::LPAREN);   $i++; continue 2;
                case ')': $tokens[] = new Token(TokenType::RPAREN);   $i++; continue 2;
                case ',': $tokens[] = new Token(TokenType::COMMA);    $i++; continue 2;
            }

            // String literal
            if ($c === "'") {
                $i++;
                $start = $i;
                while ($i < $n && $input[$i] !== "'") {
                    $i++;
                }
                if ($i >= $n) {
                    throw new \RuntimeException('Unterminated string literal');
                }
                $tokens[] = new Token(TokenType::STRING, \substr($input, $start, $i - $start));
                $i++;
                continue;
            }

            // Number literal
            if ($c >= '0' && $c <= '9') {
                $start = $i;
                while ($i < $n && (($input[$i] >= '0' && $input[$i] <= '9') || $input[$i] === '.')) {
                    $i++;
                }
                $tokens[] = new Token(TokenType::NUMBER, \substr($input, $start, $i - $start));
                continue;
            }

            // Identifier or keyword
            if (self::isIdentStart($c)) {
                $start = $i;
                while ($i < $n && self::isIdentCont($input[$i])) {
                    $i++;
                }
                $text = \substr($input, $start, $i - $start);
                $upper = \strtoupper($text);
                if (isset(self::KEYWORDS[$upper])) {
                    $tokens[] = new Token(self::KEYWORDS[$upper], $text);
                } else {
                    $tokens[] = new Token(TokenType::IDENTIFIER, $text);
                }
                continue;
            }

            throw new \RuntimeException("Unexpected character: '{$c}'");
        }

        return $tokens;
    }

    private static function isIdentStart(string $c): bool
    {
        return ($c >= 'a' && $c <= 'z') || ($c >= 'A' && $c <= 'Z') || $c === '_';
    }

    private static function isIdentCont(string $c): bool
    {
        return self::isIdentStart($c) || ($c >= '0' && $c <= '9') || $c === '.';
    }
}
