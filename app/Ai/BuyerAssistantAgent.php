<?php

declare(strict_types=1);

namespace App\Ai;

use App\Ai\Tools\ReadOnlyTool;
use App\Ai\Tools\SearchListings;
use App\DTOs\DocsChatTurn;
use App\Services\ListingSearchService;
use App\Services\PromptInjectionGuard;
use Laravel\Ai\Attributes\MaxSteps;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasProviderOptions;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Messages\Message;
use Laravel\Ai\Promptable;

/**
 * The step cap leaves room for the tool's own limit of SearchListings::MAX_CALLS searches plus the answer and one
 * clarifying turn; the tool refuses further calls, so cost stays bounded.
 */
#[MaxSteps(6)]
#[Temperature(0)]
final class BuyerAssistantAgent implements Agent, Conversational, HasProviderOptions, HasTools, VersionedPrompt
{
    use Promptable;
    use UsesActiveProvider;

    private readonly SearchListings $search;

    /**
     * @param  string|null  $model  a text model of the active provider to use instead of its default
     * @param  list<DocsChatTurn>  $history  earlier turns of the conversation
     */
    public function __construct(private readonly ?string $model = null, private readonly array $history = [])
    {
        $this->search = new SearchListings(app(ListingSearchService::class), app(PromptInjectionGuard::class));
    }

    public function instructions(): string
    {
        return trim((string) file_get_contents(resource_path('prompts/'.$this->promptName().'.md')));
    }

    /**
     * @return list<ReadOnlyTool>
     */
    public function tools(): iterable
    {
        return [$this->search];
    }

    public function searchTool(): SearchListings
    {
        return $this->search;
    }

    /**
     * @return list<Message>
     */
    public function messages(): iterable
    {
        return array_map(fn (DocsChatTurn $turn): Message => new Message($turn->role, $turn->content), $this->history);
    }

    protected function feature(): string
    {
        return 'assistant';
    }

    public function modelName(): string
    {
        if (! $this->hasProviderOverride() && $this->model !== null) {
            return $this->model;
        }

        return app(AiProviders::class)->configured($this->feature(), $this->providerOverride)->model;
    }

    /**
     * The prompt's name and version, read from its file name: "buyer-assistant.v1.md" is "buyer-assistant@v1".
     */
    public function promptVersion(): string
    {
        $name = $this->promptName();
        $dot = strrpos($name, '.');

        return $dot === false ? $name : substr_replace($name, '@', $dot, 1);
    }

    private function promptName(): string
    {
        return (string) config('ai.prompts.buyer_assistant');
    }
}
