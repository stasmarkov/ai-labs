<?php

declare(strict_types=1);

namespace App\Guides;

use App\DTOs\Guide;
use App\DTOs\GuideExperiment;
use App\DTOs\GuideItem;
use App\DTOs\GuideNumber;
use App\DTOs\GuideRecipe;
use App\DTOs\GuideStep;
use App\DTOs\GuideSupport;

final readonly class GuideRepository
{
    /** The guide kinds; capability guides back a live lab page, a planned guide only explains what is coming. */
    public const KINDS = ['capability', 'foundation', 'planned'];

    public function __construct(private string $directory) {}

    public function has(string $key): bool
    {
        return preg_match('/^[a-z0-9-]+$/', $key) === 1 && is_file($this->path($key));
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = array_map(fn (string $file): string => basename($file, '.php'), glob($this->directory.'/*.php') ?: []);
        sort($keys);

        return $keys;
    }

    public function find(string $key): Guide
    {
        if (! $this->has($key)) {
            throw new InvalidGuideException("Unknown guide [{$key}].");
        }

        $path = $this->path($key);
        $data = require $path;

        if (! is_array($data)) {
            throw new InvalidGuideException("Guide [{$key}] must return an array.");
        }

        return $this->build($key, $data, (string) sha1_file($path));
    }

    private function path(string $key): string
    {
        return $this->directory.'/'.$key.'.php';
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function build(string $key, array $data, string $hash): Guide
    {
        $kind = $this->string($key, $data, 'kind');

        if (! in_array($kind, self::KINDS, true)) {
            throw new InvalidGuideException("Guide [{$key}] has unknown kind [{$kind}].");
        }

        $planned = $kind === 'planned';

        return new Guide(
            key: $key,
            title: $this->string($key, $data, 'title'),
            summary: $this->string($key, $data, 'summary'),
            kind: $kind,
            planned: $planned,
            works: $this->rows($key, $data, 'works', fn (array $row): GuideStep => new GuideStep(
                $this->string($key, $row, 'title'),
                $this->string($key, $row, 'body'),
                $this->optional($row, 'event'),
                $this->optional($row, 'snippet'),
            )),
            failures: $this->rows($key, $data, 'failures', fn (array $row): GuideItem => new GuideItem(
                $this->string($key, $row, 'title'),
                $this->string($key, $row, 'body'),
                $this->optional($row, 'snippet'),
            )),
            recipes: $this->rows($key, $data, 'recipes', fn (array $row): GuideRecipe => new GuideRecipe(
                $this->string($key, $row, 'title'),
                $this->string($key, $row, 'body'),
                $this->optional($row, 'snippet'),
            )),
            support: $this->rows($key, $data, 'support', fn (array $row): GuideSupport => new GuideSupport(
                $this->string($key, $row, 'name'),
                $this->string($key, $row, 'status'),
                $this->string($key, $row, 'note'),
            )),
            numbers: $this->rows($key, $data, 'numbers', function (array $row) use ($key): GuideNumber {
                $pending = (bool) ($row['pending'] ?? false);
                if (! $pending && (! isset($row['file'], $row['path']))) {
                    throw new InvalidGuideException("Guide [{$key}] number needs file and path unless pending.");
                }

                return new GuideNumber(
                    $this->string($key, $row, 'label'),
                    $this->optional($row, 'file'),
                    $this->optional($row, 'path'),
                    $pending,
                    $this->optional($row, 'unit'),
                );
            }),
            experiments: $this->rows($key, $data, 'experiments', fn (array $row): GuideExperiment => $this->experiment($key, $row)),
            plan: $this->plan($key, $data, $planned),
            related: array_values(array_map('strval', is_array($data['related'] ?? null) ? $data['related'] : [])),
            contentHash: $hash,
        );
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<string>
     */
    private function plan(string $key, array $data, bool $planned): array
    {
        $plan = $data['plan'] ?? [];

        if (! is_array($plan)) {
            throw new InvalidGuideException("Guide [{$key}] field [plan] must be a list.");
        }

        $bullets = [];
        foreach ($plan as $bullet) {
            if (! is_string($bullet) || trim($bullet) === '') {
                throw new InvalidGuideException("Guide [{$key}] field [plan] holds a bullet that is not a non-empty string.");
            }
            $bullets[] = $bullet;
        }

        if ($planned && $bullets === []) {
            throw new InvalidGuideException("Guide [{$key}] is planned and needs at least one [plan] bullet.");
        }

        return $bullets;
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function experiment(string $key, array $row): GuideExperiment
    {
        $predictions = $this->rows($key, $row, 'predictions', fn (array $prediction): array => [
            'id' => $this->string($key, $prediction, 'id'),
            'text' => $this->string($key, $prediction, 'text'),
        ]);
        $ids = array_column($predictions, 'id');

        if (count($predictions) < 2 || count($predictions) > 4 || count(array_unique($ids)) !== count($ids)) {
            throw new InvalidGuideException("Guide [{$key}] experiment needs 2 to 4 predictions with distinct ids.");
        }

        $answer = is_array($row['answer'] ?? null) ? $row['answer'] : [];
        $answerId = $this->string($key, $answer, 'id');

        if (! in_array($answerId, $ids, true)) {
            throw new InvalidGuideException("Guide [{$key}] experiment answer [{$answerId}] is not one of its predictions.");
        }

        $numbers = $this->optional($answer, 'numbers');

        return new GuideExperiment(
            $this->string($key, $row, 'title'),
            $predictions,
            ['id' => $answerId, 'body' => $this->string($key, $answer, 'body')] + ($numbers === null ? [] : ['numbers' => $numbers]),
            $this->string($key, $row, 'page'),
            array_map('strval', is_array($row['params'] ?? null) ? $row['params'] : []),
        );
    }

    /**
     * @template T
     *
     * @param  array<string, mixed>  $data
     * @param  callable(array<string, mixed>): T  $make
     * @return list<T>
     */
    private function rows(string $key, array $data, string $field, callable $make): array
    {
        $rows = $data[$field] ?? [];

        if (! is_array($rows)) {
            throw new InvalidGuideException("Guide [{$key}] field [{$field}] must be a list.");
        }

        $built = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new InvalidGuideException("Guide [{$key}] field [{$field}] holds a row that is not an array.");
            }
            $built[] = $make($row);
        }

        return $built;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function string(string $key, array $data, string $field): string
    {
        $value = $data[$field] ?? null;

        if (! is_string($value) || trim($value) === '') {
            throw new InvalidGuideException("Guide [{$key}] needs a non-empty [{$field}].");
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function optional(array $data, string $field): ?string
    {
        $value = $data[$field] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }
}
