# AI recipe imports

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
output-token bound, 75 second upstream timeout and a per-account limit of 20 calls
per hour stored under protected `storage/recipe-ai/`. No credentials or source
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
readback, and requires public HTTP 403. No model tokens or database writes are
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
and no redirects. Recipe JSON-LD is preferred; page text is the fallback.

The AI prompt preserves source language and uncertain quantities, silently corrects
obvious spelling mistakes, asks for unresolved cooking ambiguity, and treats sources
as data. Missing yield, servings, prep and cook times are estimated from the recipe;
supplied values are preserved and estimates and assumptions are identified in notes.
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
reviewable reminders with a note rather than failing the whole import; irrelevant
time/temperature fields are cleared. No time or temperature is invented and no
extra AI repair call is made. Malformed nested content and excessive lists remain
rejected. Refused and incomplete responses give actionable messages.

Validation: 35 offline import checks and 41 disposable database save checks passed. A live public cookie sample passed strict-schema import, timer extraction and Cookies-under-Desserts classification using gpt-5.4-mini; no production recipe was saved.

Quantity prefills normalize equivalent decimal/fraction duplicates to one valid
representation and remove empty/zero fraction placeholders when a valid decimal
exists. Conflicting amounts retain the displayed ingredient and a review note
without guessing an amount. Structured amount precision and positive servings
are bounded for storage. Ingredient display lines correct obvious spelling while
source_text preserves the input. Save errors identify the ingredient or action.

Validation: the user-provided chocolate oat cookie text was imported twice live
and saved in an isolated disposable database, including nine ingredients, nine
or ten sequential stages, a 120-second simmer timer, heat reminders, Cookies
under Desserts, and estimated yield/servings/times. The final display corrected
the butter typo. Personal source text and AI drafts remain ignored, outside Git.
