<?php

namespace App\Pagination;

use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator as DoctrinePaginator;

class QueryPaginator
{
    private const DEFAULT_PER_PAGE = 50;
    private const MAX_PER_PAGE = 100;

    /**
     * @template T
     *
     * @return PaginatedResult<T>
     */
    public function paginate(QueryBuilder $queryBuilder, int $page, int $perPage = self::DEFAULT_PER_PAGE): PaginatedResult
    {
        $page = max(1, $page);
        $perPage = max(1, min(self::MAX_PER_PAGE, $perPage));
        $pagedQueryBuilder = clone $queryBuilder;

        $paginator = new DoctrinePaginator(
            $pagedQueryBuilder
                ->setFirstResult(($page - 1) * $perPage)
                ->setMaxResults($perPage)
                ->getQuery()
        );
        $totalItems = count($paginator);
        $totalPages = max(1, (int) ceil($totalItems / $perPage));

        if ($page > $totalPages) {
            $page = $totalPages;
            $pagedQueryBuilder = clone $queryBuilder;
            $paginator = new DoctrinePaginator(
                $pagedQueryBuilder
                    ->setFirstResult(($page - 1) * $perPage)
                    ->setMaxResults($perPage)
                    ->getQuery()
            );
        }

        return new PaginatedResult(
            items: iterator_to_array($paginator->getIterator(), false),
            totalItems: $totalItems,
            currentPage: $page,
            perPage: $perPage,
        );
    }
}
