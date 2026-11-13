<?php

declare(strict_types=1);

namespace App\Ai\AdminTools;

use RuntimeException;

/**
 * A tool could not do its work although its arguments passed validate, for example a listing deleted in between.
 */
final class AdminToolFailed extends RuntimeException {}
