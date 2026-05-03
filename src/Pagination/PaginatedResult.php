<?php

namespace App\Pagination;

/**
 * @template T
 */
final class PaginatedResult
{
    /**
     * @param list<T> $items
     */
    public function __construct(
        private readonly array $items,
        private readonly int $totalItems,
        private readonly int $currentPage,
        private readonly int $perPage,
    ) {
    }

    /**
     * @return list<T>
     */
    public function getItems(): array
    {
        return $this->items;
    }

    public function getTotalItems(): int
    {
        return $this->totalItems;
    }

    public function getCurrentPage(): int
    {
        return $this->currentPage;
    }

    public function getPerPage(): int
    {
        return $this->perPage;
    }

    public function getTotalPages(): int
    {
        return max(1, (int) ceil($this->totalItems / $this->perPage));
    }

    public function hasPreviousPage(): bool
    {
        return $this->currentPage > 1;
    }

    public function hasNextPage(): bool
    {
        return $this->currentPage < $this->getTotalPages();
    }

    public function getPreviousPage(): int
    {
        return max(1, $this->currentPage - 1);
    }

    public function getNextPage(): int
    {
        return min($this->getTotalPages(), $this->currentPage + 1);
    }

    public function getFirstItemNumber(): int
    {
        if ($this->totalItems === 0) {
            return 0;
        }

        return (($this->currentPage - 1) * $this->perPage) + 1;
    }

    public function getLastItemNumber(): int
    {
        if ($this->totalItems === 0) {
            return 0;
        }

        return min($this->totalItems, $this->currentPage * $this->perPage);
    }
}
