<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Ai\AdminTools\AddTagTool;
use App\Ai\AdminTools\AdminWriteTool;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;

#[Name('propose_tag')]
#[Description('Propose adding a tag to a listing. Nothing changes: the change is stored as pending and you get an id, a preview and a link. Give the link to the developer: the confirmation needs the developer in the browser, where they confirm or reject it.')]
final class ProposeTagTool extends ProposeChangeTool
{
    protected function adminTool(): AdminWriteTool
    {
        return app(AddTagTool::class);
    }
}
