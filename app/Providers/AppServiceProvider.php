<?php

namespace App\Providers;

use App\Contracts\DocsBenchRepositoryInterface;
use App\Contracts\DocsIndexRepositoryInterface;
use App\Contracts\DocsSearchRepositoryInterface;
use App\Contracts\EmbeddingStatsRepositoryInterface;
use App\Contracts\ListingDocumentRepositoryInterface;
use App\Contracts\ListingRepositoryInterface;
use App\Contracts\SemanticBenchRepositoryInterface;
use App\Contracts\SemanticSearchRepositoryInterface;
use App\Repositories\EloquentDocsBenchRepository;
use App\Repositories\EloquentDocsIndexRepository;
use App\Repositories\EloquentDocsSearchRepository;
use App\Repositories\EloquentEmbeddingStatsRepository;
use App\Repositories\EloquentListingDocumentRepository;
use App\Repositories\EloquentListingRepository;
use App\Repositories\EloquentSemanticBenchRepository;
use App\Repositories\EloquentSemanticSearchRepository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ListingRepositoryInterface::class, EloquentListingRepository::class);
        $this->app->bind(ListingDocumentRepositoryInterface::class, EloquentListingDocumentRepository::class);
        $this->app->bind(SemanticSearchRepositoryInterface::class, EloquentSemanticSearchRepository::class);
        $this->app->bind(SemanticBenchRepositoryInterface::class, EloquentSemanticBenchRepository::class);
        $this->app->bind(DocsIndexRepositoryInterface::class, EloquentDocsIndexRepository::class);
        $this->app->bind(DocsSearchRepositoryInterface::class, EloquentDocsSearchRepository::class);
        $this->app->bind(DocsBenchRepositoryInterface::class, EloquentDocsBenchRepository::class);
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
