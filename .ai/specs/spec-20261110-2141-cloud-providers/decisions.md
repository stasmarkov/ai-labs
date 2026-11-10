# Decisions

## Model ids (task 1)

Source: the installed laravel/ai SDK, `vendor/laravel/ai/src/Providers/{Anthropic,OpenAi,Gemini}Provider.php`, whose `cheapestTextModel()` and `defaultEmbeddingsModel()` values are the providers' current ids as shipped with the SDK. Each is overridable by the env variable named in `config/ai.php`.

| Provider | Text (cheapest general) | Embeddings (1024 dimensions) |
|----------|-------------------------|------------------------------|
| anthropic | `claude-haiku-5-5` (`AnthropicProvider::cheapestTextModel`) | none |
| openai | `gpt-6-luna` (`OpenAiProvider::cheapestTextModel`) | `text-embedding-3-small` (`defaultEmbeddingsModel`; `dimensions` is requested as 1024) |
| gemini | `gemini-3.1-flash-lite` (`GeminiProvider::cheapestTextModel`) | `gemini-embedding-2` (`defaultEmbeddingsModel`; `dimensions` is requested as 1024) |

A renamed model id fails at call time (404) and is a guide failure mode.

## Capability table sources

- text, embeddings: the SDK provider classes' interfaces (`TextProvider`, `EmbeddingProvider`) plus the entry's `models`.
- streaming, structured output: true for every text provider.
- local or cloud, key variable: the map in `App\Ai\AiProviders`.

## Resolver

- `forFeature()` throws `ProviderNotEnabledException` (422 `provider_not_enabled`) for a bad override and `ProviderNotConfiguredException` (503 `provider_not_configured`) for a key-less configured cloud provider; `configured()` returns the choice with no check, for pages that must render a misconfiguration.
