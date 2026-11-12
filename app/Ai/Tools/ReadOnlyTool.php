<?php

declare(strict_types=1);

namespace App\Ai\Tools;

use Laravel\Ai\Contracts\Tool;

/**
 * Marks a tool that only reads data. The buyer assistant accepts nothing else, so a tool that writes cannot be added by
 * accident.
 */
interface ReadOnlyTool extends Tool {}
