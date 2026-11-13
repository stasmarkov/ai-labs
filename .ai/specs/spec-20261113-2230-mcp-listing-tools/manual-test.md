# Manual test plan: MCP server for the listing tools (SL-35)

Base URL: https://ai-lab.ddev.site

## Preconditions

- `ddev start` is running, `APP_ENV=local`, and `AI_MCP_SERVER` is not set to `false` in `.env`.
- Migrations are run: `ddev artisan migrate`.
- The database holds the 50 000 demo listings. Listing 1 has no title and no tags before you start (check in Step 5).
- No login is needed. Use a browser for the pages and a terminal in the project folder for the commands.
- Steps 1 to 8 talk to the server over stdin the way an MCP client does. Define this helper once in your shell. It sends the handshake, then the one request you pass in, and prints one JSON line per reply.

```sh
mcp() {
  { printf '%s\n' '{"jsonrpc":"2.0","id":1,"method":"initialize","params":{"protocolVersion":"2025-06-18","capabilities":{},"clientInfo":{"name":"manual","version":"1"}}}' \
      '{"jsonrpc":"2.0","method":"notifications/initialized"}' "$1"; sleep 3; } \
  | ddev exec php artisan mcp:start listings
}
```

The request in `$1` uses `"id":2`. Look at the reply with `"id":2`.

Rollback for the whole plan (resets listing 1 and removes the test changes):

Tags are rows of `listing_tags`, not a column of `listings`.

```sh
ddev artisan tinker --execute 'App\Models\Listing::find(1)->update(["title" => null]); App\Models\ListingTag::where("listing_id", 1)->delete();'
```

## Step 1

Start the server and say hello (README command, AC-1).

```sh
mcp '{"jsonrpc":"2.0","id":2,"method":"ping"}'
```

Expected:
- The first line is the `initialize` reply with `serverInfo` `{"name":"ai-lab listings","version":"1.0.0"}` and an `instructions` text that tells the client to propose a change and let the developer confirm it on the local page.
- Every output line is valid JSON. Nothing else is printed (no warnings, no stack traces).

## Step 2

List the tools (AC-1).

```sh
mcp '{"jsonrpc":"2.0","id":2,"method":"tools/list"}'
```

Expected:
- Six tools: `search_listings`, `get_listing`, `count_by_tag`, `propose_title_change`, `propose_tag`, `change_status`.
- Each has a description and an `inputSchema`.
- `search_listings`, `get_listing`, `count_by_tag` and `change_status` have `"annotations":{"readOnlyHint":true,...}`.
- `propose_title_change` and `propose_tag` have no `readOnlyHint`.

## Step 3

Read tools return capped results and are logged (AC-2, NFR-2).

```sh
mcp '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"count_by_tag","arguments":{"tag":"family-car"}}}'
mcp '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"get_listing","arguments":{"id":1}}}'
mcp '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"search_listings","arguments":{}}}'
ddev artisan tinker --execute 'echo App\Models\McpToolCall::latest()->take(3)->get(["tool","outcome","duration_ms"])->toJson();'
```

Expected:
- `count_by_tag` returns text with `family-car` and a count.
- `get_listing` returns listing 1 (Ford Fiesta) with no `isError`.
- `search_listings` returns `total` 50000 and at most 10 rows. Each description is at most 500 characters, each tag list at most 20 entries, and the whole text is at most 4000 characters.
- The log query shows three rows with outcome `ok` and a `duration_ms`.

## Step 4

Invalid input is an error, not a crash (AC-2).

```sh
mcp '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"get_listing","arguments":{"id":"abc"}}}'
```

Expected:
- The result has `"isError":true` and the text `The id argument must be a positive integer.`
- The newest `mcp_tool_calls` row has outcome `error`.

## Step 5

Propose changes; the listing stays untouched (AC-3).

```sh
ddev artisan tinker --execute 'echo json_encode(["title" => App\Models\Listing::find(1)->title, "tags" => App\Models\ListingTag::where("listing_id", 1)->pluck("tag")->all()]);'
mcp '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"propose_title_change","arguments":{"id":1,"title":"Verify title, one owner"}}}'
mcp '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"propose_tag","arguments":{"id":1,"tag":"verify-tag"}}}'
mcp '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"propose_title_change","arguments":{"id":999999999,"title":"Nope"}}}'
ddev artisan tinker --execute 'echo json_encode(["title" => App\Models\Listing::find(1)->title, "tags" => App\Models\ListingTag::where("listing_id", 1)->pluck("tag")->all()]);'
```

Expected:
- First and last tinker lines are the same: `{"title":null,"tags":[]}`.
- Each of the two valid proposals returns `status` `pending`, a `change_id` (a UUID), a `preview`, an `expires_at`, a note that the confirmation needs the developer in the browser, and a `url` of the form `https://ai-lab.ddev.site/ai/changes/<uuid>`. Copy both URLs: the first is change A (title), the second change B (tag).
- The proposal for listing 999999999 returns `isError` true and stores nothing.
- `mcp_tool_calls` has two `proposed` rows.

## Step 6

Confirm change A in the browser (AC-4, AC-5, NFR-3).

1. Open change A's URL.
2. Check the page, then click Confirm.
3. Reload `https://ai-lab.ddev.site/listings/1`.

Expected:
- The page answers 200 and shows the preview, "The listing now" (the current title and tags, which may differ from the preview's snapshot), "Proposed by propose_title_change" with its arguments, and Confirm and Reject buttons. The lab nav has a Changes entry.
- After Confirm the page shows `Status: confirmed` and `Applied.`
- Listing 1 now has the title `Verify title, one owner`. It was written once.
- `https://ai-lab.ddev.site/ai/changes` lists the change under Decided.

Then check the list itself (recorded in the ship checks). Propose two more changes (Step 5 commands with a new title and tag) and open `/ai/changes`.

Expected:
- Each pending change shows its MCP tool name (`propose_title_change`, `propose_tag`), the listing, the preview (a title change reads `"old" to "new"`, and "make model" stands in for an empty old title), the listing's current title and tags, and Confirm and Reject buttons.
- Click Reject on one and Confirm on the other. Each moves from "Waiting for a decision" to "Decided" without a manual reload.
- Open one change in two tabs, click Confirm in tab 1, then Confirm in tab 2: tab 2 shows "no longer pending" (network 409 `change_not_pending`) and then refreshes to `confirmed`.

## Step 7

Reject change B (AC-5).

1. Open change B's URL and click Reject.
2. Check the listing tags.

Expected:
- The page shows status `rejected`.
- `ddev artisan tinker --execute 'echo App\Models\ListingTag::where("listing_id", 1)->count();'` prints `0`.

## Step 8

Status tool, and no way to confirm from the client (AC-4, AC-6).

```sh
mcp '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"change_status","arguments":{"id":"<change A id>"}}}'
mcp '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"change_status","arguments":{"id":"<change B id>"}}}'
mcp '{"jsonrpc":"2.0","id":2,"method":"tools/call","params":{"name":"confirm_change","arguments":{"id":"<change A id>"}}}'
```

The argument is `id`.

Expected:
- A: `status` `confirmed` with a `result` that has `status` `applied`, `changed` true and `new_title` `Verify title, one owner`.
- B: `status` `rejected`.
- The third call returns the JSON-RPC error `Tool [confirm_change] not found.`

## Step 9

Edge cases and the confirmation checks on the endpoint (AC-5, NFR-1, NFR-3). Use change A (already confirmed). The decision endpoints need `Sec-Fetch-Site: same-origin`, an `Origin` equal to `APP_URL` when one is sent, the CSRF header (or the same-origin header) and the one-time nonce that the change page puts into your session (C-17).

```sh
A=<change A id>; U=https://ai-lab.ddev.site
curl -sk -o /dev/null -w '%{http_code}\n' $U/ai/changes/not-a-uuid
curl -sk -o /dev/null -w '%{http_code}\n' $U/ai/changes/00000000-0000-0000-0000-000000000000
curl -sk -w '\n%{http_code}\n' -X POST -H 'Accept: application/json' $U/ai/changes/$A/confirm
curl -sk -w '\n%{http_code}\n' -X POST -H 'Sec-Fetch-Site: same-origin' -H 'Accept: application/json' $U/ai/changes/$A/confirm
```

Expected, in order:
- 404 (not a UUID).
- 404 (unknown UUID on the show page).
- 419 (no CSRF token, no same-origin header).
- 422 with "The nonce field is required." (the same-origin header passes Laravel's own check, then the nonce is missing).

Now the case that matters, a client that fetches the page itself. Propose a new change, copy its id as `C`, then:

```sh
J=$(mktemp); curl -sk -c $J -b $J $U/ai/changes/$C -o /dev/null
X=$(awk '$6=="XSRF-TOKEN"{print $7}' $J | python3 -c 'import sys,urllib.parse;print(urllib.parse.unquote(sys.stdin.read().strip()))')
curl -sk -b $J -w '\n%{http_code}\n' -X POST -H 'Content-Type: application/json' -H 'Accept: application/json' -H "X-XSRF-TOKEN: $X" -d '{"nonce":"0123456789abcdef0123456789abcdef"}' $U/ai/changes/$C/confirm
curl -sk -b $J -w '\n%{http_code}\n' -X POST -H 'Content-Type: application/json' -H 'Accept: application/json' -H "X-XSRF-TOKEN: $X" -H 'Sec-Fetch-Site: same-origin' -H "Origin: $U" -d '{"nonce":"0123456789abcdef0123456789abcdef"}' $U/ai/changes/$C/confirm
```

Expected: 403 `A change is confirmed or rejected only from its page in the browser.` (no `Sec-Fetch-Site`, even with the cookie and the CSRF token), then 403 `confirmation_refused` (a wrong nonce). Change C stays pending. A request with another session's cookie jar and the right nonce also gets 403.

Known limit (C-17): a client that has a shell can fetch the page, read the nonce from the page data and forge the `Sec-Fetch-Site` and `Origin` headers, because curl can send any header. The checks keep a client that only speaks MCP, or that does not set out to imitate a browser, from confirming. They are not a barrier against a hostile local process, and they do not prove a person clicked.

Two tabs: propose a new title, open its page in two tabs, click Confirm in tab 1, then Confirm in tab 2.

Expected: tab 2 shows an error and the network call is 409 with `change_not_pending`. The title is written once. Confirming or rejecting change B (rejected) also gives 409.

Failed case: propose `propose_title_change` for a listing you then delete or soft-remove, or let a change pass `expires_at`, then click Confirm. Expected: 409 for an expired change; for a deleted listing the change ends `failed` (it shows the validation message) and no exception page appears. A change whose write throws ends `failed` with "the change could not be applied", and a change stuck in `applying` for two minutes is stored as `failed` when read. These are covered by `tests/Feature/McpChangesTest.php`; do them by hand only if you want to see it.

## Step 10

The gate (AC-7).

```sh
ddev exec env AI_MCP_SERVER=false php artisan mcp:start listings; echo "exit=$?"
ddev exec env APP_ENV=production php artisan mcp:start listings; echo "exit=$?"
```

Expected: both print `The ai-lab MCP server runs only locally with AI_MCP_SERVER on.` and `exit=1`.

For the routes: set `AI_MCP_SERVER=false` in `.env`, run `ddev artisan config:clear`, then open `https://ai-lab.ddev.site/ai/changes` and `https://ai-lab.ddev.site/ai/changes/<change A id>`.

Expected: both answer 404, and the lab nav has no Changes entry. Restore `.env` and run `ddev artisan config:clear` again.

## Step 11

The guide and docs (AC-8).

1. Open `https://ai-lab.ddev.site/ai/guides/mcp`.
2. Click through the tabs.
3. Open `README.md` and `docs/learning-guide.md`.

Expected:
- The page answers 200 with six tabs: How it works, Implementation, Modify, Support, Numbers, Experiments. No `SL-` ids or file paths in the text.
- README shows `claude mcp add ai-lab -- ddev exec php artisan mcp:start listings`, a Claude Desktop JSON snippet, and the inspector command.
- The learning guide has a section "MCP server for the listing tools".

Connect check (optional): run the `claude mcp add` command, then `/mcp` in Claude Code. `ai-lab` shows connected with 6 tools.

## Step 12

Admin assistant and other lab pages unchanged (MNC-1, MNC-2).

1. Open `https://ai-lab.ddev.site/ai`, `/ai/assistant`, `/ai/settings` and `/ai/admin-assistant`.
2. On `/ai/admin-assistant`, type "How many listings have the tag family-car?" (needs a configured provider).

Expected:
- All four pages answer 200. The only nav difference is the new Changes entry.
- The admin assistant answers with a count as before, and a write request there still asks for its own confirmation.

## Step 13

Public pages, asset fingerprint, parity (MNC-3).

```sh
bench/asset-fingerprint.sh
PARITY_INSECURE=1 bench/parity.sh capture && git diff -U0 bench/parity
curl -sk -o /dev/null -w '%{http_code}\n' https://ai-lab.ddev.site/
curl -sk -o /dev/null -w '%{http_code}\n' https://ai-lab.ddev.site/listings
```

Expected:
- The fingerprint matches the baseline in `BENCHMARKS.md` (`app-DNob7CFE.css`, `app-PTzu1QaN.js`).
- Only the `captured=` line differs in the parity diff.
- Both pages answer 200.

## Step 14

The suite is offline (MNC-4).

```sh
ddev artisan test --compact
```

Expected: all green. No test makes a network call or starts a process.
