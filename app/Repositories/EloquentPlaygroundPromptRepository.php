<?php

declare(strict_types=1);

namespace App\Repositories;

use App\Contracts\PlaygroundPromptRepositoryInterface;
use App\DTOs\NewPlaygroundVersion;
use App\DTOs\PlaygroundPromptRecord;
use App\DTOs\PlaygroundPromptSummary;
use App\DTOs\PlaygroundVersionRecord;
use App\Exceptions\PlaygroundPromptException;
use App\Models\PlaygroundPrompt;
use App\Models\PlaygroundPromptVersion;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

final readonly class EloquentPlaygroundPromptRepository implements PlaygroundPromptRepositoryInterface
{
    public function create(string $name, NewPlaygroundVersion $first): PlaygroundPromptRecord
    {
        try {
            return DB::transaction(function () use ($name, $first): PlaygroundPromptRecord {
                $prompt = PlaygroundPrompt::query()->create(['name' => $name]);
                $this->insertVersion($prompt->id, 1, $first);

                return $this->record($prompt->refresh()->load('versions'));
            });
        } catch (UniqueConstraintViolationException) {
            throw PlaygroundPromptException::nameTaken();
        }
    }

    public function addVersion(int $promptId, NewPlaygroundVersion $version): ?PlaygroundVersionRecord
    {
        try {
            return DB::transaction(function () use ($promptId, $version): ?PlaygroundVersionRecord {
                $prompt = PlaygroundPrompt::query()->whereKey($promptId)->lockForUpdate()->first();

                if ($prompt === null) {
                    throw PlaygroundPromptException::notFound();
                }

                $latest = PlaygroundPromptVersion::query()->where('playground_prompt_id', $promptId)->orderByDesc('version')->first();

                if ($latest !== null && $version->isUnchangedFrom($this->versionRecord($latest, $prompt->name))) {
                    return null;
                }

                $stored = $this->insertVersion($promptId, ($latest->version ?? 0) + 1, $version);
                $prompt->touch();

                return $this->versionRecord($stored, $prompt->name);
            });
        } catch (UniqueConstraintViolationException) {
            throw PlaygroundPromptException::versionConflict();
        }
    }

    public function nameExists(string $name): bool
    {
        return PlaygroundPrompt::query()->where('name', $name)->exists();
    }

    public function find(int $id): ?PlaygroundPromptRecord
    {
        $prompt = PlaygroundPrompt::query()->with('versions')->find($id);

        return $prompt === null ? null : $this->record($prompt);
    }

    public function findVersion(int $versionId): ?PlaygroundVersionRecord
    {
        $version = PlaygroundPromptVersion::query()->with('prompt')->find($versionId);

        return $version === null ? null : $this->versionRecord($version, $version->prompt->name);
    }

    public function all(): array
    {
        return PlaygroundPrompt::query()
            ->withCount('versions')
            ->withMax('versions', 'version')
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get()
            ->map(fn (PlaygroundPrompt $prompt): PlaygroundPromptSummary => new PlaygroundPromptSummary(
                id: $prompt->id,
                name: $prompt->name,
                latestVersion: (int) $prompt->getAttribute('versions_max_version'),
                versionsCount: (int) $prompt->getAttribute('versions_count'),
                updatedAt: $prompt->updated_at,
            ))
            ->all();
    }

    public function delete(int $id): bool
    {
        return PlaygroundPrompt::query()->whereKey($id)->delete() > 0;
    }

    private function insertVersion(int $promptId, int $number, NewPlaygroundVersion $version): PlaygroundPromptVersion
    {
        return PlaygroundPromptVersion::query()->create([
            'playground_prompt_id' => $promptId,
            'version' => $number,
            'system_prompt' => $version->systemPrompt,
            'note' => $version->note === null ? null : mb_substr($version->note, 0, 200),
            'temperature' => $version->temperature,
            'max_tokens' => $version->maxTokens,
        ])->refresh();
    }

    private function record(PlaygroundPrompt $prompt): PlaygroundPromptRecord
    {
        return new PlaygroundPromptRecord(
            id: $prompt->id,
            name: $prompt->name,
            versions: $prompt->versions->map(fn (PlaygroundPromptVersion $version): PlaygroundVersionRecord => $this->versionRecord($version, $prompt->name))->values()->all(),
        );
    }

    private function versionRecord(PlaygroundPromptVersion $version, string $promptName): PlaygroundVersionRecord
    {
        return new PlaygroundVersionRecord(
            id: $version->id,
            promptId: $version->playground_prompt_id,
            promptName: $promptName,
            version: $version->version,
            systemPrompt: $version->system_prompt,
            note: $version->note,
            temperature: $version->temperature,
            maxTokens: $version->max_tokens,
            createdAt: $version->created_at,
        );
    }
}
