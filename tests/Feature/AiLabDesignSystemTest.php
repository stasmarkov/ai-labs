<?php

declare(strict_types=1);

function labPath(string $relative): string
{
    return base_path('resources/'.$relative);
}

function labTokens(): array
{
    preg_match_all('/--color-([a-z]+):\s*(#[0-9a-fA-F]{6});/', file_get_contents(labPath('css/ai.css')), $matches);

    return array_combine($matches[1], $matches[2]);
}

function labLuminance(string $hex): float
{
    $channels = array_map(function (string $part): float {
        $value = hexdec($part) / 255;

        return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
    }, str_split(ltrim($hex, '#'), 2));

    return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
}

function labContrast(string $foreground, string $background): float
{
    $lighter = max(labLuminance($foreground), labLuminance($background));
    $darker = min(labLuminance($foreground), labLuminance($background));

    return ($lighter + 0.05) / ($darker + 0.05);
}

it('AC-9 the lab text colours reach a contrast of 4.5:1 on the paper', function (): void {
    $tokens = labTokens();

    foreach (['ink', 'muted', 'signal', 'success', 'danger'] as $name) {
        expect(labContrast($tokens[$name], $tokens['paper']))->toBeGreaterThanOrEqual(4.5, $name);
    }

    expect(labContrast('#ffffff', $tokens['signal']))->toBeGreaterThanOrEqual(4.5);
});

it('AC-9 the lab has visible keyboard focus and respects reduced motion', function (): void {
    $css = file_get_contents(labPath('css/ai.css'));

    expect($css)->toContain(':focus-visible')
        ->and($css)->toContain('prefers-reduced-motion: reduce');
});

it('AC-9 the layout and the design components exist and the pages use the layout', function (): void {
    foreach (['Layouts/LabLayout', 'components/LabButton', 'components/LabTextInput', 'components/LabSegmented',
        'components/LabMetric', 'components/LabEventRow', 'components/LabSimilarityBar', 'components/LabStatusDot',
        'components/LabTag'] as $component) {
        expect(file_exists(labPath("js/ai/{$component}.vue")))->toBeTrue($component);
    }

    $layout = file_get_contents(labPath('js/ai/Layouts/LabLayout.vue'));

    expect($layout)->toContain('<aside')
        ->and($layout)->toContain('<nav')
        ->and($layout)->toContain('<main')
        ->and($layout)->toContain('min-w-0')
        ->and(file_get_contents(labPath('js/ai/app.ts')))->toContain('LabLayout');
});
