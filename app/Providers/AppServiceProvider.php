<?php

namespace App\Providers;

use App\Contracts\AdminListingRepositoryInterface;
use App\Contracts\AiSettingsRepositoryInterface;
use App\Contracts\AiToolStepRepositoryInterface;
use App\Contracts\CaptionSimilarityRepositoryInterface;
use App\Contracts\DocsAnswerCacheRepositoryInterface;
use App\Contracts\DocsBenchRepositoryInterface;
use App\Contracts\DocsIndexRepositoryInterface;
use App\Contracts\DocsSearchRepositoryInterface;
use App\Contracts\DuplicateEvalRepositoryInterface;
use App\Contracts\DuplicateGroupRepositoryInterface;
use App\Contracts\DuplicateJudgementRepositoryInterface;
use App\Contracts\DuplicateReviewRepositoryInterface;
use App\Contracts\EarlyHintSender;
use App\Contracts\EmbeddingIndexRepositoryInterface;
use App\Contracts\EmbeddingStatsRepositoryInterface;
use App\Contracts\ExportRepositoryInterface;
use App\Contracts\ListingDocumentRepositoryInterface;
use App\Contracts\ListingPhotoRepositoryInterface;
use App\Contracts\ListingRepositoryInterface;
use App\Contracts\McpToolCallRepositoryInterface;
use App\Contracts\PendingChangeRepositoryInterface;
use App\Contracts\PhotoEvalRepositoryInterface;
use App\Contracts\PhotoExtractionRepositoryInterface;
use App\Contracts\PhotoLabRepositoryInterface;
use App\Contracts\PhotoSearchEvalRepositoryInterface;
use App\Contracts\PhotoTextSearchRepositoryInterface;
use App\Contracts\RagEvalRepositoryInterface;
use App\Contracts\ResearchRunRepositoryInterface;
use App\Contracts\SemanticBenchRepositoryInterface;
use App\Contracts\SemanticSearchRepositoryInterface;
use App\Contracts\ShutdownScheduler;
use App\Contracts\SimilarEvalRepositoryInterface;
use App\Contracts\SimilarPhotosRepositoryInterface;
use App\Guides\GuideFacts;
use App\Guides\GuideRepository;
use App\Guides\SnippetResolver;
use App\Repositories\EloquentAdminListingRepository;
use App\Repositories\EloquentAiSettingsRepository;
use App\Repositories\EloquentAiToolStepRepository;
use App\Repositories\EloquentDocsAnswerCacheRepository;
use App\Repositories\EloquentDocsBenchRepository;
use App\Repositories\EloquentDocsIndexRepository;
use App\Repositories\EloquentDocsSearchRepository;
use App\Repositories\EloquentDuplicateEvalRepository;
use App\Repositories\EloquentDuplicateGroupRepository;
use App\Repositories\EloquentDuplicateJudgementRepository;
use App\Repositories\EloquentDuplicateReviewRepository;
use App\Repositories\EloquentEmbeddingIndexRepository;
use App\Repositories\EloquentEmbeddingStatsRepository;
use App\Repositories\EloquentExportRepository;
use App\Repositories\EloquentListingDocumentRepository;
use App\Repositories\EloquentListingPhotoRepository;
use App\Repositories\EloquentListingRepository;
use App\Repositories\EloquentMcpToolCallRepository;
use App\Repositories\EloquentPendingChangeRepository;
use App\Repositories\EloquentPhotoEvalRepository;
use App\Repositories\EloquentPhotoExtractionRepository;
use App\Repositories\EloquentPhotoLabRepository;
use App\Repositories\EloquentPhotoSearchEvalRepository;
use App\Repositories\EloquentPhotoTextSearchRepository;
use App\Repositories\EloquentRagEvalRepository;
use App\Repositories\EloquentResearchRunRepository;
use App\Repositories\EloquentSemanticBenchRepository;
use App\Repositories\EloquentSemanticSearchRepository;
use App\Repositories\EloquentSimilarEvalRepository;
use App\Repositories\EloquentSimilarPhotosRepository;
use App\Repositories\PhotoVectorRepository;
use App\Services\MarkdownChunker;
use App\Support\PhpEarlyHintSender;
use App\Support\PhpShutdownScheduler;
use App\Support\ServerTiming;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(ServerTiming::class);
        $this->app->bind(MarkdownChunker::class, fn (): MarkdownChunker => new MarkdownChunker(
            (int) config('ai.docs.chunk.max_tokens'),
            (int) config('ai.docs.chunk.merge_below_tokens'),
        ));
        $this->app->scoped(AiSettingsRepositoryInterface::class, EloquentAiSettingsRepository::class);
        $this->app->bind(ListingRepositoryInterface::class, EloquentListingRepository::class);
        $this->app->bind(AdminListingRepositoryInterface::class, EloquentAdminListingRepository::class);
        $this->app->bind(AiToolStepRepositoryInterface::class, EloquentAiToolStepRepository::class);
        $this->app->bind(PendingChangeRepositoryInterface::class, EloquentPendingChangeRepository::class);
        $this->app->bind(McpToolCallRepositoryInterface::class, EloquentMcpToolCallRepository::class);
        $this->app->bind(ResearchRunRepositoryInterface::class, EloquentResearchRunRepository::class);
        $this->app->bind(ListingDocumentRepositoryInterface::class, EloquentListingDocumentRepository::class);
        $this->app->bind(ListingPhotoRepositoryInterface::class, EloquentListingPhotoRepository::class);
        $this->app->bind(PhotoEvalRepositoryInterface::class, EloquentPhotoEvalRepository::class);
        $this->app->bind(PhotoExtractionRepositoryInterface::class, EloquentPhotoExtractionRepository::class);
        $this->app->bind(PhotoLabRepositoryInterface::class, EloquentPhotoLabRepository::class);
        $this->app->bind(SimilarPhotosRepositoryInterface::class, EloquentSimilarPhotosRepository::class);
        $this->app->bind(CaptionSimilarityRepositoryInterface::class, PhotoVectorRepository::class);
        $this->app->bind(DuplicateGroupRepositoryInterface::class, EloquentDuplicateGroupRepository::class);
        $this->app->bind(DuplicateEvalRepositoryInterface::class, EloquentDuplicateEvalRepository::class);
        $this->app->bind(DuplicateJudgementRepositoryInterface::class, EloquentDuplicateJudgementRepository::class);
        $this->app->bind(DuplicateReviewRepositoryInterface::class, EloquentDuplicateReviewRepository::class);
        $this->app->bind(PhotoTextSearchRepositoryInterface::class, EloquentPhotoTextSearchRepository::class);
        $this->app->bind(SimilarEvalRepositoryInterface::class, EloquentSimilarEvalRepository::class);
        $this->app->bind(PhotoSearchEvalRepositoryInterface::class, EloquentPhotoSearchEvalRepository::class);
        $this->app->bind(SemanticSearchRepositoryInterface::class, EloquentSemanticSearchRepository::class);
        $this->app->bind(EmbeddingIndexRepositoryInterface::class, EloquentEmbeddingIndexRepository::class);
        $this->app->bind(SemanticBenchRepositoryInterface::class, EloquentSemanticBenchRepository::class);
        $this->app->bind(DocsIndexRepositoryInterface::class, EloquentDocsIndexRepository::class);
        $this->app->bind(DocsSearchRepositoryInterface::class, EloquentDocsSearchRepository::class);
        $this->app->bind(DocsAnswerCacheRepositoryInterface::class, EloquentDocsAnswerCacheRepository::class);
        $this->app->bind(GuideRepository::class, fn (): GuideRepository => new GuideRepository(resource_path('guides')));
        $this->app->bind(GuideFacts::class, fn (): GuideFacts => new GuideFacts(base_path('bench/results')));
        $this->app->bind(SnippetResolver::class, fn (): SnippetResolver => new SnippetResolver(base_path()));
        $this->app->bind(DocsBenchRepositoryInterface::class, EloquentDocsBenchRepository::class);
        $this->app->bind(RagEvalRepositoryInterface::class, EloquentRagEvalRepository::class);
        $this->app->bind(EmbeddingStatsRepositoryInterface::class, EloquentEmbeddingStatsRepository::class);
        $this->app->bind(ExportRepositoryInterface::class, EloquentExportRepository::class);
        $this->app->bind(ShutdownScheduler::class, PhpShutdownScheduler::class);
        $this->app->bind(EarlyHintSender::class, PhpEarlyHintSender::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());

        // Each download holds bandwidth and, in stream mode, a PHP worker on purpose, so callers are limited per IP.
        RateLimiter::for('exports', fn (Request $request): Limit => Limit::perMinute((int) config('exports.per_minute'))->by((string) $request->ip()));
    }
}
