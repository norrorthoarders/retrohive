# The catalogue tree

## What it has to do

One library holds both machines and the software that runs on them, nested as
deeply as the collection needs, with the shape decided by whoever runs it rather
than by whoever wrote the schema:

```
My home library
├── Amiga                              ← the machine is the root
│   ├── Hardware
│   │   ├── Computers
│   │   ├── Peripherals
│   │   │   ├── Adapters
│   │   │   │   └── Network adapters   ← entries live here
│   │   │   └── Storage
│   │   └── Expansions
│   │       ├── Accelerator
│   │       └── Memory
│   └── Software
│       ├── Games
│       │   └── Racing
│       └── Applications
├── Mega Drive                         ← a console gets a console's tree
│   ├── Hardware
│   │   ├── Consoles
│   │   ├── Peripherals
│   │   │   ├── Controllers
│   │   │   └── Memory cards
│   │   └── Cartridges
│   └── Software
│       └── Games
└── Game Boy
    └── …                              ← no disk controllers, no antivirus tools
```

Peripherals appears under every machine, on purpose. Amiga peripherals and PC
peripherals are different objects with different sub-kinds, and pretending they
are one shared category to save typing makes the tree lie about the collection.

## Shape

One table, arbitrary depth, editable by whoever runs the library:

```sql
categories (
  id, parent_id,
  library_id,           -- whose taxonomy; NULL marks a template row
  library_key,          -- COALESCE(library_id, 0), so templates cannot duplicate
  domain ENUM('software','hardware'),
  role   ENUM('machine','peripheral','other'),
  platform_id,          -- which machine's branch this is
  applies_to SET,       -- template rows only: which machine classes get this kind
  name, slug,
  path, depth,          -- materialised, rebuilt on any move
  sort_order
)

items.category_id       -- an entry hangs off a leaf
UNIQUE (library_key, slug)
```

### Per library

Categories were the last shared thing, which was tolerable while the tree was one
flat taxonomy and stopped being so the moment a platform became a branch in it. A
platform belongs to a library, so the same path in two libraries has to be two
sets of rows — otherwise renaming one renames both.

Template rows (`library_id IS NULL`) exist only to be copied when a library is
made. Nothing is ever filed under one, and the entry form does not offer them.

### Rooted at the machine

Each platform gets its own tree: the machine, then `Hardware` and `Software`,
then the kinds. These are **real rows, not a view** — which is the point. A Game
Boy branch can simply not have Network adapters, and deleting one from it leaves
every other machine alone.

Slugs are prefixed with the platform (`amiga-adapters`, `pc-adapters`) because
they are unique per library and "adapters" has to be able to mean a different row
under each machine.

### Sized to what the machine is

A console is a sealed box you plug carts and pads into; a computer is not. Each
template kind carries `applies_to` — a set of `computer`, `console`, `handheld`,
empty meaning all — and seeding copies only the branches that belong:

| class    | platforms | nodes each |
|----------|-----------|------------|
| computer | 33        | 79         |
| console  | 25        | 39         |
| handheld | 5         | 34         |

The class comes from the machine models filed under the platform where there are
any, and from `platforms.machine_class` otherwise — a fallback for the sixty
platforms nobody has modelled, not a second source of truth.

A branch whose parent was excluded is excluded with it, so dropping `Expansions`
from a handheld takes Accelerator and Memory with it rather than orphaning them.

The assignments live in `structure/*.json`, so what a console gets is a data
question answered through the sync, not a release.

## Editing

- Add a child to any node, rename, reorder, move.
- Move takes its subtree with it and rebuilds paths.
- Delete only when empty, as with libraries - the same reasoning applies.
- Copy a subtree to another platform, because building Peripherals > Adapters >
  Network adapters eleven times by hand is how people give up. The copy keeps the
  source's `role`, takes the target's platform and library, and gets a slug free
  within that library.
- Reordering happens in the page and is written once with **Save the new order**,
  so nothing reloads while you are still arranging.
- Filter by name, machine class or manufacturer. A row survives if it matches or
  if anything beneath it does, so a hit three levels down still shows which
  machine it belongs to.
- A starter tree is seeded per platform, and can be deleted wholesale.

## Metadata sources

Scopes bind to a node and inherit downwards, exactly as they do now:

```sql
provider_scopes (provider_id, node_id, enabled)
```

Enable the Amiga Hardware Database at `Hardware > Amiga` and it covers every
node beneath. Switch it off at `Hardware > Amiga > Peripherals` and that branch
alone stops offering it. The nearest ancestor wins, which is the rule already
written and tested in `providers_for()` - only the column changes.

Because a platform node is in the path, "this scraper, on Amiga hardware only"
needs no separate platform column on the scope. That is a genuine simplification
over the version this replaces.

## What this replaces

The tree built earlier is platform-free, with `items.platform_id` beside
`items.category_id`. It avoids duplicating subtrees per machine, but it cannot
express a per-platform shape, which is what the collection actually has. The
inheritance logic and the path maintenance carry over unchanged; the node table
and the entry's foreign key are what differ.


---

## What is *not* in the tree

Three things sit alongside the category tree and are deliberately separate from
it. Collapsing any of them into the tree has been tried and does not work.

**Libraries.** A library is a shelf with members — the only access boundary in
the system. It is not a level of the tree: the same category (`Games`,
`Peripherals > Storage`) appears in every library, and an entry moving between
libraries does not change what kind of thing it is.

**Platforms.** The machine an entry runs on. Not part of the path, because
adding "Networking" once should not mean adding it eleven times, once under each
machine. A browse view composes library → domain → platform → category from the
entry's fields, which gives the nesting people expect without the duplication.

**Titles and hardware models.** What the thing *is*, as against your copy of it.
A title carries the developer, publisher and year; its genre is simply where it is
filed, since a genre is a category. The entry on your
shelf carries condition, completeness and what you paid. The category is a
property of the title *and* inherited by the copy, so the tree still describes
both.

## Where the domain lives

`categories.domain` is `software` or `hardware`, and it is the **single** place
that distinction is recorded. Everything else derives it:

- `v_items.domain` is selected from the joined category row
- the `/software` and `/hardware` routes filter on it
- the entry form reads it to decide which half of the form to draw
- `filing_options('hardware')` narrows the type picker, so adding a graphics
  card is never offered "Games"

It used to be stated in four places, which is four chances for them to disagree
during a change that looked unrelated. If you add a fifth consumer, derive it —
do not store it again.

## Hardware vocabulary

`hardware_vocab` is a small controlled list per platform: interfaces, sockets,
form factors and features. `platform_id = 0` means "applies anywhere", which is
the common case.

Zero rather than `NULL`, and this is worth knowing before you add rows: in SQL
no `NULL` equals another `NULL`, so with a nullable column the unique key on
`(kind, platform_id, code)` would not constrain the shared rows at all. You
could insert `('interface', NULL, 'zorro-ii')` twice and the database would
accept both. `provider_scopes.platform_id` uses the same sentinel for the same
reason.

`model_slots` says what a model accepts in these terms, and
`item_hardware.interface_vocab_id` says what a part presents. That pairing is
what makes *"what do I own that fits this A1200?"* an exact query rather than a
string comparison against free text. `model_slots` and `interface_vocab_id`
exist for this reason, but nothing queries them yet — there is no page a model
can be viewed on, only lists. `parts_fitting_model()` was written for that page
and removed when an audit found no caller; the query it would run is worth
keeping in mind if a model detail page is ever built. The free-text `interface`
column stays alongside the vocabulary either way, so nothing is ever
unrecordable.


## Machine specifications

A machine has whatever it has. `item_hardware.specs` is one ordered list of
label/value rows — `Processor`, `Memory`, `Kickstart`, `Trapdoor`, `Recapped`,
whatever the person decides is worth recording.

This replaced three mechanisms that all answered the same question and
disagreed: fixed `cpu`/`memory`/`storage` columns, a `field_values` blob keyed
by whatever fields a model declared, and a `slot_contents` blob keyed by slot
name. Every machine answered the same three questions whether or not they
applied, a monitor's tube size had nowhere to go at all, and a model with a
"Storage" field and a "Storage" slot overwrote itself in silence.

`model_fields` survives, in a smaller role: it *suggests* rows when a model is
chosen. The suggestion is a starting point and the list belongs to the entry —
a machine that has been upgraded no longer matches its model, which is exactly
the machine worth cataloguing.

The structured hardware columns that remain are the ones with query value:
`interface` and `interface_vocab_id` (so "what fits a Zorro III slot?" is an
exact match), `board_revision`, `serial_number`, `working_state`,
`video_standard`, `fits` and `provides`. Those are questions with answers you
want to filter and join on. A spec row is prose about one object.

### Fitted, versus standalone

Anything fitted inside a machine that you do not want to track separately is a
spec row. A peripheral you *do* want to track on its own — with its own
condition, serial number, photographs and value — is its own entry, added from
**Add a peripheral**, and joined to the machine through `item_links`.

The difference is whether the thing has a life of its own. A 512 KB trapdoor
expansion you will never move is a row; an accelerator you swap between two
machines and might sell separately is an entry.


## The hardware hierarchy

```
library                    who owns it, who may see it        libraries
  hardware | software      what sort of thing                 categories.domain
    class                  console, computer, handheld        platform_classes
      platform             Sega Mega Drive, Amiga, PC         platforms
        machine            the unit on your shelf             items, category role 'machine'
        peripheral         the pad, the accelerator           items, category role 'peripheral'
```

Two of those levels used to be wrong.

**A console was filed as a kind of computer.** `Console` and `Handheld` were
children of `Computers` in the category tree, which says something untrue. They
are siblings now, and the class itself moved to `platform_classes` — a table,
because "maybe more later" is the normal case and an arcade board or a
synthesiser should not need a deploy.

**Whether a category held machines or peripherals lived in PHP.** It was
`['computers', 'console', 'handheld']`, hardcoded, so the tree lived in the
database while the meaning of the tree lived in code. It is
`categories.role` now: `machine`, `peripheral`, or `other` for the cables and
blank media that are neither.

## Fitting a peripheral

A peripheral is a first-class entry — its own condition, serial number,
photographs and value — and is fitted to **at most one machine at a time**.
That is a claim about the physical world, so the database enforces it rather
than trusting every code path to remember:

```sql
fitted_child_id INT UNSIGNED AS (IF(relation = 'installed_in', child_item_id, NULL)) STORED,
UNIQUE KEY uq_fitted_once (fitted_child_id)
```

Generated so the rule applies to `installed_in` alone. The other relations —
`bundled_with`, `spare_for`, `connects_to` — are genuinely many-to-many, and a
NULL never collides with another NULL, so they stay unconstrained.

`fittable_peripherals()` is what makes the interface offer only choices that can
actually be made: same platform, not already inside something else, and in a
library you can read. A Mega Drive pad is not offered for a Saturn, and a card
already in one machine does not appear in another machine's list until it is
taken out.

One consequence worth knowing: `fk_link_child` has no `ON UPDATE CASCADE`.
MariaDB refuses a generated column that depends on a cascading foreign key, and
the cascade was inert anyway — `items.id` is a surrogate key that is never
rewritten, so the clause had nothing to fire on.


## Locations

Where things physically are: a tree per library, of any depth.

```
Computer room
  Cabinet
    Shelf 2
    Shelf 10
Loft
```

Nothing forces you through a level you do not use. "Loft" is a complete answer,
and so is "Computer room › Cabinet › Shelf 2 › Box A".

**Scoped to a library**, for the same reason everything else is: it is the only
access boundary in the system, so a shared club shelf does not list the rooms in
your house. The cost is real — a room holding entries from two libraries has to
be named in both — but the alternatives are worse: either your floor plan is
visible to everyone on the instance, or locations get a second ownership model
that nothing else uses.

**Not split by domain.** A shelf holds what it holds; a box of Amiga disks and
an A500 can share a cupboard, and making "Computer room" twice so hardware and
software each have one describes a distinction the room does not have. If you do
keep them apart, say so in the tree — `Disks` and `Machines` as two branches —
which is the same thing said once instead of twice.

**Names are unique within a place, not within a library.** Two libraries belong
to two people, and both of them have an office with a shelf 1 in it — neither
should have to call theirs "office-2". Equally, one library may hold
`Cabinet A › Shelf 1` and `Cabinet B › Shelf 1`, because those are two shelves.
What it may not hold is two Shelf 1s in Cabinet A.

That rule is enforced in `location_name_taken()` rather than by a unique key.
A key on `(library_id, parent_id, name)` looks right and is not: `parent_id` is
NULL at the top level, no NULL collides with another NULL, and so the rows most
likely to be duplicated by accident — the rooms — would be exactly the ones the
key ignored.

Locations have **no slug**. They had one, copied from `categories`, and it was
the cause of both bugs above: `unique_slug()` checks the whole table, so the
second person's "Office" became `office-2`, and the unique key it fed forbade
two shelves with the same name anywhere in a library. Nothing ever read the
column.

**Sorted naturally, with no order column.** Shelf 10 belongs after Shelf 2, and
`strnatcasecmp` says so without anybody maintaining a number — one that would
drift the first time you inserted a shelf in the middle. Where a floor is set,
it sorts first, so Basement comes before Living room rather than after it
alphabetically.

**Floors are optional, signed, and numeric.** 0 is the level you walk in on and
anything below it is negative. There are no names — no "Basement", no "Ground
floor" — because a building with two basement levels, a warehouse counting from
1, or anything not described in English needs different words and no different
numbers. It also matches what is in the box, so nobody has to work out that
"Basement" meant the −1 they typed.

**And they are inherited.** `location_floor()` answers with the nearest ancestor
that has one, and says whether it came from there. Put several rooms inside a
"Floor 1" and every shelf beneath them is on floor 1 with nothing typed —
fifteen places in a two-storey flat with a basement need the floor set three
times. Anything further down may still disagree and wins for itself and
everything below it, which is how a mezzanine works.

There is deliberately no separate table for floors, and this is why: a floor is
a place with a number on it. `Stockholm flat › Floor 1 › Study › Shelf 2` says
everything a rigid address-floor-room arrangement would, without forcing a loft
with two boxes in it to invent a floor record before it can exist.

**Whereabouts belongs to the entry, not the place.** `items.location_position`
is free text — "1", "a", "left", "behind the printer" — because people number
shelves, letter them, or describe them, and an integer would force everyone into
one of those and be wrong for the rest. It describes this object's spot rather
than a property of the shelf, which is why it sits on the entry.

Deleting a place takes what is inside it and leaves the entries alone —
`items.location_id` is `ON DELETE SET NULL`, so they stop claiming to be
somewhere they are not rather than disappearing with the shelf.
