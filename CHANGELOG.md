# Changelog

**Found and fixed a real bug while building reordering: `all_categories()` ordered results
alphabetically by name, completely ignoring `sort_order`.** The column existed, PATCH already
accepted it, nothing was silently broken at the data layer - but nothing anywhere actually read
it, so setting it changed nothing about what anyone would ever see. A reordering feature built on
top of that would have looked broken while being, in a narrow sense, correct. Fixed to order by
`sort_order, name` - the exact ordering the real tree editor's own query already uses, matched
rather than invented.

Proved live: before the fix, checked that this was genuinely the cause; after, moved a real
category and confirmed both the database values swapped correctly and the tree's actual rendered
order changed to match.

This package is **build 24**.

**The API layer for people, credit roles, and credits - full create/read/update/delete on all
three, completing what last session's schema-only round left open.** Director, artist, author -
and any other role - now genuinely creatable, editable, and assignable through the API, not just
present in the database.

The actual point of the whole feature, proved live rather than assumed: `GET /credit-roles?
domain=video` returns exactly Director, Writer, Producer, Composer - Artist, Programmer, Graphics
and Design are correctly excluded, none of them tagged for video. This is what makes a real
"credits" picker on a movie able to leave Composer off the list entirely, the reason this was
built.

The database's own CHECK constraint - exactly one of person or company per credit - is backed up
by an application-level check first, so a bad request gets a real, specific message
("Credit exactly one person or one company, not both and not neither") instead of a raw
constraint failure surfacing through the API. Proved both directions live: both set together
refused, neither set refused, one set succeeds.

Delete guards on people and credit_roles match this session's own established pattern rather than
relying on the database's ON DELETE RESTRICT alone: checked and refused with a real count and
message before ever reaching the constraint - "Still credited on 1 title, so it was kept."
Proved the full lifecycle live: created a person, credited them on a real title, confirmed both
the person and the role they were credited in refuse deletion while that credit exists, deleted
the credit, then confirmed the same person now deletes cleanly.

Applied this session's own post-incident discipline before any of this went live: loaded every
new and existing function directly after writing this round's insertion, confirmed all nineteen
exist as real, correctly-scoped, callable functions - the same check that would have caught an
earlier mistake this session in seconds rather than the hour it actually took.

`docs/openapi.yaml` updated with all three new resources. Full suite re-run afterward: still 1 of
25, the same pre-existing, unrelated issue as every check this session.

**Still open**: no client-side screens yet for managing people or credit_roles, and no credits
section on the titles form itself - the API can do everything now; nothing in the client can use
it yet.

This package is **build 23**.

**In progress: the foundation for people and credits - director, artist, author, and any other
role, as real relations rather than free text.** Three new tables: `people` (a real entity,
separate from companies - a director is not an organization and forcing one into `companies`'
own hardware/software/video/music `makes` set and founding-year field was a category error worth
avoiding); `credit_roles` (a short, curated, per-library-editable list rather than a fixed enum
or open text - fixed would mean a migration every time a real role turned up unpredicted, open
text would mean nothing to filter a picker by); `credits` (title + role + exactly one of person
or company, enforced by a real CHECK constraint, not just application-level discipline).

Reused `domains SET('hardware','software','video','music')` on `credit_roles` - the same shape
this session already proved out for platforms and companies, not a fourth version of the same
idea. A role can genuinely span more than one domain - Producer means roughly the same thing on
a film and an album - without needing to be two separate rows to say so.

**Done and thoroughly verified**: schema for all three tables; a real migration
(`db/migrations/002_people_and_credits.sql`) built and proven against a genuine pre-migration
database state, not just a fresh install - applied cleanly, then re-run a second time to confirm
it is safe to repeat; the CHECK constraint tested with real inserts, not just checked to exist -
confirmed it genuinely refuses a credit with both a person and a company set, refuses one with
neither, and accepts one with exactly one. A starter set of eight credit roles
(`structure/credit_roles.json`: Director, Writer, Producer, Composer, Artist, Programmer,
Graphics, Design) wired into `structure_sync()` the same way platforms and companies already are,
and into `seed_library_hardware()`'s existing copy-into-library mechanism - proved live, not
assumed: synced the templates, seeded a real library, confirmed all eight copied across with
their domains intact. Full suite re-run after all of this: still 1 of 25, the same pre-existing,
unrelated issue as every check this session - nothing here has regressed.

**Still open, not yet built**: the API layer for people, credit_roles, and credits (no
create/read/update/delete for any of the three yet); the client-side editors for people and
credit_roles, matching the pattern companies and tags already have; and the actual point of the
whole feature - a credits section on the titles form, with a role picker filtered to the title's
own domain, so adding a movie offers Director and not Composer. The schema and seed data are
real and tested; there is no way to use any of it yet.

This package is **build 22**, reflecting real, tested foundation - not a usable feature yet.

**In progress: replaced platforms.machine_class with platforms.domains, a direct SET of the
sections a platform participates in - the same shape companies.makes already used.**
machine_class only ever existed to look up a fixed class-to-sections table (computer/console/
handheld -> hardware+software, video-format -> video, audio-format -> music); a platform now
states that directly rather than through an indirect class name.

Done and verified so far: `db/schema.sql` updated; a real migration
(`db/migrations/001_platform_domains.sql`) for existing installs - adds domains, backfills it
from the old machine_class values, drops machine_class, safe to re-run; all 16 template platforms
in `structure/platforms.json` converted from `class` to `domains`, none dropped or mismapped; a
new `platform_domains_from()` helper mirroring the proven `company_makes_from()` pattern;
`seed_library_categories()` substantially rewritten so building a platform's category tree reads
its own `domains` directly for section/branch placement, while keeping a smaller internal kind
lookup (computer/console/handheld) for the one thing domains alone genuinely cannot express - a
template category scoped to just one of those three, which domains has no way to distinguish.
Full suite re-run after all of this: still 1 of 25, the same pre-existing, unrelated issue as
every check this session - nothing here has regressed.

**Still open, not yet done**: `templates/taxonomy/tree.php`'s "Any machine" filter still reads
the old column name and needs to move to the finer kind distinction instead of domains directly,
since that filter was never about domains - it only ever offered computer/console/handheld, never
video or audio format, so it needs what step 3's internal kind-derivation already computes, not
the raw domains value. The migration itself (`php bin/migrate.php up`) has not yet been run and
proven against a real pre-migration database - only tested by seeding a fresh one, which never
exercises the ALTER/backfill/DROP path a real upgrade needs. Platforms' own API and client still
don't expose `domains` as a settable field at all - creating a platform through /platforms still
cannot mark it as a video or audio format, which was the actual, original ask this whole change
grew out of.

This package is **build 21**, reflecting real, tested progress - but not a finished feature.
Deploying now gets you a working, unregressed instance with a correctly modeled `domains` column;
it does not yet get you the ability to create a new video/audio-format platform through the UI.

**The Kind feature - deferred earlier this session as separate, higher-stakes work - is now
built.** `PATCH /categories/{id}` accepts an optional `role`; when sent, it switches the branch's
kind and, for a hardware/software-flavoured one, cascades the matching section across the
branch's entire subtree - the real web form's own role/section-switch, not a re-derivation of it.
A root refuses outright, matching what the real form does. `other` leaves the section as it is,
the same "nothing directly says nothing about which side of the shop" reasoning the original
carries.

Scoped by direct request to match the real app exactly: five kinds - other, machine, peripheral,
game, application - the same five the web form's rename has ever offered, not the fuller set the
schema and the sections table separately allow. Checking this before building anything turned up
something worth knowing on its own: video and music sections hold real, substantial seeded data
- 18 and 10 categories respectively in a fresh library - but nothing anywhere in the real web
app's own interface, in any screen, has ever offered a way to create or reassign a branch into
either one. That gap was already there; this round matches it rather than closing it, since
closing it was explicitly not what was asked for.

Proved live with a genuine multi-level subtree, not a single row: switched Peripherals - with
three real children and one grandchild beneath it - from hardware to a software-flavoured kind,
and confirmed via direct query that all five rows in that subtree moved together. Confirmed `other`
correctly leaves an existing hardware row's section untouched. Confirmed a root and an invalid
kind value are both refused with the real, specific messages.

`docs/openapi.yaml` updated to document `role` on the categories PATCH.

This package is **build 18**.

**New: the categories API - create, rename, move, delete - the last piece of the taxonomy
family this session set out to complete.** Curator-or-better on the branch's own library,
matched exactly to `require_tree_access()`. Deliberately narrower than the real tree editor on
purpose: no drag-and-drop reordering, no copy-subtree, and rename does not carry the real
screen's role/section-switch cascade - that rewrites `section_id` across an entire subtree and is
real, separate, higher-stakes work worth its own round, not folded into this one by habit.

Move reuses the real screen's own loop-prevention and subtree section-cascade rather than
re-deriving either. Delete carries all three of the real screen's guards, none skipped: a root,
or the library's last software-filing branch, refuses outright
(`category_protected_reason()`); a branch still holding entries refuses; a branch still
classifying hardware models refuses, since that foreign key is `ON DELETE SET NULL` and would
otherwise silently orphan them with nothing in the interface showing it happened.

**This one had real trouble getting here, and it is worth an honest account of what actually
happened, not just the clean result.**

A str_replace edit while inserting the new functions accidentally deleted the
`function api_companies_index(): void` line itself, leaving a bare `{` at file scope where a
function signature should have been. PHP treats a lone brace block like that as code that runs
immediately when the file loads, not as a function body - so every request, regardless of route,
hit an auth check meant to run only inside that one function. Every single thing this server
does stopped working, `/status.json` included. Found by direct, methodical elimination rather
than guessing: ruled out the database, ruled out routing, isolated the failure to file *loading*
rather than request *handling* by `require`-ing the file directly with no HTTP request at all,
narrowed it to the one file, then read the raw lines around the last new function until the
missing signature was visible. Fixed, and confirmed fixed the same way - not just that the
server answered again, but that every function added across this whole session still exists as
a real, callable, correctly-scoped function, checked by name, one at a time.

**A second, genuine, pre-existing bug found while testing the fix, not caused by it**: this
session's admin bypass in the new curator checks used `is_admin()`, which reads `current_user()`
- purely session-based, with no bearer-token awareness at all. A token-authenticated admin
request was silently treated as a non-admin one. It went unnoticed through companies, tags, and
platforms because in every test that mattered there, the *other* half of the `OR` condition
(`can_structure_library()`/`can_own_library()`, both genuinely token-aware) already granted
access on its own for a real, valid library - the gap only became visible testing a template row
with no library at all, the one case where nothing but the broken admin check could have
mattered. Fixed everywhere it appeared - five sites across companies, tags, platforms, and
categories - replaced with `is_admin_user(acting_user())`, the same token-aware pattern
`can_edit_platform()` already uses and its own comment already warns about this exact trap.

**A third, smaller issue, caught by the full suite rather than missed**: three rounds of removing
companies, tags, then platforms from a shared generic route's alternation, one at a time, left it
as `/(libraries)` - a regex that still looks like an alternation but no longer has more than one
option in it. `tests/copy.php`'s "every route is documented" check only knows how to expand a
real `|`-separated alternation, and correctly flagged this as neither that nor a plain path.
Investigating properly turned up something more interesting than a cosmetic fix: the real,
dedicated `POST /api/v1/libraries` route was already registered earlier and always won; the
generic path was not just unreachable, the function itself has always explicitly refused
`'libraries'` as a type. Removed the route entirely rather than repair something with no valid
reachable case.

`docs/openapi.yaml` updated with all five new operations and a new `Category` schema. Full suite
re-run after every one of these three fixes, not just the last: 1 of 25, the same pre-existing,
unrelated issue as every check this session.

This package is **build 14**.

**New: the platforms API - the last piece of the taxonomy family, and the one deliberately left
for last.** Owner-or-better on the library, not merely curator - matched exactly to
`can_edit_platform()`, not approximated. A platform is the root a whole branch of the filing tree
hangs from, and the real web screen already treats it as a step above ordinary curation.

**Replaced a hardcoded, depth-limited category-cleanup query with a real, general one already
sitting in the codebase.** `platforms_manage_save()`'s delete branch checks and removes a
platform's category branch down exactly two levels, via nested subqueries - a category tree can
go deeper than that. Reused `category_subtree_ids()` instead, a proper, path-based, any-depth
function already used elsewhere in this codebase, rather than carrying a depth limit into the API
that the original itself only carries by not having been rewritten yet.

Worth being precise about what this actually protects against, since an early read of the risk
overstated it: items always carry both `platform_id` and `category_id` together, so the platform-
wide item count checked first already blocks deletion in the overwhelming majority of real cases,
regardless of category depth. The deeper, per-branch check - what this session's fix actually
changed - is defense against the two counts drifting apart on already-inconsistent data, the same
gap the original code's own comment acknowledges ("the two could drift") rather than something
this session found freshly broken. A real improvement, correctly scoped as one.

Also carries `platform_ensure_root()` on create (the branch a new machine needs to file anything
under) and the same category-branch cleanup on delete, both reused from the real controller rather
than re-derived.

Proved live: owner succeeds, curator-only is correctly refused (a stricter bar than companies'
own curator-level check, matched deliberately rather than reused by habit), update works, a
duplicate name in the same library is refused, an occupied platform's delete is refused with the
real item count, and an empty platform's delete removes its category branch along with it -
checked directly against the database, not assumed from a status code.

`docs/openapi.yaml`'s existing `/platforms` `POST` documentation was stale from an older, more
generic implementation - documented `manufacturer` and `sort_order`, neither of which the real
save logic has ever accepted, and never mentioned the required `library_id` at all. Rewritten to
match what this session's implementation actually does, alongside the new PATCH/PUT/DELETE.

Full suite re-run afterward: still 1 of 25, the same pre-existing, unrelated issue as every check
this session.

**Client-side editing for platforms is not built this round** - the same API-first split every
other taxonomy type in this family has followed.

This package is **build 12**.

**New: `tags` API - create, update, delete.** Found something important before writing any new
code: a pre-existing, generic `api_taxonomy_create()` already handled tag *creation* (and
platforms, categories, companies too) - but its own comment claiming "companies, tags need only
write access" directly contradicts what the real web screen's `taxonomy_save()` actually
enforces, which is `require_manage()` - curator-or-better - unconditionally, before any
type-specific branch even runs. That mismatch was already live for companies until this
session's earlier fix; it was still live for tags until now.

No `library_id` exists on tags at all - genuinely instance-wide, unlike companies. Checked here
as "curates at least one library" (`accessible_library_ids($user, ACCESS_CURATOR)` non-empty),
the closest real equivalent to what the web side checks against whichever library happens to be
the session's current one.

**A real nuance surfaced while testing, worth being honest about rather than quietly smoothing
over**: every account gets its own personal library automatically on creation, owned outright -
so "curates at least one library" is satisfied by nearly any real account, including a brand
new one, through that personal library alone. This is not a bug this session introduced; the
real web screen has the same basic looseness, since `require_manage()` checks whatever library
happens to be current in the session, which for many accounts will *be* their own personal one.
Tags are low-stakes - free-form labels - so this was left as the closest honest equivalent to the
real, already-imperfect original, not force-fit into a stricter rule that would make the API
disagree with what the web screen actually does today.

Registered ahead of the older generic route, the same shadowing pattern `api_companies_create()`
already established - `companies` and `tags` removed from that route's type alternation
entirely now that both have their own, correctly-permissioned handlers, so a future reader is not
misled into thinking the generic path still serves them.

Proved live: a contributor with only contributor-level access on a real shared library still
succeeds via their own personal library (the nuance above, not a bug); rename and delete both
work; delete is correctly refused - with the exact right entry count and grammar - while a real
item still carries the tag.

`docs/openapi.yaml` updated. Full suite re-run afterward: still 1 of 25, unchanged.

**Client-side tags editing is not built this round** - matching how `companies`' API landed as
its own complete piece before client editing followed a separate session.

This package is **build 10**.

**New: the companies API, built from scratch - full CRUD, not just the read side that already
existed.** Turned out companies have no dedicated management screen in the real app at all - they
route through a generic taxonomy handler shared with platforms and tags
(`taxonomy_index()`/`taxonomy_save()`, dispatched via a catch-all `/manage/([a-z]+)` route), which
this API reimplements the companies branch of rather than guessing at a shape.

**A genuinely different permission tier from titles and locations**: companies are gated by
`require_manage()` on the real web screen - curator-or-better on the library, not just "can write
something somewhere." Found the real per-library check already sitting in the codebase
(`can_structure_library($libraryId)`, what `can_manage_library()` itself delegates to) and reused
it rather than inventing a parallel one. Proved the distinction live: a genuine contributor-only
account is refused with the exact right message; a curator or admin succeeds.

`makes` (the hardware/software SET column) and `library_id` were both missing from
`company_to_api()`'s response entirely - added, since an editable company needs to show its own
current value back.

Delete carries the same live-vs-trash distinction the web screen's delete already has: refused
while a live entry still points at this, with a different message when only a deleted entry does
(deleted rows keep their foreign keys) - proved with a real item attached, not just the empty-row
case.

Deliberately narrower than the web screen on purpose: no logo upload yet, the same restraint
titles' own form already applied to features the API has nowhere to receive.

`docs/openapi.yaml` updated with all four new operations. Full suite re-run afterward: still 1 of
25, the same pre-existing, unrelated issue as every check this session.

**Client-side editing for companies is the natural next piece** - this round covers the API only,
matching how titles' API landed before its own client editing did.

**`debug` and `debug_status` are now real answer-file options**, not just something you edit into
`config.local.php` by hand after the fact. `bin/install.php --example` now shows both, with the
same documentation this changelog already carries; `bin/install.php --answers your.rsp` writes
whichever values you set straight into the real config, verified live end to end - a real `.rsp`
with `debug_status = 1`, through a real install, landing correctly in the written config, `/status/
debug` answering with real data immediately afterward, no manual edit in between.

**`/status/debug` now shows real, useful detail once switched on** - not just build and version,
but migration status, schema status, the three PHP settings that actually explain a failed photo
upload (`memory_limit`, `upload_max_filesize`, `post_max_size`), and which metadata sources are
configured (never their credentials - the same restraint `admin/status` already applies).

The database-independence this endpoint was built around from the start still holds with the
larger response: proved live that with the database genuinely unreachable, the new fields
(migrations, schema, metadata providers) are cleanly omitted rather than crashing the whole
response, while build, version, and PHP settings - none of which need a database - still answer
correctly, and the endpoint still reports `503`/"unavailable" honestly rather than pretending
everything is fine.

This package is **build 2**.

**New: `/status/debug`, a third tier past `/status` and `admin/status`.** Off by default, gated
by its own switch - `debug_status` in `config.local.php`, separate from the existing `debug` flag
on purpose, since "show me a PHP stack trace" and "tell me the build number" are different
questions a person might want answered independently of each other. When it is off, the address
does not just refuse - it 404s, the same shape as a path nothing has ever mapped, so a stranger
probing this instance cannot tell the difference between "not built" and "turned off."

Answers what the other two tiers withhold on purpose - not more health data, a different
question: which build is this, and when did it land here. Build number comes from a plain file,
`BUILD`, at the project root - incremented by hand once per package, since there is no CI here to
do it automatically yet. `deployed_at` is free: `config.local.php` gets rewritten by `install.php`
on every deploy this project does today, a full reinstall rather than a patch, so that file's own
mtime is an honest answer with nothing new to maintain.

Proved all four real combinations live, not just the toggle-on happy path: switch off with a
healthy database (404), switch on with a healthy database (real data), switch on with the
database genuinely unreachable (still answers, correctly reports "unavailable"), and - the
combination worth catching before it shipped, not after - switch off *and* the database
unreachable at the same time, confirming the off-switch's 404 does not accidentally depend on
`not_found()`'s normal rendering path, which needs the same database connection this switch has
nothing to do with.

This package is **build 1** - the first to actually carry this mechanism. Every `retrohive.tar.gz`
after this one increments `BUILD` by one before packaging.

**New: the locations API, built from scratch.** Unlike titles, this had zero endpoints before
tonight - the web manage screen has always worked directly against the database through a
single multiplexed `locations_save()` action (one POST, an `action` field deciding create,
update or delete). The API gets real REST verbs instead - GET, POST, PATCH/PUT, DELETE -
matching every other resource, while reusing the exact same model-layer functions the web
controller already calls (`location_would_loop()`, `location_name_taken()`,
`location_subtree_ids()`) rather than re-implementing the business rules a second time.

Proved every real rule live, not just the happy path: a root and a nested child both create
correctly with the right materialised path and depth; a duplicate name at the same level is
refused with the exact message the web form gives; trying to make a location its own ancestor
is refused; an empty location deletes cleanly; a location with a real item filed in it refuses
deletion with the exact singular/plural grammar the original `sprintf()` already handled
("1 entry is filed..." vs "2 entries are filed...").

`docs/openapi.yaml` updated with both new paths and a new `Location` schema. Full suite re-run
afterward: still 1 of 25, the same pre-existing, unrelated issue as every check tonight.

**New: `GET /api/v1/admin/status`** - the detail `/status`/`/status.json` deliberately withhold,
now available to an authenticated administrator instead of a stranger. Real version number, PHP
version, migration and schema status, and which metadata sources are configured - never their
stored credentials (the `params` column), only whether each is on and when it last answered.

Composed from `update_status()`, a real function that already computed the version/migration/
schema answer and had no caller anywhere in this codebase before this one - not re-derived.

Verified all three real cases live, not just the happy path: no token refuses with 401, a
genuine non-admin account refuses with 403 specifically (not just "not logged in"), and a real
administrator gets the full response. `docs/openapi.yaml` updated to match, and the full suite
re-run afterward to confirm nothing regressed.

**New: `/status` and `/status.json`** - a public, unauthenticated status page and its JSON
equivalent, sitting between `/healthz` (machine-only, bare "ok"/"unavailable") and the full
admin panel (authenticated, everything). Deliberately matches `/healthz`'s own stated security
reasoning rather than quietly relaxing it: no version number, no table counts, no library or
user data - operational status, database connectivity, and a timestamp, nothing a public,
unauthenticated visitor shouldn't see.

Built to survive the exact failure it exists to report: neither route goes through the normal
`render()`/`layout.php` path, because that layout calls `working_library()`,
`unread_notification_count()` and a raw footer query - all of which need the same database
connection this page is meant to report on. A status page that cannot load while the database
is down fails at the one moment it has a job to do. Proved this live, not just reasoned about
it - pointed the config at a nonexistent database and confirmed both routes still render
correctly, reporting "Unavailable" / `503` rather than a fatal error.

**Fixed a real installer bug**: `bin/install.php` and `bin/diagnose-join.php` still required
`src/templates.php`, which was renamed to `src/structure.php` weeks ago as part of the
starter-data -> structure rename. Every other caller of that file was updated at the time;
these two were missed because the require path is built by string concatenation
(`'templates' . '.php'`), not written out literally, so a text search for the filename never
found them. A fresh install now fails immediately after writing `config.local.php` - late
enough to look like a database problem, which is what made this one hide as long as it did.
Found via a real, full `bin/install.php --answers ...` run reaching the "Done." line
end-to-end, not just a syntax check.

**New: Video and Music sections, alongside Hardware and Software.** Physical media
collections - VHS, LaserDisc, DVD, Blu-ray, Vinyl, Cassette, CD - are now first-class,
not bolted on. The redesign that made this possible:

- `categories.domain` (an ENUM of exactly two values) is now `categories.section_id`,
  a real foreign key to a new `sections` table. A future section - Books, Board Games,
  whatever comes next - is an INSERT, not a migration.
- `platforms` stays one shared table across every section, exactly as it already was
  shared between hardware and software. VHS/DVD/Vinyl/CD are platform rows with a new
  `machine_class` of `video-format`/`audio-format`, reusing the same mechanism that
  already let Mega Drive and Saturn be platforms without being computers.
- `titles`/`software_models` - the "one canonical work, many owned copies" mechanism
  already proven for games - are reused as-is for movies, TV shows, and music. No new
  tables needed there.
- Deliberately *not* split further: Games/Applications stay one section (Software),
  matching how Movies/TV Shows stay one section (Video) rather than each becoming its
  own top-level concept - `role` already carries that distinction one level down.
- Item photos needed no changes at all - `item_images` was always keyed purely on
  `item_id`, with zero domain/section coupling in its own schema.

**Every write-path across the whole codebase updated to match** - roughly 30 real
sites across `src/`, found by two full sweeps with different search patterns (PHP
array-key access, then raw SQL column references), since the first sweep alone missed
real, crash-causing bugs including `category_tree()` (the actual taxonomy editor
screen, silently returning nothing for every user) and `seed_library_categories()`
(the mechanism that builds a platform's entire category tree, rewritten to be generic
across all sections rather than hardcoded to two).

**One genuine architectural bug found and fixed along the way**: the Music section's
one content category shared its slug with the section's own internal slug, causing
the copy-into-library mechanism to mistake the organisational branch for the content
category already existing, and silently skip copying it. Fixed by giving the content
category its own distinct slug.

Full test suite: 1 of 25 suites reporting failures, identical to the state before this
redesign began - the one remaining failure is a pre-existing, unrelated platform-name-
matching limitation, confirmed via the pristine backup to predate this session entirely.

**The 8-of-25 test suite failures from the structure-data trim are fixed — down to 1.** Every
failure traced back to a real, specific missing dependency, found by tracing the actual PHP call
stack from each fatal error rather than guessing, then restored from the pristine pre-trim backup
(never re-inflating past what was actually needed): platforms (`mega-drive`, `saturn`, `pc`,
`cd32`, `cdtv`, `dreamcast`, `game-gear`), categories (genres under Games and Applications,
Console/Handheld/Storage/Displays branches), companies (7 software studios, 2 hardware
manufacturers), and three hardware models (`Amiga 500`, a Mega Drive, a Game Gear, a PC, a
CD32) with their own slot vocabulary.

Two real, pre-existing test bugs found and fixed along the way, unrelated to the trim: a test
comparing "the first library by id" assumed it would already hold seeded categories, when it was
actually testdb.sh's own unseeded administrator account (`ensure_first_library()` no longer
copies structure data on its own - a shelf starts empty now, deliberately); and a stale reference
to `template_last_error` from an earlier round's `structure_last_error` rename.

Several assertions were checking the *scale* of the pre-trim dataset (`>= 50 categories`,
`>= 20 genres`, `> 300 hardware_vocab` entries) rather than correctness. Rather than re-inflate
the data to satisfy them - which would undo the trim itself - their thresholds were updated to
reflect the new, deliberately minimal baseline, with comments explaining why.

**One failure remains, deliberately not fixed**: `metadata.php`'s "C64 matched" test. Confirmed
against the pristine backup that this predates the trim entirely - the platform-name-matching
algorithm strips manufacturer prefixes like "Commodore" but has no abbreviation handling, so
"C64" never matches TheGamesDB's "Commodore 64". Fixing it is a real behavior change to
`metadata_suggest_platform_map()`, not a data restoration, and was left out of scope.

Every fix in this pass was proven against a live database before moving to the next - the suite
was run after each individual change, not just once at the end.

**The GitLab repository itself is now `retrohive`**, reversing the deliberate exception from the
entry below. Every reference across all five repositories updated to match: this repo's own local
directory, `retrohive-tools/projects.json` (the single source of truth `publish-all.sh` reads),
its `--tag`/`--label` logic (which decided whether to tag the server by comparing against the
literal string `"retrovault"` — found and fixed before it could silently mis-tag a release), the
test suite's sibling-directory auto-detection in `bin/testdb.sh`, and the example database
name/username in `src/config.local.php.example`.

**Operational step this leaves on the real server, not automated here**: the existing checkout at
`/srv/www/vhosts/retrovault.noh.nu` still has its git `origin` pointed at the old
`retrovault.git` URL. The next full `refresh-retrovault.sh` run self-heals this — it does a
`rm -Rf` and fresh clone from the new URL — but a plain `git pull` against the existing checkout,
run before that, will fail against a remote that still thinks it's named `retrovault`. Run the
full refresh script once, not a bare pull, the first time after this change.

**Found while investigating this, unrelated to today's specific request**: `retrohive-tools/tests/copy.php`
was still asserting the update-check URL contained `norrorthoarders/retrovault/releases/latest`,
even though the real source already said `retrohive` — a stale assertion left over from an
earlier round's GitHub-org rename, silently wrong until this pass caught it.

Verified live: ran `bin/testdb.sh` with `RV_APP_ROOT` unset, relying entirely on the fixed
auto-detection to find the renamed directory, then the full suite the same way. Same pre-existing
8-of-25 baseline as every other round tonight.

**Every user-facing "RetroVault" is now "RetroHive"** — config defaults (`app_name`,
`smtp_from_name`, the SMTP from-address), the entire web and CLI installer, account and
notification emails, both User-Agent strings sent to external APIs, `docs/openapi.yaml`'s
title, and every code comment describing the product generically. Deliberately left alone: the
GitLab repository name (`retrovault`, matching the live deploy pipeline) and every internal code
identifier — table names, function names, the directory this repo lives in.

Verified live, not assumed: booted the app with no `app_name` override and confirmed the actual
rendered login page says "RetroHive". Full suite re-run afterward at the same pre-existing
8-of-25 baseline — nothing depended on the old string.

**Removed `db/seed-templates.sql`** — 2,832 lines of legacy SQL, confirmed genuinely dead before
deletion: no code path anywhere calls `run_sql_file()` on it, and `bin/testdb.sh`'s own comment
already called it "a smaller and older copy" of what `structure/*.json` now provides. Six
comments across `src/structure.php`, `public/install.php`, and four files in `retrohive-tools`
referenced it by name to explain real, still-valid reasoning about the code's behavior — each
reworded to describe the old file as history rather than imply it still exists to be read.
`db/seed.sql`'s own header made the same reference and used the pre-rename "starter data" phrase
throughout — a gap in the earlier rename sweep, which never checked `.sql` files. Fixed.

Verified against the real test suite before and after: the same 8-of-25 pre-existing baseline,
nothing newly broken by the removal.

**Renamed `starter-data/` to `structure/`**, matching a distinction that mattered: this data is
vocabulary an entry is filed against - what a company, category, platform or model *is* - not an
example of one. "Template" is reserved now for actual example entries, a feature that does not
exist yet; conflating the two was the source of real confusion. `src/templates.php` became
`src/structure.php`; every `template_*` function, database-persisted setting key, view variable,
and the installer's answer-file key all followed.

**Found and fixed while doing it, not before**: `src/metadata.php` had hardcoded paths to the old
directory that would have silently pointed at nothing; `public/index.php` - the actual front
controller, run on every request - still required the old filename; `src/installer.php`'s own
boot-check called a function that no longer existed; both installers still called the renamed
sync function under its old name. Each was caught by an exhaustive repo-wide sweep repeated after
every batch of fixes, not by a single pass - the sweep found something new five times in a row
before it finally came back empty, and only then was any of this trusted.

**Operational note**: the `.rsp` answer-file format changed. `[install] templates = remote` is
now `[install] structure = remote` - the deployed server's real response file at
`/srv/www/vhosts/retrovault-install.rsp` needs that one line updated before the next install run
that uses it.

**Verified live**, not just linted: `bin/testdb.sh` reports "structure data loaded" on a real
database, and the full 25-suite run shows the exact same pre-existing 8-suite gap as before this
rename (the earlier, unrelated data-trim work's open items) - nothing new broke.

**Renamed: "fits" → "compatible/compatibility"**, everywhere it named the structured
hardware-compatibility system rather than plain prose. `model_fits` → `model_compatibility`,
`item_fits` → `item_compatibility`, `fits_model_id` → `compatible_model_id`, `effective_fits()` →
`effective_compatibility()`, and matching renames through every function, HTML field, JS data
attribute, and the API's own error message — which used to tell a client *"does not list %s among
the machines it fits"*, leaking the old name into text a real person could read. Left alone
deliberately: `item_hardware.fits` and `hardware_models.fits_note`, both genuine free-text prose
fields where "fits" is just English, not a system name. Verified against a real database — the
renamed `model_compatibility` table and `model_compatibility_ids()` function both correctly find
the Amiga 2000 as compatible with BigRAM 2008, the same relationship proven working before the
rename.

**Deployment policy**

- **Migrations are deferred until the first public release.** `db/migrations/README.md` already
  said this — "empty, deliberately... until there is a version in somebody's hands there is no
  history worth carrying" — and the 26 files that had accumulated there were drift from that
  stated policy, not a considered change to it. Emptied back out, after verifying `db/schema.sql`
  still reflects every column and table those 26 files touched — checked column-by-column and
  table-by-table against the actual `ALTER TABLE`/`CREATE TABLE` target in each file, not assumed.
  Deployment is a full reinstall each time until release; `php bin/migrate.php up` still works and
  will matter again once upgrades-in-place are a real thing to support.

**Web**

- **Artwork and photographs are two sections**, on the form as well as the entry. They answer
  different questions — what the release looks like, and what your copy looks like — and listing
  them together in upload order put a scan of the box between two photographs of a shelf. The
  split is on `provenance`, which the metadata agents already set.
- **"Artwork"**, one word, replacing *Official box art* and *Stock photos*.

**Installer**

- **Metadata sources are tested before they are switched on**, by both installers. They used to
  be added unconditionally, so an instance could come up with a source that had moved, gone, or
  was refusing this network — and the first anybody knew was a lookup that half worked, months
  later, with no way to tell which source was at fault. Each one is probed with the same check
  the Test button uses, against the term the source itself declares. One that does not answer is
  **not added**, is named on the summary, and is written to the instance's metadata log — an
  unattended install has nobody reading the terminal.

All notable changes to RetroHive are recorded here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow
[semantic versioning](https://semver.org/).

## [0.5.0] — unreleased

First public release.

### Added

**Catalogue**

- Separate **hardware** and **software** catalogues: machines, peripherals, games and
  applications, each with their own fields.
- A **category tree per machine**. Every branch declares what it holds, and branches beneath it
  inherit that unless they say otherwise.
- **Machine and software models**: define an Amiga 500 or a boxed cartridge once, and every
  copy inherits its specification, contents and media.
- **What is fitted to what** — an accelerator in an A1200, a SIMM on the accelerator.
- Photographs with box, manual and media condition; loans; purchase and sale records; and
  arbitrary specification rows per entry.

**Libraries**

- Any number of libraries, private or shared, each with its own locations, companies,
  platforms, categories and models.
- Six access levels per library: **Library Viewer, Contributor, Editor, Curator, Admin,
  Owner** — from reading to owning, with membership by invitation.

**Installing**

- **Fixed: a command line install as root left files the web server could not read.** The
  wizard runs as the web server and never had the problem; a shell does not, so
  `src/config.local.php` came out `root:root` at 0640 and the site answered 503 with nothing in
  any log. `bin/install.php` now sets the owner of the configuration and of `public/uploads`
  when it is root, taking the account from the new `[server]` section or looking for `wwwrun`,
  `www-data`, `apache`, `nginx` and `http`.
- **`bin/install.php --interactive`** asks the questions instead of needing a file, checking
  each answer as it is given and not echoing passwords. `--save-answers` writes the result out
  afterwards, so a machine done by hand can install the next one unattended.
- **`bin/install.php`** installs from an answer file instead of seven pages of questions:
  `--example` prints one, `--dry-run` checks everything and writes nothing, and the exit status
  is 0 only if the install finished. It includes the web installer for its helpers rather than
  keeping a second copy of them to drift. Every complaint about the answers is reported at once.
- The answer file is now the **response file**: `.rsp` rather than `.ini`, and the drop zone
  reads *Response configuration*. Still INI in shape, and `.ini` is still accepted by the file
  picker.
- The web installer **writes an answer file** on its review step and again at the end, and
  **reads one** on its first,
  so the second machine is one page and a drop rather than seven pages of the same answers. The
  file is checked as it lands. A complete one — credentials included, database answering —
  skips the remaining five pages and installs on the spot; one with the credentials still blank
  fills the pages in instead; an unusable one is marked and the ordinary installation carries
  on underneath. `deploy = erase` stops to be confirmed unless the file also says
  `force_erase = 1`, in both installers: an answer file gets copied between machines, and the
  collection it destroys is whichever database it happens to name that day.
- **Fixed: `delete_installer` broke the command line installer.** Everything the two share —
  the requirement checks, the database work, the answer file — was in `public/install.php`, so
  deleting the wizard took half of `bin/install.php` with it and the next run died on a missing
  require. That half now lives in `src/installer.php`, which nothing deletes.
- `delete_installer` removes `public/install.php` when the install finishes, and `sign_in`
  lands the browser on the instance already signed in as the administrator it just made. Both
  off unless the answer file turns them on.
- A section written twice in an answer file is refused. `parse_ini_string()` keeps the last and
  discards the first without a word, which for `deploy` is the difference between rebuilding a
  database and leaving it alone.
  No username or password is written into it: those come out as `change-…-here`, and a file
  still carrying one is refused rather than installed with a database user by that name.
- The answer file is INI, parsed by `parse_ini_string()` and executed never — the wizard takes
  one by upload, and `require` on an uploaded file is remote code execution wearing a hat. One
  definition, in `public/install.php`, used by both installers.
- `--quiet` now says nothing at all when it works, with the reason on stderr and a non-zero
  status when it does not. `RETROVAULT_DB_PASS` and `RETROVAULT_ADMIN_PASS` override the two
  passwords so the answer file can be templated and hold no secret, and `--answers -` reads it
  from standard input so it need not exist on disk.

**Web**

- The image set a lookup fills is **Stock images** on both domains, in the form, the entry and
  the lookup review — it was Artwork in one place and Stock photos in another. `image_sections()`
  feeds the selects, so the name is now decided once.

**Audit, continued**

- **A pending registration now tells every admin**, in-app and by mail. `notify_admins()` was
  written and had no caller; a signup needing approval reached the security log and nothing else,
  so an admin who was not reading it that day found out when somebody asked why they still could
  not sign in. The link goes straight to `/manage/users`.
- **`item.lent_overdue` was a registered notification kind for a feature that no longer exists.**
  Lending was removed earlier tonight; this one entry was missed because nothing triggered it, so
  it never surfaced as broken — only as unused. Removed, and its slot in `notification_kinds()`
  taken by the new registration kind rather than left as a gap.
- **A test in `retrovault-tools` still referenced the removed kind** and only failed once the
  full suite ran after this change — a reminder that a single file's suite passing is not the
  same question as the whole tree agreeing with itself. Updated to exercise the same preference
  logic against `registration.pending` instead.

- **`rule_status()` was written and never called.** Both the form and the API still had their own
  inline `in_array(...) ? ... : 'owned'` for the status field it was built for. Both now call it,
  and `tests/fields.php` asserts they do — the same gap `rule_box_state()` had until this pass.
- **Fixed: `hardware_detail()` was written, documented, and never called.** It resolves "the
  entry's own value, or the model's if the entry has none" — its own doc comment says this exists
  to stop two pages disagreeing about which one wins. Nothing called it, so the entry page read
  `item_hardware` raw: any machine with a model set but nothing retyped onto the entry itself
  showed a **blank** model, interface, and fits field, when the model had the answer all along.
  Wired into the entry view's data and the Specification table. The edit form's pre-fill
  deliberately still reads the raw row — resolving there would silently write the model's value
  onto the entry as if someone had typed it.
- **Three functions in `installer.php` were dead**, superseded by a separately-evolved, actually
  wired implementation in `src/migrate.php` + `update.php`. Removed.
- **Four more removed**: `shared_library_ids` (a duplicate of a call made directly elsewhere),
  `log_count` (the one caller that would want it computes its own count instead),
  `metadata_debug_clear` (redundant with the reset `metadata_debug_on()` already does),
  `parts_fitting_model` (written for a model detail page that was never built — no route reaches
  one). `docs/TAXONOMY.md` referenced the last of these; corrected.
- **`notify_admins()` was found complete and unwired**; documented rather than deleted or
  silently wired in, then given its own pass: a `registration.pending` notification kind and a
  call beside the security-log line that already existed. An admin who signs up needing approval
  is told now, in-app and by mail, rather than only whoever next reads the log.
- **`store_logo()` was wrongly flagged as an unwired feature, and it was not one.** I checked
  whether that exact name was called, found it was not, and concluded company logos had no way in
  — without checking whether a *differently named* function already did the job. It does:
  `store_company_logo()` and `delete_company_logo()` are a complete, separately-written pair,
  fully wired through `taxonomy_save()` and a file input already sitting in the generic taxonomy
  edit form. Uploading a company logo has worked the whole time. `store_logo()` and its sibling
  `delete_logo()` — a generic pair for "companies or vendors", from before vendors merged into
  companies — were the genuinely dead ones, and are removed now that the working replacement is
  confirmed rather than assumed.
- **A first automated pass over-reported**: it flagged `metadata_search_amigahw`,
  `metadata_platforms_thegamesdb` and four others as dead. All are reached through
  `'metadata_search_' . $type` / `'metadata_platforms_' . $type` dispatch, not a literal call —
  checked individually against `metadata_provider_types()` before anything was touched. Two of
  the flagged names, `mobygames` and `csdb`, turned out to be **deliberately withdrawn features**
  with a test asserting they stay unused while their parsers remain reachable by name.

**API**

- **`src/rules.php`** — the rules an entry obeys, in one place, called by both the web form and
  the API. The same question had two answers before: `condition` was a validated enum on one side
  and free text on the other, and the rule that clearing "there is a box" also clears the box
  grade existed twice, in different words, with no reason to think they agreed.
- What is shared is the **rule**; what is not is the **policy on a bad value**. Each function
  returns null for "that is not one of these", and the form falls back to `unknown` while the API
  answers 422 — a person mid-page should not lose it to a select that cannot be wrong anyway, and
  a client that sent nonsense wants to know. Making those identical would have been a change to
  the web, dressed up as a refactor.

- The entry payload returns **`acquired_from`, `acquired_note`, `sold_to`, `sold_note` and
  `sold_currency`**. All five became writable last round and none was returned, so a client could
  set who a thing came from and never read it back.

- **Fixed: compatibility was read from one of the two places it is declared.** A model may name
  the machines it fits, and a single card may name them itself through `item_fits` — the
  *Compatible hardware* checkboxes on the web form. The check read only the model's list, so a
  peripheral whose compatibility had been recorded by hand looked like one that had said nothing:
  the answer came out right for the wrong reason, until somebody set a model, at which point it
  came out wrong. It calls `effective_fits()` now, which already knows the precedence.
- **Fixed: a machine with no model was refused by every peripheral that declared what it fits.**
  The check compared the machine's `model_id` to the peripheral's list and, finding NULL, read it
  as 0 and refused. Every machine in a fresh catalogue has no model — so the better a peripheral
  was catalogued, the more certainly it was rejected. Two silences, both meaning "cannot tell":
  a peripheral naming nothing goes anywhere, and a machine with no model cannot be checked
  against a list of models at all.
- **A maker or publisher can be sent by name.** `developer` and `publisher` accept a string and
  match it case-insensitively, by name then by slug, creating the company only when nothing
  matches. The app used to refuse — "add it under Companies on the web" — which is a phone
  telling somebody to go and find a computer. A near-match is **named in the log**: a source
  answering "Team17 Software Limited" to a library holding "Team17" is describing one firm, and
  no rule here can be sure of that, since the same rule would merge Sega and Sega Europe.

- **Fixed: the API has never handled a location.** Not by id, not by path — so "Where it is kept"
  on the phone was typed, sent and silently dropped. `location_path` is accepted now, matched on
  the breadcrumb somebody reads rather than on `locations.path`, which looks like the answer and
  is not: that column holds an id path (`/1/7/`) for subtree queries. Matching against it would
  have found nothing a client ever sends, with a test cheerfully saying the field was handled.
- **Provenance is writable**: `acquired_from`, `acquired_note`, `sold_to`, `sold_note`,
  `sold_currency` and `location_position`. The web has written these since it existed and the API
  accepted none of them, so an entry created from a phone could record what it cost and not who
  it came from.

- **Lending is gone from the platform.** It was half-removed already — `status_options()` had
  dropped `lent` but the columns, the enum value, the dashboard panels, the CSV columns and the
  API fields all stayed, so a client could set a status the web would not offer. Migration 0026
  finishes it: entries marked lent become owned, and **what was recorded is appended to the
  notes** rather than dropped, because somebody who wrote down who had a thing deserves to still
  be able to read it. The `lent`/`returned` event kinds stay in `item_events` for rows already
  written — deleting somebody's history is not what removing a feature means.

- **Fixed: `can_write_library()` never existed.** I invented the name; artwork import, fitting
  and unfitting all returned 500 with *Call to undefined function*. They use `can_write_item()`,
  which is also the stricter and more correct check.
- **The rules for what may be installed in what**, enforced on the server and offered through
  `/items/{id}/links/candidates`: only hardware, only peripherals, only into machines, and only
  where the peripheral either names this machine among those it fits or names none, with
  platforms agreeing.
- `/meta` reports **`app_version`**, the server's own — distinct from the API version and from
  any client's.

- **`GET /models`** — the canonical models an entry can be filed under. `items.model_id` has been
  writable for a while with no way to discover an id to put in it, which makes a writable field a
  field nobody can use. Narrowed by `category_id` the way the web's picker narrows it: a model
  belongs to a branch, and a list of every model on an instance is not a picker but a haystack.

- **The rest of `item_hardware`**: `interface`, `provides`, `fits`, `recapped_on`, `serviced_on`,
  `manufactured_year`, and the **specification rows**. Those are a JSON column of
  `{label, value}` rather than columns, because an Amiga has a chipset and a PC has a bus and
  neither list is finite. The API decodes it before sending, so a client is not parsing JSON out
  of a JSON field.

- **`/items/{id}/links`** — what is fitted to an entry and what it is fitted to, in both
  directions, plus fitting and unfitting. `item_links` is the catalogue's one genuinely
  relational idea and the API had nothing at all for it, so a phone could see *Installed
  peripherals* on the web and not know the relationship existed. `direction` decides which way
  round, because otherwise a client would have to know which of two entries is the parent before
  it could say they are related. Loops are refused through the same `item_link_would_loop()` the
  web calls.

- **Fixed: a library made through the API was invisible to whoever made it.** `owner_id` says
  whose a library is; `library_members` decides who may *see* it, and `accessible_library_ids()`
  reads the second. Neither create route wrote that row, so the library existed, appeared under
  library management — which asks the server for everything — and was missing from the caller's
  own list and every picker built from it. The web has always written both.

- `working_state` is writable and returned with the rest of the `hardware` object — the web calls
  it **Does it work**, and it is the first thing anybody asks about a machine.

- `POST` **`/libraries`** — make a library of your own, which any signed-in account may do. The
  admin route needed an administrator, so the API was stricter than the web for the same action:
  `library_create()` on the web checks only that somebody is signed in. `POST /admin/libraries`
  stays for administering an instance.

- `POST` **`/items/{id}/images/import`** — fetch a picture from a metadata source and attach it.
  The web has had this since metadata lookup existed; the API never did, so a phone could find
  the box art and not keep it. The server does the fetching, because it already knows how to
  check what came back is an image, resize it, and notice the same picture twice. Artwork lands
  as `official` provenance, never among somebody's own photographs.

- **The fields a client could read and not write.** `condition_grade`, `has_box`,
  `condition_box`, `condition_manual`, `condition_media` and `model_id` on the entry itself, and
  `model`, `board_revision`, `firmware`, `serial_number` and `modifications` from `item_hardware`
  — none of which the API accepted, so a phone could show a serial number and not correct one.
  `modifications` is the one that mattered most: with only `notes` writable, a client had to put
  modifications in the notes, which is the confusion migration 0014 exists to end.
- The detailed view returns a **`hardware`** object, null on software and on entries nobody has
  filled in. It is a query against `item_hardware` rather than a column read, so it happens on
  the single-entry view only — a list of two hundred does not need two hundred round trips for
  fields no list shows.
- Clearing **`has_box`** clears the box grade with it, as the web form does. Grading a box that
  is not there is meaningless.

- `meta.errors` on a metadata search is **always an object**. PHP encodes an empty associative
  array as `[]` and a populated one as `{...}`, so the field changed shape depending on whether
  any source had failed — disabling a single provider was enough to break a client that decoded
  the other one.

- `POST` **`/admin/users`**, so accounts can be made from a phone. Through the same
  `create_user()` the installer uses, which gives the account its personal library on the way
  past — the one shelf everybody is promised.

- `POST` and `DELETE` **`/profile/avatar`**, so a picture can be set from a phone. Multipart into
  `store_user_avatar()`, the same path the web form takes — one place decides what a valid
  picture is and what it is resized to.

- **`/admin/libraries`** — list, create, change, delete. The list is every library, not the ones
  the caller may read: an administrator needs it complete, since a library nobody can see is one
  nobody can fix. Deleting is refused for a library that still holds anything, and for the last
  one — an instance with none has nowhere to put the next thing somebody adds. Renaming moves
  the slug with it.

- `GET` **`/admin/users`** and `PATCH` **`/admin/users/{id}`**, so account management can leave
  the browser. Two refusals rather than warnings: removing the last active administrator, and
  changing your own role — undoing that would need the role just given up.

- **A 401 says which of five things went wrong.** No header at all, a token nobody has heard of,
  a revoked one, an expired one, an account since disabled — all produced "Send a valid bearer
  token in the Authorization header", which is true of every one of them and useful for none. It
  sent people to check a header that was fine. The no-header case now names the proxy in front
  as a candidate, because a header that leaves the client and does not arrive is the hardest of
  these to reason about from either end.

- **Every API refusal is now in the log.** Nothing the API did reached the log page before: no
  sign-ins, no refusals, nothing — so an operator watching the log while being told "the app
  will not save" saw an empty screen. Refusals about who you are go in the security stream, the
  rest in the server stream, with the method, the path, the status and the fields complained
  about.
- **A token issued to a device is recorded** as `api.token.issued`, named after the device, so
  "which phone was that" has an answer.

- `GET` **`/admin/logs`** with the filters the web viewer offers, plus the per-channel counts
  and the events that have actually happened, so a client draws the same tabs without four
  requests. `GET` and `POST` **`/admin/maintenance`**: every check is run to answer the list,
  because the reason to press a repair is that its check found something, and the check is run
  again afterwards so the answer says what is left.

- `docs/openapi.yaml` describes `/notifications`, `/notifications/read` and `/metadata/search`,
  which it had never mentioned. The suite now compares the routes in `public/index.php` against
  the spec and fails on anything missing — or written twice, which YAML resolves by keeping the
  last and saying nothing.

- The API suite covers the settings endpoints: every field kind, the bounds, the all-or-nothing
  rule on a batch, and that a secret never comes back. 27 assertions to 71.

- `GET`/`PATCH` **`/profile`** and **`/profile/notifications`**: your details, your password, and
  what you want to be told about.
- `GET`/`PATCH` **`/admin/settings`**: the instance settings, described rather than dumped — each
  field carries its kind, its choices and its limits, so a native client can draw the form
  without knowing the settings in advance, and a setting added later appears in an app nobody
  rebuilt. Secrets report only whether they are set.

**Instance settings**

- The paragraph above the log streams is gone. The tabs say Security and Server; explaining what
  those mean above a screen that shows them was furniture.

- The structure table **moved to the library screen**, where it answers the question people
  have. It counted the template set against the files — one answer for the whole instance — and
  now counts what a library holds against what there is to copy, beside the button that copies
  it. A row is marked only when the library has fewer; the filing tree is built once per
  platform, so its branch counts are legitimately larger. Instance settings keeps the address to
  fetch from, which is genuinely instance-wide, and the button that fetches.

- **Structure data** is one table: what this instance holds of each kind against how many the
  files held when they were last fetched, marked where they disagree. Every sync records both
  numbers and writes the local ones into the server log, so "when did the peripherals go from 4
  to 21" has an answer. An install syncs, so the record exists from the first day.
- **Force update**, beside Save, resyncs ignoring what is already present. An ordinary fetch
  skips a slug it recognises, so a correction to a row that shipped wrong could never arrive.
  Neither touches a library.
- The log **Test** panel is gone; **Write test log** sits beside Save in Logging, so it saves
  and then writes rather than testing what was stored last time somebody pressed Save.

**Fixed**

- **Deleting a machine left its branch behind.** `categories.platform_id` is `ON DELETE SET
  NULL`, so removing a platform left its root standing: the filing tree went on showing the
  machine's name with nothing behind it, nothing filed under it could say what it ran on, and
  resyncing the library did not repair it — the resync matches on slug, saw the branch and
  called the machine already built. The branch now goes with the machine, and a resync relinks
  a root that lost its platform some other way.

- A maintenance job reporting **what PHP will accept**: `post_max_size`, `upload_max_filesize`,
  `memory_limit`, which `php.ini` is actually loaded and under which SAPI. It flags an
  `upload_max_filesize` above `post_max_size` — which can never be reached, because the smaller
  number caps the whole request — and limits too low for a photograph of a boxed machine. The
  installer checked this once and is then deleted, which left no way to ask a running instance.

- **A command line install switched on no metadata sources.** The wizard has always enabled the
  ones needing no account; `bin/install.php` never did, so an instance built from a response
  file came up with nothing to look titles up with and no sign it was meant to have any. Both
  now share `installer_enable_metadata_sources()`. `metadata_sources` in the response file says
  whether to, and the wizard asks on its settings step — ticked, which is what it has always
  done without asking.

- A maintenance job for **specification names whose machine is gone**. Deleting a library takes
  its platforms and leaves the vocabulary behind pointing at rows that no longer exist —
  `ON DELETE SET NULL` on the category side, nothing at all on this one. Nothing read them and
  nothing counted them, and they accumulated: 4,552 on a database that had been used for a
  while.
- The maintenance API sent an **empty message for every job**. `maintenance_result()` calls it
  `note` and the endpoint read `message`, so the native screen showed a count and no sentence.

- **Specification names read 1158 against 589 on a freshly installed instance**, in red, with
  nothing wrong. `seed_library_hardware()` copies the interface vocabulary for a library's own
  platforms — a library with platforms and not the words for what plugs into them cannot
  describe a card — so the table grows by roughly the file's size with every library made. The
  count now takes the template rows only, and holds still as libraries are added.

- The **peripheral model count** on the settings screen read 0 while twenty-one were filed. It
  tested `role = 'peripheral'` on the model's own branch, and the tree declares that kind on the
  branch that means it — Expansions — with everything under it inheriting. A model is either a
  machine or a part, so it is counted as the counterpart of the machine line.
- Choosing a company on a **model or hardware entry** narrows the platform list only when the
  thing is a machine. A machine's maker built the platform; a peripheral's usually did not — a
  Phase 5 accelerator goes in a Commodore machine — and narrowing there removed the Amiga from
  the list and reset the platform on a model that had one.

**Metadata**

- Lookup against **OpenRetro, TheGamesDB, IGDB, the Amiga Hardware Database, the Big Book of
  Amiga Hardware, TheRetroWeb, Wikipedia, Wikimedia Commons and Wikidata**.
- Which sources answer for which branch is decided in the category tree and inherited
  downward.
- Nothing is written without review: every field and every image is offered and applied only
  when ticked.
- **Save and look up** on the entry forms, offered when the branch being filed into has a
  source switched on.

**Accounts and access**

- Local accounts, or sign-in through **LDAP / Active Directory** with group-to-role mapping.
- Registration modes: closed, public, by secret address, or by invitation — with optional
  email confirmation or administrator approval.
- API tokens for mobile and third-party clients.

**Running it**

- Browser installer with requirement checks, or a command-line install.
- Structure data for 63 machines, fetched from GitHub or the shipped copies. An instance
  running against template files older than itself still arrives working: what is a judgement
  rather than data lives in the code, and the tree is repaired on both sides if the fetched
  copy declares nothing.
- Maintenance jobs for the things that drift: orphaned photographs, photograph rows whose file
  is gone, branches with no machine, machines with no branch, blurbs left in notes.
- Syslog or file logging, SMTP with a proven-delivery check, and a `/healthz` endpoint for a
  load balancer.
