# Redesign: facets instead of a tree

## What is wrong now

The tree folds three independent questions into one hierarchy:

```
Hardware  ›  Amiga  ›  Peripherals  ›  Adapters
  domain      machine        what kind of thing
```

Because they are one path, the type half has to be repeated under every
machine. Measured on the current seed:

| | now (12 platforms) | all 65 platforms |
|---|---|---|
| tree nodes | 158 | **847** |
| genre rows | 258 | **2,795** |
| distinct types | 24 | 24 |
| distinct genres | 43 | 43 |

847 rows to express 24 ideas. Worse than the size is the maintenance shape:

- adding **Networking** as a type means adding it 65 times
- adding a **platform** means cloning a 12-node subtree
- renaming **Peripherals** means renaming 65 rows
- a genre defined on Amiga Games is invisible to C64 Games

None of that is a bug. It is what a single hierarchy costs when the things
being described are not actually hierarchical.

## What to do instead

Keep the browsing hierarchy. Stop storing it.

An entry answers three independent questions:

```sql
items
  library_id     -- whose shelf, and therefore who can see it
  platform_id    -- which machine
  type_id        -- what kind of thing
```

and **types** become a small shared tree, not one per machine:

```
Software                      Hardware
├── Games                     ├── Computers
├── Applications              ├── Peripherals
│   ├── Graphics and CAD      │   ├── Adapters
│   └── Music and audio       │   │   └── Network adapters
├── Demoscene                 │   ├── Storage
├── Utilities                 │   └── Networking
└── Operating system          ├── Expansions
                              │   ├── Accelerator
                              │   └── Memory
                              └── Parts and spares
```

About 30 rows. Adding **Networking** is one row and it applies to every
machine. Adding a platform is one row in `platforms` and nothing else.

```sql
types
  id, parent_id, domain ENUM('software','hardware'),
  name, slug, path, depth, sort_order,
  -- Almost always NULL. Set it only for a type that genuinely belongs to one
  -- machine - "Zorro card", "Trapdoor expansion" - so the shared case stays
  -- shared and the specific case is still expressible.
  platform_id
```

That last column is the answer to the objection that Amiga peripherals and PC
peripherals are different things. At the level of *kind* they are not: both have
adapters, storage, networking. What differs is the **interface vocabulary**,
which `hardware_vocab` already scopes per platform, and the instances
themselves. Where a type really is machine-specific, one nullable column says so
without duplicating the other 23.

### The hierarchy you browse is generated

```
My Collection                       ← library
└── Hardware                        ← domain, from types.domain
    └── Commodore Amiga             ← platform, from items.platform_id
        └── Peripherals             ← type
            └── Adapters
                └── X-Surf-100      ← the entry
```

Every level is a `GROUP BY` over entries that exist. Empty branches never
appear, which is better than the current behaviour: today a fresh install shows
twelve machines and sixty branches, all empty.

### Genres and scrapers follow

```sql
genres          type_id     -- Platformer on Games. 43 rows, not 2,795.
provider_scopes type_id, platform_id NULL
```

Both keep the inheritance already written and tested - only the column they
hang off changes. A scraper scoped to `(Peripherals, Amiga)` covers Adapters and
Storage beneath it, on Amiga, and nowhere else. That is the same rule as now,
expressed without needing a per-machine node to attach to.

## What this costs

The migration is real: `categories` becomes `types`, entries move from a
per-platform node to a shared one, and `provider_scopes` and `genres` re-point.
Mechanical, but it touches the entry form, the filters, the tree editor and the
API.

What is **not** lost: the browsing hierarchy, per-platform scrapers,
platform-specific types where they matter, arbitrary depth.

## The interface

The current screens are a database with forms on top. An inventory wants three
things it does not have.

### 1. Browse that narrows

One screen. Each choice reveals the next, and only shows what exists:

```
Library:   [ My Collection ▾ ]   Amiga Club Shelf
Domain:    [ Hardware ] [ Software ]
Machine:   Amiga (18)   C64 (4)   MSX (1)
Type:      Computers (2)   Peripherals (11)   Expansions (5)
           └ Adapters (4)   Storage (3)   Networking (4)
```

Counts come from the same query that draws the level, so nothing shows a branch
with nothing in it.

### 2. Adding that assumes the last thing

Cataloguing is repetitive - twenty Amiga games in a sitting. The form should
remember library, machine and type from the previous entry and lead with the
title. Everything else collapsed until asked for.

For hardware the fields differ: no genre or release year, but interface,
what it fits, condition, and what it is installed in. Same form, different
half revealed, driven by `types.domain`.

### 3. An entry that shows where it sits

```
X-Surf-100
My Collection › Hardware › Commodore Amiga › Peripherals › Adapters › Networking
Installed in: Amiga 1200 (trapdoor)
```

The breadcrumb is the structure made visible, and every segment is a filter.

## Order of work

1. `types` table and the migration off per-platform nodes - everything else
   depends on it.
2. The browse screen. It is the one that makes the structure legible, and the
   fastest way to find out whether the model is right.
3. The add flow.
4. Entry detail with the breadcrumb.

Steps 2 to 4 are where the value is. Step 1 is what makes them cheap to build
rather than expensive.
