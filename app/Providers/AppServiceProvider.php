<?php

namespace App\Providers;

use App\Contracts\DocsAnswerCacheRepositoryInterface;
use App\Contracts\DocsBenchRepositoryInterface;
use App\Contracts\DocsIndexRepositoryInterface;
use App\Contracts\DocsSearchRepositoryInterface;
use App\Contracts\EmbeddingStatsRepositoryInterface;
use App\Contracts\ListingDocumentRepositoryInterface;
use App\Contracts\ListingRepositoryInterface;
use App\Contracts\RagEvalRepositoryInterface;
use App\Contracts\SemanticBenchRepositoryInterface;
use App\Contracts\SemanticSearchRepositoryInterface;
use App\Repositories\EloquentDocsAnswerCacheRepository;
use App\Repositories\EloquentDocsBenchRepository;
use App\Repositories\EloquentDocsIndexRepository;
use App\Repositories\EloquentDocsSearchRepository;
use App\Repositories\EloquentEmbeddingStatsRepository;
use App\Repositories\EloquentListingDocumentRepository;
use App\Repositories\EloquentListingRepository;
use App\Repositories\EloquentRagEvalRepository;
use App\Repositories\EloquentSemanticBenchRepository;
use App\Repositories\EloquentSemanticSearchRepository;
use App\Services\MarkdownChunker;
use App\Support\ServerTiming;
use Illuminate\Database\Eloquent\Model;
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
        $this->app->bind(ListingRepositoryInterface::class, EloquentListingRepository::class);
        $this->app->bind(ListingDocumentRepositoryInterface::class, EloquentListingDocumentRepository::class);
        $this->app->bind(SemanticSearchRepositoryInterface::class, EloquentSemanticSearchRepository::class);
        $this->app->bind(SemanticBenchRepositoryInterface::class, EloquentSemanticBenchRepository::class);
        $this->app->bind(DocsIndexRepositoryInterface::class, EloquentDocsIndexRepository::class);
        $this->app->bind(DocsSearchRepositoryInterface::class, EloquentDocsSearchRepository::class);
        $this->app->bind(DocsAnswerCacheRepositoryInterface::class, EloquentDocsAnswerCacheRepository::class);
        $this->app->bind(DocsBenchRepositoryInterface::class, EloquentDocsBenchRepository::class);
        $this->app->bind(RagEvalRepositoryInterface::class, EloquentRagEvalRepository::class);
        $this->app->bind(EmbeddingStatsRepositoryInterface::class, EloquentEmbeddingStatsRepository::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Model::preventLazyLoading(! $this->app->isProduction());
    }
}
