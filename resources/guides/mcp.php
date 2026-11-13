<?php

declare(strict_types=1);

return [
    'kind' => 'foundation',
    'title' => 'MCP server for the listing tools',
    'summary' => 'The listing tools of the admin assistant, published to Claude Code or Claude Desktop over a local MCP server. Reads run at once; a change is only proposed and the developer confirms it in the browser. Local machine only.',
    'works' => [
        [
            'title' => 'A server groups tools and describes itself',
            'body' => "MCP (Model Context Protocol) is a JSON-RPC protocol that lets a client, such as Claude Code, discover and call tools of a server. A **server** has a name, a version and instructions for the client's model. A **tool** has a name, a description, an input schema and optional **annotations**: `IsReadOnly` tells the client the call changes nothing. This server is `ai-lab listings` with six tools, and its instructions say that a change can only be proposed.",
            'snippet' => 'App\Mcp\Servers\ListingsServer',
        ],
        [
            'title' => 'A client starts the server over stdio',
            'body' => 'The server is registered as a local one, so a client starts it as a process: `php artisan mcp:start listings`. The client writes JSON-RPC lines to its standard input, the server answers on standard output, and nothing else may be printed there. No port is opened and no login is needed, which is why it runs only on the local machine. The gate stops the command with exit code 1 outside the local environment or with `AI_MCP_SERVER=false`.',
            'snippet' => 'routes/ai.php',
        ],
        [
            'title' => 'A read tool wraps an admin assistant tool',
            'body' => "`search_listings`, `get_listing` and `count_by_tag` build their input schema from the admin tool's parameters, validate with the admin tool's own `validate`, and return the same capped result (4000 characters, at most 10 rows, descriptions cut at 500, 20 tags). A refused call returns an MCP error with the messages, and every call is logged with its outcome.",
            'snippet' => 'App\Mcp\Tools\AdminToolWrapper::handle',
        ],
        [
            'title' => 'A write tool only proposes',
            'body' => '`propose_title_change` and `propose_tag` validate the change and store it as a **pending change** with a preview and a 30 minute expiry. The listing is not touched. The reply holds the change id, the preview, the status `pending` and a link to the confirmation page, and says that the developer must confirm it in the browser. The name starts with `propose_` so a model reading the tool list knows that calling it changes nothing.',
            'snippet' => 'App\Mcp\Tools\ProposeChangeTool::handle',
        ],
        [
            'title' => 'The confirmation is out of band, in the browser',
            'body' => "The protocol library has no way to ask the human through the client, and a client's own permission prompt can be set to always allow. A confirm tool would let the model confirm its own proposal. So the only way to apply a change is a click on the Changes page, which posts to the confirm endpoint. That endpoint answers only a request the browser marks `Sec-Fetch-Site: same-origin` (and, when it sends an Origin, the app's own), and only with the confirmation nonce: a random value made when the page is rendered, kept in the developer's session, checked in constant time and good once. A client that only talks MCP never gets a session or that header, so it cannot confirm. No MCP tool can confirm, reject or apply a change.\n\n**Residual risk:** these are request headers and a session value, not proof that a person clicked. A client that can run a shell could fetch the page with curl, read the nonce and send the same headers, and a client that drives a full headless browser could click Confirm. So treat a client with that power as trusted, and read the preview and the listing's current state before you confirm.",
            'snippet' => 'App\Services\PendingChangeDecisionService::confirm',
        ],
        [
            'title' => 'Confirm applies once, then the model can read the outcome',
            'body' => 'Confirm claims the change with one conditional update, so two clicks cannot both write. It validates again, writes in a transaction through the same code the admin assistant uses, and ends as `confirmed` with the result, or as `failed` when the change no longer fits (a deleted listing, for example). A change that is decided or expired answers 409 `change_not_pending`. The model asks `change_status` to see the outcome.',
            'snippet' => 'App\Mcp\Tools\ChangeStatusTool::handle',
        ],
    ],
    'failures' => [
        [
            'title' => 'The client shows no tools or cannot start the server',
            'body' => "**Cause:** the command is wrong, DDEV is not running, or something other than JSON reaches standard output. A client that cannot parse a line drops the connection.\n\n**Fix:** run `ddev start`, then try the command by hand or with `ddev artisan mcp:inspector listings`. The inspector shows the exact error and the raw messages.",
        ],
        [
            'title' => 'The server stops at start with a message',
            'body' => "**Cause:** the gate is closed: the app is not in the local environment, or `AI_MCP_SERVER=false`.\n\n**Fix:** run it on the local machine with `APP_ENV=local` and remove or set `AI_MCP_SERVER=true`. The Changes page answers 404 for the same reason.",
            'snippet' => 'App\Providers\AppServiceProvider::boot',
        ],
        [
            'title' => 'The model says a change was made, but the listing is the same',
            'body' => "**Cause:** a propose tool only stores a pending change. The model may report the proposal as if it were applied.\n\n**Fix:** open the link from the reply, read the preview and click Confirm. Until then `change_status` says `pending`.",
        ],
        [
            'title' => 'Confirm answers that the change is not pending',
            'body' => "**Cause:** the change was already confirmed or rejected, or it expired after 30 minutes. An overdue change is marked `expired` when someone tries to decide it.\n\n**Fix:** ask the model to propose the change again; the old one stays in the recent list with its outcome.",
        ],
    ],
    'recipes' => [
        [
            'title' => 'Connect Claude Code',
            'body' => "From the project folder, with DDEV running:\n\n```sh\nclaude mcp add ai-lab -- ddev exec php artisan mcp:start listings\n```\n\nThen run `/mcp` in Claude Code: `ai-lab` should list six tools. Ask it to search listings, or to propose a new title and give you the link. (`ddev exec` runs the command in the web container and passes standard input and output through.)\n\n**Check:** `claude mcp list` shows `ai-lab` as connected.",
        ],
        [
            'title' => 'Connect Claude Desktop',
            'body' => "Add the server to `claude_desktop_config.json` (Settings, Developer, Edit config), with the path of this project, and restart Claude Desktop:\n\n```json\n{\n  \"mcpServers\": {\n    \"ai-lab\": {\n      \"command\": \"sh\",\n      \"args\": [\"-c\", \"cd /path/to/ai-lab && ddev exec php artisan mcp:start listings\"]\n    }\n  }\n}\n```\n\n**Check:** the tools icon in a new chat lists `ai-lab`.",
        ],
        [
            'title' => 'Try the tools with the MCP Inspector',
            'body' => "```sh\nddev artisan mcp:inspector listings\n```\n\nThe inspector is a browser page that connects to the server, lists the tools with their schemas and annotations, and lets you call one with your own arguments. Use it to see what a client sees, with no model involved.\n\n**Check:** `tools/list` returns six tools, and `search_listings` with `{\"limit\": 3}` returns three rows.",
        ],
        [
            'title' => 'Add a tool',
            'body' => "1. For a read, write a class that extends the admin tool wrapper and returns the admin tool it publishes; give it a `Name`, a `Description` and `IsReadOnly`.\n2. For a write, extend the propose tool the same way, so it stores a pending change.\n3. List the class in the server's `tools`.\n\nThe schema, the validation and the caps come from the admin tool, so write the rules once there. Never add a tool that confirms or applies a change.\n\n**Check:** the server tests list the tool with its annotation, and a propose tool leaves the listing unchanged.",
            'snippet' => 'App\Mcp\Tools\SearchListingsTool',
        ],
        [
            'title' => 'Change the expiry or switch the server off',
            'body' => "Set `ai.mcp.change_ttl_minutes` in the config for the expiry of a proposal, and `AI_MCP_SERVER=false` to close the gate: the server refuses to start and the Changes page and endpoints answer 404. The model and provider settings do not apply, because no model runs on this side.\n\n**Check:** `ddev artisan mcp:start listings` exits with the gate message.",
        ],
    ],
    'support' => [
        [
            'name' => 'Tool calls and decisions',
            'status' => 'logged',
            'note' => 'Every tool call is a row with the tool, its arguments (cut to 2000 characters), the outcome (`ok`, `error`, `proposed` or `failed`), the duration and the change it proposed. Confirm and reject are recorded on the change itself: status, time and result or error. Rows are not pruned.',
        ],
        [
            'name' => 'Writes and local-only access',
            'status' => 'guarded',
            'note' => 'A listing changes only through the confirm endpoint, on a local app, from a same-origin browser request with the one-time nonce of the page; the endpoints are rate limited. A change stuck in `applying` for two minutes ends as `failed`. Tests cover that no tool writes, that two confirms apply once, and that a deleted listing ends as `failed`.',
        ],
        [
            'name' => 'A proposal looks wrong or the client misbehaves',
            'status' => 'runbook',
            'note' => "1. Open `/ai/changes`: each pending change shows the call that proposed it, with its arguments.\n2. Wrong change: click Reject; nothing is written.\n3. A change was applied by mistake: propose the old value again and confirm it.\n4. The client cannot connect: run the same command with the MCP Inspector and read the first error.",
        ],
    ],
    'numbers' => [
        ['label' => 'Tools published', 'pending' => true],
        ['label' => 'Tool calls that ended in an error, share', 'pending' => true],
    ],
    'experiments' => [
        [
            'title' => 'Propose a change from the client',
            'predictions' => [
                ['id' => 'a', 'text' => 'The listing title changes at once.'],
                ['id' => 'b', 'text' => 'A pending change appears on the Changes page, with a Confirm button, and the listing is unchanged.'],
                ['id' => 'c', 'text' => 'The client asks the developer to confirm and then writes.'],
            ],
            'answer' => ['id' => 'b', 'body' => 'A propose tool only stores a pending change. The page shows the preview and who proposed it; the listing changes after you click Confirm, and not before.'],
            'page' => 'ai.changes',
            'params' => [],
        ],
    ],
    'related' => ['admin-assistant', 'guards'],
];
