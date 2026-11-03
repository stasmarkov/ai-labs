<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

it('AC-7 photos shows what is coming', function (): void {
    $this->get('/ai/photos')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Lab/Photos')
            ->where('slices', ['SL-36'])
            ->where('blockedBy', 'Q-06')
            ->where('backlog', 'backlog.md › SL-36')
            ->where('guide', 'docs/learning-guide.md'));
});
