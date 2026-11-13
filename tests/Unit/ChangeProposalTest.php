<?php

declare(strict_types=1);

use App\Ai\AdminTools\AdminResultCap;
use App\DTOs\ChangeProposal;
use Carbon\CarbonImmutable;

it('AC-3 keeps the change id and url when the preview is too large for the cap', function (): void {
    $proposal = new ChangeProposal('0b0e7a5e-0000-4000-8000-000000000001', ['title' => str_repeat('x', 6000)], 'https://ai-lab.test/ai/changes/0b0e7a5e', CarbonImmutable::parse('2026-11-14T10:00:00Z'));

    $reply = $proposal->toArray();

    expect($reply)->toMatchArray(['change_id' => '0b0e7a5e-0000-4000-8000-000000000001', 'url' => 'https://ai-lab.test/ai/changes/0b0e7a5e', 'status' => 'pending'])
        ->and($reply['preview'])->toBe(['truncated' => true])
        ->and(mb_strlen(AdminResultCap::encode($reply)))->toBeLessThanOrEqual(AdminResultCap::MAX_CHARACTERS);
});

it('AC-3 returns a small preview as it is', function (): void {
    $proposal = new ChangeProposal('id', ['new_title' => 'A title'], 'https://ai-lab.test/ai/changes/id', CarbonImmutable::parse('2026-11-14T10:00:00Z'));

    expect($proposal->toArray()['preview'])->toBe(['new_title' => 'A title']);
});

it('AC-3 tells the model the developer must confirm in the browser', function (): void {
    $note = (new ChangeProposal('id', [], 'https://ai-lab.test/ai/changes/id', CarbonImmutable::now()))->toArray()['note'];

    expect($note)->toContain('needs the developer in the browser')->not->toContain('cannot confirm');
});
