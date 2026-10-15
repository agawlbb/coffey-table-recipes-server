# AI recipe variations

POST `/households/{household}/recipes/{parent}/variation` accepts `changes` (1–4,000 characters), `request_id` (32 lowercase hexadecimal characters, new for each intentional request), and optional cumulative `answers` (`question`/`answer` pairs). Returns the ordinary import `{recipe, questions}` shape plus a top-level `change_description`. A client-supplied draft or parent is never authoritative. The server reloads the live source under household membership authorization on every request, including cache hits. Provider input preserves ingredient group titles and structured step actions (including temperatures and timers) but strips database identifiers and unrelated household data.

Generation reviews the complete adaptation: title, description, ingredient quantities and preparation, every instruction, equipment, temperatures, doneness, step actions, prep/cook/total and resting times, yield, servings, notes and categories. Affected details must agree with the new ingredients and method. Variation-specific instructions override ordinary import source-preservation rules, so original timings and handling instructions are not blindly retained.

`change_description` is a required, nonblank string of at most 4,000 characters, in English regardless of the recipe language, explaining actual changes and their cooking implications. It belongs only to the response envelope, never recipe description, notes or database fields. The private draft retry cache includes it; the editor receives it as temporary review information outside the recipe/save payload. Invalid explanations fail generation without poisoning retries. A versioned cache fingerprint prevents pre-explanation drafts from being reused under the updated contract. Ordinary import responses are unchanged.

Generation saves a private review draft, not a recipe. Only approval through the ordinary recipe-create editor creates the variation. Originals are never written by generation. Drafts have `source_type: ai_variation`, server-selected parent, a Variation tag, no photos/nutrition copied from the original, and a stable import key for save retries. A new request ID deliberately creates a distinct variation. Generation retries, including earlier clarification rounds, reuse the cached result; up to eight distinct rounds are allowed per request. Cancellation leaves no recipe. Private draft cache is under `storage/variation-drafts` (excluded from Git and public HTTP by the existing storage protection); remove old draft files under the deployment's retention policy only when no request is using them.

## Model and access policy

The provider remains the existing OpenAI Responses integration with `store:false`. No new key or provider is introduced. `recipe_ai.variation_model` independently selects the model; default `gpt-5.4-mini`.

Variations default to `all_members`: every authenticated household member can generate variations of recipes they can access. Billing configuration does not restrict this access or imply complimentary usage. Existing household membership and source-recipe authorization remain required.

An explicit `variation_access: disabled` still disables generation, and `ai_images` retains the existing image-generation household allowlist when an operator deliberately selects it. Unknown access values fail closed.

## Access correction (2026-10-15)

Enabled variations for all household members by default, as requested. Removed the billing-dependent fallback to the image allowlist. The 43 mocked variation checks and 61 relationship checks passed; no paid provider requests were sent during validation.

## Validation

The complete-adaptation and explanation update passed all 550 PHP fixture checks
and tracked PHP syntax validation locally. Variation fixtures cover changed title,
method, timer and overall timing, explanation validation, clarification/retry
behavior and exclusion from persisted data. Provider responses were mocked; no
live AI calls or emails were sent. Actual AI cooking quality remains for review
when requesting a variation.

Run `php tests/recipe_variation.php` for mocked generation, cache isolation/retry, authorization ordering and access policy checks. No external AI call is used. Run relation/editor suites plus standard server validation. Existing migrations already contain parent relationships and import-key uniqueness; no schema migration is required. Actual database concurrency/foreign-key behavior requires the existing MySQL integration suite. No new schema migration is required. Backend main deployment uses the standard Linux GitHub Actions workflow; mobile builds are separate.

## Main integration (2026-10-15)

Merged the recipe variations feature into backend main for hosted deployment. Local validation passed all 535 PHP fixture checks, tracked PHP syntax checks, and all 63 Python deployment tests. Tests used mocked AI responses and sent no email. Deployment preserves existing variation access and billing configuration.

## Explanation language correction (2026-10-15)

Review explanations and follow-up questions use English. Recipe content retains its original language. The cache fingerprint was updated so retries cannot reuse explanations generated under the previous language rule. The 58 variation checks and 62 relationship checks passed with mocked provider responses, including a Spanish-source fixture and the English response contract.
