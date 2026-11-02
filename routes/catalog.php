<?php

use App\Http\Controllers\DescribeListingController;
use App\Http\Controllers\ListListingsController;
use App\Http\Controllers\ShowListingController;
use Illuminate\Support\Facades\Route;

Route::get('/listings', ListListingsController::class)->name('listings.index');
Route::get('/listings/{id}', ShowListingController::class)
    ->where('id', '[1-9][0-9]{0,17}')
    ->name('listings.show');
Route::get('/listings/{id}/describe', DescribeListingController::class)
    ->where('id', '[1-9][0-9]{0,17}')
    ->name('listings.describe');
