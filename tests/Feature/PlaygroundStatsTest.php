<?php

declare(strict_types=1);

use App\Enums\PlaygroundResultStatus;
use App\Models\PlaygroundPrompt;
use App\Models\PlaygroundPromptVersion;
use App\Models\PlaygroundResult;
use App\Models\PlaygroundRun;
use Illuminate\Support\Facades\File;

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir().'/playground-stats-'.bin2hex(random_bytes(4));
});

afterEach(function (): void {
    File::deleteDirectory($this->directory);
});

function playgroundRunWith(array $results): PlaygroundRun
{
    $run = PlaygroundRun::factory()->create();

    foreach ($results as $position => [$provider, $status, $duration]) {
        PlaygroundResult::factory()->create([
            'playground_run_id' => $run->id,
            'position' => $position + 1,
            'provider' => $provider,
            'model' => "model-{$position}",
            'status' => $status,
            'duration_ms' => $duration,
        ]);
    }

    return $run;
}

it('AC-9 writes the run, model, prompt and version counts and the two-model local time for the guide', function (): void {
    playgroundRunWith([['ollama', PlaygroundResultStatus::Ok, 4000], ['ollama', PlaygroundResultStatus::Ok, 6000]]);
    playgroundRunWith([['ollama', PlaygroundResultStatus::Ok, 10000], ['ollama', PlaygroundResultStatus::Ok, 10000]]);
    playgroundRunWith([['ollama', PlaygroundResultStatus::Ok, 1000], ['ollama', PlaygroundResultStatus::Failed, 1000]]);
    playgroundRunWith([['ollama', PlaygroundResultStatus::Ok, 1000], ['ollama', PlaygroundResultStatus::Ok, 1000], ['ollama', PlaygroundResultStatus::Ok, 1000]]);

    $prompt = PlaygroundPrompt::factory()->create();
    PlaygroundPromptVersion::factory()->create(['playground_prompt_id' => $prompt->id, 'version' => 1]);
    PlaygroundPromptVersion::factory()->create(['playground_prompt_id' => $prompt->id, 'version' => 2]);
    PlaygroundPromptVersion::factory()->create(['version' => 1]);

    $this->artisan('playground:stats', ['--output-dir' => $this->directory])->assertSuccessful();

    $data = json_decode((string) file_get_contents($this->directory.'/stats.json'), true);

    expect($data['summary'])->toEqual([
        'runs' => 4,
        'average_models_per_run' => 2.3,
        'prompts' => 2,
        'versions' => 3,
        'two_model_local_runs' => 2,
        'average_two_model_local_duration_s' => 15.0,
    ]);
});

it('AC-9 leaves a run with a cloud target out of the two-model local time', function (): void {
    playgroundRunWith([['ollama', PlaygroundResultStatus::Ok, 2000], ['ollama', PlaygroundResultStatus::Ok, 2000]]);
    playgroundRunWith([['ollama', PlaygroundResultStatus::Ok, 9000], ['openai', PlaygroundResultStatus::Ok, 9000]]);

    $this->artisan('playground:stats', ['--output-dir' => $this->directory])->assertSuccessful();

    $data = json_decode((string) file_get_contents($this->directory.'/stats.json'), true);

    expect($data['summary']['two_model_local_runs'])->toBe(1)
        ->and($data['summary']['average_two_model_local_duration_s'])->toEqual(4.0);
});

it('AC-9 refuses to write numbers when no run is stored', function (): void {
    $this->artisan('playground:stats', ['--output-dir' => $this->directory])->assertFailed();

    expect(file_exists($this->directory.'/stats.json'))->toBeFalse();
});
