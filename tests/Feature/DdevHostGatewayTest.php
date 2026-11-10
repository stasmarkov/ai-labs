<?php

declare(strict_types=1);

use Symfony\Component\Yaml\Yaml;

it('AC-5 the web container maps host.docker.internal to the host gateway', function (): void {
    $file = base_path('.ddev/docker-compose.host-gateway.yaml');

    expect($file)->toBeFile();

    $compose = Yaml::parseFile($file);

    expect($compose['services'])->toHaveKey('web')
        ->and($compose['services']['web']['extra_hosts'])->toContain('host.docker.internal:host-gateway');
});

it('AC-5 no server overlay overrides the web extra_hosts', function (): void {
    $overlays = glob(base_path('.ddev/servers/*/docker-compose.*.yaml'));

    expect($overlays)->not->toBeEmpty();

    foreach ($overlays as $overlay) {
        $compose = Yaml::parseFile($overlay);

        expect($compose['services']['web'] ?? [])->not->toHaveKey('extra_hosts');
    }
});
