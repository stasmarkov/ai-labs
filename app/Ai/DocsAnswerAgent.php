<?php

declare(strict_types=1);

namespace App\Ai;

use App\DTOs\DocsChatTurn;
use App\DTOs\DocsMatch;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Promptable;

final class DocsAnswerAgent implements Agent, HasProviderOptions, VersionedPrompt
{
    use Promptable;
    use UsesActiveProvider;

    public readonly string $canary;

    /**
     * @param  string|null  $canary  a per-request marker appended to the system prompt; random when omitted
     * @param  string|null  $model  a text model of the active provider to use instead of its default
     * @param  list<DocsChatTurn>  $history  earlier turns of the conversation; a non-empty list selects the history prompt
     */
    public function __construct(?string $canary = null, private readonly ?string $model = null, private readonly array $history = [])
    {
        $this->canary = $canary ?? 'ap-canary-'.Str::lower(bin2hex(random_bytes(8)));
    }

    public function instructions(): string
    {
        return $this->systemPrompt()."\n".$this->canary;
    }

    /**
     * The prompt file's text, without the per-request canary: what the output guard protects.
     */
    public function systemPrompt(): string
    {
        return trim((string) file_get_contents(resource_path('prompts/'.$this->promptName().'.md')));
    }

    protected function feature(): string
    {
        return 'docs-answer';
    }

    public function modelName(): string
    {
        if (! $this->hasProviderOverride() && $this->model !== null) {
            return $this->model;
        }

        return app(AiProviders::class)->configured($this->feature(), $this->providerOverride)->model;
    }

    /**
     * The prompt's name and version, read from its file name: "docs-answer.v1.md" is "docs-answer@v1".
     */
    public function promptVersion(): string
    {
        $name = $this->promptName();
        $dot = strrpos($name, '.');

        return $dot === false ? $name : substr_replace($name, '@', $dot, 1);
    }

    private function promptName(): string
    {
        return (string) config($this->history === [] ? 'ai.prompts.docs_answer' : 'ai.prompts.docs_answer_history');
    }

    /**
     * The user message: the history as escaped <turn> elements inside <history> when there is any, the chunks as escaped
     * <doc> elements inside <context>, then the question outside it.
     *
     * @param  list<DocsMatch>  $matches  in citation order; the first is source 1
     */
    public function promptFor(string $question, array $matches): string
    {
        $docs = [];

        foreach ($matches as $index => $match) {
            $docs[] = sprintf(
                '<doc n="%d" source="%s" path="%s" section="%s">%s</doc>',
                $index + 1,
                self::escape($match->source),
                self::escape($match->path),
                self::escape($match->heading ?? ''),
                self::escape($match->body),
            );
        }

        return $this->historyBlock()."<context>\n".implode("\n", $docs)."\n</context>\nQuestion: ".$question;
    }

    private function historyBlock(): string
    {
        if ($this->history === []) {
            return '';
        }

        $turns = array_map(
            fn (DocsChatTurn $turn): string => sprintf('<turn role="%s">%s</turn>', self::escape($turn->role), self::escape($turn->content)),
            $this->history,
        );

        return "<history>\n".implode("\n", $turns)."\n</history>\n";
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE);
    }
}
