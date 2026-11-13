<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Ai\AdminTools\AdminTool;
use App\Ai\AdminTools\GetListingTool as AdminGetListingTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get_listing')]
#[Description('Read one listing by id: its fields, title, tags and description (cut at 500 characters). Read only.')]
#[IsReadOnly]
final class GetListingTool extends AdminToolWrapper
{
    protected function adminTool(): AdminTool
    {
        return app(AdminGetListingTool::class);
    }
}
