<?php

declare(strict_types=1);

return [
    'kind' => 'capability',
    'title' => 'Admin assistant',
    'summary' => 'A chat that runs its own tool loop in plain PHP: it reads listings with tools, proposes a change as a card, and writes only after you click Confirm. Local machine only.',
    'works' => [
        [
            'title' => 'The question is guarded, then the first request is built',
            'body' => 'The input guard checks the question before any model call; a hit ends the turn with no request and no logged run. Otherwise the loop sends the system prompt, the last 40 messages, the five tool definitions, `tool_choice: auto`, `temperature: 0` and `stream: false` to the OpenAI-shaped chat endpoint of the local model server.',
            'snippet' => 'App\Ai\OllamaChatClient::complete',
        ],
        [
            'title' => 'The model answers with text or with tool calls',
            'body' => 'The reply is `choices[0].message`. Plain `content` ends the turn. A `tool_calls` list means the model wants a tool run: each call has an `id`, a function `name` and `arguments` as a **string**. The loop keeps that string untouched and decodes it only when it handles the call. One `ai_runs` row is logged per model call, with tokens in and out.',
            'snippet' => 'App\Ai\AdminAssistantLoop::drive',
        ],
        [
            'title' => 'PHP checks every call before anything runs',
            'body' => 'Invalid JSON, an unknown tool name or arguments the tool rejects each go back to the model as an error message, with no query run. Tools describe their own rules in `validate`, so the model cannot talk a tool past them.',
            'snippet' => 'App\Ai\AdminAssistantLoop::handle',
        ],
        [
            'title' => 'Read tools run and the result goes back as data',
            'body' => 'A valid read call runs through the registry, which refuses write tools. The result is capped (4000 characters, at most 10 rows, descriptions cut at 500) and sent back as a `tool` message wrapped in `<tool_result>` tags with `<` and `>` escaped, so listing text cannot close the tag or pass for an instruction. Each step is logged with its arguments and a short summary.',
            'snippet' => 'App\Ai\AdminTools\AdminToolRegistry::runRead',
        ],
        [
            'title' => 'A write stops the loop and becomes a card',
            'body' => 'The first valid write call is not run. The loop returns a pending action: a random id, the tool, its arguments, a before-and-after preview and a 10 minute expiry. Later calls in the same answer get a `deferred` reply. The page shows the card with Confirm and Reject, and nothing has changed yet.',
            'snippet' => 'App\Ai\AdminAssistantLoop::handle',
        ],
        [
            'title' => 'Only the confirm endpoint writes, then the loop continues',
            'body' => 'Confirm checks that the action belongs to this conversation, is not expired and was not used, validates the arguments again and writes in a transaction. The outcome is logged as a `confirmed` or `rejected` step and fed back to the model, which answers or proposes the next change. A question typed while a card is open gets 409, and a typed "yes" is just a question. At most 4 tool steps and 6 model calls run per question; the last call is sent with `tool_choice: none`.',
            'snippet' => 'App\Services\AdminAssistantConfirmation::apply',
        ],
    ],
    'failures' => [
        [
            'title' => 'The model sends arguments that are not valid JSON',
            'body' => "**Cause:** `arguments` arrives as text, and a small model sometimes closes a brace too early or adds a comment.\n\n**Fix:** nothing to fix in the code. The step shows `invalid_arguments`, the model gets the error and usually repairs the call. If it keeps failing, try a larger model.",
            'snippet' => 'App\Ai\AdminAssistantLoop::handle',
        ],
        [
            'title' => 'The model says a change was made before you confirmed',
            'body' => "**Cause:** the model writes what it expects to happen instead of waiting for the tool result.\n\n**Fix:** the prompt says never to claim a change before a result says it was applied. Whatever the model says, the listing page only changes after Confirm; check it there.",
            'snippet' => 'App\Ai\AdminAssistantLoop::instructions',
        ],
        [
            'title' => 'The answer says the step limit was reached',
            'body' => "**Cause:** the question needed more than 4 tool steps or the model repeated a search.\n\n**Fix:** ask a narrower question. The limit is on purpose: it bounds the cost of one question, and the last call is forced to answer in text.",
        ],
        [
            'title' => 'The page says the model does not support tools',
            'body' => "**Cause:** the model set for this feature cannot call tools, and the server refuses the request.\n\n**Fix:** pick a model that supports tools in the feature's settings. The error is reported before anything runs.",
            'snippet' => 'App\Ai\OllamaChatClient::complete',
        ],
    ],
    'recipes' => [
        [
            'title' => 'Add a tool',
            'body' => "1. Write a class that implements the tool contract: a `name`, a `description`, a JSON-schema `parameters`, `isWrite`, `validate` and `run`.\n2. For a write, implement the write contract too, so it can describe its change in `preview`.\n3. Add it to the registry's constructor and list.\n\nKeep `validate` strict and `run` capped. A write tool never runs from the loop; the confirm endpoint is the only caller.\n\n**Check:** the tool tests cover its rules and its cap, and a write proposal leaves the data unchanged.",
            'snippet' => 'App\Ai\AdminTools\UpdateTitleTool',
        ],
        [
            'title' => 'Swap the model or provider',
            'body' => "Set the provider and model of the admin assistant feature in the environment or on the settings page. Only the local model server is supported, because the loop speaks its chat endpoint directly; another provider is refused before any request.\n\n**Check:** the run row names the new model, and the timeline still shows tool steps.",
            'snippet' => 'App\Ai\AdminAssistantLoop::promptVersion',
        ],
        [
            'title' => 'Change the prompt or the limits',
            'body' => "Add a new prompt version and point the admin assistant prompt setting at it; the version is logged with every run. The step, call and time limits are constants of the loop.\n\n**Check:** rerun the loop tests and a few questions on the page, and read the run rows.",
            'snippet' => 'App\Ai\AdminAssistantLoop::promptVersion',
        ],
    ],
    'support' => [
        [
            'name' => 'Run rows and steps',
            'status' => 'logged',
            'note' => 'Every model call is a run row with tokens and latency. Every tool step is a row with its arguments, outcome (`ok`, `error`, `failed`, `pending`, `confirmed` or `rejected`) and a short summary, never the full listings.',
        ],
        [
            'name' => 'Writes and local-only access',
            'status' => 'guarded',
            'note' => 'The routes answer 404 outside the local environment or with the admin switch off, refuse cross-site requests and form bodies, are rate limited, and write only through a valid confirmation of the same conversation. Tests cover each rule.',
        ],
        [
            'name' => 'The assistant proposed or did the wrong thing',
            'status' => 'runbook',
            'note' => "1. Open the run link under the step and read the request and the steps.\n2. Wrong arguments: read the step; the card showed them before anything was written.\n3. A change was applied by mistake: read the `confirmed` step, then set the title or tag back with another proposal.\n4. Many errors in the steps: the model is too small for tool calls; switch model.",
        ],
    ],
    'numbers' => [
        ['label' => 'Tool steps per question, median', 'pending' => true],
        ['label' => 'Tool calls valid on the first try', 'pending' => true],
    ],
    'experiments' => [
        [
            'title' => 'A read question',
            'predictions' => [
                ['id' => 'a', 'text' => 'The assistant answers from memory with no tool step.'],
                ['id' => 'b', 'text' => 'The timeline shows a search step with its arguments, then an answer naming the listings it returned.'],
                ['id' => 'c', 'text' => 'A confirmation card appears.'],
            ],
            'answer' => ['id' => 'b', 'body' => 'A search is a read tool: it runs at once, shows its arguments and result size in the timeline, and the answer is built from what it returned. No card appears.'],
            'page' => 'ai.admin-assistant',
            'params' => ['q' => 'How many diesel BMW listings are in Lviv, and show me two of them'],
        ],
        [
            'title' => 'A change request',
            'predictions' => [
                ['id' => 'a', 'text' => 'The title changes at once and the assistant tells you.'],
                ['id' => 'b', 'text' => 'A card shows the old and new title and nothing changes until you click Confirm.'],
                ['id' => 'c', 'text' => 'The assistant refuses because writes are not allowed.'],
            ],
            'answer' => ['id' => 'b', 'body' => 'The model can only propose. The card shows the exact change, and the listing page stays as it was until you click Confirm.'],
            'page' => 'ai.admin-assistant',
            'params' => ['q' => 'Change the title of listing 12 to "Family estate, one owner"'],
        ],
    ],
    'related' => ['assistant', 'guards'],
];
