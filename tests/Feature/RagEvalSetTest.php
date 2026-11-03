<?php

declare(strict_types=1);

use App\DTOs\RagEvalQuestion;
use App\Exceptions\RagEvalSetException;
use App\Services\RagEvalSet;
use Illuminate\Support\Facades\File;

function evalQuestions(): array
{
    return (new RagEvalSet)->load();
}

function evalDocRoot(string $source): string
{
    return rtrim((string) config("ai.docs.{$source}.path"), '/');
}

/**
 * @return list<string> the expected docs whose evidence is missing from the file on disk
 */
function evidenceProblems(string $source): array
{
    $problems = [];

    foreach (evalQuestions() as $question) {
        foreach ($question->expected as $expectation) {
            if ($expectation->source !== $source) {
                continue;
            }

            $file = evalDocRoot($source).'/'.$expectation->path;

            if (! is_file($file)) {
                $problems[] = "{$question->id}: {$expectation->doc()} does not exist";

                continue;
            }

            $text = RagEvalSet::normalizeEvidence(File::get($file));

            foreach ($expectation->evidence as $phrase) {
                if (! str_contains($text, RagEvalSet::normalizeEvidence($phrase))) {
                    $problems[] = "{$question->id}: \"{$phrase}\" not found in {$expectation->doc()}";
                }
            }
        }
    }

    return $problems;
}

function writeEvalFile(string ...$lines): string
{
    $path = storage_path('framework/testing/rag-eval-'.uniqid().'.jsonl');
    File::ensureDirectoryExists(dirname($path));
    File::put($path, implode("\n", $lines)."\n");

    return $path;
}

it('AC-1 ships 30 valid lines: 24 on-topic and 6 off-topic', function (): void {
    $questions = evalQuestions();
    $onTopic = array_filter($questions, fn (RagEvalQuestion $question): bool => $question->isOnTopic());

    expect($questions)->toHaveCount(30)
        ->and($onTopic)->toHaveCount(24)
        ->and(collect($questions)->pluck('id')->unique())->toHaveCount(30);
});

it('AC-1 covers at least 16 Laravel and 6 repository questions with paraphrase and identifier tags', function (): void {
    $onTopic = array_filter(evalQuestions(), fn (RagEvalQuestion $question): bool => $question->isOnTopic());
    $about = fn (string $source): int => count(array_filter($onTopic, fn (RagEvalQuestion $question): bool => $question->expected[0]->source === $source));
    $tagged = fn (string $tag): int => count(array_filter($onTopic, fn (RagEvalQuestion $question): bool => in_array($tag, $question->tags, true)));

    expect($about('laravel'))->toBeGreaterThanOrEqual(16)
        ->and($about('repo'))->toBeGreaterThanOrEqual(6)
        ->and($tagged('paraphrase'))->toBeGreaterThanOrEqual(4)
        ->and($tagged('identifier'))->toBeGreaterThanOrEqual(3);
});

it('AC-1 gives every on-topic line a document and 1 to 12 word evidence, and every off-topic line neither', function (): void {
    foreach (evalQuestions() as $question) {
        if ($question->isOnTopic()) {
            expect($question->expected)->not->toBeEmpty();

            foreach ($question->expected as $expectation) {
                expect($expectation->evidence)->not->toBeEmpty();
            }
        } else {
            expect($question->expected)->toBe([])
                ->and($question->answer)->toBe("I don't know.");
        }
    }
});

it('AC-1 finds every repository evidence phrase in the file on disk', function (): void {
    expect(evidenceProblems('repo'))->toBe([]);
});

it('AC-1 finds every Laravel evidence phrase in the docs on disk', function (): void {
    expect(evidenceProblems('laravel'))->toBe([]);
})->skip(fn (): bool => ! is_dir(evalDocRoot('laravel')), 'storage/app/docs/laravel is absent: run docs:fetch-laravel');

it('AC-1 names the line of an invalid JSON object', function (): void {
    $valid = '{"id":"ok-one","type":"off_topic","question":"q","answer":"I don\'t know.","expected":[]}';
    $path = writeEvalFile($valid, '{not json');

    expect(fn () => (new RagEvalSet)->load($path))->toThrow(RagEvalSetException::class, 'line 2');
});

it('AC-1 names the line of a rule violation', function (string $line, string $message): void {
    $path = writeEvalFile($line);

    expect(fn () => (new RagEvalSet)->load($path))
        ->toThrow(RagEvalSetException::class, 'line 1:')
        ->and(fn () => (new RagEvalSet)->load($path))->toThrow(RagEvalSetException::class, $message);
})->with([
    'bad id' => ['{"id":"A","type":"off_topic","question":"q","answer":"a","expected":[]}', 'id must match'],
    'bad type' => ['{"id":"abc","type":"maybe","question":"q","answer":"a","expected":[]}', 'type must be'],
    'missing question' => ['{"id":"abc","type":"off_topic","answer":"a","expected":[]}', '"question" must be'],
    'on-topic without document' => ['{"id":"abc","type":"on_topic","question":"q","answer":"a","expected":[]}', 'needs at least one expected document'],
    'off-topic with document' => ['{"id":"abc","type":"off_topic","question":"q","answer":"a","expected":[{"doc":"repo:README.md","evidence":["x"]}]}', 'must have no expected documents'],
    'bad doc' => ['{"id":"abc","type":"on_topic","question":"q","answer":"a","expected":[{"doc":"README.md","evidence":["x"]}]}', 'source:path'],
    'empty evidence' => ['{"id":"abc","type":"on_topic","question":"q","answer":"a","expected":[{"doc":"repo:README.md","evidence":[]}]}', '"evidence" of repo:README.md'],
    'evidence too long' => ['{"id":"abc","type":"on_topic","question":"q","answer":"a","expected":[{"doc":"repo:README.md","evidence":["one two three four five six seven eight nine ten eleven twelve thirteen"]}]}', 'must be 1 to 12 words'],
    'evidence of markdown characters only' => ['{"id":"abc","type":"on_topic","question":"q","answer":"a","expected":[{"doc":"repo:README.md","evidence":["`` ** | >"]}]}', 'no text left'],
    'bad tags' => ['{"id":"abc","type":"off_topic","question":"q","answer":"a","expected":[],"tags":"x"}', '"tags" must be'],
]);

it('AC-1 rejects a duplicate id and a missing file', function (): void {
    $line = '{"id":"abc","type":"off_topic","question":"q","answer":"a","expected":[]}';

    expect(fn () => (new RagEvalSet)->load(writeEvalFile($line, $line)))->toThrow(RagEvalSetException::class, 'line 2: duplicate id')
        ->and(fn () => (new RagEvalSet)->load('/nonexistent/questions.jsonl'))->toThrow(RagEvalSetException::class, 'not found');
});
