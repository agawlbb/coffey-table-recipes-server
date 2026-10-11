# AI images

Generate an image with AI is available even when a recipe has photos. New images
join the draft without replacing the cover. The daily attempt cap is removed;
explicit repeated requests produce new images. Concurrent requests by the same
account are serialized. Normal Save persists photos; Cancel leaves drafts unsaved.
The renderer remains low-quality 816x816 JPEG to reduce cost and transfer size.

The Responses API uses gpt-5.4-mini with the GPT Image renderer. New imported
categories get specific artwork through authenticated POST /households/{id}/category-image,
which verifies category assignment in the household. A category file lock and
existing-file check prevent duplicate generation. Save requests category artwork
after recipe persistence; failures cannot roll back a saved recipe. Category browsing
retries missing artwork, including existing Cookies categories. Existing seeded
categories retain their bundled art. No schema migration is needed: image_key
records ai-{id}; category browse returns image_url. Generated files stay out of Git.

Public media provides CORS headers for the local Flutter web app. Uploaded images
are explicitly world-readable files under the existing public media directory;
private configuration and source storage remain protected.

Validation: offline image tests verify repeated generation beyond the old daily
cap and generation with existing photos. Disposable database tests verify category
image persistence, reuse, and household isolation. Editor tests cover multiple
images and save behavior.
