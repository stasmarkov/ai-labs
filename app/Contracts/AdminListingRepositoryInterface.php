<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\AdminListingDetail;
use App\DTOs\AdminListingPage;
use App\DTOs\AdminListingRow;
use App\DTOs\AdminTagChange;
use App\DTOs\AdminTitleChange;
use App\DTOs\ListingFilter;
use Closure;
use Illuminate\Database\Eloquent\ModelNotFoundException;

interface AdminListingRepositoryInterface
{
    /**
     * Newest-first listings matching the filter and, when given, carrying the tag. `total` counts every match.
     */
    public function search(ListingFilter $filter, ?string $tag, int $limit): AdminListingPage;

    public function find(int $id): ?AdminListingDetail;

    /**
     * The listings with these ids in one query, keyed by id. An unknown id is simply absent.
     *
     * @param  list<int>  $ids
     * @return array<int, AdminListingRow>
     */
    public function findMany(array $ids): array;

    public function countByTag(string $tag): int;

    /**
     * @throws ModelNotFoundException
     */
    public function updateTitle(int $id, string $title): AdminTitleChange;

    /**
     * Adds the tag unless the listing has it already.
     *
     * @throws ModelNotFoundException
     */
    public function addTag(int $id, string $tag): AdminTagChange;

    /**
     * Runs the callback in a database transaction: its writes are all kept or all undone.
     *
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public function transaction(Closure $callback): mixed;
}
