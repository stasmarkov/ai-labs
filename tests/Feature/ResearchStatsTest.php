<?php

declare(strict_types=1);

use App\Enums\ResearchRunStatus;
use App\Models\ResearchRun;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir().'/research-stats-'.bin2hex(random_bytes(4));
});

afterEach(function (): void {
    File::deleteDirectory($this->directory);
});

it('AC-11 writes the run counts and the averages of the executed runs for the guide', function (): void {
    ResearchRun::factory()->create(['status' => ResearchRunStatus::Done, 'model_calls' => 4, 'steps' => 6, 'started_at' => now()->subSeconds(40), 'finished_at' => now()]);
    ResearchRun::factory()->create(['status' => ResearchRunStatus::Done, 'model_calls' => 8, 'steps' => 12, 'started_at' => now()->subSeconds(80), 'finished_at' => now()]);
    ResearchRun::factory()->create(['status' => ResearchRunStatus::Failed, 'model_calls' => 3, 'steps' => 3, 'started_at' => now()->subSeconds(30), 'finished_at' => now()]);
    ResearchRun::factory()->create(['status' => ResearchRunStatus::Refused, 'model_calls' => 0, 'started_at' => now(), 'finished_at' => now()]);
    ResearchRun::factory()->create();

    $this->artisan('research:stats', ['--output-dir' => $this->directory])->assertSuccessful();

    $data = json_decode((string) file_get_contents($this->directory.'/stats.json'), true);

    expect($data['summary'])->toEqual([
        'runs' => 5,
        'done' => 2,
        'failed' => 1,
        'refused' => 1,
        'running' => 1,
        'done_percent' => 67,
        'average_model_calls' => 5.0,
        'max_model_calls' => 8,
        'average_steps' => 7.0,
        'average_duration_s' => 50.0,
    ])->and($data['metadata']['models'])->toBe(['qwen3.5:9b']);
});

it('AC-11 refuses to write numbers when no run is stored', function (): void {
    $this->artisan('research:stats', ['--output-dir' => $this->directory])->assertFailed();

    expect(file_exists($this->directory.'/stats.json'))->toBeFalse();
});
