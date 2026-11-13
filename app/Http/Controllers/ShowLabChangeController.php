<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\PendingChangePageService;
use Inertia\Inertia;
use Inertia\Response;

final class ShowLabChangeController extends Controller
{
    private const ID_PLACEHOLDER = 'CHANGEID';

    public function __invoke(string $id, PendingChangePageService $pages): Response
    {
        $change = $pages->find($id) ?? abort(404);
        $lists = $pages->lists();

        return Inertia::render('Lab/Changes', [
            ...$lists,
            'change' => $change,
            'confirmUrl' => route('changes.confirm', ['id' => $id], false),
            'rejectUrl' => route('changes.reject', ['id' => $id], false),
            ...self::urls(),
        ]);
    }

    /**
     * @return array{showUrl: string, confirmTemplate: string, rejectTemplate: string, idPlaceholder: string}
     */
    public static function urls(): array
    {
        return [
            'showUrl' => route('ai.changes.show', ['id' => self::ID_PLACEHOLDER], false),
            'confirmTemplate' => route('changes.confirm', ['id' => self::ID_PLACEHOLDER], false),
            'rejectTemplate' => route('changes.reject', ['id' => self::ID_PLACEHOLDER], false),
            'idPlaceholder' => self::ID_PLACEHOLDER,
        ];
    }
}
