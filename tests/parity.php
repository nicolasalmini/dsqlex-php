<?php

declare(strict_types=1);

require __DIR__ . '/../autoload.php';

use Dsqlex\AstNode;
use Dsqlex\BinOp;
use Dsqlex\Context;
use Dsqlex\Dsqlex;
use Dsqlex\EvalOptions;
use Dsqlex\Lexer;
use Dsqlex\NodeKind;
use Dsqlex\TokenType;
use Dsqlex\Value;
use Dsqlex\ValueType;

$failures = 0;

function ok(bool $cond, string $name): void
{
    global $failures;
    if (!$cond) {
        $failures++;
        fwrite(STDERR, "FAIL: {$name}\n");
    }
}

function expectThrow(callable $fn, string $name): void
{
    try {
        $fn();
    } catch (\Throwable $e) {
        ok(true, $name);
        return;
    }
    ok(false, $name . ' (no exception thrown)');
}

function decEq(Value $v, string $expected, string $name): void
{
    ok($v->type === ValueType::DECIMAL && \bccomp($v->decVal, $expected, 20) === 0, $name);
}

function strEq(Value $v, string $expected, string $name): void
{
    ok($v->type === ValueType::STRING && $v->strVal === $expected, $name);
}

function isNull(Value $v, string $name): void
{
    ok($v->type === ValueType::NULL_, $name);
}

function isBool(Value $v, bool $expected, string $name): void
{
    ok($v->type === ValueType::BOOL && $v->boolVal === $expected, $name);
}

$ctx = new Context();
$ctx->setDecimal('x', '100.00');
$ctx->setDecimal('y', '20.00');
$ctx->setDecimal('price', '500.00');
$ctx->setDecimal('quantity', '100.00');
$ctx->setDecimal('rate', '5.00');
$ctx->setNull('n');
$ctx->setNull('bonus');

$t = Lexer::tokenize('LEAST GREATEST least Greatest');
ok($t[0]->type === TokenType::FN_LEAST && $t[1]->type === TokenType::FN_GREATEST
    && $t[2]->type === TokenType::FN_LEAST && $t[3]->type === TokenType::FN_GREATEST,
    'lexer: LEAST/GREATEST tokens');

$t = Lexer::tokenize('active?');
ok($t[0]->type === TokenType::IDENTIFIER && $t[0]->text === 'active?', 'lexer: trailing ? identifier');

$t = Lexer::tokenize('user.active?');
ok($t[0]->type === TokenType::IDENTIFIER && $t[0]->text === 'user.active?', 'lexer: dotted ? identifier');

$t = Lexer::tokenize('select?');
ok($t[0]->type === TokenType::IDENTIFIER && $t[0]->text === 'select?', 'lexer: select? is identifier');

expectThrow(fn() => Lexer::tokenize('a??'), 'lexer: a?? rejected');

$t = Lexer::tokenize('a - b');
ok($t[1]->type === TokenType::MINUS, 'lexer: minus token unchanged');

$ast = Dsqlex::parse('SELECT -1');
ok($ast->expr->kind === NodeKind::UNARY_OP && $ast->expr->expr->kind === NodeKind::NUMBER_LIT,
    'parser: -1 unary node');

$ast = Dsqlex::parse('SELECT amount * -1');
ok($ast->expr->kind === NodeKind::BINARY_OP && $ast->expr->op === BinOp::MULTIPLY
    && $ast->expr->right->kind === NodeKind::UNARY_OP,
    'parser: amount * -1');

$ast = Dsqlex::parse('SELECT -(1 + 2)');
ok($ast->expr->kind === NodeKind::UNARY_OP
    && $ast->expr->expr->kind === NodeKind::BINARY_OP && $ast->expr->expr->op === BinOp::PLUS,
    'parser: -(1 + 2)');

$ast = Dsqlex::parse('SELECT 5 - - 2');
ok($ast->expr->kind === NodeKind::BINARY_OP && $ast->expr->op === BinOp::MINUS
    && $ast->expr->right->kind === NodeKind::UNARY_OP,
    'parser: 5 - - 2');

$ast = Dsqlex::parse('SELECT - -5');
ok($ast->expr->kind === NodeKind::UNARY_OP && $ast->expr->expr->kind === NodeKind::UNARY_OP,
    'parser: nested unary');

expectThrow(fn() => Dsqlex::parse('SELECT --5'), 'parser: --5 is comment, not unary');

$ast = Dsqlex::parse('x IN (1, -2)');
ok($ast->expr->kind === NodeKind::IN_EXPR && \count($ast->expr->args) === 2
    && $ast->expr->args[1]->kind === NodeKind::UNARY_OP,
    'parser: negative IN item');

$ast = Dsqlex::parse('x IN ()');
ok($ast->expr->kind === NodeKind::IN_EXPR && \count($ast->expr->args) === 0,
    'parser: empty IN list');

expectThrow(fn() => Dsqlex::parse('SELECT 1 + 2 * -3'), 'parser: mixed groups with negated operand rejected');

$ast = Dsqlex::parse('LEAST(a, 1, 2)');
ok($ast->expr->kind === NodeKind::FUNCTION_CALL && $ast->expr->strVal === 'LEAST'
    && \count($ast->expr->args) === 3, 'parser: LEAST call');

decEq(Dsqlex::evalString('SELECT -5', $ctx), '-5', 'eval: -5');
decEq(Dsqlex::evalString('SELECT -x', $ctx), '-100.00', 'eval: -x');
decEq(Dsqlex::evalString('SELECT -(1 + 2)', $ctx), '-3', 'eval: -(1+2)');
decEq(Dsqlex::evalString('SELECT - -5', $ctx), '5', 'eval: - -5');
isNull(Dsqlex::evalString('SELECT -n', $ctx), 'eval: -null');
isNull(Dsqlex::evalString('SELECT -NULL', $ctx), 'eval: -NULL');
expectThrow(fn() => Dsqlex::evalString("SELECT -'abc'", $ctx), 'eval: -nonnumeric errors');

foreach (['n + 1', '1 + n', 'n - 1', 'n * 2', 'n / 2', 'x * n'] as $e) {
    isNull(Dsqlex::evalString($e, $ctx), "eval: {$e} is null");
}
isNull(Dsqlex::evalString('ROUND(n, 2)', $ctx), 'eval: ROUND(null,2)');
isNull(Dsqlex::evalString('ROUND(x, n)', $ctx), 'eval: ROUND(x,null)');
isNull(Dsqlex::evalString('ABS(n)', $ctx), 'eval: ABS(null)');

decEq(Dsqlex::evalString('LEAST(3, 1, 2)', $ctx), '1', 'eval: LEAST numbers');
decEq(Dsqlex::evalString('GREATEST(3, 1, 2)', $ctx), '3', 'eval: GREATEST numbers');
decEq(Dsqlex::evalString('LEAST(x, y)', $ctx), '20.00', 'eval: LEAST fields');
decEq(Dsqlex::evalString('LEAST(7)', $ctx), '7', 'eval: LEAST single arg');
isNull(Dsqlex::evalString('LEAST(x, n)', $ctx), 'eval: LEAST null propagation');
isNull(Dsqlex::evalString('GREATEST(1, n)', $ctx), 'eval: GREATEST null propagation');
strEq(Dsqlex::evalString("LEAST('banana', 'apple', 'cherry')", $ctx), 'apple', 'eval: LEAST strings');
strEq(Dsqlex::evalString("GREATEST('banana', 'apple', 'cherry')", $ctx), 'cherry', 'eval: GREATEST strings');
decEq(Dsqlex::evalString('LEAST(1, 1.0)', $ctx), '1', 'eval: LEAST first on tie');
expectThrow(fn() => Dsqlex::evalString('LEAST()', $ctx), 'eval: LEAST() zero args error');
expectThrow(fn() => Dsqlex::evalString('GREATEST()', $ctx), 'eval: GREATEST() zero args error');

decEq(Dsqlex::evalString('SELECT -2.5', $ctx), '-2.5', 'integration: -2.5');
decEq(Dsqlex::evalString('SELECT -price', $ctx), '-500.00', 'integration: -price');
decEq(Dsqlex::evalString('SELECT price * -1', $ctx), '-500.00', 'integration: price * -1');
decEq(Dsqlex::evalString('SELECT 5 - - 2', $ctx), '7', 'integration: 5 - - 2');

$ctx2 = new Context();
$ctx2->setDecimal('balance', '-42');
isBool(Dsqlex::evalString('balance IN (-42, 0)', $ctx2), true, 'integration: negative IN match');
isBool(Dsqlex::evalString('balance IN (-41, 0)', $ctx2), false, 'integration: negative IN no match');

isNull(Dsqlex::evalString('SELECT -bonus', $ctx), 'integration: -bonus');
decEq(Dsqlex::evalString('SELECT LEAST(price, quantity, rate)', $ctx), '5.00', 'integration: LEAST');
decEq(Dsqlex::evalString('SELECT GREATEST(price, quantity, rate)', $ctx), '500.00', 'integration: GREATEST');
isNull(Dsqlex::evalString('SELECT LEAST(price, bonus)', $ctx), 'integration: LEAST null');
isNull(Dsqlex::evalString('SELECT bonus + 1', $ctx), 'integration: bonus + 1');

$ctx3 = new Context();
$ctx3->setBool('eligible?', true);
isBool(Dsqlex::evalString('SELECT eligible?', $ctx3), true, 'integration: ? identifier resolves');

decEq(Dsqlex::evalString('SELECT -0', $ctx), '0', 'eval: -0 valid decimal');
strEq(Dsqlex::evalString("LEAST('2', '10')", $ctx), '10', 'eval: LEAST numeric strings lexicographic');
strEq(Dsqlex::evalString("GREATEST('2', '10')", $ctx), '2', 'eval: GREATEST numeric strings lexicographic');
$ctx4 = new Context();
$ctx4->setString('s', 'c');
isBool(Dsqlex::evalString("s IN ('a', 'b')", $ctx4), false, 'eval: IN string compare');
$ctx4->setString('s', 'b');
isBool(Dsqlex::evalString("s IN ('a', 'b')", $ctx4), true, 'eval: IN string equality');

expectThrow(fn() => Dsqlex::evalString('missing', $ctx), 'resolve: unknown simple field throws');
expectThrow(fn() => Dsqlex::evalString('missing.field', $ctx), 'resolve: unknown dotted field throws');

$opts = new EvalOptions();
$opts->resolver = fn(string $name, array $visited) => $name === 'external'
    ? Value::decimal('1.5')
    : throw new \RuntimeException("Unknown field: {$name}");
decEq(Dsqlex::evalString('external', $ctx, $opts), '1.5', 'resolver: fallback resolves');
expectThrow(fn() => Dsqlex::evalString('nope', $ctx, $opts), 'resolver: error surfaces');

$optsPrecedence = new EvalOptions();
$optsPrecedence->resolver = fn(string $name, array $visited) => Value::decimal('999');
decEq(Dsqlex::evalString('x', $ctx, $optsPrecedence), '100.00', 'resolver: flat field wins over resolver');

$optsCycle = new EvalOptions();
$optsCycle->resolver = function (string $name, array $visited) use (&$optsCycle, $ctx): Value {
    $inner = new EvalOptions();
    $inner->resolver = $optsCycle->resolver;
    $inner->visited = \array_merge($visited, [$name]);
    return Dsqlex::evalString($name, $ctx, $inner);
};
expectThrow(fn() => Dsqlex::evalString('loop', $ctx, $optsCycle), 'resolver: circular reference detected');

$ev = new EvalOptions();
$ev->eventResolver = fn(string $type, string $subtype, Context $c, EvalOptions $o): Value =>
    Dsqlex::evalString('amt * 2', $c, $o);

$evCtx = new Context();
$evCtx->setDecimal('amt', '10');
decEq(Dsqlex::evalString('EVENT(a, b)', $evCtx, $ev), '20', 'EVENT: 2-arg current context');

$evCtx2 = new Context();
$sub = new Context();
$sub->setDecimal('amt', '5');
$evCtx2->setNested('sub', $sub);
decEq(Dsqlex::evalString('EVENT(a, b, sub)', $evCtx2, $ev), '10', 'EVENT: 3-arg nested context');

$evCtx3 = new Context();
$i1 = new Context();
$i1->setDecimal('amt', '3');
$i2 = new Context();
$i2->setDecimal('amt', '4');
$evCtx3->setList('items', [$i1, $i2]);
decEq(Dsqlex::evalString('EVENT(a, b, items)', $evCtx3, $ev), '14', 'EVENT: 3-arg list sums results');

$evCtx4 = new Context();
$evCtx4->setList('items', []);
decEq(Dsqlex::evalString('EVENT(a, b, items)', $evCtx4, $ev), '0', 'EVENT: empty list yields zero');

expectThrow(fn() => Dsqlex::evalString('EVENT(a, b, missing)', $evCtx, $ev), 'EVENT: missing source errors');
expectThrow(fn() => Dsqlex::evalString('EVENT(a, b, amt)', $evCtx, $ev), 'EVENT: non-map source errors');
expectThrow(fn() => Dsqlex::evalString("EVENT('a', 'b')", $evCtx, $ev), 'EVENT: non-identifier args error');
expectThrow(fn() => Dsqlex::evalString('EVENT(a)', $evCtx, $ev), 'EVENT: wrong arity errors');
expectThrow(fn() => Dsqlex::evalString('EVENT(a, b)', $evCtx), 'EVENT: missing eventResolver errors');

$evCycle = new EvalOptions();
$evCycle->eventResolver = function (string $type, string $subtype, Context $c, EvalOptions $o): Value {
    return Dsqlex::evalString('EVENT(a, b)', $c, $o);
};
expectThrow(fn() => Dsqlex::evalString('EVENT(a, b)', $evCtx, $evCycle), 'EVENT: recursion cycle detected');

$sumCtx = new Context();
$d1 = new Context();
$d1->setDecimal('amt', '3');
$d2 = new Context();
$d2->setDecimal('amt', '4');
$sumCtx->setList('items', [$d1, $d2]);
decEq(Dsqlex::evalString('items.amt', $sumCtx), '7', 'dot-path: list of contexts sums numerics');

$sumCtx2 = new Context();
$s1 = new Context();
$s1->setString('name', 'x');
$s2 = new Context();
$s2->setString('name', 'y');
$sumCtx2->setList('items', [$s1, $s2]);
$lv = Dsqlex::evalString('items.name', $sumCtx2);
ok($lv->type === ValueType::LIST && \count($lv->listVal) === 2
    && $lv->listVal[0]->strVal === 'x' && $lv->listVal[1]->strVal === 'y',
    'dot-path: nonnumeric list returns LIST value');

$mapCtx = new Context();
$inner = new Context();
$inner->setDecimal('base', '7');
$mapCtx->setNested('order', $inner);
$mv = Dsqlex::evalString('order', $mapCtx);
ok($mv->type === ValueType::MAP && $mv->mapVal instanceof Context
    && $mv->mapVal->fields['base']->decVal === '7', 'nested map returns MAP value');
$outer = new Context();
$outer->setNested('order', $inner);
$wrap = new Context();
$wrap->setNested('o', $outer);
$mv2 = Dsqlex::evalString('o.order', $wrap);
ok($mv2->type === ValueType::MAP && $mv2->mapVal->fields['base']->decVal === '7',
    'dot-path terminal map returns MAP value');

$dtCtx = new Context();
$dtCtx->setDate('d1', new \DateTimeImmutable('2024-01-01'));
$dtCtx->setDate('d2', new \DateTimeImmutable('2024-06-15'));
ok(Dsqlex::evalString('LEAST(d1, d2)', $dtCtx)->timeVal->format('Y-m-d') === '2024-01-01',
    'LEAST dates chronological');
ok(Dsqlex::evalString('GREATEST(d1, d2)', $dtCtx)->timeVal->format('Y-m-d') === '2024-06-15',
    'GREATEST dates chronological');
$dtCtx2 = new Context();
$dtCtx2->setDateTime('t1', new \DateTimeImmutable('2024-01-01T00:00:00Z'));
$dtCtx2->setDateTime('t2', new \DateTimeImmutable('2024-01-01T12:30:00Z'));
ok(Dsqlex::evalString('GREATEST(t1, t2)', $dtCtx2)->timeVal->format('H:i') === '12:30',
    'GREATEST datetimes chronological');
$dtCtx3 = new Context();
$dtCtx3->setNaiveDateTime('n1', new \DateTimeImmutable('2024-01-01 00:00:00'));
$dtCtx3->setNaiveDateTime('n2', new \DateTimeImmutable('2023-12-31 23:59:59'));
ok(Dsqlex::evalString('LEAST(n1, n2)', $dtCtx3)->timeVal->format('Y-m-d H:i:s') === '2023-12-31 23:59:59',
    'LEAST naive datetimes chronological');
$dtCtx4 = new Context();
$dtCtx4->setTime('h1', new \DateTimeImmutable('09:00:00'));
$dtCtx4->setTime('h2', new \DateTimeImmutable('18:30:00'));
ok(Dsqlex::evalString('GREATEST(h1, h2)', $dtCtx4)->timeVal->format('H:i:s') === '18:30:00',
    'GREATEST times chronological');

$dtCtx5 = new Context();
$dtCtx5->setDate('e1', new \DateTimeImmutable('2024-03-05 09:00:00'));
$dtCtx5->setDate('e2', new \DateTimeImmutable('2024-03-05 23:59:59'));
ok(Dsqlex::evalString('LEAST(e1, e2)', $dtCtx5)->timeVal->format('H:i:s') === '09:00:00',
    'DATE: same day different time is equal (first on tie)');

$dtCtx6 = new Context();
$dtCtx6->setTime('f1', new \DateTimeImmutable('2020-01-01 10:00:00.500'));
$dtCtx6->setTime('f2', new \DateTimeImmutable('1999-12-31 10:00:00.250'));
$dtCtx6->setTime('f3', new \DateTimeImmutable('2030-06-15 10:00:00.750'));
ok(Dsqlex::evalString('LEAST(f1, f2, f3)', $dtCtx6)->timeVal->format('u') === '250000',
    'TIME: compares H:i:s.u ignoring anchor date');
ok(Dsqlex::evalString('GREATEST(f1, f2, f3)', $dtCtx6)->timeVal->format('u') === '750000',
    'TIME: fractional seconds ordering');

$dtCtx7 = new Context();
$dtCtx7->setDateTime('g1', new \DateTimeImmutable('2024-01-01T12:00:00+02:00'));
$dtCtx7->setDateTime('g2', new \DateTimeImmutable('2024-01-01T10:00:00+00:00'));
ok(Dsqlex::evalString('LEAST(g1, g2)', $dtCtx7)->timeVal->getOffset() === 7200,
    'DATETIME: equal instants across offsets compare equal (first on tie)');
$dtCtx8 = new Context();
$dtCtx8->setDateTime('g3', new \DateTimeImmutable('2024-01-01T12:00:00+02:00'));
$dtCtx8->setDateTime('g4', new \DateTimeImmutable('2024-01-01T10:00:01+00:00'));
ok(Dsqlex::evalString('GREATEST(g3, g4)', $dtCtx8)->timeVal->format('s') === '01',
    'DATETIME: chronological ordering across offsets');

$mut = new \DateTime('2024-05-01 10:00:00');
$mutCtx = new Context();
$mutCtx->setDate('m', $mut);
$mut->modify('+10 days');
ok(Dsqlex::evalString('m', $mutCtx)->timeVal->format('Y-m-d') === '2024-05-01',
    'temporal value immune to caller mutation');

expectThrow(fn() => Dsqlex::evalString('ROUND(NULL, missing_prec)', new Context()), 'ROUND: missing precision errors');

$tie = Dsqlex::evalString('LEAST(1, 1.0)', new Context());
ok($tie->type === ValueType::DECIMAL && $tie->decVal === '1', 'LEAST preserves first original on tie');

if ($failures === 0) {
    echo "All parity tests passed\n";
    exit(0);
}
echo "{$failures} test(s) failed\n";
exit(1);
