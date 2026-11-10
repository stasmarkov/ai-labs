<?php

declare(strict_types=1);

namespace App\Ai;

use App\DTOs\DocsMatch;
use App\DTOs\RagEvalQuestion;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasStructuredOutput;
use Laravel\Ai\Promptable;

#[Temperature(0)]
final class RagGraderAgent implements Agent, HasProviderOptions, HasStructuredOutput, VersionedPrompt
{
    use Promptable;
    use UsesActiveProvider;

    public const MAX_REASON_LENGTH = 300;

    /**
     * @param  string|null  $model  a text model of the active provider to use instead of its default
     */
    public function __construct(private readonly ?string $model = null) {}

    public function instructions(): string
    {
        return trim((string) file_get_contents(resource_path('prompts/'.$this->promptName().'.md')));
    }

    /**
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'correctness' => $schema->integer()->min(0)->max(2)->required(),
            'groundedness' => $schema->integer()->min(0)->max(2)->required(),
            'reason' => $schema->string()->required(),
        ];
    }

    protected function feature(): string
    {
        return 'rag-grader';
    }

    public function modelName(): string
    {
        return $this->model ?? app(AiProviders::class)->configured($this->feature())->model;
    }

    /**
     * The prompt's name and version, read from its file name: "rag-grader.v1.md" is "rag-grader@v1".
     */
    public function promptVersion(): string
    {
        $name = $this->promptName();
        $dot = strrpos($name, '.');

        return $dot === false ? $name : substr_replace($name, '@', $dot, 1);
    }

    private function promptName(): string
    {
        return (string) config('ai.prompts.rag_grader');
    }

    /**
     * The user message: the question with its type and expected behaviour (answer, or decline for an off-topic question), the reference answer, the model's answer and the chunks it was given.
     *
     * @param  list<DocsMatch>  $sources  in citation order; the first is source 1
     */
    public function promptFor(RagEvalQuestion $question, string $answer, array $sources): string
    {
        $docs = [];

        foreach ($sources as $index => $match) {
            $docs[] = sprintf(
                '<doc n="%d" source="%s" path="%s">%s</doc>',
                $index + 1,
                self::escape($match->source),
                self::escape($match->path),
                self::escape($match->body),
            );
        }

        return implode("\n", [
            '<question>'.self::escape($question->question).'</question>',
            '<question_type>'.$question->type.'</question_type>',
            '<expected_behaviour>'.($question->isOnTopic() ? 'answer' : 'decline').'</expected_behaviour>',
            '<reference_answer>'.self::escape($question->answer).'</reference_answer>',
            '<chatbot_answer>'.self::escape($answer).'</chatbot_answer>',
            "<context>\n".implode("\n", $docs)."\n</context>",
        ]);
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE);
    }
}
