<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Ai\AdminTools\AdminTool;
use App\Ai\AdminTools\SearchListingsTool as AdminSearchListingsTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('search_listings')]
#[Description('Search the used-car listings. Every argument is optional. Returns the total number of matches and up to 10 rows, newest first, each with id, make, model, year, price, city, title and tags. Read only.')]
#[IsReadOnly]
final class SearchListingsTool extends AdminToolWrapper
{
    protected function adminTool(): AdminTool
    {
        return app(AdminSearchListingsTool::class);
    }
}
