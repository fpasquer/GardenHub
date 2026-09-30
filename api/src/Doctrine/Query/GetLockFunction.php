<?php

declare(strict_types=1);

namespace App\Doctrine\Query;

use Doctrine\ORM\Query\AST\ArithmeticExpression;
use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

/** GET_LOCK(name, timeout): 1 when acquired, 0 on timeout, NULL on error. */
final class GetLockFunction extends FunctionNode
{
    private mixed $lockName = null;
    private ?ArithmeticExpression $timeout = null;

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);
        $this->lockName = $parser->StringPrimary();
        $parser->match(TokenType::T_COMMA);
        $this->timeout = $parser->ArithmeticExpression();
        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }

    public function getSql(SqlWalker $sqlWalker): string
    {
        return sprintf(
            'GET_LOCK(%s, %s)',
            $sqlWalker->walkStringPrimary($this->lockName),
            $sqlWalker->walkArithmeticExpression($this->timeout),
        );
    }
}
