<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\AiSettingsRepositoryInterface;
use App\DTOs\AiSettingChoice;
use App\Models\AiSetting;
use ArrayObject;
use Illuminate\Support\Facades\Cache;

final readonly class EloquentAiSettingsRepository implements AiSettingsRepositoryInterface
{
    private const MEMO_KEY = 'all';

    /** @var ArrayObject<string, array<string, AiSettingChoice>> */
    private ArrayObject $memo;

    public function __construct()
    {
        /** @var ArrayObject<string, array<string, AiSettingChoice>> $memo */
        $memo = new ArrayObject;

        $this->memo = $memo;
    }

    public function all(): array
    {
        if (isset($this->memo[self::MEMO_KEY])) {
            return $this->memo[self::MEMO_KEY];
        }

        // The cache holds plain arrays: the cache stores refuse to unserialize objects (cache.serializable_classes is
        // false), so a cached DTO would come back as __PHP_Incomplete_Class on the next request.
        /** @var list<array{feature: string, provider: string, model: string}> $rows */
        $rows = Cache::remember(self::CACHE_KEY, self::CACHE_TTL, fn (): array => AiSetting::query()
            ->orderBy('feature')
            ->get(['feature', 'provider', 'model'])
            ->map(fn (AiSetting $row): array => ['feature' => (string) $row->feature, 'provider' => (string) $row->provider, 'model' => (string) $row->model])
            ->all());

        $choices = [];

        foreach ($rows as $row) {
            // A stored id is shown in a copyable shell command and sent to providers, so one that does not look like a
            // model id (edited by hand in the database) is ignored rather than trusted.
            if (preg_match(AiSettingChoice::MODEL_PATTERN, $row['model']) === 1) {
                $choices[$row['feature']] = new AiSettingChoice($row['feature'], $row['provider'], $row['model']);
            }
        }

        return $this->memo[self::MEMO_KEY] = $choices;
    }

    public function save(AiSettingChoice $choice): void
    {
        AiSetting::query()->updateOrCreate(
            ['feature' => $choice->feature],
            ['provider' => $choice->provider, 'model' => $choice->model],
        );

        $this->memo->exchangeArray([]);
        Cache::forget(self::CACHE_KEY);
    }

    public function reset(string $feature): void
    {
        AiSetting::query()->whereKey($feature)->delete();

        $this->memo->exchangeArray([]);
        Cache::forget(self::CACHE_KEY);
    }
}
