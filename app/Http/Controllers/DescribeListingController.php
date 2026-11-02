<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ListingDescriptionService;
use Laravel\Ai\Responses\StreamableAgentResponse;

final class DescribeListingController extends Controller
{
    public function __invoke(int $id, ListingDescriptionService $service): StreamableAgentResponse
    {
        return $service->describe($id);
    }
}
