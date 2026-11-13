<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Ai\AdminTools\AdminTool;
use App\Ai\AdminTools\CountByTagTool as AdminCountByTagTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('count_by_tag')]
#[Description('Count the listings that carry a tag. Read only.')]
#[IsReadOnly]
final class CountByTagTool extends AdminToolWrapper
{
    protected function adminTool(): AdminTool
    {
        return app(AdminCountByTagTool::class);
    }
}
