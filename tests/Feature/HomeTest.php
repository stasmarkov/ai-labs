<?php

declare(strict_types=1);

it('renders the home page', function (): void {
    $this->withoutVite();

    $this->get('/')->assertOk();
});
