<?php

declare(strict_types=1);

namespace App\Ai\AdminTools;

/**
 * A tool that changes data. It can describe the exact change before anything is written.
 */
interface AdminWriteTool extends AdminTool
{
    /**
     * What the confirmation card shows. Reads only.
     *
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    public function preview(array $arguments): array;
}
