# Manual test plan: lab pages that teach (SL-53)

## Step 1

Layout (AC-1, AC-2).

```sh
PW=$(dirname $(ls ~/.npm/_npx/*/node_modules/playwright/package.json | head -1)) node bench/ui-audit.mjs
```

Then open `/ai/moderation` and `/ai/describe` in the browser at full width, and again with the window narrowed to about 1024 px.

Expected:
- The script exits 0.
- On both pages the listing select is full width, with the example buttons on their own row below it.

## Step 2

Teaching content (AC-3, AC-4, AC-5, NFR-1).

1. Open `/ai/playground`, `/ai/research` and `/ai/moderation`.
2. On each, open the four parts: How it works, Build it yourself, Watch out for, Same pattern elsewhere.
3. Click a guide link from one of them.
4. Tab through the page with the keyboard.

Expected:
- Each part has content.
- Every field shows an example.
- The example chips fill the form.
- The link lands on the matching tab of the guide, and the guide shows the same three sections.
- Keyboard focus reaches every part.

Optional cleanup. This deletes **all** local playground runs and prompts, which are dev data only:

```sh
ddev artisan tinker --execute 'App\Models\PlaygroundRun::query()->delete(); App\Models\PlaygroundPrompt::query()->delete();'
```

## Step 3

Nothing else changed (MNC-2).

```sh
bench/asset-fingerprint.sh
PARITY_INSECURE=1 bench/parity.sh capture && git diff -U0 bench/parity
ddev artisan test --compact
```

Expected:
- The fingerprint is unchanged.
- Parity differs only in `captured=`.
- The suite passes.
