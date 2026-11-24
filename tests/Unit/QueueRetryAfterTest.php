<?php

declare(strict_types=1);

use App\Jobs\EmbedDocsBatchJob;
use App\Jobs\EmbedListingBatchJob;
use App\Jobs\EmbedPhotosBatchJob;
use App\Jobs\EmbedPhotoTextsBatchJob;
use App\Jobs\ExecuteResearchRunJob;
use App\Jobs\ExtractPhotoJob;
use App\Jobs\JudgeDuplicateGroupJob;
use Tests\TestCase;

uses(TestCase::class);

it('keeps the database queue retry_after above every job timeout, so a long job is never handed to a second worker', function (): void {
    $retryAfter = (int) config('queue.connections.database.retry_after');
    $timeouts = [
        ExtractPhotoJob::class => (new ReflectionClass(ExtractPhotoJob::class))->getDefaultProperties()['timeout'],
        JudgeDuplicateGroupJob::class => (new ReflectionClass(JudgeDuplicateGroupJob::class))->getDefaultProperties()['timeout'],
        EmbedPhotosBatchJob::class => (new ReflectionClass(EmbedPhotosBatchJob::class))->getDefaultProperties()['timeout'],
        EmbedPhotoTextsBatchJob::class => (new ReflectionClass(EmbedPhotoTextsBatchJob::class))->getDefaultProperties()['timeout'],
        EmbedListingBatchJob::class => (new ReflectionClass(EmbedListingBatchJob::class))->getDefaultProperties()['timeout'],
        EmbedDocsBatchJob::class => (new ReflectionClass(EmbedDocsBatchJob::class))->getDefaultProperties()['timeout'],
        ExecuteResearchRunJob::class => (int) config('ai.research.max_seconds') + 60,
    ];

    foreach ($timeouts as $job => $timeout) {
        expect($retryAfter)->toBeGreaterThan((int) $timeout, $job);
    }
});
