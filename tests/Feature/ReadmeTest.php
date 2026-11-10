<?php

declare(strict_types=1);

test('AC-4 README describes the lab for a learner', function (): void {
    $readme = file_get_contents(base_path('README.md'));

    expect($readme)->toStartWith('# ai-lab');

    foreach ([
        'ddev setup',
        'https://ai-lab.ddev.site',
        'ollama pull '.config('ai.providers.ollama.models.text.default'),
        'ollama pull '.config('ai.providers.ollama.models.embeddings.default'),
        'docs/learning-guide.md',
        'BENCHMARKS.md',
        '/ai',
    ] as $needle) {
        expect($readme)->toContain($needle);
    }

    expect($readme)->not->toContain('## About Laravel');
    expect($readme)->not->toContain('interview'.'.ddev.site');
});

test('AC-4 project docs carry the ai-lab name and purpose', function (): void {
    expect(file_get_contents(base_path('CLAUDE.md')))->toContain('ai-lab')->not->toContain('Fresh Laravel 13 skeleton');
    expect(file_get_contents(base_path('BENCHMARKS.md')))->toContain('ai-lab');
    expect(file_get_contents(base_path('docs/learning-guide.md')))->toStartWith('# ai-lab learning guide');
    expect(file_get_contents(base_path('backlog.md')))->toContain('# ai-lab backlog');
});

test('AC-4 README states requirements, benches, evals, history and the folder hint', function (): void {
    $readme = file_get_contents(base_path('README.md'));

    foreach ([
        '## Requirements',
        'about 20 GB',
        config('ai.providers.ollama.models.text.default'),
        config('ai.providers.ollama.models.embeddings.default'),
        'gemma4:12b',
        '## Benches and evals',
        'bench/run.sh',
        'ddev artisan rag:eval',
        '## History',
        'renamed ai-lab on 2026-11-10',
        '~/work/interview',
        'mv ~/work/interview ~/work/ai-lab',
    ] as $needle) {
        expect($readme)->toContain($needle);
    }

    expect($readme)->not->toContain('Auto'.'Pulse');
    expect($readme)->not->toContain('interview'.'.ddev.site');
});

test('AC-8 README explains how to switch on a cloud provider with a key', function (): void {
    $readme = file_get_contents(base_path('README.md'));

    foreach (['ANTHROPIC_API_KEY', 'OPENAI_API_KEY', 'GEMINI_API_KEY', 'AI_CLOUD_PER_MINUTE', '/ai/guides/providers'] as $needle) {
        expect($readme)->toContain($needle);
    }

    expect($readme)->not->toContain('are planned as optional extras');

    $example = file_get_contents(base_path('.env.example'));

    foreach (['ANTHROPIC_API_KEY=', 'OPENAI_API_KEY=', 'GEMINI_API_KEY=', 'AI_CLOUD_PER_MINUTE', 'AI_CLOUD_MAX_TOKENS', 'AI_DESCRIBE_PROVIDER'] as $needle) {
        expect($example)->toContain($needle);
    }
});
