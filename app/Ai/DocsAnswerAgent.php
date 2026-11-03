<?php

declare(strict_types=1);

namespace App\Ai;

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
     */
    public function __construct(?string $canary = null)
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

    public function providerName(): string
    {
        return (string) config('ai.default');
    }

    public function modelName(): string
    {
        return (string) ($this->activeProviderEntry()['models']['text']['default'] ?? '');
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
        return (string) config('ai.prompts.docs_answer');
    }

    /**
     * The user message: the chunks as escaped <doc> elements inside <context>, then the question outside it.
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

        return "<context>\n".implode("\n", $docs)."\n</context>\nQuestion: ".$question;
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE);
    }
}
