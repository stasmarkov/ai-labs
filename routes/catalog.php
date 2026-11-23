<?php

use App\Http\Controllers\AddPlaygroundVersionController;
use App\Http\Controllers\AskAdminAssistantController;
use App\Http\Controllers\AskAssistantController;
use App\Http\Controllers\AskDocsController;
use App\Http\Controllers\ConfirmAdminActionController;
use App\Http\Controllers\CreatePlaygroundPromptController;
use App\Http\Controllers\DeletePlaygroundPromptController;
use App\Http\Controllers\DescribeListingController;
use App\Http\Controllers\ListListingsController;
use App\Http\Controllers\ListPlaygroundPromptsController;
use App\Http\Controllers\ListPlaygroundRunsController;
use App\Http\Controllers\ModerateListingController;
use App\Http\Controllers\NlSearchController;
use App\Http\Controllers\PhotoTextSearchController;
use App\Http\Controllers\RejectAdminActionController;
use App\Http\Controllers\RunPlaygroundController;
use App\Http\Controllers\SearchDocsController;
use App\Http\Controllers\SemanticSearchController;
use App\Http\Controllers\ServeExportController;
use App\Http\Controllers\ShowListingController;
use App\Http\Controllers\ShowPlaygroundPromptController;
use App\Http\Controllers\ShowPlaygroundRunController;
use App\Http\Controllers\ShowResearchRunController;
use App\Http\Controllers\ShowSlowListingController;
use App\Http\Controllers\StartResearchRunController;
use App\Http\Middleware\AddServerTimingHeader;
use App\Http\Middleware\EnsureAdminAssistant;
use App\Http\Middleware\EnsureDebugRoutes;
use App\Http\Middleware\EnsureLocalLab;
use App\Http\Middleware\LimitCloudAi;
use App\Http\Middleware\RefuseCrossSiteRequests;
use App\Http\Middleware\SendEarlyHints;
use App\Services\ExportFileService;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Support\Facades\Route;

Route::get('/listings', ListListingsController::class)->name('listings.index');
Route::get('/listings/{id}', ShowListingController::class)
    ->where('id', '[1-9][0-9]{0,17}')
    ->middleware([SendEarlyHints::class, AddLinkHeadersForPreloadedAssets::class])
    ->name('listings.show');
Route::get('/listings/{id}/slow', ShowSlowListingController::class)
    ->where('id', '[1-9][0-9]{0,17}')
    ->middleware([EnsureDebugRoutes::class, 'throttle:slow'])
    ->name('listings.slow');
Route::get('/listings/{id}/describe', DescribeListingController::class)
    ->where('id', '[1-9][0-9]{0,17}')
    ->middleware(['throttle:describe', LimitCloudAi::class.':describe'])
    ->name('listings.describe');
Route::get('/api/listings/semantic', SemanticSearchController::class)
    ->middleware(['throttle:semantic-search', LimitCloudAi::class.':embeddings-listings', AddServerTimingHeader::class])
    ->name('listings.semantic');
Route::get('/api/listings/nl', NlSearchController::class)
    ->middleware(['throttle:nl-search', LimitCloudAi::class.':nl-search', AddServerTimingHeader::class])
    ->name('listings.nl');
Route::get('/api/photos/search', PhotoTextSearchController::class)
    ->middleware(['throttle:photo-search', LimitCloudAi::class.':embeddings-photos', AddServerTimingHeader::class])
    ->name('photos.search');
Route::get('/api/docs/search', SearchDocsController::class)
    ->middleware(['throttle:docs-search', LimitCloudAi::class.':embeddings-docs', AddServerTimingHeader::class])
    ->name('docs.search');
Route::post('/api/docs/ask', AskDocsController::class)
    ->middleware(['throttle:docs-ask', LimitCloudAi::class.':docs-answer,embeddings-docs', AddServerTimingHeader::class])
    ->name('docs.ask');
Route::middleware([EnsureAdminAssistant::class, RefuseCrossSiteRequests::class, 'throttle:admin-assistant'])->prefix('api/admin-assistant')->group(function (): void {
    Route::post('/ask', AskAdminAssistantController::class)->middleware(AddServerTimingHeader::class)->name('admin-assistant.ask');
    Route::post('/actions/{actionId}/confirm', ConfirmAdminActionController::class)->middleware(AddServerTimingHeader::class)->name('admin-assistant.confirm');
    Route::post('/actions/{actionId}/reject', RejectAdminActionController::class)->middleware(AddServerTimingHeader::class)->name('admin-assistant.reject');
});
Route::post('/api/assistant/ask', AskAssistantController::class)
    ->middleware(['throttle:assistant-ask', LimitCloudAi::class.':assistant', AddServerTimingHeader::class])
    ->name('assistant.ask');
Route::post('/api/moderation', ModerateListingController::class)
    ->middleware([RefuseCrossSiteRequests::class, 'throttle:moderation', LimitCloudAi::class.':moderation', AddServerTimingHeader::class])
    ->name('moderation.moderate');
Route::post('/api/research', StartResearchRunController::class)
    ->middleware([RefuseCrossSiteRequests::class, 'throttle:research', LimitCloudAi::class.':research'])
    ->name('research.start');
Route::get('/api/research/{id}', ShowResearchRunController::class)
    ->whereUuid('id')
    ->middleware('throttle:research-read')
    ->name('research.show');
// The stored prompts and runs are the developer's own data, and api/* answers CORS with a wildcard origin, so reads are
// refused cross-site like writes.
Route::prefix('api/playground')->middleware([EnsureLocalLab::class, RefuseCrossSiteRequests::class])->group(function (): void {
    Route::get('/prompts', ListPlaygroundPromptsController::class)->name('playground.prompts.index');
    Route::get('/prompts/{id}', ShowPlaygroundPromptController::class)->whereNumber('id')->name('playground.prompts.show');
    Route::post('/prompts', CreatePlaygroundPromptController::class)->name('playground.prompts.store');
    Route::post('/prompts/{id}/versions', AddPlaygroundVersionController::class)->whereNumber('id')->name('playground.versions.store');
    Route::delete('/prompts/{id}', DeletePlaygroundPromptController::class)->whereNumber('id')->name('playground.prompts.destroy');
    Route::post('/runs', RunPlaygroundController::class)
        ->middleware(['throttle:playground-run', LimitCloudAi::class.':playground'])
        ->name('playground.runs.store');
    Route::get('/runs', ListPlaygroundRunsController::class)->name('playground.runs.index');
    Route::get('/runs/{id}', ShowPlaygroundRunController::class)->whereUuid('id')->name('playground.runs.show');
});
Route::get('/exports/{id}', ServeExportController::class)
    ->where('id', ExportFileService::ID_PATTERN)
    ->middleware([EnsureDebugRoutes::class, 'throttle:exports'])
    ->name('exports.show');
