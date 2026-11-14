<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Contracts\ResearchRunRepositoryInterface;
use App\DTOs\ResearchRunRecord;
use App\DTOs\ResearchStepRecord;
use App\Enums\ResearchRunStatus;
use App\Exceptions\ProviderNotConfiguredException;
use App\Exceptions\ProviderNotEnabledException;
use App\Services\ResearchRunner;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('agent:research {topic? : What to research in the docs} {--provider= : Text provider for this run} {--json : Print the run as JSON} {--replay= : Show a stored run by id, without any model or search call}')]
#[Description('Run the multi-step research agent on a topic, or replay a stored run')]
final class AgentResearchCommand extends Command
{
    public function handle(ResearchRunner $runner, ResearchRunRepositoryInterface $runs): int
    {
        $replay = $this->option('replay');
        $topic = $this->argument('topic');

        if ($replay !== null && $topic !== null) {
            $this->error('Give a topic or --replay, not both.');

            return self::INVALID;
        }

        if ($replay !== null) {
            return $this->replay($runs, (string) $replay);
        }

        if ($topic === null || trim((string) $topic) === '') {
            $this->error('Give a topic to research, or --replay=<run id>.');

            return self::INVALID;
        }

        $provider = $this->option('provider');

        $live = ! $this->option('json');

        try {
            $run = $runner->run(
                (string) $topic,
                $provider === null || $provider === '' ? null : (string) $provider,
                $live ? function (ResearchStepRecord $step): void {
                    $this->renderStep($step);
                } : null,
            );
        } catch (InvalidArgumentException|ProviderNotEnabledException|ProviderNotConfiguredException $e) {
            $this->error($e->getMessage());

            return self::INVALID;
        }

        return $this->show($run, stepsShown: $live);
    }

    private function replay(ResearchRunRepositoryInterface $runs, string $id): int
    {
        $run = $runs->find($id);

        if ($run === null) {
            $this->error('No research run with this id.');

            return self::INVALID;
        }

        return $this->show($run);
    }

    private function show(ResearchRunRecord $run, bool $stepsShown = false): int
    {
        if ($this->option('json')) {
            $this->line((string) json_encode($this->payload($run), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        } else {
            $this->render($run, $stepsShown);
        }

        return match ($run->status) {
            ResearchRunStatus::Done => self::SUCCESS,
            ResearchRunStatus::Refused => 3,
            default => self::FAILURE,
        };
    }

    private function render(ResearchRunRecord $run, bool $stepsShown): void
    {
        $this->line("Run {$run->id} [{$run->status->value}] {$run->provider} / {$run->model}");
        $this->line("Topic: {$run->topic}");

        if (! $stepsShown) {
            foreach ($run->steps as $step) {
                $this->renderStep($step);
            }
        }

        $this->newLine();

        if ($run->report !== null) {
            $this->line('Report:');
            $this->line($run->report);
        }

        foreach ($run->sources ?? [] as $source) {
            $this->line("[{$source['n']}] ".($source['path'] ?? '').(($source['heading'] ?? null) === null ? '' : ' — '.$source['heading']));
        }

        if ($run->error !== null) {
            $this->line("Error: {$run->error}");
        }
    }

    private function renderStep(ResearchStepRecord $step): void
    {
        $index = $step->subQuestionIndex === null ? '' : ' #'.($step->subQuestionIndex + 1);
        $this->newLine();
        $this->line("Step {$step->position} {$step->phase->value}{$index} [{$step->status->value}] {$step->durationMs} ms".($step->error === null ? '' : " error: {$step->error}"));
        $this->line('  input: '.$this->encode($step->input));
        $this->line('  output: '.$this->encode($step->output));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(ResearchRunRecord $run): array
    {
        return [
            'id' => $run->id,
            'topic' => $run->topic,
            'status' => $run->status->value,
            'provider' => $run->provider,
            'model' => $run->model,
            'sub_questions' => $run->subQuestions,
            'report' => $run->report,
            'sources' => $run->sources,
            'error' => $run->error,
            'model_calls' => $run->modelCalls,
            'steps' => array_map(fn (ResearchStepRecord $step): array => [
                'position' => $step->position,
                'phase' => $step->phase->value,
                'sub_question_index' => $step->subQuestionIndex,
                'status' => $step->status->value,
                'error' => $step->error,
                'duration_ms' => $step->durationMs,
                'input' => $step->input,
                'output' => $step->output,
            ], $run->steps),
        ];
    }

    private function encode(mixed $value): string
    {
        return (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
