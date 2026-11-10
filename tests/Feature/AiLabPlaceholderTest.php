<?php

declare(strict_types=1);

use Inertia\Testing\AssertableInertia as Assert;

it('AC-7 photos shows what is coming', function (): void {
    $this->get('/ai/photos')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Lab/Photos')
            ->has('summary')
            ->where('guideHref', '/ai/guides/photos#plan'));
});
