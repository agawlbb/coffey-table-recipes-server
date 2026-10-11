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
`OPENAI_API_KEY`. The default model is `gpt-4.1-mini`; configure an image/PDF-capable
Responses API model if changing it. Requests use JSON output, `store:false`, an
output-token bound, 75 second upstream timeout and a per-account limit of 20 calls
per hour stored under protected `storage/recipe-ai/`. No credentials or source
uploads are logged or committed. The source input is sent to OpenAI when the user
continues; the UI discloses that. Setup remains required on hosted coffeybean.com.

For independent key installation, save a protected, ignored
`config/recipe-ai.local.json` on the hosted backend containing `api_key` and
optionally `model`, `pdftotext`, and `tesseract`. Configuration merges this over
the defaults without modifying database or mail credentials. Never put this
file under `app/`, in Flutter configuration, or in Git. The existing configuration
directory denies HTTP access and backend deployment preserves private files.

Optional free preprocessing tools: installed tools are discovered on PATH, or set `recipe_ai.pdftotext` and
`recipe_ai.tesseract` to absolute executable paths available on the hosting server.
Do not assume cPanel has them installed. PHP needs cURL, DOM and process execution
for external extractors. Extractors run without a shell, use private temporary
files removed in finally, and time out after 15 seconds. Unavailable/failed/sparse
extraction falls back to PDF/image AI input. The input file bound is 5 MB, image
bound 24 megapixels, and extracted text bound 60 KB. Website reads have a 2 MB bound,
20 second timeout, verified TLS, public DNS validation, pinned resolution, no proxy,
and no redirects. Recipe JSON-LD is preferred; page text is the fallback.

The AI prompt preserves source language and uncertain quantities, asks for missing
cooking details, and treats sources as data. AI has no database tools. Server
normalization removes untrusted IDs and relationships; final editor save applies
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
84 tests. No live AI calls, hosted writes or deployment were performed.
