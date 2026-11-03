<?php

declare(strict_types=1);

use App\DTOs\RagEvalQuestion;
use App\Services\DocsCachePairSet;
use App\Services\DocsCorpus;
use App\Services\RagEvalSet;

/**
 * "text" is the whole file normalized; "lines" holds each raw line normalized on its own, for whole-line matches.
 *
 * @return list<array{path: string, text: string, lines: list<string>}>
 */
function indexedCorpusTexts(): array
{
    $corpus = new DocsCorpus;
    $texts = [];

    foreach ($corpus->sources() as $source) {
        if (! $corpus->isAvailable($source)) {
            continue;
        }

        foreach ($corpus->files($source) as $file) {
            $raw = $corpus->read($file);
            $texts[] = [
                'path' => "{$source}:{$file->path}",
                'text' => RagEvalSet::normalizeText($raw),
                'lines' => array_map(RagEvalSet::normalizeText(...), preg_split('/\R/u', $raw) ?: []),
            ];
        }
    }

    return $texts;
}

/**
 * Eval text that must not occur in an indexed file: every question, every cache pair text, and every on-topic answer of 6 or more words
 * (shorter answers only as a whole line). Off-topic answers are the fixed "I don't know." and are exempt.
 *
 * @return array<string, string> label => normalized text
 */
function guardedEvalTexts(): array
{
    $guarded = [];

    foreach ((new RagEvalSet)->load() as $question) {
        $guarded["{$question->id} question"] = RagEvalSet::normalizeText($question->question);

        if ($question->type === RagEvalQuestion::ON_TOPIC) {
            $guarded["{$question->id} answer"] = RagEvalSet::normalizeText($question->answer);
        }
    }

    foreach ((new DocsCachePairSet)->load() as $pair) {
        $guarded["{$pair->id} a"] = RagEvalSet::normalizeText($pair->a);
        $guarded["{$pair->id} b"] = RagEvalSet::normalizeText($pair->b);
    }

    return $guarded;
}

it('AC-2 finds no eval question or reference answer in any file the corpus indexes', function (): void {
    $texts = indexedCorpusTexts();
    $found = [];

    foreach (guardedEvalTexts() as $label => $needle) {
        $isShortAnswer = str_ends_with($label, ' answer') && count(explode(' ', $needle)) < 6;

        foreach ($texts as $file) {
            $hit = $isShortAnswer
                ? in_array($needle, $file['lines'], true)
                : str_contains($file['text'], $needle);

            if ($hit) {
                $found[] = "{$label} occurs in {$file['path']}";
            }
        }
    }

    expect($found)->toBe([]);
});

it('AC-2 scans the repository corpus and never the eval set itself', function (): void {
    $paths = array_column(indexedCorpusTexts(), 'path');

    expect($paths)->toContain('repo:README.md')
        ->and(array_filter($paths, fn (string $path): bool => str_contains($path, 'rag-eval/questions')))->toBe([]);
});
