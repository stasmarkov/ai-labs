<?php

declare(strict_types=1);

test('AC-8 the committed follow-up note matches its data file', function (): void {
    $dir = base_path('bench/results/chat-stop/2026-11-03');
    $data = json_decode((string) file_get_contents($dir.'/followups.json'), true, flags: JSON_THROW_ON_ERROR);
    $note = (string) file_get_contents($dir.'/followups.md');

    expect($data['chats'])->toHaveCount(3)
        ->and($data['total'])->toBe(3)
        ->and($data['right'])->toBe(count(array_filter($data['chats'], fn (array $chat): bool => $chat['answer_right'])))
        ->and($note)->toContain("Right answers: {$data['right']} of {$data['total']}.");

    foreach ($data['chats'] as $chat) {
        expect($chat['history'])->toHaveCount(2)
            ->and($chat['retrieved_paths'])->not->toBeEmpty()
            ->and($note)->toContain($chat['question'])
            ->and($note)->toContain(implode(', ', $chat['retrieved_paths']));
    }
});
