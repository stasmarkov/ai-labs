<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\ListingDetailService;
use Illuminate\Contracts\View\View;

final class ShowListingController extends Controller
{
    public function __invoke(int $id, ListingDetailService $service): View
    {
        return view('listings.show', ['listing' => $service->find($id)]);
    }
}
