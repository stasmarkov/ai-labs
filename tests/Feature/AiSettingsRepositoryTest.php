<?php

declare(strict_types=1);

use App\Contracts\AiSettingsRepositoryInterface;
use App\DTOs\AiSettingChoice;
use App\Models\AiSetting;
use Illuminate\Support\Facades\Cache;

function settingsRepository(): AiSettingsRepositoryInterface
{
    return app(AiSettingsRepositoryInterface::class);
}

it('AC-3 all returns nothing when no setting is saved', function (): void {
    expect(settingsRepository()->all())->toBe([]);
});

it('AC-3 a saved choice is returned keyed by feature and replaced by a second save', function (): void {
    settingsRepository()->save(new AiSettingChoice('listing-description', 'ollama', 'qwen3.5:9b'));
    settingsRepository()->save(new AiSettingChoice('listing-description', 'ollama', 'gemma4:12b'));

    $all = settingsRepository()->all();

    expect($all)->toHaveKey('listing-description')
        ->and($all['listing-description'])->toEqual(new AiSettingChoice('listing-description', 'ollama', 'gemma4:12b'))
        ->and(AiSetting::query()->count())->toBe(1);
});

it('AC-3 reset removes the saved choice so the feature falls back to configuration', function (): void {
    settingsRepository()->save(new AiSettingChoice('listing-description', 'ollama', 'qwen3.5:9b'));
    settingsRepository()->save(new AiSettingChoice('photo-vision', 'ollama', 'gemma4:12b'));

    settingsRepository()->reset('listing-description');

    expect(array_keys(settingsRepository()->all()))->toBe(['photo-vision']);
});

it('AC-3 save and reset forget the cache key so the next read sees the change', function (): void {
    expect(settingsRepository()->all())->toBe([]);
    expect(Cache::has(AiSettingsRepositoryInterface::CACHE_KEY))->toBeTrue();

    settingsRepository()->save(new AiSettingChoice('photo-vision', 'ollama', 'gemma4:12b'));
    expect(Cache::has(AiSettingsRepositoryInterface::CACHE_KEY))->toBeFalse()
        ->and(settingsRepository()->all())->toHaveKey('photo-vision');

    settingsRepository()->reset('photo-vision');
    expect(Cache::has(AiSettingsRepositoryInterface::CACHE_KEY))->toBeFalse()
        ->and(settingsRepository()->all())->toBe([]);
});

it('AC-4 resetting a feature with no saved choice stores nothing and leaves the others', function (): void {
    settingsRepository()->save(new AiSettingChoice('photo-vision', 'ollama', 'gemma4:12b'));

    settingsRepository()->reset('listing-description');

    expect(AiSetting::query()->pluck('feature')->all())->toBe(['photo-vision']);
});
