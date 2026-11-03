<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

it('AC-7 chat and photos show what is coming', function (): void {
    $this->get('/ai/chat')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Lab/Chat')
            ->where('slices', ['SL-45', 'SL-41'])
            ->where('blockedBy', null)
            ->where('backlog', 'backlog.md › SL-45')
            ->where('guide', 'docs/learning-guide.md'));

    $this->get('/ai/photos')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Lab/Photos')
            ->where('slices', ['SL-36'])
            ->where('blockedBy', 'Q-06')
            ->where('backlog', 'backlog.md › SL-36')
            ->where('guide', 'docs/learning-guide.md'));
});
