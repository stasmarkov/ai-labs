<?php

declare(strict_types=1);

function labPage(string $name): string
{
    return file_get_contents(base_path('resources/js/ai/Pages/Lab/'.$name.'.vue'));
}

it('AC-1 the moderation picker and the examples sit on separate rows', function (): void {
    $page = labPage('Moderation');

    expect($page)->not->toMatch('/<div class="flex flex-col gap-4 sm:flex-row sm:items-end">\s*<div class="flex min-w-0 flex-1 flex-col gap-1">\s*<label for="moderation-pick"/');
    expect($page)->toMatch('/<select\s+id="moderation-pick"[^>]*min-w-40/');
});

it('AC-1 the describe listing select has its own row and a minimum width', function (): void {
    $page = labPage('Describe');

    expect($page)->not->toContain('sm:flex-row sm:items-end" @submit.prevent="start"');
    expect($page)->toMatch('/<select\s+id="describe-listing"[^>]*min-w-40/');
});
