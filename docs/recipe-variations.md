# AI recipe variations

POST `/households/{household}/recipes/{parent}/variation` accepts `changes` (1–4,000 characters), `request_id` (32 lowercase hexadecimal characters, new for each intentional request), and optional cumulative `answers` (`question`/`answer` pairs). Returns the ordinary import `{recipe, questions}` shape. A client-supplied draft or parent is never authoritative. The server reloads the live source under household membership authorization on every request, including cache hits. Provider input preserves ingredient group titles and structured step actions (including temperatures and timers) but strips database identifiers and unrelated household data.

Generation saves a private review draft, not a recipe. Only approval through the ordinary recipe-create editor creates the variation. Originals are never written by generation. Drafts have `source_type: ai_variation`, server-selected parent, a Variation tag, no photos/nutrition copied from the original, and a stable import key for save retries. A new request ID deliberately creates a distinct variation. Generation retries, including earlier clarification rounds, reuse the cached result; up to eight distinct rounds are allowed per request. Cancellation leaves no recipe. Private draft cache is under `storage/variation-drafts` (excluded from Git and public HTTP by the existing storage protection); remove old draft files under the deployment's retention policy only when no request is using them.

## Model and access policy

The provider remains the existing OpenAI Responses integration with `store:false`. No new key, fallback provider, or automatic paid fallback is introduced. `recipe_ai.variation_model` independently selects the model; default `gpt-5.4-mini`. `variation_access` defaults to `disabled`, so deployment alone does not enable spending.

- `disabled`: generation unavailable.
- `ai_images`: exactly the existing server-side `HouseholdService::requireAiImages` family allowlist.
- `all_members`: ordinary household members, but only when `variation_billing` explicitly equals `complimentary_verified`.

`variation_billing` defaults to `paid`; paid, missing, or unknown values force the image allowlist even if `all_members` is configured. Before opting into complimentary access, the operator must verify account enrollment, model eligibility, daily limits, data-sharing terms and overage behavior. A model listed as eligible does not prove this account has free requests. No runtime claim of free usage or automatic billing guarantee is made. The endpoint does not alter billing or data-sharing settings.

## Validation

Run `php tests/recipe_variation.php` for mocked generation, cache isolation/retry, authorization ordering and access policy checks. No external AI call is used. Run relation/editor suites plus standard server validation. Existing migrations already contain parent relationships and import-key uniqueness; no schema migration is required. Actual database concurrency/foreign-key behavior requires the existing MySQL integration suite. No new schema migration is required. Backend main deployment uses the standard Linux GitHub Actions workflow; mobile builds are separate.

## Main integration (2026-10-15)

Merged the recipe variations feature into backend main for hosted deployment. Local validation passed all 535 PHP fixture checks, tracked PHP syntax checks, and all 63 Python deployment tests. Tests used mocked AI responses and sent no email. Deployment preserves existing variation access and billing configuration.
