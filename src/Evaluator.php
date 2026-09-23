<?php

declare(strict_types=1);

namespace Dsqlex;

final class ValueType
{
    public const DECIMAL = 0;
    public const STRING  = 1;
    public const BOOL    = 2;
    public const NULL_   = 3;
    public const LIST    = 4;
    public const MAP     = 5;
    public const DATE    = 6;
    public const DATETIME = 7;
    public const NAIVE_DATETIME = 8;
    public const TIME    = 9;
}

final class Value
{
    public int $type;
    public string $decVal; // bcmath string
    public string $strVal;
    public bool $boolVal;
    public array $listVal;
    public $mapVal = null;
    public $timeVal = null;

    private function __construct(int $type, string $decVal = '0', string $strVal = '', bool $boolVal = false, array $listVal = [])
    {
        $this->type = $type;
        $this->decVal = $decVal;
        $this->strVal = $strVal;
        $this->boolVal = $boolVal;
        $this->listVal = $listVal;
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

    public static function list(array $items): self
    {
        return new self(ValueType::LIST, '0', '', false, $items);
    }

    public static function map(Context $ctx): self
    {
        $v = new self(ValueType::MAP);
        $v->mapVal = $ctx;
        return $v;
    }

    public static function date(\DateTimeInterface $d): self
    {
        $v = new self(ValueType::DATE);
        $v->timeVal = \DateTimeImmutable::createFromInterface($d);
        return $v;
    }

    public static function datetime(\DateTimeInterface $d): self
    {
        $v = new self(ValueType::DATETIME);
        $v->timeVal = \DateTimeImmutable::createFromInterface($d);
        return $v;
    }

    public static function naiveDatetime(\DateTimeInterface $d): self
    {
        $v = new self(ValueType::NAIVE_DATETIME);
        $v->timeVal = \DateTimeImmutable::createFromInterface($d);
        return $v;
    }

    public static function time(\DateTimeInterface $d): self
    {
        $v = new self(ValueType::TIME);
        $v->timeVal = \DateTimeImmutable::createFromInterface($d);
        return $v;
    }
}

final class EvalOptions
{
    public $resolver = null;
    public $eventResolver = null;
    public array $visited = [];
}

final class Context
{
    /** @var Value[] */
    public array $fields = [];

    /** @var Context[] */
    public array $nested = [];

    public array $lists = [];

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

    public function setList(string $key, array $items): void
    {
        $this->lists[$key] = $items;
    }

    public function setDate(string $key, \DateTimeInterface $val): void
    {
        $this->fields[$key] = Value::date($val);
    }

    public function setDateTime(string $key, \DateTimeInterface $val): void
    {
        $this->fields[$key] = Value::datetime($val);
    }

    public function setNaiveDateTime(string $key, \DateTimeInterface $val): void
    {
        $this->fields[$key] = Value::naiveDatetime($val);
    }

    public function setTime(string $key, \DateTimeInterface $val): void
    {
        $this->fields[$key] = Value::time($val);
    }
}

final class Evaluator
{
    // bcmath scale for intermediate calculations
    private const SCALE = 20;

    public static function evaluate(AstNode $ast, Context $ctx, ?EvalOptions $opts = null): Value
    {
        $opts ??= new EvalOptions();
        switch ($ast->kind) {
            case NodeKind::SELECT:
                return self::evaluate($ast->expr, $ctx, $opts);

            case NodeKind::NUMBER_LIT:
                return Value::decimal($ast->decVal);

            case NodeKind::STRING_LIT:
                return Value::string($ast->strVal);

            case NodeKind::BOOL_LIT:
                return Value::bool($ast->boolVal);

            case NodeKind::NULL_LIT:
                return Value::null();

            case NodeKind::IDENTIFIER:
                return self::resolveIdentifier($ast->strVal, $ctx, $opts);

            case NodeKind::BINARY_OP:
                return self::evalBinop($ast, $ctx, $opts);

            case NodeKind::UNARY_OP:
                $val = self::evaluate($ast->expr, $ctx, $opts);
                if ($val->type === ValueType::NULL_) {
                    return Value::null();
                }
                $d = self::valueToDecimal($val);
                if (\strlen($d) > 0 && $d[0] === '-') {
                    $d = \substr($d, 1);
                } else {
                    $d = '-' . $d;
                }
                return Value::decimal($d);

            case NodeKind::CASE_EXPR:
                foreach ($ast->whens as $wc) {
                    $cond = self::evaluate($wc->condition, $ctx, $opts);
                    if (self::isTruthy($cond)) {
                        return self::evaluate($wc->result, $ctx, $opts);
                    }
                }
                if ($ast->elseClause !== null) {
                    return self::evaluate($ast->elseClause, $ctx, $opts);
                }
                return Value::null();

            case NodeKind::FUNCTION_CALL:
                return self::evalFunction($ast->strVal, $ast->args, $ctx, $opts);

            case NodeKind::IN_EXPR:
                $val = self::evaluate($ast->expr, $ctx, $opts);
                foreach ($ast->args as $item) {
                    $iv = self::evaluate($item, $ctx, $opts);
                    if (self::compareValues($val, $iv) === 0) {
                        return Value::true();
                    }
                }
                return Value::false();

            case NodeKind::NOT_IN_EXPR:
                $val = self::evaluate($ast->expr, $ctx, $opts);
                foreach ($ast->args as $item) {
                    $iv = self::evaluate($item, $ctx, $opts);
                    if (self::compareValues($val, $iv) === 0) {
                        return Value::false();
                    }
                }
                return Value::true();

            case NodeKind::LIKE_EXPR:
                $val = self::evaluate($ast->expr, $ctx, $opts);
                $pat = self::evaluate($ast->pattern, $ctx, $opts);
                if ($val->type === ValueType::NULL_ || $pat->type === ValueType::NULL_) {
                    return Value::null();
                }
                return Value::bool(self::likeMatch(self::valToString($val), self::valToString($pat)));

            case NodeKind::NOT_LIKE_EXPR:
                $val = self::evaluate($ast->expr, $ctx, $opts);
                $pat = self::evaluate($ast->pattern, $ctx, $opts);
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
            case ValueType::LIST:
                throw new \RuntimeException('Cannot convert list to decimal');
            case ValueType::MAP:
                throw new \RuntimeException('Cannot convert map to decimal');
            case ValueType::DATE:
            case ValueType::DATETIME:
            case ValueType::NAIVE_DATETIME:
            case ValueType::TIME:
                throw new \RuntimeException('Cannot convert temporal value to decimal');
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
            case ValueType::LIST:
                return \implode(',', \array_map(fn(Value $v) => self::valToString($v), $v->listVal));
            case ValueType::MAP:
                return '';
            case ValueType::DATE:
                return $v->timeVal->format('Y-m-d');
            case ValueType::DATETIME:
                return $v->timeVal->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
            case ValueType::NAIVE_DATETIME:
                return $v->timeVal->format('Y-m-d H:i:s');
            case ValueType::TIME:
                return $v->timeVal->format('H:i:s');
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
            return \strcmp($lhs->strVal, $rhs->strVal) <=> 0;
        }
        if ($lhs->type === ValueType::BOOL && $rhs->type === ValueType::BOOL) {
            return ($lhs->boolVal ? 1 : 0) <=> ($rhs->boolVal ? 1 : 0);
        }
        if ($lhs->type === $rhs->type) {
            switch ($lhs->type) {
                case ValueType::DATE:
                    return \strcmp($lhs->timeVal->format('Y-m-d'), $rhs->timeVal->format('Y-m-d')) <=> 0;
                case ValueType::DATETIME:
                    return $lhs->timeVal <=> $rhs->timeVal;
                case ValueType::NAIVE_DATETIME:
                    return \strcmp($lhs->timeVal->format('Y-m-d H:i:s.u'), $rhs->timeVal->format('Y-m-d H:i:s.u')) <=> 0;
                case ValueType::TIME:
                    return \strcmp($lhs->timeVal->format('H:i:s.u'), $rhs->timeVal->format('H:i:s.u')) <=> 0;
            }
        }
        // Mixed: try decimal
        try {
            $dl = self::valueToDecimal($lhs);
            $dr = self::valueToDecimal($rhs);
            return \bccomp($dl, $dr, self::SCALE);
        } catch (\RuntimeException $e) {
            $sl = self::valToString($lhs);
            $sr = self::valToString($rhs);
            return \strcmp($sl, $sr) <=> 0;
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

    private static function resolveIdentifier(string $name, Context $ctx, EvalOptions $opts): Value
    {
        if (isset($ctx->fields[$name])) {
            return $ctx->fields[$name];
        }

        // Dot-path
        if (\strpos($name, '.') !== false) {
            return self::resolveDotPath(\explode('.', $name), $ctx, $name, $opts);
        }

        if (isset($ctx->nested[$name])) {
            return Value::map($ctx->nested[$name]);
        }
        if (isset($ctx->lists[$name])) {
            return Value::list(\array_map(
                fn($item) => $item instanceof Context ? Value::map($item) : $item,
                $ctx->lists[$name]));
        }

        if ($opts->resolver !== null) {
            if (\in_array($name, $opts->visited, true)) {
                throw new \RuntimeException("Circular reference detected: {$name}");
            }
            return ($opts->resolver)($name, $opts->visited);
        }

        throw new \RuntimeException("Unknown field: {$name}");
    }

    private static function resolveDotPath(array $parts, $acc, string $path, EvalOptions $opts): Value
    {
        if (\count($parts) === 0) {
            if ($acc instanceof Context) {
                return Value::map($acc);
            }
            if (\is_array($acc)) {
                return Value::list(\array_map(
                    fn($item) => $item instanceof Context ? Value::map($item) : $item,
                    $acc));
            }
            return $acc;
        }

        if (\is_array($acc)) {
            $results = [];
            foreach ($acc as $item) {
                $results[] = self::resolveDotPath($parts, $item, $path, $opts);
            }
            $allNumeric = true;
            foreach ($results as $r) {
                if ($r->type !== ValueType::DECIMAL
                    && !($r->type === ValueType::STRING && \is_numeric($r->strVal))) {
                    $allNumeric = false;
                    break;
                }
            }
            if (!$allNumeric) {
                return Value::list($results);
            }
            $sum = '0';
            foreach ($results as $r) {
                $sum = \bcadd($sum, self::valueToDecimal($r), self::SCALE);
            }
            return Value::decimal($sum);
        }

        if ($acc instanceof Context) {
            $key = \array_shift($parts);
            if (isset($acc->fields[$key])) {
                return self::resolveDotPath($parts, $acc->fields[$key], $path, $opts);
            }
            if (isset($acc->nested[$key])) {
                return self::resolveDotPath($parts, $acc->nested[$key], $path, $opts);
            }
            if (isset($acc->lists[$key])) {
                return self::resolveDotPath($parts, $acc->lists[$key], $path, $opts);
            }
            throw new \RuntimeException("Unknown field: {$path} (failed at '{$key}')");
        }

        $key = $parts[0];
        throw new \RuntimeException("Cannot access '{$key}' on non-map value in path '{$path}'");
    }

    private static function evalBinop(AstNode $node, Context $ctx, EvalOptions $opts): Value
    {
        // Short-circuit AND/OR
        if ($node->op === BinOp::AND_) {
            $lv = self::evaluate($node->left, $ctx, $opts);
            if (!self::isTruthy($lv)) {
                return $lv;
            }
            return self::evaluate($node->right, $ctx, $opts);
        }
        if ($node->op === BinOp::OR_) {
            $lv = self::evaluate($node->left, $ctx, $opts);
            if (self::isTruthy($lv)) {
                return $lv;
            }
            return self::evaluate($node->right, $ctx, $opts);
        }

        $lv = self::evaluate($node->left, $ctx, $opts);
        $rv = self::evaluate($node->right, $ctx, $opts);

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
    private static function evalFunction(string $name, array $args, Context $ctx, EvalOptions $opts): Value
    {
        switch ($name) {
            case 'ROUND':
                if (\count($args) !== 2) {
                    throw new \RuntimeException('ROUND requires exactly 2 arguments');
                }
                $val = self::evaluate($args[0], $ctx, $opts);
                $precVal = self::evaluate($args[1], $ctx, $opts);
                if ($val->type === ValueType::NULL_ || $precVal->type === ValueType::NULL_) {
                    return Value::null();
                }
                $d = self::valueToDecimal($val);
                $precD = self::valueToDecimal($precVal);
                $prec = (int)$precD;
                return Value::decimal(self::bcRound($d, $prec));

            case 'COALESCE':
                foreach ($args as $arg) {
                    $val = self::evaluate($arg, $ctx, $opts);
                    if ($val->type !== ValueType::NULL_) {
                        return $val;
                    }
                }
                return Value::null();

            case 'UPPER':
                if (\count($args) !== 1) {
                    throw new \RuntimeException('UPPER requires exactly 1 argument');
                }
                $val = self::evaluate($args[0], $ctx, $opts);
                if ($val->type === ValueType::NULL_) {
                    return Value::null();
                }
                return Value::string(\strtoupper(self::valToString($val)));

            case 'LOWER':
                if (\count($args) !== 1) {
                    throw new \RuntimeException('LOWER requires exactly 1 argument');
                }
                $val = self::evaluate($args[0], $ctx, $opts);
                if ($val->type === ValueType::NULL_) {
                    return Value::null();
                }
                return Value::string(\strtolower(self::valToString($val)));

            case 'ABS':
                if (\count($args) !== 1) {
                    throw new \RuntimeException('ABS requires exactly 1 argument');
                }
                $val = self::evaluate($args[0], $ctx, $opts);
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
                    $val = self::evaluate($arg, $ctx, $opts);
                    $buf .= self::valToString($val);
                }
                return Value::string($buf);

            case 'LEAST':
            case 'GREATEST':
                if (\count($args) === 0) {
                    throw new \RuntimeException('LEAST/GREATEST requires at least one argument');
                }
                $vals = [];
                $hasNull = false;
                foreach ($args as $arg) {
                    $v = self::evaluate($arg, $ctx, $opts);
                    if ($v->type === ValueType::NULL_) {
                        $hasNull = true;
                    }
                    $vals[] = $v;
                }
                if ($hasNull) {
                    return Value::null();
                }
                $target = $name === 'LEAST' ? -1 : 1;
                $best = $vals[0];
                foreach (\array_slice($vals, 1) as $v) {
                    if (self::compareValues($v, $best) === $target) {
                        $best = $v;
                    }
                }
                return $best;

            case 'EVENT':
                $argc = \count($args);
                $valid = $argc === 2 || $argc === 3;
                if ($valid) {
                    foreach ($args as $a) {
                        if ($a->kind !== NodeKind::IDENTIFIER) {
                            $valid = false;
                            break;
                        }
                    }
                }
                if (!$valid) {
                    throw new \RuntimeException('EVENT requires 2 or 3 arguments: EVENT(type, subtype) or EVENT(type, subtype, context_source)');
                }
                $type = $args[0]->strVal;
                $subtype = $args[1]->strVal;
                if ($argc === 2) {
                    return self::resolveEvent($type, $subtype, $ctx, $opts);
                }
                $source = $args[2]->strVal;
                if (isset($ctx->lists[$source])) {
                    $sum = '0';
                    foreach ($ctx->lists[$source] as $item) {
                        $v = self::resolveEvent($type, $subtype, $item, $opts);
                        $sum = \bcadd($sum, self::valueToDecimal($v), self::SCALE);
                    }
                    return Value::decimal($sum);
                }
                if (isset($ctx->nested[$source])) {
                    return self::resolveEvent($type, $subtype, $ctx->nested[$source], $opts);
                }
                if (isset($ctx->fields[$source])) {
                    throw new \RuntimeException("EVENT context source '{$source}' must be a map or list of maps");
                }
                throw new \RuntimeException("EVENT context source '{$source}' not found in context");
        }

        throw new \RuntimeException("Unknown function: {$name}");
    }

    private static function resolveEvent(string $type, string $subtype, Context $ctx, EvalOptions $opts): Value
    {
        if ($opts->eventResolver === null) {
            throw new \RuntimeException('EVENT() calls require an :event_resolver option');
        }
        $eventKey = $type . '.' . $subtype;
        if (\in_array($eventKey, $opts->visited, true)) {
            throw new \RuntimeException("Circular reference detected: {$eventKey}");
        }
        $newOpts = new EvalOptions();
        $newOpts->resolver = $opts->resolver;
        $newOpts->eventResolver = $opts->eventResolver;
        $newOpts->visited = \array_merge($opts->visited, [$eventKey]);
        return ($opts->eventResolver)($type, $subtype, $ctx, $newOpts);
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
