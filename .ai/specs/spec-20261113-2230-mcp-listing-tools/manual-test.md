# Manual test plan: MCP server for the listing tools (SL-35)

Base URL: https://ai-lab.ddev.site. Needs `APP_ENV=local` and the migrations run (`ddev artisan migrate`).

## Step 1

Connect Claude Code (AC-1, AC-2).

```sh
claude mcp add ai-lab -- ddev exec -T php artisan mcp:start listings
```

Use the command README gives if it differs. Then, in Claude Code:

1. Run `/mcp`.
2. Ask "Using ai-lab, how many listings have the tag family-car, and show me listing 12".

Expected:
- `ai-lab` is connected with 6 tools.
- `count_by_tag` and `get_listing` run, and their results are capped like the admin assistant's.
- `mcp_tool_calls` has an `ok` row for each.

## Step 2

Propose and confirm a change (AC-3, AC-4, AC-5, AC-6).

1. Ask "Using ai-lab, change the title of listing 12 to Family estate, one owner".
2. Open the link it returns, or `/ai/changes`.
3. Click Reject, then ask for the same change again and click Confirm.
4. Ask "what is the status of that change?".

Expected:
- The tool answers with a pending change and a link. `/listings/12` is unchanged until Confirm.
- Reject leaves the page unchanged. Confirm sets the heading.
- `change_status` says `confirmed`.
- Claude Code has no tool that confirms.

Rollback:

```sh
ddev artisan tinker --execute 'App\Models\Listing::find(12)->update(["title" => null]);'
```

## Step 3

The gate (AC-7).

```sh
ddev exec -T env AI_MCP_SERVER=false php artisan mcp:start listings; echo "exit=$?"
```

Expected: a message and `exit=1`.

## Step 4

Nothing else changed (MNC-1 to MNC-4).

```sh
ddev artisan test --compact
bench/asset-fingerprint.sh
PARITY_INSECURE=1 bench/parity.sh capture && git diff -U0 bench/parity
```

Expected:
- The suite is green and offline.
- The fingerprint is unchanged.
- Only the `captured=` line differs.
- `/ai/admin-assistant` works as before.
