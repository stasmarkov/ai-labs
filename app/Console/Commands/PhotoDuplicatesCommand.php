<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Exceptions\ProviderNotConfiguredException;
use App\Jobs\JudgeDuplicateGroupJob;
use App\Services\DuplicateClusterService;
use App\Services\DuplicateJudgementService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('photos:duplicates
    {--judge : Queue one best-photo judgement per group on the vision model}
    {--wait : Wait for the judgements to finish, then print the summary}')]
#[Description('Group near-duplicate photos: image similarity at or above the cutoff, joined into connected components of at most 6')]
final class PhotoDuplicatesCommand extends Command
{
    private const WAIT_LIMIT_SECONDS = 3600;

    private const STALL_SECONDS = 60;

    private const POLL_SECONDS = 2;

    public function handle(DuplicateClusterService $service, DuplicateJudgementService $judgements): int
    {
        if ($this->option('wait') && ! $this->option('judge')) {
            $this->error('--wait needs --judge.');

            return self::INVALID;
        }

        $report = $service->rebuild();

        if ($report->photos === 0) {
            $this->error("No photo is embedded with {$report->label}. Run photos:embed first.");

            return self::FAILURE;
        }

        $this->line("Label: {$report->label}");
        $this->line("Cutoff: {$report->cutoff}");
        $this->line("Photos read: {$report->photos}");
        $this->info("Groups: {$report->groups} ({$report->photosGrouped} photos, {$report->splitGroups} split to fit the cap)");
        $this->line("Older groups replaced: {$report->replacedGroups}");
        $this->line("Clustered in {$report->elapsedMs} ms");

        if (! $this->option('judge')) {
            return self::SUCCESS;
        }

        return $this->judge($judgements, $report->label);
    }

    private function judge(DuplicateJudgementService $service, string $label): int
    {
        try {
            $request = $service->request();
        } catch (ProviderNotConfiguredException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $judgementIds = [];

        foreach ($service->groupIds($label, $request) as $groupId) {
            $judgementId = $service->queue($groupId, $request);

            if ($judgementId !== null) {
                $judgementIds[] = $judgementId;
            }
        }

        $this->info('Queued '.count($judgementIds).' groups on '.JudgeDuplicateGroupJob::QUEUE." for {$request->model} ({$request->promptVersion}).");

        if (! $this->option('wait') || $judgementIds === []) {
            return self::SUCCESS;
        }

        $deadline = time() + self::WAIT_LIMIT_SECONDS;
        $lastProgressAt = time();
        $last = -1;
        $warned = false;

        while (($counts = $service->statusCounts($judgementIds))->queued + $counts->running > 0) {
            if (time() >= $deadline) {
                $this->error('Timed out waiting for the judgement jobs.');

                return self::FAILURE;
            }

            $settled = $counts->completed + $counts->failed;

            if ($settled > $last) {
                $last = $settled;
                $lastProgressAt = time();
            } elseif (! $warned && time() - $lastProgressAt >= self::STALL_SECONDS) {
                $warned = true;
                $this->warn('No progress; start a worker: ddev artisan queue:work --queue='.JudgeDuplicateGroupJob::QUEUE);
            }

            sleep(self::POLL_SECONDS);
        }

        $this->info("Judged: {$counts->completed} completed, {$counts->failed} failed.");

        return $counts->failed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
