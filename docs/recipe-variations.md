# AI recipe variations

POST `/households/{household}/recipes/{parent}/variation` accepts `changes` (1–4,000 characters), `request_id` (32 lowercase hexadecimal characters, new for each intentional request), and optional cumulative `answers` (`question`/`answer` pairs). Returns the ordinary import `{recipe, questions}` shape plus a top-level `change_description`. A client-supplied draft or parent is never authoritative. The server reloads the live source under household membership authorization on every request, including cache hits. Provider input preserves ingredient group titles and structured step actions (including temperatures and timers) but strips database identifiers and unrelated household data.

Generation reviews the complete adaptation: title, description, ingredient quantities and preparation, every instruction, equipment, temperatures, doneness, step actions, prep/cook/total and resting times, yield, servings, notes and categories. Affected details must agree with the new ingredients and method. Variation-specific instructions override ordinary import source-preservation rules, so original timings and handling instructions are not blindly retained.

`change_description` is a required, nonblank string of at most 4,000 characters, in the same language as the original recipe prose, explaining actual changes and their cooking implications. It belongs only to the response envelope, never recipe description, notes or database fields. The private draft retry cache includes it; the editor receives it as temporary review information outside the recipe/save payload. Invalid explanations fail generation without poisoning retries. A versioned cache fingerprint prevents pre-explanation drafts from being reused under the updated contract. Ordinary import responses are unchanged.

Generation saves a private review draft, not a recipe. Only approval through the ordinary recipe-create editor creates the variation. Originals are never written by generation. Drafts have `source_type: ai_variation`, server-selected parent, a Variation tag, no original nutrition and only a visually approved parent cover, and a stable import key for save retries. A new request ID deliberately creates a distinct variation. Generation retries, including earlier clarification rounds, reuse the cached result; up to eight distinct rounds are allowed per request. Cancellation leaves no recipe. Private draft cache is under `storage/variation-drafts` (excluded from Git and public HTTP by the existing storage protection); remove old draft files under the deployment's retention policy only when no request is using them.

## Model and access policy

The provider remains the existing OpenAI Responses integration with `store:false`. No new key or provider is introduced. `recipe_ai.variation_model` independently selects the model; default `gpt-5.4-mini`.

Variations default to `all_members`: every authenticated household member can generate variations of recipes they can access. Billing configuration does not restrict this access or imply complimentary usage. Existing household membership and source-recipe authorization remain required.

An explicit `variation_access: disabled` still disables generation, and `ai_images` retains the existing image-generation household allowlist when an operator deliberately selects it. Unknown access values fail closed.

## Access correction (2026-10-15)

Enabled variations for all household members by default, as requested. Removed the billing-dependent fallback to the image allowlist. The 43 mocked variation checks and 61 relationship checks passed; no paid provider requests were sent during validation.

## Validation

## Family browsing (2026-10-15)

GET recipe collections accept `families=1` to search/filter all live members and
paginate one family representative per match. Category counts also count a
family once. The original represents a family while live; otherwise its oldest
live member does. Default collection behavior remains ungrouped for full offline
inventory synchronization. Each row supplies `family_representative_id`.

Detail responses add `variation_family`, including the current recipe and all
live ancestors, siblings, cousins and nested descendants in its connected family.
Deleted nodes may connect live descendants but are never returned; all graph
lookups remain household scoped. The app uses this list in a dropdown beneath
the recipe title. Existing immediate `related` relationships remain unchanged.

Validation passed all 569 PHP fixture checks and 65 mocked Python deployment
checks, including nested family completeness, shared dropdown membership,
variation-title search returning the original, category counts, grouped paging,
and fallback to a live representative after deleting the original.

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

Review explanations and follow-up questions match the actual original recipe title
and cooking instructions. An English recipe requires an English explanation; a
Spanish recipe requires Spanish. Request/answer language, dish names, ingredients
and category labels must not change the explanation language. The stored language
tag is only a hint when it conflicts with the actual recipe prose. This replaces
the incorrectly introduced English-only rule. The cache fingerprint was updated
so retries cannot reuse output generated under either prior language rule.
Validation: 61 mocked variation checks, 62 relationship checks, 77 AI preprocessing checks, 18 description checks and changed-file PHP syntax checks passed. No live provider requests were sent.

## Boneless jerk chicken regression (2026-10-15)

Reproduced live from the English Smoked Jerk Chicken Thighs recipe with the exact
request `uses boneless thighs`: explanation was Spanish, title unchanged, and
method retained skin/bone instructions despite skinless/boneless ingredients.
Variation requests now explicitly bind the recipe's stored language into the
provider instructions and explanation schema (with prose inference only when no
valid language tag exists). Every variation must have a distinct descriptive
title identifying its change; unchanged titles are rejected before caching.
The prompt specifically requires removing inapplicable skin/bone instructions.
The cache policy version was advanced. Deployment now privately reads back the
two variation PHP source files and verifies their hashes before issuing a receipt.
Validation: 64 variation checks, 62 relationship checks, 77 AI preprocessing
checks, PHP syntax, and all 65 Python deployment tests passed with mocked services.

Live verification after deploying c0e9422: hosted source hashes and deployment
receipt matched. Repeated the exact request `uses boneless thighs` from the
English (`en`) Smoked Jerk Chicken Thighs recipe in the localhost app using the
hosted backend. The returned title was `Smoked Jerk Boneless Chicken Thighs` and
the review explanation was English. It explained the shorter smoke time and
removal of bone/skin handling. The method used a 35–45 minute smoke stage with a
matching 45-minute timer and no skin-side-down or bone-probe instructions. The
review draft remains unsaved in the built-in browser; the original was not edited.
Unlike the earlier mocked checks, this verification used actual provider output.

## Parent cover review (2026-10-15)

When the authorized parent has a readable hosted JPEG, PNG or WebP cover, the
variation request includes its actual pixels in the same existing AI call. Only
validated local media/recipes or media/recipe-photos keys are read; arbitrary URLs
and private filesystem paths are never fetched. Image bounds match recipe-save
limits (5 MB and 24 megapixels). The explicitly flagged cover is used, falling
back to the first photo only when no cover is flagged.

The model assesses whether the picture still represents the complete variation.
It must return a boolean parent_cover_appropriate and a short parent_cover_reason
in the recipe language, and explain reuse/omission in change_description. Visible
ingredient, cut, skin, texture, shape, garnish or method mismatches and uncertainty
must reject reuse. This is an AI assessment, not an additional user confirmation
or image-generation request. Missing/unreadable covers are omitted and the model
is told not to claim a visual review.

An approved cover enters the recipe draft as a new photo with image_base64 and
its original caption/attribution. The ordinary editor previews it and Save writes
an independent photo record and file. Cancellation writes no recipe or photo.
The parent is never modified. parent_cover_review stays outside the saved recipe.
The private retry cache retains the assessed result; its fingerprint includes
image bytes and attribution, so changing the cover triggers a new assessment.

Validation: all 591 PHP fixture checks and tracked PHP syntax checks passed.
Coverage includes approval, rejection, malformed/missing assessment, unsafe and
unreadable source images, pixel transmission, fresh identities, attribution,
cache invalidation, source immutability and independent file/database persistence.
All 17 Flutter variation tests pass, including preview/save of an approved cover.
Mocked tests sent no live provider requests or email.