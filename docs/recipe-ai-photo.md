# Optional AI recipe photo

The editor offers Generate AI photo only when there are no draft photos and no
photos in the recipe as loaded. Uploaded or generated photos hide the option.
Deleting existing photos requires saving that removal and reopening the editor
before generating. The authenticated `POST /households/{id}/recipe-photo` route
verifies membership, recipe ownership and that persisted photos are absent.

The route uses the existing server-only `recipe_ai.api_key`, with configurable
`recipe_ai.image_model` (default `gpt-image-2.5-flare`). The Responses API uses
`gpt-5.4-mini` to invoke that image renderer through a forced image-generation
tool call. Both models require project access. It sends only the dish
title, ingredient lines and instructions, bounded to 16 KB. Source text, notes,
credentials and existing image files are excluded. Recipe content is treated as
untrusted data. One image is requested at fixed 816x816, low quality, JPEG with
80% compression. These dimensions meet the current 655,360-pixel minimum and
16-pixel alignment constraints. JPEG reduces transfer/storage size; generation
size and quality control AI cost. Output must be a valid JPEG no larger than
816 pixels per edge or 1 MB.

Per-user generation locks prevent simultaneous calls. A protected storage cache
reuses an identical household/model/recipe request on the same UTC day, including
when a client loses its response. The limit is five new attempts per UTC day;
failures count and there are no automatic paid retries. Previous-day cache data
is replaced on the next request. Cached images stay outside Git and public paths.
The draft records AI attribution and an illustrative caption. No recipe or photo
is persisted in the database until normal editor Save; Cancel leaves it unsaved.

Offline tests: `php tests/recipe_photo.php` verifies fixed cost settings, prompt
minimization, valid output, caching, eligibility, daily limits and failure handling.
Flutter editor tests cover eligibility, generated cover/attribution, normal save
and retryable inline errors. Hosted deployment runs the offline photo checks.

Validation on October 11, 2026: 20 offline photo checks and 25 import checks passed.
The 18 Flutter editor/import tests and analysis passed; the local web build was
prepared with the new option.
A live public sample failed due to model access: the configured key's model list
contained only `gpt-6-luna`, and querying the configured image model returned 404.
Image-model access must be enabled for this API project before live generation
can be verified. No live image, database record or uploaded media was created.

Official request and size contract:
https://developers.openai.com/api/docs/guides/image-generation
https://developers.openai.com/api/reference/resources/images/methods/generate

Model migration: imports, follow-up answers and photo orchestration use
`gpt-5.4-mini`. Offline tests verify the Responses payload and completed image
output. Live validation awaits project authorization; no paid generation was
performed during migration.
https://developers.openai.com/api/docs/guides/tools-image-generation
