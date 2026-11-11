<?php

declare(strict_types=1);

namespace App\Contracts;

use App\DTOs\AiSettingChoice;

interface AiSettingsRepositoryInterface
{
    public const CACHE_KEY = 'ai-settings:v1';

    public const CACHE_TTL = 3600;

    /**
     * The saved choices keyed by feature, read through the cache key that save and reset forget.
     *
     * @return array<string, AiSettingChoice>
     */
    public function all(): array;

    public function save(AiSettingChoice $choice): void;

    public function reset(string $feature): void;
}
