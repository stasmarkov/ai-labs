<?php

declare(strict_types=1);

test('AC-6 server_software comes from the request server variables', function (): void {
    config(['app.debug_routes' => true]);

    $this->withServerVariables(['SERVER_SOFTWARE' => 'stub-server/1.0'])
        ->getJson('/debug/runtime')
        ->assertOk()
        ->assertJsonPath('data.server_software', 'stub-server/1.0');
});
