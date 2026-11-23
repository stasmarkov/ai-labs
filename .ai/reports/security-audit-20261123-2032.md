# Security audit summary

## Run
- Status: complete
- Profile: standard
- Scope: {"gitRange":"f4564d2..HEAD"}
- Budget: not set. Max units: 40
- Source ref: b0cbfe294a1a2a41d3599e8eb93313639dc2776b
- Run dir: /Users/stasmarkov/work/interview/.ai/security-audit/run-2

## Coverage
- Units: planned 0, not_applicable 0, out_of_scope 6, in_progress 0, covered 33, candidate 0, blocked 0, deferred 1
- Final critic: clean
- Findings: 0 confirmed, 0 needs validation, 0 rejected
- This is a partial pass.

## Leads
None.

## Not audited
- Streamed%20AI%20endpoints%20%28describe%2C%20docs-ask%2C%20assistant-ask%29%3A%20main%20Ollama%20stream%20abandoned%20on%20busy%20fallback%20or%20client%20disconnect::LocalFallback%3A%3Astream%20abandon%20path%20%28FirstTokenBudgetExceeded%20thrown%20inside%20foreach%20over%20the%20main%20stream%29%20and%20the%20finally%20blocks%20that%20only%20disarm%20and%20release::app%2FAi%20streamed%20fallback::Detached%20work%20after%20cancellation: max_units_cap
- routes%2Fweb.php%23POST%20%2Fai%2Fchanges%2F%7Bid%7D%2Fconfirm::app%2FHttp%2FMiddleware%2FEnsureMcpServer.php%23handle::app%2FMcp::references%2Fattack-classes.md%23Access%20control: outside_scope
- routes%2Fcatalog.php%23POST%20%2Fapi%2Fadmin-assistant%2Fask::app%2FHttp%2FMiddleware%2FEnsureAdminAssistant.php%23handle::app%2FHttp%2FControllers::references%2Fattack-classes.md%23Access%20control: outside_scope
- routes%2Fcatalog.php%23POST%20%2Fapi%2Fplayground%2Fruns::app%2FHttp%2FMiddleware%2FEnsureLocalLab.php%23handle::app%2FHttp%2FControllers::references%2Fattack-classes.md%23Access%20control: outside_scope
- routes%2Fcatalog.php%23GET%20%2Fexports%2F%7Bid%7D::routes%2Fcatalog.php%23ExportFileService%3A%3AID_PATTERN::app%2FHttp%2FControllers::references%2Fattack-classes.md%23Feature%20abuse%20and%20data%20leakage: outside_scope
- routes%2Fdebug.php%23debug%20group::app%2FHttp%2FMiddleware%2FEnsureDebugRoutes.php%23handle::routes%2Fdebug.php::references%2Fattack-classes.md%23Obvious%20things: outside_scope
- routes%2Fcatalog.php%23POST%20%2Fapi%2Fresearch::routes%2Fcatalog.php%23throttle%3Aresearch%2CLimitCloudAi%3Aresearch::app%2FHttp%2FControllers::references%2Fresource-exhaustion-and-availability.md%23Retry%20storm%20and%20fail-open%20amplification: outside_scope

## Next
None.

## Limits
- One run is not exhaustive.
- The audit never runs target code. A lead is confirmed only when two independent verifiers agree on static source evidence; upstream confirms only with a locally observed result. Leads that need running the code stay needs_validation.
- Details live in the run dir, which is VCS-ignored.
