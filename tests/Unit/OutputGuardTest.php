<?php

declare(strict_types=1);

use App\Ai\OutputGuard;

const GUARD_PROMPT = 'Alpha beta gamma delta epsilon zeta eta theta iota kappa lambda mu nu xi omicron.';

it('AC-5 detects the canary', function (): void {
    $guard = new OutputGuard('ap-canary-abc', GUARD_PROMPT, []);

    expect($guard->leak('fine so far'))->toBeNull()
        ->and($guard->leak('here ap-canary-abc is'))->toBe('canary');
});

it('AC-5 detects twelve consecutive prompt words ignoring case and punctuation', function (): void {
    $guard = new OutputGuard('c', GUARD_PROMPT, []);

    expect($guard->leak('alpha, BETA gamma delta epsilon zeta eta theta iota kappa lambda'))->toBeNull()
        ->and($guard->leak('x alpha, BETA gamma delta epsilon zeta eta theta iota kappa lambda mu y'))->toBe('prompt_overlap');
});

it('AC-5 allows a run that the context or the question also holds', function (): void {
    $guard = new OutputGuard('c', GUARD_PROMPT, ['The docs say alpha beta gamma delta epsilon zeta eta theta iota kappa lambda mu.']);

    expect($guard->leak('alpha beta gamma delta epsilon zeta eta theta iota kappa lambda mu'))->toBeNull()
        ->and($guard->leak('beta gamma delta epsilon zeta eta theta iota kappa lambda mu nu'))->toBe('prompt_overlap');
});

it('AC-5 still finds a copied run when citation markers sit between its words', function (): void {
    $guard = new OutputGuard('c', GUARD_PROMPT, []);

    expect($guard->leak('alpha beta gamma delta [1] epsilon zeta eta theta [2, 3] iota kappa lambda mu'))->toBe('prompt_overlap');
});
