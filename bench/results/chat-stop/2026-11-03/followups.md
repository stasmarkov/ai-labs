# Follow-up retrieval examples (2026-11-03)

Three two-turn chats sent to `POST /api/docs/ask` with a `history` of one completed turn. Retrieval runs on the latest question only; history reaches the model as a marked block, not as search input. Run on nginx-fpm with qwen3.5:9b and prompt `docs-answer@v2`. Raw data: `followups.json`.

Right answers: 1 of 3.

| Chat | Follow-up | Earlier turn | Retrieved paths (in order) | Answer right |
|------|-----------|--------------|----------------------------|--------------|
| undo-migration | how do I undo it? | how do I run a migration? | docs/getting-started.md, queues.md, dusk.md, verification.md, ai-sdk.md, passwords.md | no |
| queue-retry | how do I make it retry? | how do I dispatch a job to a queue? | http-client.md, http-client.md, http-client.md, rate-limiting.md, http-client.md, queues.md | no |
| cache-clear | how do I clear it? | how do I cache a value in Laravel? | queues.md, cache.md, horizon.md, prompts.md, queues.md, dusk.md | yes |

## Reading

- undo-migration: "it" carries the subject, and the latest question has none. No migration passage is retrieved, so the model reports that the docs do not cover undoing migrations. It is the honest answer to what was retrieved, and still not the answer to the user.
- queue-retry: "retry" matches the HTTP client's `retry` method. The history knows the subject is a queued job, but search never sees it, so the model answers about `Http::retry`.
- cache-clear: search sees only "how do I clear it?", with no "cache" in it. `cache.md` still ranks second because the vector for "clear it" lands near passages about clearing things (the cache docs have a "Clearing the Cache" section; queue and Horizon passages about clearing jobs fill the other slots). The model then reads "cache" from the history and picks the right passage. The right answer here is luck in retrieval plus history in generation, not retrieval understanding the follow-up.

History helps the model read the question, not find the passage. Rewriting the follow-up into a standalone question before retrieval is the fix, and is out of scope here.
