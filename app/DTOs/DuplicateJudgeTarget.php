<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class DuplicateJudgeTarget
{
    /**
     * @param  list<DuplicateJudgeMember>  $members  in ascending photo id order
     */
    public function __construct(
        public int $judgementId,
        public int $groupId,
        public string $provider,
        public string $model,
        public string $promptVersion,
        public array $members,
    ) {}
}
