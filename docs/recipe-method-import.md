# Alternative cooking methods

Website imports with distinct appliance `HowToSection` headings (for example,
Stovetop and Instant Pot) require a choice before AI extraction or saving. The
detector runs locally without an AI call. Cake/frosting and other component
sections remain one recipe. The AI prompt also prohibits mixing alternative
complete cooking methods in other source formats.

Clients send `supports_related_imports: true` and answer with `method_selection`
set to the advertised method ID or `both`. Each selected method receives only
its own instructions plus shared ingredients and common sections. Shared cook
and total times are removed before extraction because they may describe a
different method. Each draft retains website provenance and a distinct import
identity. Additional drafts are returned in `related_imports`, each with its own
recipe, questions and editor options.

The app reviews and saves each draft separately. After the first save, subsequent
drafts receive its persisted ID as `parent_recipe_id`. Cancelling the second
review preserves the first save. No recipe is written during extraction. Older
clients can answer the clarification with one method; importing both requires
the updated app. The save endpoint rejects unresolved method-choice drafts.

Validation (2026-10-17): 25 offline method tests cover zero-call detection,
single-method filtering, legacy-client behavior and independent paired drafts.
A live test with the supplied Life Made Simple smoked sausage and lentil soup
page returned separate methods with 25-minute stovetop and 15-minute pressure
cooking timers, no clarification questions and two AI calls after choosing both.
No database recipes were saved by that test.
