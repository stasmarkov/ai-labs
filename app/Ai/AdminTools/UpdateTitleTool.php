<?php

declare(strict_types=1);

namespace App\Ai\AdminTools;

use App\Contracts\AdminListingRepositoryInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;

final readonly class UpdateTitleTool implements AdminWriteTool
{
    public const TITLE_MIN = 3;

    public const TITLE_MAX = 120;

    /** Whitespace in the Unicode sense plus the invisible Hangul fillers, which are letters by category. */
    private const BLANK = '[\p{Z}\s\x{115F}\x{1160}\x{3164}\x{FFA0}]';

    public function __construct(private AdminListingRepositoryInterface $listings) {}

    public function name(): string
    {
        return 'updateTitle';
    }

    public function description(): string
    {
        return 'Propose a new title for a listing. Nothing changes until the developer confirms it.';
    }

    public function parameters(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'id' => ['type' => 'integer', 'description' => 'The listing id.'],
                'title' => ['type' => 'string', 'description' => 'The new title, '.self::TITLE_MIN.' to '.self::TITLE_MAX.' characters, one line.'],
            ],
            'required' => ['id', 'title'],
            'additionalProperties' => false,
        ];
    }

    public function isWrite(): bool
    {
        return true;
    }

    public function validate(array $arguments): array
    {
        $arguments = AdminArguments::normalise($arguments, ['title']);
        $errors = [...AdminArguments::unknown($arguments, ['id', 'title']), ...AdminArguments::idErrors($arguments)];
        $title = $arguments['title'] ?? null;

        if ($title === null) {
            $errors[] = 'The title argument is required.';
        } elseif (! is_string($title)) {
            $errors[] = 'The title argument must be a string.';
        } elseif (preg_match('/[\p{Cc}\p{Cf}\p{Zl}\p{Zp}]/u', $title) !== 0) {
            $errors[] = 'The title argument must not contain control characters or line breaks.';
        } else {
            $trimmed = self::trimmed($title);
            $length = mb_strlen($trimmed);

            if ($length < self::TITLE_MIN || $length > self::TITLE_MAX) {
                $errors[] = 'The title argument must be '.self::TITLE_MIN.' to '.self::TITLE_MAX.' characters.';
            } elseif (preg_match('/[\p{L}\p{N}]/u', (string) preg_replace('/'.self::BLANK.'/u', '', $trimmed)) !== 1) {
                $errors[] = 'The title argument must contain a letter or a digit.';
            }
        }

        if (AdminArguments::idErrors($arguments) === [] && $this->listings->find((int) $arguments['id']) === null) {
            $errors[] = 'No listing with id '.$arguments['id'].'.';
        }

        return $errors;
    }

    public function preview(array $arguments): array
    {
        $listing = $this->listings->find((int) ($arguments['id'] ?? 0));

        return [
            'id' => (int) ($arguments['id'] ?? 0),
            'old_title' => $listing?->title,
            'new_title' => $this->title($arguments),
        ];
    }

    public function run(array $arguments): array
    {
        try {
            $change = $this->listings->updateTitle((int) ($arguments['id'] ?? 0), $this->title($arguments));
        } catch (ModelNotFoundException) {
            throw new AdminToolFailed('No listing with id '.(int) ($arguments['id'] ?? 0).'.');
        }

        return AdminResultCap::cap([
            'id' => $change->id,
            'old_title' => $change->oldTitle,
            'new_title' => $change->newTitle,
            'changed' => $change->changed,
        ]);
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function title(array $arguments): string
    {
        $title = $arguments['title'] ?? '';

        return is_string($title) ? self::trimmed($title) : '';
    }

    private static function trimmed(string $title): string
    {
        return (string) preg_replace('/^'.self::BLANK.'+|'.self::BLANK.'+$/u', '', $title);
    }
}
