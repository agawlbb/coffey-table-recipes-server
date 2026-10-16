# AI recipe imports

Ingredient components (2026-10-16): each AI ingredient has a nullable group_title.
Imports preserve source headings and can infer clearly separate components from
the method, such as Dough and Sauce. Ambiguous allocation prompts a question;
ordinary single-component recipes stay ungrouped. Draft normalization creates
ordered, unique local groups and maps ingredients to them without trusting AI
database IDs. Repeated ingredients and quantities are preserved. The same response
format supports variations and existing component titles supplied with them.
Split "each" ingredient rows retain the component. Group names are bounded and
case-insensitive duplicates coalesce. Existing app, cooking and share displays
already render the saved component headings.

The authorized Breakfast Ring test uses a CLI-only, exact-title hosted job,
opted in only with [breakfast-groups] in the deployment commit. It backs up the
original recipe and assignments in protected storage, requests only Dough/Sauce
labels for every existing ingredient position (no rewritten ingredient fields),
requires unique complete assignments, then atomically
adds groups and assigns existing ingredient IDs. Concurrent recipe changes abort
the update. No recipe text, amounts, steps, pictures or categories are replaced;
current nutrition freshness is preserved. The temporary cron job is removed on
success or failure. No other recipe is changed.

US quantity cleanup (2026-10-16): the import prompt requests familiar kitchen
fractions and separate records for explicit shared-per-ingredient amounts.
ImportIngredientQuantities also normalizes these cases locally after AI output,
without another API call: `.25 cups each of x and y` becomes `1/4 cup x` and
`1/4 cup y`, each retaining the full amount and unique draft IDs. Exact quantity
fields and displayed lines agree. Common halves, thirds, quarters, eighths and
sixteenths use fractions/mixed numbers when within 0.00005 of the supplied amount.
Metric amounts, quantity ranges, conflicting exact values and arbitrary decimal
quantities are preserved. Deterministic splitting requires explicit "each" and
a simple conjunction; alternatives and comma/preparation ambiguities are left
to the model and normal review. Original import source text is unchanged.
Validation: 152 AI preprocessing checks, 87 variation checks and 19 website import
checks passed. A live gpt-5.4-mini import returned separate quarter-cup walnut and
almond entries, 1 1/4 cups oats and 1/2 cup peanut butter; no database write occurred.

Current action policy (October 11, 2026): AI imports generate ONLY explicit
positive-duration timers (including timed rests) and commands to set an appliance
to a numeric C/F temperature. The strict schema permits only timer and
appliance_temperature; normalization drops other or incomplete actions without
creating reminders. Qualitative heat, ingredient additions, stirring, chilling
without a duration and internal doneness checks remain in step text. Historical
reminder/internal-temperature behavior described below is superseded.

Hosted cleanup: bin/cleanup-recipe-actions.php supports dry-run/apply against only
hosting_account_recipes. It backs up affected action rows and original recipe revisions
in protected storage before transactionally deleting disallowed actions and
converting positive-duration rests to timers. Valid timers/appliance temperatures,
recipe instructions and ingredients are retained. Affected revisions/timestamps
are updated; current nutrition freshness is retained. The deployment workflow
runs this operation only on an explicit [cleanup-actions] commit or opted-in
manual dispatch. The temporary handler requires a random secret header, has an
idempotent completion marker, and is removed afterward. No new public API exists.
Validation: 50 import normalization/schema checks and 10 source picture checks
pass; changed PHP files pass syntax checks. Hosted results are recorded after run.

Hosted execution completed October 11, 2026 (01:02 UTC October 12), workflow
38190286927, backend commit fcd22ed. Removed 18 unwanted actions and converted
12 explicit-duration rests into timers across 12 recipes. The post-cleanup dry
run found zero disallowed actions and zero unconverted rests. Protected backup:
`storage/action-cleanup-20261012-010205-c4cd6ab65774.json`, SHA-256
`b08c63470d71cb60f86b47ebb1702ec650544d813667376f2e454b56dc9f6abb`.
The temporary handler was removed. Deployment and cleanup workflow succeeded;
17 Python deployment/configuration tests also passed. No local database was used.


POST `/households/{id}/recipe-import` requires Firebase authentication and household
membership. It analyzes input without changing recipe data. Body: `kind` is
`text`, `website`, `file`, `camera`, or `answers`. Text uses `text`; website uses
`url`; files/camera use `file_base64`. Answers use `recipe`, `answers` (question /
answer objects), preserved `text`, `url`, and `import_identity` from the first draft's
`import_key`. Response contains `recipe`, `questions` and `preprocessing`.

POST `/households/{id}/recipes` saves a reviewed draft through RecipeEditorService.
The existing update endpoint retains revision checks. New saves insert the recipe
and all content in one transaction, retain source text and importing account,
and reject duplicate import keys with HTTP 409. No migration is needed.

Configure `recipe_ai.api_key` only in ignored `config/local.php`, or use
`OPENAI_API_KEY`. The app model is `gpt-5.4-mini`, selected by version-controlled defaults.
Private configuration cannot override it, including older key files. Requests use strict JSON Schema output, `store:false`, an
output-token bound and a 75 second upstream timeout. The per-account hourly request
limit was removed at the owner's request on 2026-10-12; legacy counter files are
ignored. Firebase authentication and household authorization still apply. No credentials or source
uploads are logged or committed. The source input is sent to OpenAI when the user
continues; the UI discloses that. Setup remains required on hosted coffeybean.com.

For independent key installation, save a protected, ignored
`config/recipe-ai.local.json` on the hosted backend containing `api_key` and
optionally `pdftotext` and `tesseract`. Configuration merges this over
the defaults without modifying database or mail credentials. Never put this
file under `app/`, in Flutter configuration, or in Git. The existing configuration
directory denies HTTP access and backend deployment preserves private files.

The manual `Configure recipe AI key` workflow uses the existing cPanel deployment
secrets plus `RECIPE_AI_API_KEY`. It verifies OpenAI model access, uploads only
`config/recipe-ai.local.json` to the verified recipe directory, compares private
readback, and requires public HTTP 403 before writing and after verification.
Existing configuration is backed up outside the web directory. Failed installs
quarantine the uploaded key outside that directory and restore the old key only
while access protection remains verified. See `security-maintenance.md` for
rollback and private recovery details. No model tokens or database writes are
used by these checks. Secret values and server response details are never logged.
When provisioning from a local key, obtain approval for storing the temporary
encrypted GitHub Actions secret, and remove that secret after setup completes.

Optional free preprocessing tools: installed tools are discovered on PATH, or set `recipe_ai.pdftotext` and
`recipe_ai.tesseract` to absolute executable paths available on the hosting server.
Do not assume cPanel has them installed. PHP needs cURL, DOM and process execution
for external extractors. Extractors run without a shell, use private temporary
files removed in finally, and time out after 15 seconds. Unavailable/failed/sparse
extraction falls back to PDF/image AI input. The input file bound is 5 MB, image
bound 24 megapixels, and extracted text bound 60 KB. Website reads have a 2 MB bound,
20 second timeout, verified TLS, public DNS validation, pinned resolution, no proxy,
and up to five redirects with each destination independently validated and pinned.
HTTP input links upgrade to HTTPS. Recipe JSON-LD is preferred; page text is the
fallback. If direct reading is blocked, moved or unavailable, the existing
gpt-5.4-mini standardization request can use cached public web search limited to
the source domain, with at most three tool calls. This adds no second
standardization request. Search has its own API tool charge; normal direct
extraction remains free preprocessing. A fallback draft must use a returned
search source on the same host and have ingredients and steps. Old .html recipe
variants must match the canonical slug (recipe3 cannot become recipe2).
Unverifiable sources return a paste-text error; nothing is saved. The verified
canonical URL is retained as the recipe source.

Website fix validation, October 12, 2026: 17 deterministic website checks and
77 recipe AI checks pass, covering HTTP upgrade, safe redirects, private redirect
rejection, source evidence, wrong domains/variants and empty recipe rejection.
A live import of the old Food Network recipe3.html link returned the exact
recipe3-1946704 source, eight ingredients, seven steps, a 375 F oven action and
600/1800-second timers, without questions or a database save.
Web search implementation follows https://developers.openai.com/api/docs/guides/tools-web-search.
Legacy recipe variants are also constrained in the structured response schema.
Source evidence tolerates HTTP/HTTPS, www and trailing-slash differences, while
retaining the actual returned source URL and rejecting different recipes/domains.

Website pictures: Recipe.image JSON-LD (including arrays/ImageObject/relative
URLs) is preferred, with Open Graph/Twitter picture metadata as a fallback.
Cached website lookup may return an exact existing main-picture URL; it must not
invent one. At most two candidates are fetched using public-IP checks, pinned
DNS, verified TLS and bounded redirects/bytes/time. JPEG/PNG/WebP up to 5 MB and
24 megapixels are added to the draft as its cover, with website credit. No image
generation or separate image-analysis call is made. The user can edit/remove the
picture before Save. Failed pictures keep the recipe import usable and show an
import warning, not a cooking note. Source images blocked by a publisher's CDN
remain unavailable; the importer does not bypass those restrictions.
Validation: 19 website import checks, 8 website picture checks, 77 AI checks and
10 source-picture checks pass. Live Food Network lookup returned an image URL,
but that CDN denied its download in the local test; the recipe remained intact.

The AI prompt preserves source language and uncertain quantities, silently corrects
obvious spelling mistakes, asks for unresolved cooking ambiguity, and treats sources
as data. Missing yield, servings, prep and cook times are estimated from the recipe;
supplied values are preserved. Notes contain practical cooking tips only; estimation
commentary is omitted and no notes is valid.
These missing fields do not trigger clarification questions. AI has no database tools.
Draft normalization accepts nested step actions and category labels, discards
AI-supplied identifiers, and assigns each action to its normalized step. Explicit
timed cooking/resting and appliance/internal temperatures produce cooking actions.
Existing category labels are supplied as reference data in the same AI request.
Missing subcategories (e.g. Cookies under Desserts) are reviewable proposals;
atomic recipe save reuses matching categories or creates the child under a verified
existing root. Canceling import creates no categories. Failed saves roll back new
categories. No database migration is required.

Server normalization removes untrusted IDs and relationships; final editor save applies
all existing schema and household validation. Users can correct the draft and
omit unknown details. OCR and AI can misread sources, so review is required.

`php tests/recipe_ai.php` runs offline preprocessing/validation checks without an
API key, network calls or database writes. `tests/recipe_edit.php` adds disposable
database checks for creation, source preservation, duplicate handling, rollback
and authorization. Its execution requires explicit approval under the hosted-only
testing workflow. Live paid AI calls and hosted writes were not performed while
implementing this feature. See official input contract:
https://developers.openai.com/api/docs/guides/file-inputs

Validation on October 11, 2026: 21 offline preprocessing checks and 35 recipe
edit/create checks passed. The user explicitly authorized the latter's disposable
local database after automatic approval review initially rejected it. PHP syntax
and Git whitespace checks passed. The Flutter repository passed analysis and all
84 tests. No live AI calls, hosted writes or deployment were performed during
the initial implementation validation.

Hosted setup validation on October 11, 2026: with explicit user authorization,
installed the API key in the protected server configuration, verified private
readback and public HTTP 403, and removed the temporary GitHub Actions secret.
The account's available model is `gpt-6-luna`. Fixed JSON-mode input wording and
verified a live sample through the local Flutter web app using the hosted backend:
the review screen showed Test Lemon Rice with five ingredients and five steps.
Discarded the sample without saving a database record. The 21 offline checks and
seven setup-script tests passed; backend changes were committed and deployed.

Action/category follow-up validation (October 11, 2026): 25 offline AI checks, 40 disposable database checks, 14 Flutter editor/import tests and analysis passed. A live sample generated a 120-second simmer timer, 350 F oven action, 720-second bake timer, 300-second rest and Cookies under Desserts. No live recipe or category was saved during this sample check.

Cooking stages are split at ingredient additions, heat changes, timed phases
and doneness milestones, preserving order and related motions. Qualitative
heat settings use reminder actions. Imports and saves convert labeled appliance
actions without numeric temperatures to reminders, including existing drafts;
internal doneness temperatures still require a numeric target and unit.

Import output now uses a strict Responses JSON Schema to constrain action enums,
field types and array limits. Incomplete or unknown scalar action types become
reviewable reminders with a separate import warning rather than failing the whole import; irrelevant
time/temperature fields are cleared. No time or temperature is invented and no
extra AI repair call is made. Malformed nested content and excessive lists remain
rejected. Refused and incomplete responses give actionable messages.

Validation: 35 offline import checks and 41 disposable database save checks passed. A live public cookie sample passed strict-schema import, timer extraction and Cookies-under-Desserts classification using gpt-5.4-mini; no production recipe was saved.

Quantity prefills normalize equivalent decimal/fraction duplicates to one valid
representation and remove empty/zero fraction placeholders when a valid decimal
exists. Conflicting amounts retain the displayed ingredient and a separate import warning
without guessing an amount. Structured amount precision and positive servings
are bounded for storage. Ingredient display lines correct obvious spelling while
source_text preserves the input. Save errors identify the ingredient or action.

Validation: the user-provided chocolate oat cookie text was imported twice live
and saved in an isolated disposable database, including nine ingredients, nine
or ten sequential stages, a 120-second simmer timer, heat reminders, Cookies
under Desserts, and estimated yield/servings/times. The final display corrected
the butter typo. Personal source text and AI drafts remain ignored, outside Git.

Imports decode pasted HTML entities locally. Missing descriptions are generated
from recipe ingredients and method, without unsupported claims. Categorization
uses dish form and preparation even when the source has no title: a filled pie
shell with meringue proposes Pies under Desserts rather than only Desserts.

Validation: the supplied title-less key lime pie source produced a factual
description, 12 ingredient entries, 10 steps, Pies under Desserts, and 120/1200
second cooking timers in a live import. Redundant parent assignments are removed
locally. Personal sample source and draft are ignored; no hosted recipe was saved.

Text import distinguishes Pasted text and Written description of a dish through
explicit UI selection; uploaded text/PDF/images, camera photos and website links
also have readable source labels, preserved through clarification. Legacy AI source
labels display in plain language. Import warnings are shown during review and
are excluded from stored cooking Notes.

Live validation of the supplied cracker/marshmallow/date-roll recipe returned five
ingredients, six steps, no questions, Pasted text as its source, and notes containing
the plastic-bag/rolling-pin method, scissors/regular-marshmallow advice, pre-chopped
dates and reserved coating crumbs. No estimation commentary was added.

Source picture suggestions: source images are numbered in the existing AI import
request, and returned food-picture bounds refer to those images. A portable PHP
extractor handles embedded JPEG and common 8-bit RGB/grayscale Flate PDF images
without GD or a paid rendering call. Extraction is bounded and unsupported PDF
image encodings are skipped; recipe import still proceeds. Original image imports
reuse their own pixels. The client crops locally, offers Use this picture / Skip
picture, preserves suggestions through clarification without resending their bytes,
and persists only selected pictures on normal Save. No generated-image API call
is needed. Up to six source candidates totaling 5 MB are analyzed at low detail
when free text extraction already supplied the recipe.

Validation: live recipe-card image, scanned PDF, and PDF with a separate embedded
food image all returned appropriate picture suggestions. The separate-image PDF
returned full-image bounds, rather than PDF-page placement coordinates. Offline
extraction/crop tests and typed-category review tests passed; no hosted recipe
was created during testing.

## Dish descriptions (October 15, 2026)

Future imports write or rewrite descriptions in the recipe's original language,
including when the source already supplies a description. The prompt and strict
schema share `RecipeImportService::descriptionInstruction()`: one or two concise
sentences describing the finished dish to a dinner guest or on a straightforward
menu. Flavor, texture and style must be supported by the ingredients and method.
Preparation recaps, ingredient inventories, hype and unsupported claims are
excluded. Optional ingredients and alternative choices must not become definite
claims. The model checks its description against the recipe before returning it.
The configured model remains `gpt-5.4-mini`; requests retain `store:false`.

Validation: `php tests/recipe_ai.php` and `php tests/recipe_descriptions.php`.
The latter exercises supplied and missing descriptions, original-language and
other-field preservation, and checks the outbound prompt/schema for contradictory
preservation instructions. These are offline transport tests, not a guarantee of
model prose quality; actual generated text still needs factual review.

Existing database recipes are not modified by this code change. The separate
description backfill requires a verified household inventory, private rollback
backup, conflict checks and description-only verification before execution.

2026-10-15: Import drafts resolve existing category labels and parents to trusted catalog IDs. AI receives the complete parent/child catalog and must reuse suitable categories before proposing additions. Time ranges preserve instruction text and use one lower-endpoint timer, including when AI returns both endpoints. Regression checks: `php tests/recipe_ai.php`.
