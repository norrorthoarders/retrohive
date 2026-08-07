# Structure data

The machines, makers, genres and studios a new library begins with are not
really software. They are a catalogue of facts about the world that grows every
time somebody notices an omission, and keeping them inside `db/seed.sql` meant
adding a platform required a release.

They live in JSON instead, in two places:

- `structure/*.json` — ships with the install, and is what a synchronise falls
  back to when the network does not answer
- `https://github.com/norrorthoarders/retrohive` under `structure/` — the copy an
  existing instance can pull from without being upgraded

## What a template row is

Every row these files create is a **template**: `library_id` is NULL for the
tables that have it, nothing ever files an entry under one, and they exist only
to be copied into a library when one is created.

That is what makes synchronising safe. It cannot touch anybody's collection —
the worst it can do is add a machine nobody wanted, and it removes nothing.

## Synchronising

Instance settings → General → Structure data. Two buttons: fetch from the
configured URL, or reload the copies that shipped.

**Adds only.** Nothing already present is overwritten and nothing is deleted, so
a year you corrected in your own list stays corrected. A row is "already
present" if its slug is.

The order the files are applied in matters and is fixed: manufacturers, then types, then
genres and studios, then platforms, then everything that names a platform. A platform names a manufacturer;
a model names a platform and a type. Applied in any other order the references
would not resolve.

## The files

| File | What it holds | Key fields |
|---|---|---|
| `hardware_manufacturers.json` | Who made the hardware | `slug`, `name`, `country` |
| `software_categories.json` | The software type tree | `slug`, `name`, `parent` |
| `hardware_categories.json` | The hardware type tree | `slug`, `name`, `role`, `parent` |
| `game_developers.json` | Game studios and publishers | `slug`, `name`, `country`, `website` |
| `software_developers.json` | Everything else that ships software | same |
| `platforms.json` | Machines | `slug`, `name`, `maker`, `class`, `year`, `colour` |
| `hardware_connections.json` | Ports, slots and sockets — what a card plugs into | `slug`, `name`, `kind`, `platform` |
| `hardware_features.json` | What an expansion provides — CPU, RAM, SCSI | `slug`, `name`, `platform` |
| `hardware_machines.json` | Machine models — an A500, a Master System | `slug`, `name`, `maker`, `platform`, `type` |
| `hardware_peripherals.json` | Cards and add-ons — a Blizzard 1230, a Sound Blaster | same |

Each file supplies the thing its name promises, so no row repeats it: a row in
`hardware_categories.json` does not say `"domain": "hardware"`, and one in
`software_developers.json` does not say `"domain": "software"`.

References between files are by **slug**, never by id: ids are local to one
database and would be meaningless in a file shared between instances.

A row with no `slug` is skipped. A row naming a `maker` or `platform` that does
not exist is still created, with that reference left empty — a file that is
slightly ahead of another should not fail wholesale.

## Adding to it

Edit the JSON, open a pull request. The format is deliberately flat and boring
so that somebody who knows about a machine but not about PHP can contribute one.

```json
{
  "slug": "sharp-mz-2500",
  "name": "MZ-2500",
  "maker": "sharp",
  "class": "computer",
  "year": 1985,
  "colour": "#a6adc8",
  "sort_order": 400
}
```

`maker` is a slug from `hardware_manufacturers.json`; `class` is one of
`computer`, `console` or `handheld`. The class decides which category branches a
platform's tree is built from, and is the answer used when a platform has no
machine models to ask.

Category rows carry an optional `classes` — a comma-separated subset of the same
three, absent meaning every machine. It is what keeps disk controllers off a
Game Boy.

Category rows also carry an optional `stock_image`: the slug of one of the
pictures that ship in `public/stock/`, used for entries filed on that branch that
have no photograph of their own. Inherited down the branch, so declaring it once
on *Input devices* covers Gamepad, Joystick, Keyboard and Mouse beneath it.

This is the way to give a new kind of thing a picture — a line in this file
rather than a case in `stock_image_rules()`. A branch's answer beats the format
rules and the per-kind fallbacks, and loses to a picture a curator uploaded for
the branch themselves, which is held in a different column for exactly that
reason.

A slug naming a picture the running release does not ship is ignored rather than
refused, on the way in and again on the way out. A feed can therefore name
pictures ahead of the release that adds them, and an instance that has not
updated yet simply falls back to the per-kind picture — the same thing that
would have happened had the feed said nothing.

## What the repository needs

For **structure data**, a `structure/` directory at the root of the default
branch holding the eleven files. The same name the application uses on disk —
not `templates/`, which this project has meant view templates by since the
beginning. Nothing else — they are fetched raw, so no
release, no build, no API.

```
retrohive/
  structure/
    hardware_manufacturers.json
    software_categories.json
    hardware_categories.json
    game_developers.json
    software_developers.json
    platforms.json
    hardware_connections.json
    hardware_features.json
    hardware_machines.json
    hardware_peripherals.json
```

The default URL assumes the default branch is `main`. If it is `master`, change
the source in Instance settings → General → Structure data.

For **update checking**, at least one published release. The GitHub endpoint
answers 404 until there is one, which reads like a broken URL rather than an
empty shelf — so the message says as much.

Either can point somewhere else entirely. The update feed understands GitHub's
shape and a plain one:

```json
{ "version": "0.6.0", "url": "https://example.com/notes" }
```

which is a static file you can put anywhere.

## Updates

Instance settings → General → Updates asks GitHub what the latest release is and
says whether it is newer than what is running.

It is **asked, never acted on**. Something that upgrades itself is something
that can break itself at three in the morning, and this is a catalogue of
somebody's possessions.
