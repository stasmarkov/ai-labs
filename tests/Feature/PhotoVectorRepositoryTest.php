<?php

declare(strict_types=1);

use App\DTOs\VectorQuery;
use App\Repositories\PhotoVectorRepository;

test('AC-6 only the shared photo vector repository holds the photo nearest query', function (): void {
    foreach (['EloquentSimilarPhotosRepository', 'EloquentPhotoTextSearchRepository'] as $repository) {
        expect(file_get_contents(app_path("Repositories/{$repository}.php")))
            ->not->toContain('iterative_scan')
            ->not->toContain('listing_photos as p');
    }

    expect(file_get_contents(app_path('Repositories/PhotoVectorRepository.php')))->toContain('iterative_scan');
});

test('AC-6 the vector query carries either a stored photo or a literal vector', function (): void {
    expect(VectorQuery::fromVector([1.0, 0.0], 'm', 5, 40)->vector)->toBe([1.0, 0.0])
        ->and(VectorQuery::fromPhoto(7, 'm', 5, 40)->photoId)->toBe(7)
        ->and(app(PhotoVectorRepository::class))->toBeInstanceOf(PhotoVectorRepository::class);
});
