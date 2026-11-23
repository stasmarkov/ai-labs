# Security audit summary

## Run
- Status: complete
- Profile: quick
- Scope: {"gitRange":"f4564d2..HEAD"}
- Budget: not set. Max units: 12
- Source ref: db9de986bbc05eb1bfbeaabfd4972652bd993eda
- Run dir: /Users/stasmarkov/work/interview/.ai/security-audit/run-1

## Coverage
- Units: planned 0, not_applicable 0, out_of_scope 11, in_progress 0, covered 11, candidate 0, blocked 0, deferred 32
- Final critic: found_work
- Findings: 0 confirmed, 0 needs validation, 0 rejected
- This is a partial pass.

## Leads
None.

## Not audited
- routes%2Fcatalog.php%23POST%20%2Fapi%2Fdocs%2Fask::app%2FAi%2FAiRunRecorder.php%23answeredByFallback::profile%2Fquick%2Fall-in-scope-subsystems::references%2Fdata-isolation-and-lifecycle.md%23Search%2C%20cache%2C%20and%20index%20ACL%20drift: max_units_cap
- routes%2Fcatalog.php%23POST%20%2Fapi%2Fdocs%2Fask::app%2FServices%2FDocsAnswerService.php%23usingProtocol%28DocsAnswerProtocol%29%20over%20fallback%20stream::profile%2Fquick%2Fall-in-scope-subsystems::references%2Fai-and-llm.md%23Indirect%20injection%20through%20retrieved%20or%20ingested%20content: max_units_cap
- routes%2Fcatalog.php%23POST%20%2Fapi%2Fdocs%2Fask::app%2FServices%2FDocsAnswerService.php%23prompt%20assembly%20for%20fallback%20stream::profile%2Fquick%2Fall-in-scope-subsystems::references%2Fai-and-llm.md%23Sensitive%20context%20extraction: max_units_cap
- routes%2Fcatalog.php%23POST%20%2Fapi%2Fdocs%2Fask::routes%2Fcatalog.php%23throttle%3Adocs-ask::profile%2Fquick%2Fall-in-scope-subsystems::references%2Fresource-exhaustion-and-availability.md%23Retry%20storm%20and%20fail-open%20amplification: max_units_cap
- routes%2Fcatalog.php%23POST%20%2Fapi%2Fdocs%2Fask::app%2FAi%2FAiRunRecorder.php%23fallbackTo::profile%2Fquick%2Fall-in-scope-subsystems::references%2Fattack-classes.md%23Chained%20vulnerabilities%20and%20trust%20boundaries: max_units_cap
- routes%2Fcatalog.php%23POST%20%2Fapi%2Fassistant%2Fask::app%2FServices%2FBuyerAssistantService.php%23injection%20guard%20before%20LocalFallback%3A%3Astream::profile%2Fquick%2Fall-in-scope-subsystems::references%2Fai-and-llm.md%23Prompt%20role%20and%20provenance%20confusion: max_units_cap
- routes%2Fcatalog.php%23POST%20%2Fapi%2Fassistant%2Fask::routes%2Fcatalog.php%23throttle%3Aassistant-ask::profile%2Fquick%2Fall-in-scope-subsystems::references%2Fresource-exhaustion-and-availability.md%23Retry%20storm%20and%20fail-open%20amplification: max_units_cap
- routes%2Fcatalog.php%23GET%20%2Fapi%2Flistings%2Fnl::app%2FHttp%2FRequests%2FNlSearchRequest.php%23rules::profile%2Fquick%2Fall-in-scope-subsystems::references%2Fattack-classes.md%23Access%20control: hunter_result_invalid
- routes%2Fcatalog.php%23POST%20%2Fapi%2Fmoderation::app%2FServices%2FModerationService.php%23thresholdFor::profile%2Fquick%2Fall-in-scope-subsystems::references%2Fattack-classes.md%23Business%20logic: max_units_cap
- routes%2Fcatalog.php%23POST%20%2Fapi%2Fmoderation::app%2FServices%2FModerationService.php%23MAX_ATTEMPTS%20loop::profile%2Fquick%2Fall-in-scope-subsystems::references%2Fresource-exhaustion-and-availability.md%23Retry%20storm%20and%20fail-open%20amplification: max_units_cap
- routes%2Fcatalog.php%23POST%20%2Fapi%2Fmoderation::app%2FServices%2FModerationService.php%23moderate%20%24model%20policy%20off::profile%2Fquick%2Fall-in-scope-subsystems::references%2Fattack-classes.md%23Access%20control: max_units_cap
- routes%2Fcatalog.php%23POST%20%2Fapi%2Fmoderation::app%2FAi%2FModerationAgent.php%23classify%20data%20block::profile%2Fquick%2Fall-in-scope-subsystems::references%2Fai-and-llm.md%23Indirect%20injection%20through%20retrieved%20or%20ingested%20content: max_units_cap
- routes%2Fweb.php%23GET%20%2Fai%2Fruns%2F%7BinvocationId%7D::app%2FHttp%2FControllers%2FShowLabRunController.php%23__invoke::profile%2Fquick%2Fall-in-scope-subsystems::references%2Fattack-classes.md%23Feature%20abuse%20and%20data%20leakage: max_units_cap
- routes%2Fweb.php%23GET%20%2Fai%2Fruns%2F%7BinvocationId%7D::app%2FDTOs%2FAiRunSummary.php%23toArray%20fallback%20fields::profile%2Fquick%2Fall-in-scope-subsystems::references%2Fdata-isolation-and-lifecycle.md%23Enumeration%20and%20aggregate%20oracles: max_units_cap
- routes%2Fweb.php%23GET%20%2Fai%2Fruns%2F%7BinvocationId%7D::app%2FAi%2FAiRunRecorder.php%23releaseFailure%20stored%20error%20message::profile%2Fquick%2Fall-in-scope-subsystems::references%2Fattack-classes.md%23Cryptography%20and%20secrets: max_units_cap
- routes%2Fweb.php%23GET%20%2Fai%2Fsettings::app%2FServices%2FAiSettingsService.php%23fallback::profile%2Fquick%2Fall-in-scope-subsystems::references%2Fattack-classes.md%23Access%20control: max_units_cap
- app%2FProviders%2FAppServiceProvider.php%23Http%3A%3AglobalMiddleware%28FirstTokenBudget%29::app%2FAi%2FFirstTokenBudget.php%23arm%2Fdisarm%20singleton::profile%2Fquick%2Fall-in-scope-subsystems::references%2Fattack-classes.md%23Chained%20vulnerabilities%20and%20trust%20boundaries: max_units_cap
- app%2FProviders%2FAppServiceProvider.php%23Http%3A%3AglobalMiddleware%28FirstTokenBudget%29::app%2FAi%2FFirstTokenBudget.php%23appliesTo::profile%2Fquick%2Fall-in-scope-subsystems::references%2Fattack-classes.md%23Wildcard: max_units_cap
- app%2FAi%2FFallbackPolicy.php%23reasonFor::app%2FAi%2FFallbackPolicy.php%23reasonFor%20fixed%20vocabulary::profile%2Fquick%2Fall-in-scope-subsystems::references%2Fattack-classes.md%23Business%20logic: max_units_cap
- app%2FAi%2FFallbackPolicy.php%23reasonFor::app%2FAi%2FAiRunRecorder.php%23fallbackFrom::profile%2Fquick%2Fall-in-scope-subsystems::references%2Fattack-classes.md%23Injection: max_units_cap
- app%2FRepositories%2FEloquentAiRunRepository.php%23record::app%2FModels%2FAiRun.php%23%24fillable::profile%2Fquick%2Fall-in-scope-subsystems::references%2Fattack-classes.md%23Injection: max_units_cap
- database%2Fmigrations%2F2026_11_23_150000_add_fallback_columns_to_ai_runs_table.php%23up::database%2Fmigrations%2F2026_11_23_150000_add_fallback_columns_to_ai_runs_table.php%23nullable%20columns::profile%2Fquick%2Fall-in-scope-subsystems::references%2Fdata-isolation-and-lifecycle.md%23Migration%20default%20and%20ownership%20confusion: max_units_cap
- config%2Fai.php%23features.%2A.fallback_model::app%2FAi%2FAiProviders.php%23STREAMED_FALLBACK_FEATURES::profile%2Fquick%2Fall-in-scope-subsystems::references%2Fattack-classes.md%23Business%20logic: max_units_cap
- git-range%23f4564d2..db9de98::config%2Fai.php%23providers%20and%20features::profile%2Fquick%2Fall-in-scope-subsystems::references%2Fattack-classes.md%23Obvious%20things: max_units_cap
- app%2FAi%2FLocalFallback.php%23LocalFallback::tests%2FFeature%2FLocalModelFallbackTest.php%23coverage%20gaps::profile%2Fquick%2Fall-in-scope-subsystems::references%2Fattack-classes.md%23Wildcard: max_units_cap
- app%2FConsole%2FCommands%2FLlmBenchCommand.php%23llm%3Abench::app%2FSupport%2FLlmFallbackBench.php%23runEval::profile%2Fquick%2Fall-in-scope-subsystems::references%2Fattack-classes.md%23Resource%20and%20file%20handling: max_units_cap
- app%2FConsole%2FCommands%2FLlmBenchCommand.php%23llm%3Abench::app%2FConsole%2FCommands%2FLlmBenchCommand.php%23environment%28%27local%27%29::profile%2Fquick%2Fall-in-scope-subsystems::references%2Fattack-classes.md%23Business%20logic: max_units_cap
- app%2FConsole%2FCommands%2FNlEvalCommand.php%23nl%3Aeval::app%2FDTOs%2FNlSearchQuery.php%23fallback::profile%2Fquick%2Fall-in-scope-subsystems::references%2Fattack-classes.md%23Access%20control: max_units_cap
- bench%2Fresults%2Fllm-fallback%2F2026-11-23%2Frun.json::app%2FSupport%2FLlmFallbackBench.php%23result%20payload::profile%2Fquick%2Fall-in-scope-subsystems::references%2Fattack-classes.md%23Cryptography%20and%20secrets: max_units_cap
- resources%2Fguides%2Flocal-models.php%23guide::resources%2Fjs%2Fai%2FPages%2FLab%2FGuide.vue%23v-html::profile%2Fquick%2Fall-in-scope-subsystems::references%2Fclient-side.md%23DOM-based%20XSS: max_units_cap
- Abandoned%20main%20Ollama%20stream%20after%20a%20first-token-budget%20fallback::LocalFallback%3A%3Astream%20to%20the%20Ollama%20upstream%20connection::profile%2Fquick%2Fall-in-scope-subsystems::Resource%20exhaustion%20and%20availability: quick_profile_final_critic
- GET%20%2Fai%20%28anonymous%20lab%20overview%2C%20recent%20runs%20table%29::LabOverviewService%20recent%20runs%20through%20EloquentAiRunRepository%3A%3AtoSummary%2C%20now%20carrying%20fallback_from_model%20and%20fallback_reason%20plus%20invocation%20ids%2C%20rendered%20by%20Overview.vue::profile%2Fquick%2Fall-in-scope-subsystems::Enumeration%20and%20aggregate%20oracles: quick_profile_final_critic
- routes%2Fcatalog.php%23POST%20%2Fapi%2Fadmin-assistant%2Fask::app%2FHttp%2FMiddleware%2FEnsureAdminAssistant.php%23handle::profile%2Fquick%2Fall-in-scope-subsystems::references%2Fai-and-llm.md%23Action-confirmation%20and%20approval%20binding: outside_scope
- routes%2Fweb.php%23GET%20%2Fai%2Fchanges::app%2FHttp%2FMiddleware%2FEnsureMcpServer.php%23handle::profile%2Fquick%2Fall-in-scope-subsystems::references%2Fattack-classes.md%23Access%20control: outside_scope
- routes%2Fcatalog.php%23%2Fapi%2Fplayground::app%2FHttp%2FMiddleware%2FEnsureLocalLab.php%23handle::profile%2Fquick%2Fall-in-scope-subsystems::references%2Fattack-classes.md%23Access%20control: outside_scope
- routes%2Fcatalog.php%23POST%20%2Fapi%2Fresearch::routes%2Fcatalog.php%23research%20middleware::profile%2Fquick%2Fall-in-scope-subsystems::references%2Fai-and-llm.md%23Unbounded%20delegated%20action%20loops: outside_scope
- routes%2Fweb.php%23POST%20%2Fai%2Fphotos%2F%7Bphoto%7D%2Fextract::app%2FHttp%2FMiddleware%2FLimitCloudAi.php%23handle%20photo-vision::profile%2Fquick%2Fall-in-scope-subsystems::references%2Fattack-classes.md%23Resource%20and%20file%20handling: outside_scope
- routes%2Fcatalog.php%23GET%20%2Fexports%2F%7Bid%7D::routes%2Fcatalog.php%23exports%20middleware::profile%2Fquick%2Fall-in-scope-subsystems::references%2Fattack-classes.md%23Access%20control: outside_scope
- app%2FHttp%2FMiddleware%23RefuseCrossSiteRequests%2CLimitCloudAi::app%2FHttp%2FMiddleware%2FLimitCloudAi.php%23handle::profile%2Fquick%2Fall-in-scope-subsystems::references%2Fresource-exhaustion-and-availability.md%23Quota-accounting%20scope%20and%20reset%20gaps: outside_scope
- routes%2Fweb.php%23PUT%20%2Fai%2Fsettings%2F%7Bfeature%7D::app%2FHttp%2FMiddleware%2FEnsureLocalSettings.php%23handle::profile%2Fquick%2Fall-in-scope-subsystems::references%2Fattack-classes.md%23Access%20control: outside_scope
- routes%2Fdebug.php%23debug%20routes::app%2FHttp%2FMiddleware%2FEnsureDebugRoutes.php%23handle::profile%2Fquick%2Fall-in-scope-subsystems::references%2Fattack-classes.md%23Obvious%20things: outside_scope
- routes%2Fcatalog.php%23GET%20%2Fapi%2Flistings%2Fsemantic::routes%2Fcatalog.php%23search%20throttles::profile%2Fquick%2Fall-in-scope-subsystems::references%2Fattack-classes.md%23Injection: outside_scope
- .github%2Fworkflows%2Fci.yml%23workflow::.ddev%2Fcommands%2Fhost%2Fsetup%23setup::profile%2Fquick%2Fall-in-scope-subsystems::references%2Fsupply-chain-and-release.md%23Untrusted%20code%20in%20a%20privileged%20workflow: outside_scope

## Next
None.

## Limits
- One run is not exhaustive.
- The audit never runs target code. A lead is confirmed only when two independent verifiers agree on static source evidence; upstream confirms only with a locally observed result. Leads that need running the code stay needs_validation.
- Details live in the run dir, which is VCS-ignored.
