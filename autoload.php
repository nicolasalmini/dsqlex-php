<?php

declare(strict_types=1);

spl_autoload_register(function (string $class): void {
    $prefix = 'Dsqlex\\';
    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }
    $relativeClass = substr($class, strlen($prefix));
    $map = [
        'Token'       => 'Token.php',
        'TokenType'   => 'Token.php',
        'Lexer'       => 'Lexer.php',
        'AstNode'     => 'Ast.php',
        'NodeKind'    => 'Ast.php',
        'BinOp'       => 'Ast.php',
        'WhenClause'  => 'Ast.php',
        'Parser'      => 'Parser.php',
        'Evaluator'   => 'Evaluator.php',
        'Value'       => 'Evaluator.php',
        'ValueType'   => 'Evaluator.php',
        'Context'     => 'Evaluator.php',
        'EvalOptions' => 'Evaluator.php',
        'Dsqlex'      => 'Dsqlex.php',
    ];
    $file = __DIR__ . '/src/' . ($map[$relativeClass] ?? str_replace('\\', '/', $relativeClass) . '.php');
    if (file_exists($file)) {
        require $file;
    }
});
