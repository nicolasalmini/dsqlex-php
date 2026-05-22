<?php

declare(strict_types=1);

namespace Dsqlex;

final class ValueType
{
    public const DECIMAL = 0;
    public const STRING  = 1;
    public const BOOL    = 2;
    public const NULL_   = 3;
}

final class Value
{
    public int $type;
    public string $decVal; // bcmath string
    public string $strVal;
    public bool $boolVal;

    private function __construct(int $type, string $decVal = '0', string $strVal = '', bool $boolVal = false)
    {
        $this->type = $type;
        $this->decVal = $decVal;
        $this->strVal = $strVal;
        $this->boolVal = $boolVal;
    }

    public static function null(): self
    {
        static $inst;
        return $inst ??= new self(ValueType::NULL_);
    }

    public static function true(): self
    {
        static $inst;
        return $inst ??= new self(ValueType::BOOL, '0', '', true);
    }

    public static function false(): self
    {
        static $inst;
        return $inst ??= new self(ValueType::BOOL, '0', '', false);
    }

    public static function decimal(string $d): self
    {
        return new self(ValueType::DECIMAL, $d);
    }

    public static function string(string $s): self
    {
        return new self(ValueType::STRING, '0', $s);
    }

    public static function bool(bool $b): self
    {
        return $b ? self::true() : self::false();
    }
}

final class Context
{
    /** @var Value[] */
    public array $fields = [];

    /** @var Context[] */
    public array $nested = [];

    public function setDecimal(string $key, string $val): void
    {
        $this->fields[$key] = Value::decimal($val);
    }

    public function setString(string $key, string $val): void
    {
        $this->fields[$key] = Value::string($val);
    }

    public function setBool(string $key, bool $val): void
    {
        $this->fields[$key] = Value::bool($val);
    }

    public function setNull(string $key): void
    {
        $this->fields[$key] = Value::null();
    }

    public function setNested(string $key, Context $ctx): void
    {
        $this->nested[$key] = $ctx;
    }
}

final class Evaluator
{
    // bcmath scale for intermediate calculations
    private const SCALE = 20;

    public static function evaluate(AstNode $ast, Context $ctx): Value
    {
        switch ($ast->kind) {
            case NodeKind::SELECT:
                return self::evaluate($ast->expr, $ctx);

            case NodeKind::NUMBER_LIT:
                return Value::decimal($ast->decVal);

            case NodeKind::STRING_LIT:
                return Value::string($ast->strVal);

            case NodeKind::BOOL_LIT:
                return Value::bool($ast->boolVal);

            case NodeKind::NULL_LIT:
                return Value::null();

            case NodeKind::IDENTIFIER:
                return self::resolveIdentifier($ast->strVal, $ctx);

            case NodeKind::BINARY_OP:
                return self::evalBinop($ast, $ctx);

            case NodeKind::CASE_EXPR:
                foreach ($ast->whens as $wc) {
                    $cond = self::evaluate($wc->condition, $ctx);
                    if (self::isTruthy($cond)) {
                        return self::evaluate($wc->result, $ctx);
                    }
                }
                if ($ast->elseClause !== null) {
                    return self::evaluate($ast->elseClause, $ctx);
                }
                return Value::null();

            case NodeKind::FUNCTION_CALL:
                return self::evalFunction($ast->strVal, $ast->args, $ctx);

            case NodeKind::IN_EXPR:
                $val = self::evaluate($ast->expr, $ctx);
                foreach ($ast->args as $item) {
                    $iv = self::evaluate($item, $ctx);
                    if (self::compareValues($val, $iv) === 0) {
                        return Value::true();
                    }
                }
                return Value::false();

            case NodeKind::NOT_IN_EXPR:
                $val = self::evaluate($ast->expr, $ctx);
                foreach ($ast->args as $item) {
                    $iv = self::evaluate($item, $ctx);
                    if (self::compareValues($val, $iv) === 0) {
                        return Value::false();
                    }
                }
                return Value::true();

            case NodeKind::LIKE_EXPR:
                $val = self::evaluate($ast->expr, $ctx);
                $pat = self::evaluate($ast->pattern, $ctx);
                if ($val->type === ValueType::NULL_ || $pat->type === ValueType::NULL_) {
                    return Value::null();
                }
                return Value::bool(self::likeMatch(self::valToString($val), self::valToString($pat)));

            case NodeKind::NOT_LIKE_EXPR:
                $val = self::evaluate($ast->expr, $ctx);
                $pat = self::evaluate($ast->pattern, $ctx);
                if ($val->type === ValueType::NULL_ || $pat->type === ValueType::NULL_) {
                    return Value::null();
                }
                return Value::bool(!self::likeMatch(self::valToString($val), self::valToString($pat)));
        }

        throw new \RuntimeException("Unknown node kind: {$ast->kind}");
    }

    private static function isTruthy(Value $v): bool
    {
        if ($v->type === ValueType::NULL_) {
            return false;
        }
        if ($v->type === ValueType::BOOL && !$v->boolVal) {
            return false;
        }
        return true;
    }

    private static function valueToDecimal(Value $v): string
    {
        switch ($v->type) {
            case ValueType::DECIMAL:
                return $v->decVal;
            case ValueType::STRING:
                if (\is_numeric($v->strVal)) {
                    return $v->strVal;
                }
                throw new \RuntimeException("Cannot convert '{$v->strVal}' to decimal");
            case ValueType::BOOL:
                throw new \RuntimeException('Cannot convert boolean to decimal');
            case ValueType::NULL_:
                throw new \RuntimeException('Cannot convert NULL to decimal');
        }
        throw new \RuntimeException('Unknown value type');
    }

    private static function valToString(Value $v): string
    {
        switch ($v->type) {
            case ValueType::DECIMAL:
                return $v->decVal;
            case ValueType::STRING:
                return $v->strVal;
            case ValueType::BOOL:
                return $v->boolVal ? 'TRUE' : 'FALSE';
            case ValueType::NULL_:
                return 'NULL';
        }
        return '';
    }

    /**
     * Returns -1, 0, 1, or -2 (not comparable)
     */
    private static function compareValues(Value $lhs, Value $rhs): int
    {
        if ($lhs->type === ValueType::NULL_ && $rhs->type === ValueType::NULL_) {
            return 0;
        }
        if ($lhs->type === ValueType::NULL_ || $rhs->type === ValueType::NULL_) {
            return -2;
        }
        if ($lhs->type === ValueType::DECIMAL && $rhs->type === ValueType::DECIMAL) {
            return \bccomp($lhs->decVal, $rhs->decVal, self::SCALE);
        }
        if ($lhs->type === ValueType::STRING && $rhs->type === ValueType::STRING) {
            if (\is_numeric($lhs->strVal) && \is_numeric($rhs->strVal)) {
                return \bccomp($lhs->strVal, $rhs->strVal, self::SCALE);
            }
            return $lhs->strVal <=> $rhs->strVal;
        }
        if ($lhs->type === ValueType::BOOL && $rhs->type === ValueType::BOOL) {
            return ($lhs->boolVal ? 1 : 0) <=> ($rhs->boolVal ? 1 : 0);
        }
        // Mixed: try decimal
        try {
            $dl = self::valueToDecimal($lhs);
            $dr = self::valueToDecimal($rhs);
            return \bccomp($dl, $dr, self::SCALE);
        } catch (\RuntimeException $e) {
            $sl = self::valToString($lhs);
            $sr = self::valToString($rhs);
            return $sl <=> $sr;
        }
    }

    private static function likeMatch(string $text, string $pattern): bool
    {
        $regex = '/^';
        $len = \strlen($pattern);
        for ($i = 0; $i < $len; $i++) {
            $ch = $pattern[$i];
            if ($ch === '%') {
                $regex .= '.*';
            } elseif ($ch === '_') {
                $regex .= '.';
            } else {
                $regex .= \preg_quote($ch, '/');
            }
        }
        $regex .= '$/i';
        return (bool)\preg_match($regex, $text);
    }

    private static function resolveIdentifier(string $name, Context $ctx): Value
    {
        if (isset($ctx->fields[$name])) {
            return $ctx->fields[$name];
        }

        // Dot-path
        $dot = \strpos($name, '.');
        if ($dot !== false) {
            $first = \substr($name, 0, $dot);
            $rest = \substr($name, $dot + 1);
            if (isset($ctx->nested[$first])) {
                return self::resolveIdentifier($rest, $ctx->nested[$first]);
            }
        }

        return Value::null();
    }

    private static function evalBinop(AstNode $node, Context $ctx): Value
    {
        // Short-circuit AND/OR
        if ($node->op === BinOp::AND_) {
            $lv = self::evaluate($node->left, $ctx);
            if (!self::isTruthy($lv)) {
                return $lv;
            }
            return self::evaluate($node->right, $ctx);
        }
        if ($node->op === BinOp::OR_) {
            $lv = self::evaluate($node->left, $ctx);
            if (self::isTruthy($lv)) {
                return $lv;
            }
            return self::evaluate($node->right, $ctx);
        }

        $lv = self::evaluate($node->left, $ctx);
        $rv = self::evaluate($node->right, $ctx);

        switch ($node->op) {
            case BinOp::PLUS:
            case BinOp::MINUS:
            case BinOp::MULTIPLY:
            case BinOp::DIVIDE:
                // SQL semantics: any arithmetic with NULL yields NULL
                if ($lv->type === ValueType::NULL_ || $rv->type === ValueType::NULL_) {
                    return Value::null();
                }
                $ld = self::valueToDecimal($lv);
                $rd = self::valueToDecimal($rv);
                switch ($node->op) {
                    case BinOp::PLUS:
                        return Value::decimal(\bcadd($ld, $rd, self::SCALE));
                    case BinOp::MINUS:
                        return Value::decimal(\bcsub($ld, $rd, self::SCALE));
                    case BinOp::MULTIPLY:
                        return Value::decimal(\bcmul($ld, $rd, self::SCALE));
                    case BinOp::DIVIDE:
                        if (\bccomp($rd, '0', self::SCALE) === 0) {
                            throw new \RuntimeException('Division by zero');
                        }
                        return Value::decimal(\bcdiv($ld, $rd, self::SCALE));
                }
                break;

            case BinOp::EQ:
                // Fast path for same types
                if ($lv->type === ValueType::STRING && $rv->type === ValueType::STRING) {
                    return Value::bool($lv->strVal === $rv->strVal);
                }
                if ($lv->type === ValueType::DECIMAL && $rv->type === ValueType::DECIMAL) {
                    return Value::bool(\bccomp($lv->decVal, $rv->decVal, self::SCALE) === 0);
                }
                return Value::bool(self::compareValues($lv, $rv) === 0);

            case BinOp::NEQ:
                if ($lv->type === ValueType::STRING && $rv->type === ValueType::STRING) {
                    return Value::bool($lv->strVal !== $rv->strVal);
                }
                if ($lv->type === ValueType::DECIMAL && $rv->type === ValueType::DECIMAL) {
                    return Value::bool(\bccomp($lv->decVal, $rv->decVal, self::SCALE) !== 0);
                }
                return Value::bool(self::compareValues($lv, $rv) !== 0);

            case BinOp::LT:
                return Value::bool(self::compareValues($lv, $rv) === -1);

            case BinOp::GT:
                return Value::bool(self::compareValues($lv, $rv) === 1);

            case BinOp::LTE:
                $c = self::compareValues($lv, $rv);
                return Value::bool($c === 0 || $c === -1);

            case BinOp::GTE:
                $c = self::compareValues($lv, $rv);
                return Value::bool($c === 0 || $c === 1);
        }

        throw new \RuntimeException("Unknown operator: {$node->op}");
    }

    /**
     * @param AstNode[] $args
     */
    private static function evalFunction(string $name, array $args, Context $ctx): Value
    {
        switch ($name) {
            case 'ROUND':
                if (\count($args) !== 2) {
                    throw new \RuntimeException('ROUND requires exactly 2 arguments');
                }
                $val = self::evaluate($args[0], $ctx);
                if ($val->type === ValueType::NULL_) {
                    return Value::null();
                }
                $d = self::valueToDecimal($val);
                $precVal = self::evaluate($args[1], $ctx);
                $precD = self::valueToDecimal($precVal);
                $prec = (int)$precD;
                return Value::decimal(self::bcRound($d, $prec));

            case 'COALESCE':
                foreach ($args as $arg) {
                    $val = self::evaluate($arg, $ctx);
                    if ($val->type !== ValueType::NULL_) {
                        return $val;
                    }
                }
                return Value::null();

            case 'UPPER':
                if (\count($args) !== 1) {
                    throw new \RuntimeException('UPPER requires exactly 1 argument');
                }
                $val = self::evaluate($args[0], $ctx);
                if ($val->type === ValueType::NULL_) {
                    return Value::null();
                }
                return Value::string(\strtoupper(self::valToString($val)));

            case 'LOWER':
                if (\count($args) !== 1) {
                    throw new \RuntimeException('LOWER requires exactly 1 argument');
                }
                $val = self::evaluate($args[0], $ctx);
                if ($val->type === ValueType::NULL_) {
                    return Value::null();
                }
                return Value::string(\strtolower(self::valToString($val)));

            case 'ABS':
                if (\count($args) !== 1) {
                    throw new \RuntimeException('ABS requires exactly 1 argument');
                }
                $val = self::evaluate($args[0], $ctx);
                if ($val->type === ValueType::NULL_) {
                    return Value::null();
                }
                $d = self::valueToDecimal($val);
                if ($d[0] === '-') {
                    $d = \substr($d, 1);
                }
                return Value::decimal($d);

            case 'CONCAT':
                $buf = '';
                foreach ($args as $arg) {
                    $val = self::evaluate($arg, $ctx);
                    $buf .= self::valToString($val);
                }
                return Value::string($buf);

            case 'EVENT':
                throw new \RuntimeException('EVENT function not supported in benchmark mode');
        }

        throw new \RuntimeException("Unknown function: {$name}");
    }

    /**
     * Round a bcmath string to $precision decimal places using half-up rounding.
     */
    private static function bcRound(string $number, int $precision): string
    {
        if ($precision < 0) {
            $precision = 0;
        }

        // Determine the sign
        $negative = false;
        if (\strlen($number) > 0 && $number[0] === '-') {
            $negative = true;
            $number = \substr($number, 1);
        }

        // Add 0.5 * 10^(-precision) for rounding
        $half = '0.' . \str_repeat('0', $precision) . '5';
        $rounded = \bcadd($number, $half, $precision);

        // Strip trailing zeros after decimal point for clean output
        if (\strpos($rounded, '.') !== false) {
            // Keep at least $precision places
            // Actually for DSQLEX compatibility, preserve the scale
        }

        return $negative ? '-' . $rounded : $rounded;
    }
}
