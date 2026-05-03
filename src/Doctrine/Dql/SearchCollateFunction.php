<?php

declare(strict_types=1);

namespace App\Doctrine\Dql;

use Doctrine\ORM\Query\AST\Functions\FunctionNode;
use Doctrine\ORM\Query\AST\Node;
use Doctrine\ORM\Query\Parser;
use Doctrine\ORM\Query\SqlWalker;
use Doctrine\ORM\Query\TokenType;

final class SearchCollateFunction extends FunctionNode
{
    public Node $stringPrimary;

    public function getSql(SqlWalker $sqlWalker): string
    {
        return sprintf(
            '%s COLLATE "unicode_search_ci_ai"',
            $sqlWalker->walkSimpleArithmeticExpression($this->stringPrimary),
        );
    }

    public function parse(Parser $parser): void
    {
        $parser->match(TokenType::T_IDENTIFIER);
        $parser->match(TokenType::T_OPEN_PARENTHESIS);

        $this->stringPrimary = $parser->StringPrimary();

        $parser->match(TokenType::T_CLOSE_PARENTHESIS);
    }
}
