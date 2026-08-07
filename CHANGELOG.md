# Changelog

**A genre filter that spans formats, and Movies/TV Shows as a kind.**

## `genre=` asks about the branch, not one platform's copy of it

`category=` names one node in one platform's tree. That is right for hardware and
software, where the platform is part of the answer - an Amiga graphics card is a
different thing from a PC one, filed apart on purpose.

It is wrong for films and records. The tree is copied per platform, so Action
exists once per format, and `category=blu-ray-movie-action` means *action films
on Blu-ray* with the DVD and VHS copies sitting under different rows of the same
name. There was no way to ask for action films.

`genre=movie-action` matches every platform's copy at once, by `source_slug` -
what each copy carries to name the template it came from, and what is already
indexed. Everything at or beneath each copy is included, so `genre=adventure`
also finds what is filed under Point and click. Narrowing to one format is what
`platform=` is for, and the two combine.

`category_to_api()` now reports `source_slug`, which is what lets a client offer
a genre once instead of once per platform. Null for a branch somebody added by
hand - it has no template behind it and nothing to collapse with.

## kind accepts movie and tv_show

Both are real `category_role` values, so both are the same column test that
already answered machine and peripheral. Video needed it for the reason hardware
did: Movies and TV Shows are two different things to browse and were sharing one
list. `music` is accepted too, for symmetry, though audio has only the one branch
today.

Full suite: still 1 of 25, unchanged.

This package is **build 103**.

**Games and Applications get real trees - 32 more branches, so a shelf of Amiga
boxes divides into something a person would recognise.**

Games had four genres, one of which was Shoot 'em up and none of which was
Adventure. Applications had Graphics and CAD with Paint under it and nothing
else, so a word processor and a disk utility were both filed as "Applications".

    Games          Action, Adventure (Point and click, Text adventure),
                   Beat 'em up, Board and card, Educational, Fighting, Pinball,
                   Platformer, Puzzle, Racing, Role-playing, Shoot 'em up,
                   Simulation, Sports, Strategy

    Applications   Business and productivity (Database, Spreadsheet,
                   Word processing), Communications, Education and reference,
                   Emulation, Graphics and CAD (3D and rendering, CAD,
                   Desktop publishing, Paint), Music and audio (Sampling and
                   editing, Tracker and sequencer), Programming and development,
                   System software, Utilities (Backup, Disk and file tools),
                   Video

Pinball and Text adventure are on the list because they divide a retro shelf in a
way they would not divide a modern one. Point and click sits under Adventure
rather than beside it, because that is what it is a kind of.

The four rows that already existed keep their slugs and are re-placed rather than
replaced - a slug is what `source_slug` joins a library's copy back to its
template, and re-slugging Action would orphan every copy of it for no gain.

Checked, rather than assumed, across all four feeds at once:

- Every genre declares `role: other` and resolves to its branch's kind. A
  genre resolving to nothing would be invisible to both the stock pictures and
  the metadata sources, and silently so.
- **No game genre is machine-class gated.** A console game is still a game, and
  a `classes` value copied down from Applications would have hidden the whole
  Games tree from every console in the library.
- **Every application type stays `computer`-only**, matching Applications
  itself, so a Game Boy is not offered Desktop publishing.
- No branch in software, video or audio declares a stock picture. A declared
  picture beats the format rules, and the rules already know a boxed Amiga game
  looks different from a jewel-cased PC one.
- All 126 template category slugs are still unique. The slug is the global key -
  `structure_apply()` looks a category up by slug alone among the rows with no
  library - so a collision would not error, it would silently overwrite another
  section's branch.

Full suite: still 1 of 25, unchanged.

This package is **build 102**.

**Genres for films, series and records - 49 branches the video and audio sections
have been missing since they were added.**

Software has had Action, Platformer, Shoot-em-up and Strategy under Games from
the beginning. Video and audio had two branches and one branch respectively:
Movies, TV Shows, Music, and nothing beneath any of them. A shelf of four hundred
records that can only be filed as "Music" is a list, not a catalogue.

    Movies      Action, Adventure, Animation, Comedy, Crime, Documentary,
                Drama, Family, Fantasy, Horror, Musical, Mystery, Romance,
                Science fiction, Thriller, War, Western
    TV Shows    Animation, Children's, Comedy, Crime, Documentary, Drama,
                Factual, Fantasy, Mystery, Reality, Science fiction, Sitcom,
                Soap opera, Thriller, Western
    Music       Blues, Classical, Country, Dance, Electronic, Folk, Funk,
                Hip hop, Jazz, Metal, Pop, Punk, R&B and soul, Reggae, Rock,
                Soundtrack, World

Each carries `role: other` and inherits its kind from the branch above, which is
the shape software's genres already use and the reason `category_effective_role()`
walks upward. Confirmed rather than assumed: a Sitcom resolves to `tv_show`, an
R&B record to `music`.

**The slugs are prefixed - `movie-drama`, `tv-drama`, `music-rock` - because a
template slug is the global key.** `structure_apply()` looks a category up by
slug alone among the rows with no library, so a movie genre called `action` would
have collided with the game genre that already exists and quietly overwritten it.
Two of them are given explicit suffixes where slugifying the name produces
something nobody would recognise: Science fiction is `sci-fi`, not
`science-fiction`, and R&B and soul is `rnb-soul` rather than `randb-and-soul`.

**No genre declares a stock picture, deliberately.** A declared picture beats the
format rules, so putting one on Drama would have given a Blu-ray drama and a VHS
drama the same image - the rules already know that a film on Blu-ray comes in a
Blu-ray case and one on VHS in a slip. Checked both directions: no genre carries
`stock_image`, and a film resolves by format through them.

Metadata sources are unaffected. Seeding writes at the topmost branch of each
kind and lets it inherit downward, so this adds 49 branches and no provider rows
- Movies still covers every genre beneath it.

Sorted alphabetically and spaced by ten, so a genre can be slipped between two
later without renumbering the rest.

Full suite: still 1 of 25, unchanged.

This package is **build 101**.

**Three more pictures: a monitor, a memory board and an accelerator card.**

Displays, Memory and Accelerator had been inheriting the generic expansion card
from the group above them - honest, in that they are peripherals, and wrong about
all three. Each now declares its own.

The catalogue is 37 pictures across 74 files, 5.4 MB. Storage and the four drives
under it are still the outstanding gap: a hard drive shows a picture of a card,
because there is no drive in the shipped set.

Full suite: still 1 of 25, unchanged.

This package is **build 100**.

**Storage gains the four kinds of drive, and Input devices is just Input.**

    Peripherals
      Input              Gamepad, Joystick, Keyboard, Mouse
      Displays
      Storage            Hard drive, Floppy drive, Zip drive, Tape drive
      Adapters           Network adapters
                         Storage controller  SCSI, IDE, RAID
                         I/O card            Serial/parallel, Multi-I/O
                         Graphics card
      Expansions         Memory, Accelerator, Sound card
      Modifications      Modchip, Flash cartridge

Storage now holds the drives themselves, one level below the branch, the way
Storage controller holds SCSI, IDE and RAID. The two are worth keeping apart and
now visibly are: a Zip *drive* is a peripheral you own, and the card it hangs off
is filed under Adapters.

**Input devices is renamed and not re-slugged.** The slug stays `input-devices`,
because it is what `source_slug` joins a library's copy back to its template,
what the unique key is on, and what the stock-image repair matches on - changing
it would orphan every existing copy of the branch for the sake of tidiness. What
anybody reads is the name.

The four drives are in sentence case - "Hard drive", not "Hard Drive" - matching
"Graphics card", "Sound card" and "Network adapters" already in the tree. Zip is
Iomega's brand rather than an initialism, so it is not shouted.

They declare no picture of their own and inherit the generic card from
Peripherals, which is honest but not good: a hard drive is not a card, and there
is no drive picture in the shipped set to give them. Four more images would fix
it, and until then the fallback is at least not wrong about the kind of thing.

`classes` unchanged, so the console and handheld trees are still nine branches
apiece and see none of this. All three structure checks re-run clean.

Full suite: still 1 of 25, unchanged.

This package is **build 99**.

**The cards are grouped again, this time on what the card is *for* rather than on
where it physically sits.**

Two branches under Peripherals, beside the things that were never cards:

    Peripherals
      Input devices      Gamepad, Joystick, Keyboard, Mouse
      Displays
      Storage
      Adapters           Network adapters
                         Storage controller  SCSI, IDE, RAID
                         I/O card            Serial/parallel, Multi-I/O
                         Graphics card
      Expansions         Memory, Accelerator, Sound card
      Modifications      Modchip, Flash cartridge

The rule, stated so the next card has an obvious home: an **adapter** connects
the machine to something else - a network, disks, ports, a monitor - and an
**expansion** makes the machine itself more capable, with memory it did not have,
a faster processor, or sound it could not previously make.

Two placements are arguable and easy to flip if the rule reads differently to
you. A **graphics card** is filed as an adapter, on the grounds that it is
literally a display adapter and the thing it connects to is the monitor; the case
against is that a Picasso II makes an Amiga capable of resolutions it had no way
to produce. A **sound card** is filed as an expansion, because a Sound Blaster
gives a PC synthesis it did not have; the case against is the row of jacks on its
bracket. Each is one line in `structure/hardware_categories.json`.

Memory, Accelerator, Displays and Storage declare no picture of their own and
inherit the generic card from the group above them, which is checked rather than
assumed - the walk has to pass through the new mid-level nodes without stopping
at them, and a leaf that stopped early would show a blank card where a specific
picture exists.

`classes` still gates per row, so the console and handheld trees are unchanged at
nine branches apiece and neither is offered any of this.

Full suite: still 1 of 25, unchanged.

This package is **build 98**.

**Expansions is gone. Its branches sit directly under Peripherals, which is now
the only place a peripheral goes.**

Build 96 nested Expansions under Peripherals instead of merging it, on the
grounds that eleven direct children with a mouse beside a RAID controller loses a
distinction worth keeping. That was the wrong call to make unilaterally: the
complaint was that the tree offers two answers to "where does a card go", and
nesting still shows two branches. It now shows one.

Peripherals, in order: Input devices, Displays, Storage, Adapters, Memory,
Accelerator, Graphics card, Sound card, Storage controller, I/O card,
Modifications. Outside the case first, then what goes inside it, then what is
done to it. The cards that had been grouped under Expansions are still adjacent,
which is most of what that node was doing.

`classes` gates per row and is unaffected by where a row sits, so nothing
widened. Checked by building the tree each machine class would actually receive:
a computer gets 25 branches, a console and a handheld 9 apiece - Gamepad and
Joystick yes, Keyboard, Sound card and SCSI controller no.

`category_repair_roles()` keeps `expansions` in the list of hardware names it
recognises, although the shipped tree no longer produces that branch. That list
exists to repair instances whose feed predates the current shape, and one of them
is now any instance built before this build - a name matching nothing costs a
comparison, and removing it would strand exactly the trees it is for.

Full suite: still 1 of 25, unchanged.

This package is **build 97**.

**Expansions moves under Peripherals rather than beside it, and Packaging models
moves to Global.**

## Expansions is a kind of peripheral, not a rival to one

Both branches carried `role = peripheral`, sat at the same level, and differed
only in `classes` - so the tree offered two top-level answers to "where does a
card go", with nothing on screen to say which. Expansions is now a child of
Peripherals, and Peripherals' children are ordered outside-in: Input devices,
Displays, Storage, Adapters, Expansions, Modifications.

Not merged flat, which is what a first reading of the complaint suggests. That
would give Peripherals eleven direct children with a mouse beside a RAID
controller and no grouping left at all - the two top-level branches were
confusing, but the distinction they drew is real and worth keeping one level
down. Nesting removes the ambiguity without losing it.

`classes` still gates per row, so a Game Boy is offered Gamepad and Joystick and
none of the cards, exactly as before - the gate is on each branch and not
inherited from where it sits.

The branch keeps the name "Expansions" rather than gaining a clarifying
parenthetical. `category_repair_roles()` matches the shipped hardware tree by
lower-cased *name* when repairing an instance whose feed predates the kinds, and
renaming it would have quietly broken that path for a cosmetic gain.

## Packaging models is global

It sat under Software and never only meant software. The shipped set has always
carried VHS clamshells, vinyl sleeves and CD jewel cases beside boxed Amiga
disks, and the category picker on its own form deliberately offers all four
sections. Filing it under Software said something untrue about it, in the same
way listing Platforms under Hardware did before it was moved to Global for the
same reason.

The table is still `software_models`. Renaming it is a migration for no gain, and
the screen has said "Packaging models" throughout.

Software now holds Environments alone, which is right: what a machine can run is
a fact about software and about nothing else here.

Full suite: still 1 of 25, unchanged.

This package is **build 96**.

**Seventeen more hardware pictures, and - more usefully - the taxonomy can now
say which picture a branch's things get, so the next one is a line of JSON
rather than a case in PHP.**

## The structure feed decides

Until now a stock picture was chosen by role and format alone, and that works for
releases because the format *is* the shape: a Blu-ray really does come in a
Blu-ray case. It does not work for hardware. A sound card and a SCSI controller
are both `peripheral` on the same platform with the same media, and no rule
reading a media string can tell them apart. The thing that knows is the branch
the entry is filed under, and the branch had no way to say so.

Category rows in the structure files now carry an optional `stock_image`, held in
a new `categories.stock_image` column (migration 009) and inherited down the
branch - declared once on *Input devices*, it covers Gamepad, Joystick, Keyboard
and Mouse beneath it. It beats the format rules and the per-kind fallbacks, and
loses to a picture a curator uploaded for the branch.

Deliberately a second column rather than a reuse of `default_image_filename`.
That one holds a decision somebody made; this one is rewritten on every structure
import. In one column the import would either silently overwrite a curator's
choice or be unable to tell it apart from its own - so, two columns, and the
curator's wins.

A slug naming a picture the running release does not ship is ignored rather than
refused, on the way in and again on the way out. The feed can name pictures ahead
of the release that adds them, and an instance that has not updated falls back to
the per-kind picture - the same thing that would have happened had the feed said
nothing.

Also carried into each library's own copy of the tree, which the copy statement
was not doing. `default_image_filename` deliberately is not copied - it names a
file on this instance's disk and a template has none - but a shipped picture
exists on every install by definition, so a template can safely name one.

## Sixteen new peripheral branches

`hardware_categories.json` grows from 14 rows to 30: Sound card, Storage
controller with SCSI/IDE/RAID beneath it, I/O card with Serial/parallel and
Multi-I/O, Input devices with Gamepad/Joystick/Keyboard/Mouse, and Modifications
with Modchip and Flash cartridge. Twenty-four of the thirty now name a picture.

Keyboard and Mouse are `computer`-class; Gamepad and Joystick are not, because a
pad is as much a console thing as a computer one. Flash cartridge is
`console,handheld`.

## A judgement call worth flagging

The `isa_` and `pci_` in these slugs describe the picture, not a rule about where
it may be used - the generic *Sound card* branch uses the ISA image on every
platform, Amiga included. An ISA sound card and a Zorro sound card are the same
silhouette at thumbnail size, and insisting on the difference would need a
picture per bus per function and still be wrong about the next machine. Anyone
who disagrees for a particular platform can set that branch by hand: the trees
are per-platform copies, so the Amiga *Sound card* branch is a different row from
the PC one and the existing picker already sets it.

## Applying it to an install that already exists

New maintenance job, **Branch pictures not carried into libraries**. A library
gets its copy of the tree when it is made, so one made before this version has
branches with nothing declared while their templates now declare something. The
repair copies the template's answer down, matched on the indexed `source_slug`,
and never touches a branch somebody has given a picture of their own. Branches
added by hand carry no `source_slug` and are left alone - they never came from a
template, so there is nothing to be out of step with.

The images arrived at mixed sizes with off-centre content and were trimmed to
their alpha bounds and re-centred at the same relative scale as the rest, so the
grid does not jump. Two of the seventeen - the compact graphics card and the PCI
network card - are variants no branch names, reachable only by picking them by
hand. That is on purpose rather than an oversight.

Full suite: still 1 of 25, unchanged.

This package is **build 95**.

**Hardware gets stock pictures after all - a beige desktop for machines, an
expansion card for peripherals - reversing the judgement in build 88.**

That build left hardware out deliberately, on the grounds that a generic
computer is a weaker claim than a generic DVD case: every keep case really does
look like that, and no two machines do. The reasoning still holds and is worth
keeping in the file, but it was the wrong call. What it left on a hardware shelf
was a grey rectangle saying nothing at all, and "this is a computer" is more
than nothing. The entry's own page already captions a stock picture as not being
a photograph of this copy, and the whole feature can be switched off, so nobody
is misled by it who does not choose to be.

Both are reached by kind alone, with no format rules above them. Hardware has no
packaging axis to vary on - what a machine "comes on" is not a question - so
every machine gets the same picture, which is the honest limit of what a generic
image can say here.

The two arrived 1536x1024 with their content off-centre, against a catalogue
that is uniformly 1024 square. They are trimmed to their own alpha bounds and
re-centred at the same relative scale the existing fifteen use, so a card does
not sit in a 3:2 frame among square ones and make the grid rows jump. 2.2 MB and
2.6 MB of PNG down to 163 KB and 104 KB of WebP, transparency intact.

Checked against the case that would have been easy to miss: a machine whose
platform slug is `cd`, or whose media reads `CD-ROM`, still gets the computer
rather than being dragged into the music or film rules. Role gates before format
does, so it never reaches them. The existing fifteen are unchanged, confirmed by
running the same twelve cases again.

Full suite: still 1 of 25, unchanged.

This package is **build 94**.

**The already-installed check from build 88 exempted the one machine it needed
to cover.**

`api_link_refusal()` learned to refuse a peripheral already fitted somewhere -
and then exempted the machine being edited:

    if ($fittedIn !== null && (int) $fittedIn['parent_item_id'] !== (int) $machine['id'])

So a card in another machine was correctly withheld, and a card in *this* one was
still offered. That is the only case anybody actually meets: you open an Amiga
2000, see the BigRAM 2008 listed under Installed peripherals, and find it in the
picker below as though it were free. The exemption was written for the
peripheral's own form, which asks the same question from the other end and needs
its current host to stay selectable - but that screen already re-offers the host
itself, so nothing needed the exemption at all.

Refused now whenever the part is fitted anywhere, with the two situations
worded apart: "already installed in Amiga 2000" when it is here, and "already
installed in X. Remove it from there first" when it is elsewhere.

Full suite: still 1 of 25, unchanged.

This package is **build 93**.

**Compatibility is editable at last - both halves of it. The three tables have
been enforced since they were added, against lists nothing outside the engine's
own interface could supply.**

`api_link_refusal()` consults `effective_compatibility()` before letting a card
into a machine, and has done throughout. But `item_to_api()` reported neither
`item_compatibility` nor `item_environments`, and `hardware_model_to_api()`
reported no `model_compatibility` - so the rule was running against data no
client could read or write. A rule enforced on data nobody can edit is worse
than no rule at all: it refuses, and the person refused has nowhere to go and
correct it.

- `item_to_api()` gains `compatibility` and `environments` behind the detail
  flag, with the rest of the per-row lists. `compatibility` carries the
  effective answer, the names, `from` (`model`/`item`/`none`), and
  `own_model_ids` - what the entry itself says as against what it inherits. A
  client drawing tick boxes needs that last one: the boxes edit the item's own
  list, and pre-ticking an inherited answer would make clearing a box appear to
  do nothing.
- `POST`/`PATCH /items` accept `compatibility` and `environments` as id arrays,
  through `api_apply_item_lists()` beside media and links. Replaced wholesale
  when the key is present, left alone when absent - so an empty array clears and
  an omitted key preserves. Environments are constrained to the entry's own
  library, matching what `sync_item_environments()` enforces for the web form.
- `hardware_model_to_api()` gains `compatible_model_ids`, and both write
  endpoints accept it. This is the authoritative half: a model's list beats a
  card's own, because a copy of a BigRAM 2008 cannot fit something a BigRAM 2008
  does not. A machine sending one is **refused** rather than ignored - a machine
  is what things fit into, and accepting the list would be recording the
  relationship backwards.

Silence still means "any machine on its platform" everywhere, which is what it
has always meant here - ticking nothing is not the same as fitting nothing, and
reading it that way would refuse every properly-filed card in a fresh catalogue.

Full suite: still 1 of 25, unchanged.

This package is **build 92**.

**Three more gates carrying the same unreachable administrator exemption the
admin gate had - found by looking for the shape rather than waiting for the
symptom.**

`api_require_admin()` was fixed in build 88: it called `api_require_write()`
first, which requires a library *membership*, so an administrator promoted by an
LDAP group was refused before the role was ever consulted. Three more functions
had the identical structure, and each had an explicit administrator exemption
written on the line immediately after the check that made it unreachable:

- `api_require_curates_library()` - the category tree, locations, companies
- `api_require_owns_library()` - hardware and packaging models
- `api_require_curates_any()` - tags

Every one reads `if (!is_admin_user(...) && !can_...)`. The exemption was
written, deliberately, for exactly the accounts that could never reach it. All
three now take `api_require_auth()` plus `api_guard_mutation()` - the same CSRF
and write-scope rules as any other call - and then ask the question they meant to
ask. Membership is still the whole of a non-administrator's access, which is the
part `acl.php` means to be strict about, and the gate was checked against an
ordinary account with no memberships to confirm it still refuses.

Full suite: still 1 of 25, unchanged.

This package is **build 91**.

**Pictures can now be filed as the publisher's artwork or as somebody's own
photograph, from outside the engine - the axis `item_images.provenance` has
carried since it was added, and which nothing but a metadata agent could ever
set.**

`store_item_images()` has taken a `$provenance` argument throughout and
`image_to_api()` has reported it throughout. What was missing was any way in:
`api_item_images_upload()` read `kind` from the request and hardcoded the other
half, so a scraper could file the publisher's box art as official and a person
scanning that same box by hand could not. One of the two axes was writable and
the other was not.

- `POST /items/{id}/images` accepts `provenance`, on both the multipart and the
  base64 paths. Anything that is not exactly `official` is `personal` - the safe
  direction, since mistaking somebody's snapshot for the publisher's artwork
  misrepresents it while the reverse merely under-claims. The base64 path was
  also never writing the column at all, so every picture that arrived that way
  took the schema default regardless of what was asked for.
- `GET /items/{id}/images?provenance=` filters to one side, for a client drawing
  two galleries.
- `PATCH /images/{id}` accepts `provenance`, so a picture can be moved between
  the two without being deleted and re-uploaded - it keeps its id, its caption,
  and its place as the cover. Here an unknown value is **refused** rather than
  rounded down: a move that silently did nothing would read as the request being
  ignored, which is a different bug to chase than a validation error.

Full suite: still 1 of 25, unchanged.

This package is **build 90**.

**Packaging models can now say what they are for. The three child lists the
`software_models` table was designed around - the fields a title is asked, what
the box should hold, and what it comes on - are exposed by the API and writable
through it for the first time.**

The tables have existed throughout and the engine's own screen has edited them
throughout. What was missing was the API: `software_model_to_api()` returned a
name, a platform, a category and a note, and `api_software_model_payload()`
accepted the same four. So every client other than the engine's own interface
saw a packaging model as a label with nothing behind it - which is what a
"Packaging models" page with no packaging on it amounts to.

`GET /software-models/{id}` now carries `fields`, `contents` and `media_list`.
The index carries `field_count`, `content_count` and `media_count` but not the
lists: three child queries per row would be a hundred and twenty of them to draw
a table of forty models that shows none of it. The counts come down as
subqueries on the one statement rather than being filled in per row afterwards.

POST and PATCH accept all three. Each list is replaced wholesale when its key is
present and left entirely alone when it is absent - the engine's own rule, and
the hardware model editor's beside it: the rows that arrive are the model's
complete answer, and merging them with what was there would invent a third list
nobody wrote. Absent and empty stay different instructions, which is what makes
`PATCH {"name": "..."}` safe and still lets a list be emptied with `[]`.

A row with a blank label is dropped rather than refused, because a form that
keeps a spare row at the bottom to type into posts an empty trailing row every
time and that is the ordinary state of the thing.

Media rows are checked against `media_option_values()`, the same vocabulary the
engine's own select is built from - free text there is how a library ends up
holding both `3.5" disk` and `3.5 inch disk` and being able to count neither. An
unrecognised medium is dropped and **named back** in `meta.media_ignored` rather
than refusing the whole save: a model that saves with one row fewer than was sent
looks exactly like a save that failed, and silence there is the worst of both.

`GET /meta` now carries the media vocabulary, grouped as the engine groups it, so
a client can build the same select instead of hardcoding twenty strings and
drifting out of step with the validator that will silently drop them.

`software_model_media()` joins its two siblings in models.php. Both existing
callers had written the query inline, which is part of why the API had no obvious
thing to call.

`media` and `year_from` remain readable and remain unwritable, matching the
engine's own form: the media list is a child table now, and the year belongs to
the title rather than to a shape of release. The columns stay so an old value is
still readable.

Full suite: still 1 of 25, unchanged.

This package is **build 89**.

**Fifteen generic packaging pictures now ship with the package, and an entry with
no photograph of its own falls back to whichever one matches what it is - plus
four real bugs found and fixed along the way, three of them pre-existing and one
of them the reason metadata lookups on films and records found nothing at all.**

## Generic pictures for entries with no photograph

A catalogue with no photographs in it is a wall of grey rectangles saying "no
photo yet", and most catalogues start that way: an agent finds cover art for the
famous releases and nothing for the rest, and photographing two hundred boxes is
a weekend nobody has. `public/stock/` now holds fifteen blank mock-ups of the
packaging itself - a big box, a jewel case, a VHS in its slip, a record half out
of its sleeve - re-encoded as WebP with their transparency intact, 21 MB of PNG
down to 2.1 MB with no visible loss. Quantised PNG was tried first and rejected:
the gradients and drop shadows posterise.

`cover` now resolves in three steps rather than two - a real photograph, then the
branch's own picture, then a generic one - and reports which answered in a new
`source` field (`photo`, `category`, `stock`, or null). `is_default` keeps its
old meaning exactly, so a client reading only that needs no change.

The stock step sits *below* the branch's own picture deliberately. Setting a
picture on a branch is something somebody did on purpose; this is an automatic
guess, and if the guess won, the deliberate act would be an override that
overrode nothing.

Nothing is written to the database. A `stock:` reference is resolved at read
time by `image_url()`, so an entry that gains a real photograph tomorrow stops
resolving to one on its own and there is no cleanup to forget. The files sit
outside the uploads directory entirely: they are part of the package, identical
on every install, restored by a redeploy for free, and invisible to everything
that manages uploads.

Which picture is chosen comes from what the entry already says - its kind, what
it comes on (media rows first, then `media_type`, then the platform, which for
the video and audio sections *is* the format), and whether `completeness` says
`loose`, which is the whole reason the bare-tape and bare-disc pictures exist.
Twenty cases were run against the real matcher rather than reasoned about,
including the two that collide by substring: a game on CD-ROM correctly gets the
big box rather than being caught by the music CD rule, and "VHS tape" correctly
matches VHS rather than cassette.

Hardware gets nothing, on purpose. A generic picture of "a computer" is a lie
about a specific machine in a way that a generic picture of a DVD case is not.
Software gets one picture per kind rather than one per era, because the big box
is what boxed software looked like across every platform this catalogue covers.

`GET /stock-images` lists them, and `POST /categories/{id}/image` now accepts a
`stock` slug as well as a file, so a branch can be pointed at one deliberately
without uploading a copy. Stored as a reference, so removing it later deletes no
file and leaves every other branch pointing at the same one alone. The whole
feature can be switched off with the new `stock_images` setting, which is an
honest position: a generic picture describes a format, not an object.

## Metadata lookups on films, series and records asked no source at all

Looking up a film against an instance with TMDB configured and working found
nothing, and logged nothing - which read as a broken source and was in fact a
question that was never asked.

`seed_library_provider_scopes()` enumerated four kinds: machine, peripheral,
game, application. Video and audio branches were never given a `provider_scopes`
row, so `providers_for()` returned an empty set for them, so `metadata_search_all()`
consulted nobody. This is the same gap `category_effective_role()` had and had
fixed - roles were added to the template data and the lists that enumerate them
were not extended to match - and it is the third time that particular omission
has surfaced.

The kinds list is fixed, but that only helps libraries seeded afterwards, so
there is now a maintenance job - **Branches no source is switched on for** - that
reports every top-of-kind branch nothing is switched on for and repairs them by
running the same seeding, which only ever adds and never overrides a decision
somebody has made.

And the message was wrong in a way that hid the cause. "No source recognised that
title" is a claim about what the sources answered; the truth was that there had
been no question. `metadata_search_all()` now returns a `consulted` count, and
both lookup screens say "No source was asked" when it is zero, naming the two
places to fix it.

## An administrator from a directory could reach no admin screen

`api_require_admin()` called `api_require_write()` first, which requires library
membership. That is deliberate for libraries - `acl.php` says so, so that an
administrator does not silently acquire the ability to read everybody's private
shelves - but instance administration has nothing to do with it. An account
promoted to administrator by an LDAP group mapping has no membership anywhere, so
every instance endpoint answered 403 "This account has no library it is allowed
to change" to a genuine administrator. The web interface showed the admin menus,
because it asks the role; the API refused them, because it asked membership.

Worse, the same fact had already downgraded their token: both sign-in paths
issued a read-only token to any account that `can_edit_anything()` said no to, so
the token was refused even for reads on those screens.

Fixed in three places. `api_require_admin()` now applies the CSRF and write-scope
guards - factored out as `api_guard_mutation()` - and the admin role, and does
not consult membership. The write-scope guard is asked per method rather than per
endpoint, so a read-only token may now read admin screens, which are all GETs,
and still cannot write. And both token paths make an administrator an exception
to the downgrade.

## A peripheral already installed somewhere was still offered

`api_link_refusal()` checked domain, kind, compatibility and platform, and never
asked whether the part was already fitted. So the picker on a machine's edit page
listed every peripheral in the library including the ones already inside other
machines - and choosing one did not merely record something false, it hit the
`uq_fitted_once` key the schema already carries and failed. The rule was
enforced; it was just enforced at the worst possible moment. Now refused by name,
saying where the part currently is, which corrects the picker and the write path
together.

## Category default pictures were deleted by the orphan sweep

Both `bin/cleanup-uploads.php` and `maintenance_upload_is_orphan()` built their
set of referenced filenames from three tables and there are four:
`categories.default_image_filename` was never counted. A picture uploaded as a
branch default read as an orphan, and the repair deletes what the check reports -
so uploading one and later running the sweep deleted the file and left the row
pointing at nothing. Pre-existing, unrelated to the stock pictures, and found
while checking that the new shipped files could not be eaten the same way. They
cannot: they are not in the uploads directory.

## Renames

**Logs** is now **Instance Logs** and **Maintenance** is now **Instance Status**,
in both the engine's own interface and the web client.

Full suite: still 1 of 25, unchanged.

This package is **build 88**.

**A full audit of `structure/`, checked against everything built this session rather than
assumed current - found and fixed one real, genuine gap.**

`structure/metadata_agents.json` - the canonical home for a provider's own tested-platform list
and default-kind assignment - never got a MusicBrainz entry. The provider itself works correctly
regardless, since the code carries a matching hardcoded fallback, but the file that's supposed to
be the real source of truth for this data was simply never updated when the provider was added.
Added, matching the same shape TheAudioDB's own entry already uses.

Everything else checked came back genuinely correct, not just assumed so: all twelve packaging
models present and correctly linked to their real platforms; both audio and video category files
using the extended role values; all seven video/audio platforms present; the hardware role fix
for memory, graphics cards, and accelerators still holding; a real `director` credit role
present for the credits feature to use. Confirmed by actually running a fresh `structure_sync()`
against a real database and reading its own summary, not by eyeballing the JSON files alone -
every one of the fifteen structure types synced with zero errors, and MusicBrainz's own
`tested_with` and `default_for_kinds` confirmed genuinely populated from the file after the fix,
not merely believed to be.

Full suite: still 1 of 25, unchanged.

This package is **build 87**.

**A real metadata source for music - MusicBrainz, read from their own documented API and
respecting both requirements their docs call out explicitly rather than treating as optional:
a meaningful User-Agent (their docs are specific that an anonymous one is throttled separately,
on top of the ordinary rate limit) and roughly one request per second per IP, the strictest of
the three rate checks they describe. The rate limit here is set deliberately closer to a full
second than the shorter delay other providers use, matched to what their own docs actually say
rather than reused from a source with a more generous limit.

A genuine release-level search, not an artist-only one - title, artist, label, catalogue number,
country, format, and where the release actually carries one, a real barcode. The first metadata
source in this catalogue able to fill that field in at all; `metadata_to_item_fields()` gained
barcode support alongside this provider, closing a gap that's existed since the field itself was
added several rounds back with nothing populating it.

Refuses outright, with a clear reason, when no contact URL or email is configured - not a
convenience refusal but their own API's real requirement, surfaced honestly rather than sending
a request likely to be throttled.

Proved live against a response shape built from MusicBrainz's own confirmed documentation
examples, not guessed: a full successful parse including the real barcode and catalogue number;
the User-Agent header confirmed correctly formed and sent; every real failure path checked - no
contact configured, a genuinely empty result, and a network failure, each handled and reported
rather than swallowed.

Full suite: back to 1 of 25 on the first attempt this time, not after a follow-up fix - confirmed
by name, not just by count.

This package is **build 86**.

**`item_specs` - a real, working way to write a non-hardware entry's own spec rows through the
API, closing what the previous round left as data with nowhere real to be written.**

Worth being direct about how this actually went: a first attempt at this landed the new handling
inside the wrong function entirely - `api_apply_item_hardware()`, which is called after an item
already exists and only ever writes to `item_hardware`. Setting a `specs` value there built a
variable nothing read; the item saved successfully and the field stayed null, silently. Found by
testing the actual write, not the code that looked right - a live PATCH request confirmed the
value never reached the database, which is what led to tracing it to the wrong function. Moved to
`api_item_input()`, the function whose return value genuinely reaches the `items` table, and
given its own accumulated-error handling to match every other field around it rather than the
hard-exit-on-first-problem behaviour it briefly had.

Proved live end to end this time, not just at the API boundary: a real spec row applied through a
direct PATCH, confirmed in the database; the same thing again through the real web form -
submitted, confirmed stored, confirmed pre-filling correctly on a second edit, confirmed showing
on the entry's own page. A hardware entry's own form confirmed unaffected throughout.

`docs/openapi.yaml` updated with the new field. Full suite: back to 1 of 25, unchanged.

This package is **build 85**.

**A real schema change: `items.specs`, so every domain has somewhere to keep a metadata
lookup's own genre, director, running time, or catalogue number - not just hardware.** A JSON
column, same shape as `item_hardware.specs`, added with a real migration
(`008_item_specs.sql`). Hardware keeps its own dedicated column rather than sharing this one -
`item_hardware.specs` belongs to the hardware detail row itself, a genuinely separate record
from the item, and splitting a machine's specs across two places would be worse than the two
columns existing side by side.

`metadata_spec_rows()` and `metadata_apply_specs()` now check hardware's own detail row first,
falling through to the entry's own column when there isn't one - no domain lookup needed, since
only one of the two places can ever hold a row for a given entry. The hardware-only gate on both
the preview and apply endpoints is gone, now that there's somewhere real for a non-hardware
entry's spec rows to land rather than nowhere at all.

**Two real regressions caught by the full suite and fixed before anything shipped, not
discovered later.** This project keeps a hardcoded, exhaustive list of every column the `items`
table is expected to have, specifically so an unclassified column - one added without anyone
remembering to say what it's for - gets caught immediately rather than drifting unnoticed;
`specs` is now on that list. Separately, three metadata providers added earlier this session
(TMDB, TheTVDB, TheAudioDB) had shipped with an empty, unfilled `tested_with` placeholder - real
platform slugs belong there for any source that doesn't filter by platform itself, the same real
list `structure/metadata_agents.json` is the canonical home for. Filled in properly, in both the
structure data itself and the code's own fallback default, not just whichever one the failing
test happened to read.

Proved live, with real database confirmation, not assumed from the code: a movie's genre and
director applied through the real API landed in `items.specs`, with zero `item_hardware` rows
created for it; a machine's chipset and RAM, applied the same way, correctly still landed in
`item_hardware.specs` with `items.specs` left untouched - the regression check that actually
matters here, confirmed rather than assumed safe.

Full suite: back to 1 of 25, the same baseline as every round tonight - confirmed by name, not
just by count, that the two real failures this round found are genuinely gone and nothing else
broke alongside them.

This package is **build 84**.

**`GET /admin/metadata-providers`'s own `meta.types` gained a `credentials` field - the piece a
client needed to render a provider's api_key as a real, labeled, masked field rather than one
more row in a generic list.** The `credentials` metadata itself has existed on every provider
that needs one (TMDB, TheTVDB, TheAudioDB) since each was added, but was never actually reachable
through the API - server-side data describing a shape nothing outside the server could read.
Closed the same way the last few gaps this session were: found by checking what already existed
before building anything new, not assumed missing.

`docs/openapi.yaml` gained real documentation for this endpoint too, which previously had none
beyond a bare "200: OK".

Full suite: still 1 of 25, unchanged.

This package is **build 83**.

**`item_kind_label()` now recognises `movie`, `tv_show`, and `music` - closing a gap flagged
explicitly, more than once, as deliberately deferred earlier this session rather than fixed on
the spot, now actually closed.** The function already correctly returned `machine`, `peripheral`,
and `game` from a category's own declared or inherited role; `movie`, `tv_show`, and `music` sat
in the same role enum, recognised everywhere else added this session (the category tree's own
Kind picker, the role-based category filter, the platform-matching "Add a movie" entry point) but
never here, where it silently fell through to an empty string on every video or audio entry.

A small, related polish caught while fixing this rather than left half-done: `kind_label`, the
capitalised version this powers, used a bare `ucfirst()` that would have rendered `tv_show` as the
literal "Tv_show" - an underscore left in a display label. A small, explicit map instead of a
generic transform, so "TV" reads as the acronym it is.

Proved live against real seeded entries, not assumed from the code alone: a movie and two
different music entries confirmed showing `kind`/`kind_label` correctly populated for the first
time, while a machine and a game were confirmed unaffected - a genuine regression check, not just
new coverage. The TV-show label specifically checked at the function level too, since no seeded
entry of that kind exists to fetch through the API.

A real mistake caught before it shipped, worth naming rather than glossing over: the first attempt
at documenting this in `docs/openapi.yaml` mixed YAML's flow and block styles in a way that's
invalid together, breaking the file outright - caught immediately by validating it, not assumed
correct because the edit looked reasonable.

Full suite: still 1 of 25, unchanged.

This package is **build 82**.

**Round 2 of the title/credits system: metadata lookups can now write real, linked credits -
`GET /metadata/preview` gained a `credits` field, closing the actual, narrow gap once a check of
what already existed turned up more than expected.**

Before writing anything, `metadata_apply_credits()` - the function that turns a candidate's
credits into a real row linked to a title, auto-creating the title itself if none is linked yet -
turned out to already exist and work correctly. So did TMDB's own candidate builder, which has
been populating `candidate['credits']` with the director since the round that added TMDB itself;
an earlier summary calling this an unbuilt gap was simply wrong, and worth saying so plainly
rather than letting stand. The one thing genuinely missing: the preview endpoint never surfaced
`candidate['credits']` at all, so nothing on a review screen could ever show or offer it, however
complete the write side already was.

Proved live, twice, with real database confirmation rather than trusting the API's own response:
a director credit applied to a movie already linked to a title landed as a new, real row
alongside an existing one, not a replacement; the same credit applied to a completely unlinked
item correctly auto-created a title from the item's own fields and attached the credit to it.

`docs/openapi.yaml` updated to match. Full suite: still 1 of 25, unchanged.

This package is **build 81**.

**`GET /hardware-models` gained `?platform_id=` filtering** - the same narrowing
`/software-models` already had, needed for the client's own new "known model" picker on the
item form to filter its list to the platform already chosen, rather than showing every
catalogued hardware model regardless of machine.

`docs/openapi.yaml` updated to match. Full suite: still 1 of 25, unchanged.

This package is **build 80**.

**`GET /admin/system-status` gained `top_clients` - up to ten of the most recently active real
devices, with the account they belong to and the IP address they were last used from.** Built on
`api_tokens`, the only table that actually tracks individual devices with an address - a token's
own last-seen stamp, not a request-count table this endpoint didn't otherwise have. Revoked
tokens excluded, since a device already cut off has nothing to say about who is using the
instance now. Named honestly rather than oversold: this is "most recently seen," not "most
active" in any volume sense - the table behind it has no per-device call tally to rank by.

Proved live: real tokens inserted, including one deliberately revoked, and confirmed the
endpoint returns the two live ones with correct account, IP, and timestamp while genuinely
excluding the revoked one - not merely assumed from the query.

`docs/openapi.yaml` updated to match. Full suite: still 1 of 25, unchanged.

This package is **build 79**.

**A real metadata source for music - TheAudioDB, and a genuine correction of what the free key
actually does versus what its docs describe, checked by hand against the live API rather than
taken on faith.** Their own documentation's search-by-album method (`searchalbum.php`) and
discography listing (`discography.php`) both looked like the obvious fit for "add a music
record" - tried both directly against the real, live API using the exact example from their own
docs, and both come back essentially unusable on the free key: `searchalbum.php` refuses outright
even on their own documented example, and `discography.php` answers with a single, id-less album
carrying no artwork at all. What the free key does answer well, confirmed the same way, is a plain
artist search - real biography, genre, style, record label, and genuine artwork.

Built around what actually works rather than what the docs suggested first: an artist lookup, not
an album-specific one. Documented plainly in the provider's own description, not left for someone
to discover the hard way - a candidate's title reads as the artist's name, and the record label
comes through correctly as the "publisher"/"label" field this catalogue already uses for audio
entries.

Proved live against the real response shape captured directly from the API, not a synthetic
guess - genre, style, country, biography, three separate images, and the real front-end URL
pattern (confirmed by search, not assumed) all mapped correctly. Every real failure path checked
too: no key, the confirmed live shape for no matches, and a network failure, each handled and
reported honestly rather than swallowed.

Full suite: back to 1 of 25, the same baseline as both metadata additions before it.

This package is **build 78**.

**A real metadata source for TV shows - TheTVDB, read straight from their own v4 OpenAPI spec
rather than assumed.** A genuinely different shape from TMDB's own provider: TheTVDB requires a
separate login step first - the API key is exchanged for a JWT bearer token, valid for a month
per their own docs, rather than being sent as a static credential on every call the way TMDB's
own key works. The token is cached rather than fetched fresh on every search - proved directly, not
assumed: a second search against the same key made exactly one request, not two, confirming reuse
actually happens rather than merely being intended.

TheTVDB's own search index turned out to already carry most of what a candidate needs directly -
overview, year, network, genres, a poster - so unlike the movie provider, no second details call
per result. Network stands in as the answer to "whose is this," the same way a film's own studio
does - a network commissions a show rather than producing it in the film sense, but it's still the
one-word answer this field means everywhere else in this catalogue.

Declares no platform opinion, the same reasoning the movie provider already has - a series has no
real per-machine release the way a game does.

Proved live against realistic response shapes for the full flow and every real failure path:
login then search on a cold cache, confirmed making both requests with the correct bearer token
on the second; a second search against a warm cache, confirmed making only the search request,
not a second login; a missing key refused outright; a login itself failing with 401, surfaced
rather than swallowed; a genuinely empty result set returned cleanly.

Full suite: back to 1 of 25, the same baseline as the movie provider before it - confirming this
addition, unlike the near-miss two rounds back, left nothing else broken.

This package is **build 77**.

**A real metadata source for movies - The Movie Database (TMDB), read straight from its own API
docs rather than assumed.** Search, then a details call per candidate for what the search
endpoint doesn't carry - overview, runtime, genres, production companies, and the director by way
of `append_to_response=credits`, all in the same request rather than a second round trip.
Authenticates with TMDB's own Bearer-token method, the one their docs recommend over the shorter
v3 key. Poster and backdrop both come through as real candidate images, at real, appropriately
different sizes - a poster wants to be seen fully; a backdrop is worth a smaller preview.

Declares no opinion on platform - a film has no real per-machine release the way a game does, so
`metadata_rank_results()` correctly falls back to title closeness alone rather than being handed a
comparison it has nothing to answer.

**Worth being direct about a real, severe near-miss caught before it went anywhere**: an editing
mistake while inserting this new provider left `metadata_search_wikipedia()`'s own function
signature stripped from the file, its entire body orphaned as an unreachable block that `php -l`
had no way to flag, since a bare `{ }` block is syntactically valid PHP on its own. Caught not by
linting but by directly searching the file for the function's own name and finding nothing -
wikipedia lookups would have silently, completely stopped working the moment this shipped. Fixed,
and the full test suite was run specifically to confirm the fix actually took, not merely assumed
to have.

Proved live against realistic response shapes for both success and every real failure path TMDB's
own docs describe: a working search and details call parsed correctly end to end, including a
director found through the appended credits; a missing API key refused outright with a clear
message; a real, live TMDB behavior worth knowing - it answers a bad key with an ordinary 200 and
a `status_code`/`status_message` pair rather than an HTTP error - correctly surfaced rather than
silently swallowed; a genuinely empty result set returned cleanly with no error at all.

Full suite: back to 1 of 25 - the same baseline, now including live confirmation that the near-miss
above left nothing broken.

This package is **build 76**.

**`software_models` gained platform filtering and the `media`/`year_from` fields it always had at
the schema level but never exposed - closing the gap for a real "pick a packaging shape" feature
on the client.** `media` in particular is what the new picker needs to actually do anything -
found missing while building it, not assumed present.

Proved live: the endpoint's real response now carries `media` for every one of the twelve
templates this repo ships; `?platform_id=` correctly narrows the list to one machine's own
shapes rather than returning all twelve regardless of what's being asked for.

Full suite: still 1 of 25, unchanged.

This package is **build 75**.

**Seven real packaging templates added for video and audio - VHS clamshell, LaserDisc, DVD, Blu-ray,
vinyl sleeve, CD jewel case, cassette - reusing `software_models` rather than inventing a parallel
table.** The table's own schema was already genuinely domain-agnostic (platform, category, media,
name - nothing hardware- or software-specific about any of it), and the column that references it
(`titles.software_model_id`) already carries no domain restriction either; a video or audio title
could already point at one of these rows, it just never had any to point at. Renaming the table
itself to something less misleading was considered and set aside - the "music" section rename
earlier this session showed exactly how much a rename like that can touch, and the functional
need here doesn't require it.

Each new model carries real specifications and real contents, the same as every other model this
repo ships - this repo's own test suite checks both are present on every shipped row, and would
have refused a version that only had a bare name and platform.

Proved live: a fresh `structure_sync()` added all seven with zero errors; every one confirmed
carrying both fields and contents; every one confirmed linked to its own real platform (VHS, DVD,
Blu-ray, vinyl, CD, cassette, LaserDisc) rather than left dangling.

Full suite: still 1 of 25, unchanged.

This package is **build 74**.

**`GET /categories?role=` fixed to use the effective, inherited role - the foundation for the new
"Add a game"/"Add a machine" style entry points, and a real, significant bug on its own.** The
filter only ever compared a row's own literal `role` column, never the inherited value
`category_effective_role()` already computes - the same walk `item_kind_label()` uses to decide
what an entry itself is. Since the ordinary case is a branch declaring its kind once at the top
and letting everything below inherit it, the old filter answered "what can I file a game under"
with only the handful of branches that happened to say `game` on their own row, missing every
leaf that inherited it - which, on a real library, is most of them.

Extended the same filter's own whitelist to the full role enum - `game`, `application`, `movie`,
`tv_show`, `music` - alongside the `machine`/`peripheral`/`other` it already had. `role` on
`POST`/`PATCH /categories/{id}` already gained these in an earlier round; this closes the read
side to match.

`docs/openapi.yaml`'s own documentation for `GET /categories` was close to nonexistent - no query
parameters described at all despite four being real and load-bearing. Documented properly.

Proved live against a real seeded library: `role=game` correctly returned 45 categories (not the
single branch that declares it directly), `role=music` returned real audio categories, and
`role=machine`/`peripheral` were confirmed unaffected by the fix - a genuine regression check, not
assumed safe.

Full suite: still 1 of 25, unchanged.

This package is **build 73**.

**Audio, actually fixed at the root - `structure/music_categories.json` renamed to
`audio_categories.json`, closing a real bug the client-side rename alone left standing.**

The `sections` table itself already read "audio," with a comment explaining exactly why -
audiobooks and podcasts are audio, and neither is music. What that comment didn't anticipate:
`structure_sync()` derives which section a category file belongs to from the file's own name
(`music_categories.json` looks up a section slugged `music`). With the section genuinely renamed
to `audio` and the file still called `music_categories.json`, every attempt to seed the audio
branch of the category tree failed outright with a foreign key violation - caught live, not in
review, the moment a real seed was attempted end to end. Fixed by renaming the file itself and
the four places in `structure.php` that reference it by name, matching the same
file-name-declares-section convention every other domain already follows.

**`role` extended to accept `movie`, `tv_show`, and `music`** - `POST`/`PATCH /categories/{id}`
only ever accepted the hardware/software half of the kind enum; a branch could never be set to a
video or audio kind through the API at all. Now cascades its section exactly the way
machine/peripheral and game/application already do - `movie`/`tv_show` move a branch into Video,
`music` moves it into Audio.

Proved live: a fresh `structure_sync()` seeded the audio category branch with zero errors,
confirmed against the real database; the API's own role-update endpoint confirmed accepting
`music` and correctly cascading the section.

`docs/openapi.yaml` updated to match. Full suite: still 1 of 25, unchanged.

This package is **build 72**.

**A real, pre-existing gap found while checking the full suite after unrelated client work -
`GET /directory` was never documented in `docs/openapi.yaml`, caught by this repo's own
completeness test.** Fixed. Nothing about the endpoint itself changed.

This package is **build 71**.

**"What a library holds" - `GET /libraries/{id}/contents`, a client for the real app's own
library_contents_index() and library_contents_summary(), built for the same reason the real app
built it: a bare item count is a poor thing to make a delete decision against.** Every entry,
paginated, plus the platforms, makers, models, and places the library defined for itself - the
things people forget a library owns until it's gone. Owner or instance administrator, matching the
real app's own comment about who should see this: it was administrator-only originally, which that
comment itself calls the wrong way round.

Proved live with real data, not assumed: the endpoint correctly returned 14 real entries, 16 real
platforms, 25 real companies, 6 real locations, 10 real hardware models, 5 real software models,
and the real owner membership, matching a genuinely seeded library exactly.

Worth being direct about the rest of this round's testing: this session hit severe, repeated
environment instability while proving this - the database process died mid-test more times than
any other round tonight, including within single, unbroken scripts. The core endpoint above was
directly, thoroughly proven with full real data despite that. What could not be completed was a
full live HTTP round-trip through the web client on top of it; that half was instead verified by
rendering the real client's own template directly against the exact data shape the live API had
already confirmed, which succeeded cleanly with no warnings - a genuine, if narrower, form of
proof than the live browser test this session has otherwise held to throughout.

A real regression caught by the full suite, the same shape as before: the new endpoint wasn't in
`docs/openapi.yaml`, caught by this repo's own completeness test, fixed, confirmed the suite is
back to 1 of 25 afterward.

This package is **build 70**.

**Public libraries are always read-only to join now - a real behavior change, not a rename.**
Confirmed against the current design in conversation first: the six access levels and the
private-library invite flow already matched what was described almost word for word: the one
genuine difference was that a shared library could previously be published two ways, read-only or
read-write, letting anyone who joined the general way become a Contributor outright. That option
is gone. Joining a public library now always grants Viewer, nothing more - a higher role for a
specific person still goes through the same invitation mechanism a private library already uses,
which grants any level up to Administrator and is entirely unaffected by this.

Fixed at the source rather than hidden behind a form: `library_visibility_flags()`, shared by
every path that can set this, no longer has a write-granting state - a caller that still sends the
old `public_write` value is treated exactly as `public` now, not refused and not silently
different. Every "set" site across both the still-live original app and the newer API-driven one
was checked and fixed the same way, not just one of them.

A migration clears the now-invalid state on any library that already carried it, rather than
leaving an upgraded instance able to show a state nothing can select or reproduce again.

Proved live: updating a library with the old, removed value now correctly leaves it read-only, not
refused and not read-write; joining it the general way was confirmed to grant Viewer and nothing
else; an administrator explicitly inviting somebody to Editor on that same public library was
confirmed to still work exactly as before - the two mechanisms are genuinely independent. The
migration itself was proved the same way every schema change has been this session: a real,
simulated old instance carrying the old state was correctly caught and corrected.

Full suite: still 1 of 25, unchanged.

This package is **build 69**.

**Five new endpoints, a client for the real app's own "Library access" page - what you already
have, what's waiting on an answer, and what's open to join.** `GET /profile/libraries` combines
all three tabs' worth of data in one call, matching the real page's own single render rather than
three round trips. `POST /libraries/{id}/join`, `/leave`, `/invite/{accept,decline}`, and
`/ownership/{accept,decline,withdraw}` are direct clients for library_join(), library_leave(),
and the invitation/ownership halves of library_admin_save() and library_ownership_respond() -
copied field by field, not re-derived, including the parts easy to get wrong: leaving is refused
on any personal library regardless of whose it is, not just this account's own; accepting an
ownership offer swaps both the owning column and the membership rows in one step, with the
outgoing owner kept on as an admin rather than dropped to nothing.

`library_to_api()` gained `access_label` - a human label for the raw access string every caller
already got, so a client never has to carry its own copy of what "curator" means.

Proved live, thoroughly, covering every real state rather than just the happy path: a real pending
invitation showed correctly and was accepted through the real endpoint, with the database
confirmed changed; joining a real published library correctly granted exactly what it offers, no
more; leaving was confirmed genuinely refused on a personal library even when it wasn't the
caller's own, and refused again on a library the caller owns outright; a real ownership offer was
accepted and the outgoing owner's own membership row was confirmed demoted to admin, not curator,
not removed.

A real regression caught by the full suite, the same shape as before: the new endpoints weren't in
`docs/openapi.yaml`, caught by this repo's own completeness test, fixed, confirmed the suite is
back to 1 of 25 afterward.

This package is **build 68**.

**Default category images - what an entry looks like with no photograph of its own, inherited
down the branch the same way "kind" already is.** `categories.default_image_filename`, migration
005; `category_effective_default_image()`, the same nearest-ancestor-wins walk
`category_effective_role()` already does for kind rather than a second inheritance mechanism
invented alongside it; `POST`/`DELETE /categories/{id}/image`, curator-level, reusing the same
validated upload machinery a profile picture already uses.

`item_to_api()`'s own `cover` field now falls back to the category's effective image only when an
entry genuinely has no photograph of its own, real or brought in by a metadata agent - and reports
`is_default` so a client can tell a real photo from a placeholder. `category_to_api()` reports both
`own_image` (only ever this node's own upload, so a "Remove" button never implies there's a file to
remove when there isn't) and `effective_image` (what an entry filed here would actually show right
now, inherited or not).

Proved live, thoroughly: uploaded a real image to a category, confirmed a real item with no photo
correctly inherited it; uploaded a real photo directly to that same item afterward and confirmed
the real photo won outright, not the category default; removed the category image and confirmed a
different item in the same branch correctly fell back to no cover at all, not something stale.

A real regression caught by the full suite, the same shape as before: the two new endpoints
weren't in `docs/openapi.yaml`, caught by this repo's own completeness test, added and confirmed
the suite is back to 1 of 25 afterward. Separately, the full migration path was proved rather than
assumed - a genuinely simulated old instance missing this column was correctly caught by `doctor`,
correctly fixed by `up`, and confirmed clean by `doctor` again afterward.

This package is **build 67**.

**A real report - Software models and Credit roles don't sync from the repository - traced to
its actual cause: `structure/software_models.json`, the template file the sync reads from, was
genuinely empty. Not a bug in the sync mechanism itself, which already, fully supports both -
`credit_roles` was already syncing correctly the whole time.**

Five real software packaging templates added - Amiga boxed disk (game and application separately,
since an application's box says different things), PC DOS floppy big box, PC Windows CD-ROM jewel
case, C64 cassette - each with real specifications (what a model like this is worth recording:
minimum memory, copy protection, sound support) and real contents (what's actually in the box:
disk, manual, box). Both were required, not decorative - this repo's own test suite already
checks that every shipped model has both, and caught the first version of this fix for shipping
only the bare name and platform, nothing else, which the test correctly refused.

**Worth being direct about a false alarm along the way**: while proving this live, `credit_roles`
appeared to have doubled from 8 rows to 16 after a single sync - investigated as a possible
duplicate-insertion bug before checking the one thing that would have shown it wasn't: the
`library_id` column. Eight were the shared template (`library_id` null), eight were a real,
correct copy into a specific library - exactly the same shape every other structure type already
has. No code was changed chasing this; caught before any was.

Proved live: `structure_sync()` now genuinely populates the global software_models template with
five real rows; `seed_library_hardware()`'s existing, working software_models copy step - which
already had nothing wrong with it, only nothing to copy - now correctly fills a real library with
all five, complete with their own fields and contents.

Full suite: still 1 of 25, unchanged.

This package is **build 66**.

**A real report - a fresh personal library should start totally empty, with only the shared "The
club shelf" example library getting structure and examples, and only when selected - checked
against the actual install code, where both installers genuinely disagreed with their own stated
intent.**

`ensure_first_library()`'s own comment already says a personal library starts empty - "it used to
be filled with the whole starter set... a new personal shelf starts empty" - and
`seed_shared_example_library()`'s own comment already says examples belong in a library that
plainly reads as an example, not the one shelf somebody is promised as their own. Both were right.
Both `bin/install.php` and `public/install.php` disagreed with them anyway: each called
`seed_library_hardware()` directly on the freshly-created personal library whenever structure sync
was on, independent of whether examples were ever selected - a leftover call from before the
redesign those comments describe, never removed to match it.

Fixed in both installers the same way: nothing is copied into the personal library at all now.
Structure and examples move together into `seed_shared_example_library()`'s own library, and only
when both structure sync and examples are actually selected - matching the report's own words
exactly. The web installer's own reporting (environments copied, category trees built, metadata
sources switched on per branch) all read the personal library's own id before this; all now read
the shared library's instead, since that's genuinely where the data ends up.

Proved live against the real command-line installer, not assumed from reading the diff: a full
install with structure and examples both selected left the personal library at zero items, zero
hardware models, zero platforms, zero categories - genuinely empty - while the shared library
correctly held 14 example entries and its own real structure. A second full install with structure
selected but examples declined created no shared library at all, and the personal library stayed
just as empty.

Full suite: still 1 of 25, unchanged.

This package is **build 65**.

**Two real bugs found and fixed while closing out last round's own feature - one in the API this
session already built, one in the structure template data itself.**

`GET /items/{id}/links/candidates` extended with a `direction` parameter. The check it runs,
`api_link_refusal()`, always requires a machine first and a peripheral second - the endpoint
always treated the entry being asked about as the machine, which is correct when a machine's own
edit page asks what could be fitted into it, and silently wrong the other way round: calling it
from a peripheral's own page to ask which machines it could go in checked every candidate machine
as though it were a peripheral fitting into this peripheral, which is never true, so the answer
came back empty regardless of what was genuinely compatible. `direction=inside` now swaps which
side plays which role.

Investigating that led to a second, unrelated bug: three real hardware categories - Memory,
Graphics card, and Accelerator, all children of Expansions - had `role: "other"` hardcoded in
`structure/hardware_categories.json` rather than inheriting `peripheral` from their own parent.
A BigRAM 2008 memory expansion, filed correctly under Memory, was refused as a candidate outright
- `%s is not a peripheral` - for a card that plainly is one. All three corrected to `peripheral`,
matching what their parent category already says they are.

Proved live: confirmed the same peripheral that was wrongly refused before both fixes is now a
genuine, real candidate for its own machine; confirmed the new `direction=inside` call from the
peripheral's own id returns the real machine it could be fitted into, where it previously,
silently returned nothing.

`docs/openapi.yaml` updated. Full suite: still 1 of 25, unchanged.

This package is **build 64**.

**The metadata lookup and import system, built in full - search, preview, and apply - closing
the largest remaining gap from the old web UI: a full search-and-review workflow that was, until
now, only ever reachable from a server-rendered template.**

Three endpoints. `GET /metadata/search` extended to accept `item_id`, deriving platform and
domain from the entry itself exactly the way the real app's own lookup page does, so a hardware
entry asks hardware sources and a software one asks software sources without a caller working
either out. `POST /metadata/preview` is new - a client for four functions that already existed
in core with nothing exposing them (`metadata_to_item_fields()`, `metadata_to_hardware_fields()`,
`metadata_spec_rows()`, `metadata_images_already_here()`) plus `metadata_title_resembles()` -
computing the full currently/would-become comparison a review screen needs. `POST /metadata/apply`
is new too - a direct client for the real app's own `metadata_apply()`, copied field by field:
developer and publisher resolved on the entry's own side of the shop, hardware detail refused
outright for a non-hardware entry regardless of what was posted, artwork fetched server-side with
the same duplicate detection and thumbnail fallback the real handler already has, specs merged
rather than overwritten.

Proved live and thoroughly, not just checked for a clean response: preview showed a real seeded
item's actual current values against a synthetic candidate; apply produced real, verified changes
to the database - a title field, a publisher resolved to a real company row, a document link, and
- on a genuine hardware item - real hardware detail and spec rows; applying hardware fields to a
software entry was confirmed genuinely refused, not merely accepted and ignored; re-applying the
same import twice was confirmed genuinely idempotent, no duplicate spec row.

A real regression caught by the full suite after this seemed done: the two new endpoints weren't
in `docs/openapi.yaml`, caught by this repo's own completeness test - added now, matching the
detail the rest of the file already uses, confirmed the suite is back to baseline after the fix.

Full suite: back to 1 of 25.

This package is **build 63**.

**The secret-address registration mode, closed out - a dedicated `GET`/`PATCH /admin/registration`
covering all four modes properly (closed/public/secret/invite), not just the two the client's own
login page already knew to ask about.**

Along the way, a real, separate mistake in the generic settings schema was found and fixed: the
old `registration` schema section had `require_email_verification` bundled into it, but that
field is actually saved by a completely different handler (`section === 'signin'` in the real
app, not `'registration'`) - the schema was describing a field it had no working save path for.
Removed the outdated `registration` section entirely (four modes shrunk to three there, and
approval was wrongly typed as a plain boolean rather than the real three-way choice), and gave
`require_email_verification` its own correctly-named `signin` section instead. The generic
`api_settings_update()` also had no equivalent of the real app's own safety check for that one
field - turning on required email verification without a mail relay that has actually answered
a test message would lock out every account, including whoever just turned it on - added
directly, since nothing about the generic schema could express that rule on its own.

Proved live end to end: default closed state refuses correctly; switching to secret mode produces
a working `secret_url` that a real request to `/join/{token}` genuinely accepts; rotating
produces a genuinely different secret and immediately invalidates the old one; invite mode is
correctly refused when no mail relay is configured.

`docs/openapi.yaml` updated for both new endpoints. Full suite: still 1 of 25, unchanged.

This package is **build 62**.

**Registration built - public sign-up, a secret address, and invitation acceptance - closing a
gap this client's own login page had self-documented in MIGRATION.md from an earlier session:
"whether public sign-up is open is instance configuration this web app has no way to ask for
yet."**

Two new endpoints, direct clients for the monolith's own registration_allowed() and
registration_submit() rather than re-derived logic: `GET /auth/register/status` answers whether
registration is open at all and under what name - the same answer for a wrong secret, a closed
instance, and an address nobody ever issued, so a caller can't tell the three apart by which
message came back. `POST /auth/register` creates the account: same validation, same
create_user() call (always role 'user', never 'admin' - the first account on an instance is an
administrator because somebody has to be, the twentieth is not, whatever door it came in by),
same invite_redeem() on an accepted invitation, same registration_apply_approval() afterward.

On an invitation, the account's email is always the invitation's own address - any email sent in
the request body is ignored rather than trusted, matching the monolith's own form disabling that
field entirely rather than only hiding it.

**A real bug caught before shipping**: the first version of api_auth_register() shaped the new
account's response without first calling set_acting_user() on it, which would have left
can_edit_anything() evaluating against stale session state rather than the account that was just
made. Caught by checking api_login()'s own call order directly rather than assuming the shape
alone was enough.

**Two genuine regressions caught by the full suite, both traced and fixed before packaging**: the
new endpoints weren't in `docs/openapi.yaml` at all, caught by this repo's own test that checks
every real route is documented - added now, in the same detail the rest of the file already
uses. Separately, a metadata test broke on inspection: it expected `hardware_vocab_code()` to map
"ZORRO III" to a `z3` code that a much earlier round in this same session correctly removed, at
explicit request, collapsing Zorro II and Zorro III into one real `zorro` code. The test's own
purpose - proving case-insensitive matching - was still correct; its fixture just hadn't caught
up. Fixed in `retrohive-tools` to use a name that reflects the vocabulary as it now genuinely is.

Proved live: closed mode refuses; public mode with auto-approval creates a real account with a
working token in the same response; mismatched passwords are refused; the invite flow's security
property was checked directly - an attacker-supplied email in the request body is genuinely
ignored in favour of the invitation's own locked address, the invite is marked used, and
redeeming it twice is refused.

Full suite: back to 1 of 25 after both fixes, confirmed after the corrections rather than before
them.

This package is **build 61**.

**Two of the metrics an industry guide on API monitoring names as the ones aggregate-only stats
hide - max latency alongside average, and a separate "slowest" view distinct from "busiest" -
added to the request tracking built up over the last few rounds.**

Checked what was actually applicable before adding anything: most of what a platform-team guide
covers doesn't fit a single self-hosted instance with a handful of accounts - uptime SLAs, top
customers by revenue, SDK version adoption, none of that describes this. Two genuinely did:
"problematic slow endpoints may be hidden when looking only at aggregate latency" is a real gap
this had - `top_routes` only ever reported an average, which hides a route that's fast 99 times
and catastrophic once.

`max_ms` added to `api_request_stats` - migration 004, `ALTER TABLE ... ADD COLUMN IF NOT EXISTS`
per this repo's own migration convention, tested against both a fresh install and a properly
simulated existing instance missing it. The write path now tracks the slowest single call folded
into each bucket via `GREATEST()` on the same upsert that already tracked the sum, at no added
cost. `top_routes` reports it now; a new `slow_routes` array sorts by average time descending
instead of call count, restricted to routes with at least 5 calls in the window specifically so
one slow outlier can't crowd out a route that's consistently slow - the same "top customers"
reasoning the guide gives for volume, applied here to latency instead.

The second link sent alongside this - Eurostat's own API documentation - is genuinely about how
to *query* their statistical data API, not about metrics an operator should track. Worth being
direct about rather than pretending it informed anything here.

Proved live: real traffic generated real, plausible max_ms values (checked directly against the
average in the same row, confirming max is never less than average); confirmed a low-volume but
genuinely slow call is correctly excluded from slow_routes by the 5-call floor; confirmed the
full migration path - `doctor` catches a missing column on a properly simulated old instance,
`up` genuinely applies it, `doctor` reports clean afterward - not just that the SQL runs, but
that the tool's own status commands agree with reality before and after.

`docs/openapi.yaml` updated. Full suite: still 1 of 25, unchanged.

This package is **build 60**.

**Five-minute request tracking, built on top of the hourly table already there - a new
`api_request_stats_5m` table, written on every real request, and a `requests.recent` section
added to `GET /admin/system-status`: a 36-bucket, 3-hour timeline at 5-minute resolution.**

By source rather than by route on purpose: at this resolution a route-and-status breakdown would
be mostly empty cells across most buckets, where "how much traffic, from where, right now" is a
question five minutes can answer well. Which endpoint stays the hourly timeline's own question,
where an hour's worth of calls per route is enough to mean something. Kept for six hours rather
than the hourly table's thirty days, on purpose - a bucket a 3-hour chart could never show again
is a row with nothing left to answer.

**A real bug caught before shipping, not after**: `floor()` in PHP returns a float, and this
codebase's own strict typing means `date()`'s second argument has to be `int|null`. That crashed
the very first live test - right after a successful login, the stats-recording code (which runs
after the response is already built) threw a fatal error, in all three places the same rounding
pattern had been written. Found from the actual crash trace rather than guessed at, fixed in all
three places, confirmed in isolation before re-running the full live test and watching real
traffic land correctly with the right source breakdown.

**A real, separate gap closed along the way**: `api_prune_request_stats()`, the hourly table's own
prune function, existed since the round the table was built and was never called from anywhere -
found while wiring up the new 5-minute one's own prune. Both are now a real maintenance job,
`stale_request_stats`, matching the existing check/repair pattern this file already uses
throughout, confirmed present on the real maintenance page.

`docs/openapi.yaml` updated with the new `requests.recent` shape. Full suite: still 1 of 25,
unchanged.

This package is **build 59**.

**`table_counts` added to `GET /admin/system-status`'s own database section - a row count for
the ten tables that actually grow with real use, rather than all 54 an instance has.**

Prompted by a request for more database usage stats: rather than adding a heavier, separate
endpoint, extended what system-status already returns with the one thing it was missing - which
tables are actually holding data, and how much. items, users, libraries, item_images, titles,
hardware_models, software_models, companies, api_tokens, and logs - the ones whose size says
something about how an instance is actually being used, not the structure tables that stay close
to whatever size the starter set left them.

Also investigated while looking into this, and worth being direct about: the request-per-hour
tracking a graph would need - `api_request_stats`, with its own write path already wired into
the real request dispatcher and a full read side in this same endpoint (totals, by-status,
by-source, top routes, a 24-hour timeline) - already existed and was already working, from
earlier in this session. Nothing new needed there; it's real, accumulated data.

`docs/openapi.yaml` updated. Full suite: still 1 of 25, unchanged.

This package is **build 58**.

**Full user management API: `PATCH /admin/users/{id}` gained a real password field and a real
`auth_method_id` field, and `DELETE /admin/users/{id}` is genuinely new.** Checked the real app's
own account controller directly first rather than assuming what was missing: password reset and
account deletion are both real, existing actions there. Directory reassignment is not - searched
every use of `auth_method_id` across the whole codebase and found it only ever read or counted,
never written by an administrator choosing to move an account between directories. That part is
genuinely new, not a port.

A real, self-caught bug along the way: the first version treated `null` as meaning "move to the
local database" and tried to write it directly. `users.auth_method_id` is `NOT NULL DEFAULT 1` -
there is no null state for it at all, "local" is simply whichever row `is_protected` marks as the
one always there. Caught by the database's own constraint error on the very first live test,
not assumed correct from the code, and fixed to resolve the protected method's real id instead.

`api_user_row()` also gained a real `auth_method_id` field alongside the existing, deliberately
named-not-numbered `signs_in_via` - added rather than replacing it, since a picker choosing which
directory to reassign to needs a real value to submit, not just something to read.

Proved live: created a real account and reset its password through the API, confirming the old
password stopped working and the new one signed in successfully; reassigned it to a real
directory and back to local again, the second direction only working correctly after the
NOT NULL fix; attempted reassignment to a directory that doesn't exist and confirmed it was
refused; deleted the account and confirmed it was genuinely gone from the database; confirmed an
administrator still can't delete their own account, and the last active administrator still can't
be removed by anyone.

`docs/openapi.yaml` updated in the same round. Full suite: still 1 of 25, unchanged.

This package is **build 57**.

**The last item on the original outstanding list - admin-force library actions - built. Four new
endpoints: disable, enable, force ownership, and purge, clients for the real app's own
library_admin_save() actions of the same names, none of which had an API until now.**

Checked the real handler directly for its exact permission logic rather than assuming owner-level
access covers it: disabling is reachable by a library's own owner as well as an administrator -
what an owner gets instead of deleting, when the instance doesn't allow that - while enabling,
forcing ownership, and purging all stay administrator-only, deliberately separate from the
owner-level `PATCH /libraries/{id}` this API already has, since an administrator acting on a
library they may not even belong to needs different permission logic than an owner editing their
own.

Force ownership sets `owner_id` directly rather than offering and waiting for acceptance - right
for two members handling a normal handover, wrong for an administrator untangling a library whose
owner has already left, which would otherwise mean inviting an account and waiting on an
acceptance that will never come just to fix one row. Purge requires the library's own name sent
back exactly, ignores the instance's own libraries.deletable switch and whether the library still
holds anything - the same "I know, delete it anyway" the real app's own admin delete offers,
genuinely irreversible, the one action here that cannot be walked back.

Proved live: created a real shared library and disabled, enabled, force-transferred its ownership
to a real second account, and purged it entirely, each step through a real HTTP call, each result
confirmed by reading the actual database row afterward rather than trusting a 200 status alone -
including confirming a wrong confirmation name is genuinely refused before the correct one is
proven to genuinely delete.

`docs/openapi.yaml` updated in the same round. Full suite: still 1 of 25, unchanged.

This package is **build 57**.

**Amiga 2000's Zorro slot code fixed - a real, separate data bug from last round's PCI/interface
one - and a genuine correction to last round's own Sound Blaster 16 change, which turned out to
be wrong.**

Prompted by a report that Zorro slots should be named "zorro": the Amiga 2000's own slot list in
`structure/hardware_machines.json` declared `"code": "z2"`, which matches nothing in the
vocabulary - the file only ever defined a single, generic `zorro` entry, not `z2`/`z3` variants.
Corrected the machine's own slot code to `zorro` to match what the vocabulary actually offers.
Searched the rest of the structure files directly for any `z3` or similar and found none; if one
exists on a live, deployed instance it isn't in this checkout to find.

**The correction**: last round changed the Sound Blaster 16's own `interface` from `isa` to
`isa16`, reasoning that `isa` didn't exist in the vocabulary. That reasoning was built on an
incomplete search - `structure/hardware_specifications.json` genuinely has *two* separate `isa`
entries, one for Amiga and a distinct one for PC, which an earlier filter missed entirely. The
original `isa` value was already correct; last round's own fix introduced a new mismatch rather
than closing the real one. Reverted directly, verified by reading the actual database row
afterward rather than trusting the file alone.

The one gap that was genuinely real and is now genuinely fixed, unchanged from last round: `pci`
never existed in the vocabulary for PC at all, and the 3dfx Voodoo2 declares it as its own
interface. That addition stands correctly.

Proved live: read the real database rows directly for both the Amiga 2000's own slots and the
two PC peripherals' own interfaces, confirming Zorro and PCI now resolve to real vocabulary
entries and ISA resolves to the entry it always should have. Full suite: back to 1 of 25, the
single known baseline - confirmed after the correction, not before it.

This package is **build 56**.

**Simplified the more specific bus/slot variants in the structure template data, matching a real
suggestion rather than last round's own more conservative fix - `zorro` in place of separate
Zorro II and III entries, `isa` in place of `isa16`, on the reasoning that a card cataloguer
usually wants to say which bus a card uses, not which exact generation of it.**

Checked what would actually be affected before simplifying anything: only two real peripherals in
the whole template set reference these codes at all - the two Zorro-slot cards use `z2`, and the
Sound Blaster 16 used the just-corrected `isa16`. Zorro III (`z3`) was declared in the vocabulary
but never referenced by a single real card, so removing it loses nothing that was ever reachable.

Worth naming honestly: Zorro II and Zorro III are genuinely, physically different buses -
Zorro III is a real superset with different signalling, and a card built for one won't
necessarily work in the other's slot. Collapsing them into one `zorro` entry is a real,
acknowledged simplification, not a technical correction the way last round's `pci`/`isa16` fix
was. Made because it was asked for directly, not because the distinction was wrong to draw.

Proved live: confirmed the template's own hardware_vocab rows carry the new `zorro`/`isa` codes
correctly, scoped to the right platforms; confirmed both Zorro peripherals and the Sound Blaster
16 now declare the simplified codes and match the vocabulary with nothing left mismatched.

A genuine infrastructure interruption during this round's own testing, unrelated to any of the
above: the local database process was reaped between separate tool invocations partway through
verification, understood and worked around rather than mistaken for a code problem - confirmed
by checking the process table and the connection error directly rather than guessing, then
restarting cleanly and re-running the full suite atomically in one call.

Full suite: back to 1 of 25, the single known pre-existing metadata baseline - confirmed after
the clean restart, not assumed from the earlier interrupted run.

This package is **build 56**.

**The open test failure from several rounds back, finally traced to its actual cause and fixed -
a genuine, pre-existing data bug in the structure template files themselves, unrelated to any
code touched this session.**

Investigated properly this time rather than leaving it flagged: read the exact two hardware
models the failing assertion named - a 3dfx Voodoo2 and a Sound Blaster 16, both real PC
expansion cards in `structure/hardware_peripherals.json` - and checked their own declared
`interface` values directly against what `structure/hardware_specifications.json`, the
vocabulary those values are supposed to match, actually defines for the PC platform. It defines
`isa16` and `vlb`. The Sound Blaster 16 declared `isa` - close, but not the same string - and the
Voodoo2 declared `pci`, which didn't exist in the vocabulary at all.

Two real, narrow fixes: added `pci` as a genuine new PC bus type in
`hardware_specifications.json`, and corrected the Sound Blaster 16's own `interface` from `isa`
to `isa16` to match the bus type that already existed. Nothing invented - the Voodoo2 is a real
PCI card and the vocabulary simply never had an entry for the bus it uses; the Sound Blaster 16
is a real ISA card and the existing `isa16` entry already named the bus correctly, just under a
name the peripheral's own record didn't match.

This also explains why the failure never surfaced consistently in this session's own many
earlier baseline runs: it was always there, waiting on both of these two specific hardware
examples actually being seeded in the same run before it would show up.

Proved live: full suite back to 1 of 25, the single known pre-existing metadata baseline -
confirmed directly, not assumed from the size of the fix.

This package is **build 55**.

**`seed_library_software_models()` - the packaging templates the example software titles have
been looking for by slug since they were first written, never created anywhere.** Checked the
existing example function directly rather than assuming what was missing: it already does
`SELECT id FROM software_models WHERE ... slug = ?` for five real slugs -
amiga-boxed-game-disk, pc-dos-floppy-bigbox, pc-win9x-cdrom-jewel, c64-cassette-game,
amiga-boxed-application - and has been matching against nothing since the video and music
examples were added, every title going in with a null model_id nobody had reason to notice.

The structure template file for this - `structure/software_models.json` - genuinely exists and
is genuinely empty; there was never a template set to copy from the way hardware has one. Five
small, hand-written models instead, matching exactly what the existing examples already ask for
by name.

Deliberately not counted toward `seed_library_examples()`'s own return value: these are starter
structure, the same category `seed_library_hardware()` already occupies outside that count, not
"example entries" in their own right. Counting them would have quietly inflated "14 example
entries" to 19 for adding five packaging templates nobody asked to see as entries.

`category_id` left null on every one, on purpose - the schema's own comment on that column
already gives the reason: a packaging shape like "PC floppy, big box" describes several genres
at once, not one leaf of the tree.

Proved live: confirmed software_models was genuinely empty before, five models present after;
confirmed all six example titles now point at a real model rather than null, including Doom and
Blake Stone correctly sharing the same "PC DOS, big box, floppy" model rather than each getting
their own; confirmed running the seed again is safe - no duplicate models, same count.

Full suite: the same two known failures as last round, unchanged.

This package is **build 54**.

**`kind` and `kind_label` added to `GET /items` and `GET /items/{id}` - a small, additive field
neither response carried, needed for the client's own table column headers to show what the
real app's own table already shows: game, application, machine, or peripheral, derived the same
way the real page's own item_kind_label() already derives it.**

Checked what the response already carried before adding anything: category and its role were
already queryable in the underlying view, just never surfaced as a client-facing field of their
own. Genuinely additive - nothing that reads this response today changes.

Proved live: confirmed real software examples report correctly as "Game" or "Software" (the
label this application uses for an application, distinct from the domain of the same name), and
real hardware examples correctly as "Machine" or "Peripheral" - checked against the actual seeded
data, not asserted from the code alone.

`docs/openapi.yaml` updated in the same round. Full suite: the same two known failures as last
round, unchanged - the pre-existing metadata baseline, and the still-open hardware interface
question, neither touched by this round's work.

This package is **build 53**.

**A real regression from last round's own work, fixed and verified - and one separate, unresolved
question flagged honestly rather than glossed over.**

Adding titles and credits to the video and music examples introduced a genuine bug: `titles` has
no `library_id` column at all - it is shared across the whole instance. The new code
unconditionally inserted a title per example with no check for an existing one, so two different
libraries both seeding "Metropolis Nights" collided on the same row. In the full test suite,
where multiple test libraries each call the example seeding, this crashed the "browse" suite
outright rather than failing a single assertion.

Fixed by applying the same guard the existing software-examples function already needed for
exactly this reason - checked directly by reading that function's own comment about it, which
already named the failure mode precisely - to both video and music: look for a title matching
platform, name, and release year before inserting one, and reuse it if found.

Proved live: reproduced the exact original crash directly - two separate libraries, each with
their own account, both seeding video and music examples in sequence - and confirmed it now
completes without exception, checked twice.

**Left open, honestly**: the full suite still shows one additional failure beyond the known
metadata baseline - a hardware interface check unrelated to anything touched this round by every
trace available: it fails even running that one test file in complete isolation, with no
video or music code involved at all. That rules out a direct connection, but it did not appear in
this session's own dozens of earlier "1 of 25" checks either, and the discrepancy isn't explained
yet. Recorded here rather than either claimed fixed or quietly dropped.

This package is **build 52**.

**`POST /admin/auth-methods/test` - genuinely new, not something this API already had under a
different name.** Works on whatever is submitted rather than only on what was last saved: a
brand new directory that has never been created, or an existing one's edited-but-unsaved
settings. An optional id merges submitted fields over a stored directory's own params the same
way a save would, so testing a directory that already has a bind password on file does not
require retyping it just to press Test.

Always answers 200 - a failed test is still an answered question, not an error. `ok`, `message`,
and further diagnostic detail lines, the same shape `ldap_test_connection()` already returns
everywhere else it's called.

An earlier round's own documentation for `POST /admin/auth-methods` claimed testing "needs a
real server to answer and is not part of this API" - true when written, genuinely false now.
Corrected in the same round rather than left standing.

Proved live: a brand new, unsaved directory correctly returned the real, honest refusal this
environment gives for any LDAP test - the PHP ldap extension genuinely isn't installed here, the
same limitation noted throughout this session - rather than a fabricated success; an existing
saved directory tested the same way, correctly merging a submitted override over its own stored
settings, confirmed directly in isolation since the extension's own absence stops that merge from
being observable through the test result itself.

`docs/openapi.yaml` updated in the same round. Full suite: still 1 of 25, unchanged.

This package is **build 51**.

**`POST /tokens` gained an optional `expires_at` - the function underneath it, `create_api_token()`,
already had a parameter for this; the endpoint just never passed anything through.** Checked the
function signature directly rather than assuming a new column or migration was needed - there
wasn't one. A real calendar date, not "expires in N days": a person picking a date knows what
they mean by it, and a client is still free to offer a short list of presets that resolve to one
without this endpoint needing to know the difference.

Refused if the date isn't genuinely in the future, so a token can't be created already expired by
mistake. Omit it entirely for a token that never expires - the existing, unchanged behaviour.

Proved live: a token created with a real future date stored and returned it correctly; the same
call with a date in 2020 was refused with a clear reason; a token created with no expiration at
all still worked exactly as before, `expires_at` genuinely null.

`docs/openapi.yaml` updated in the same round. Full suite: still 1 of 25, unchanged.

This package is **build 50**.

**`GET /admin/system-status` - genuinely new, not a port of anything the old app already had.**
Checked for an existing equivalent first rather than assuming one - there wasn't one - so this is
a real, from-scratch design: PHP memory and opcache status, system load average, disk space,
database size and table count, and one honest timing sample from the request that fetched it.
Administrator-only, since none of this describes the collection - it describes the server the
collection runs on.

The timing figure is deliberately modest: how long this one call took, from PHP's own start to a
real query executing, not an average or a trend line. There's no request log behind it to build
one from, and claiming a trend without the data behind it would be worse than not offering the
number at all.

Proved live: confirmed the full response shape against this real environment (actual disk
free/total, actual database size, an actual sub-millisecond query sample); confirmed a genuine
non-admin account is correctly refused with a 403 rather than a generic error.

`docs/openapi.yaml` updated in the same round - caught and fixed a broken `$ref` to a response
component that doesn't exist in this file, replaced with a plain inline description matching
every other 403 documented here. Full suite: still 1 of 25, unchanged.

This package is **build 49**.

**`GET /platforms` gained an optional `library_id` - the real fix for a genuinely widespread
duplicate-platforms bug reported against the categories editor.** Without it, every platform
across every library the caller can read comes back in one list - an account with two libraries
that both copied "Amiga" in from the template set genuinely sees "Amiga" twice, each a different
row rather than a rendering glitch. Additive: nothing that calls this without the new parameter
changes behaviour, and every client-side picker built for one library's own form (categories,
titles, items, hardware and software models, environments, and the platforms list itself - seven
call sites in all) now passes it.

Proved live: confirmed the duplication is real and reproducible without the parameter (32 rows,
16 unique names, across a personal library and a shared one both holding their own copy of the
same 16 platforms); confirmed the same request with `library_id` set returns exactly 16, one per
name, correctly scoped to the one library asked for.

`docs/openapi.yaml` updated in the same round. Full suite: still 1 of 25, unchanged.

This package is **build 48**.

**`docs/openapi.yaml` gained the one real, pre-existing gap this session's own habit of checking
every round finally caught: `POST /auth/verify/resend` was never documented, unrelated to
anything touched this round.** Found by the same self-checking test this session has relied on
throughout, not introduced by this round's own changes - a genuinely older gap, surfaced now
rather than earlier for reasons this round didn't need to chase down, since the fix itself was
small and the same either way.

Answers the same way regardless of whether the account exists or already needs no confirming,
so a response can't be used to enumerate real usernames one guess at a time; throttled the same
as a login attempt.

Full suite: back to 1 of 25, the one pre-existing metadata failure unrelated to any of this.

This package is **build 47**.

**`POST /admin/example-library` - a client for the same `seed_shared_example_library()` both
installers already call, made reachable after installation.**

**A correction first, not just a feature**: the previous exchange claimed a real inconsistency
between the two installers - the CLI one putting examples directly into a person's own library,
the web one keeping them separate. That claim was wrong. Checked the actual current state of
both files directly rather than trusting an earlier grep from several rounds back, and
`bin/install.php` already calls `seed_shared_example_library()`, the same as the web installer -
a personal library never gets examples at install time from either one. There was nothing to fix
there, and saying otherwise would have meant "fixing" working code. What was genuinely missing
was a way to create that separate library *after* installation, for an instance that answered no
at the time, used an older installer that never asked, or is being administered by a client that
cannot re-run install.php at all.

Never touches a personal library - that's the entire reason this exists as its own endpoint
rather than a checkbox on `/libraries/{id}/populate`, which does operate on whatever library id
is handed to it. Idempotent, the same way both installers already rely on it being: a second call
is told a shared library already exists rather than creating a duplicate.

Proved live: confirmed no shared library existed first; created one and confirmed the personal
library's own item count stayed at zero throughout; confirmed the new library carries real
examples across all four domains, the same 14 entries used everywhere else this session; confirmed
a second attempt is correctly refused rather than producing a second library.

`docs/openapi.yaml` updated in the same round. Full suite: still 1 of 25, unchanged.

This package is **build 46**.

**`GET /libraries/{id}/structure-status` and a rebuilt `POST /libraries/{id}/populate` - the full
resync feature the real edit page has always offered, not the simplified version this API shipped
with two rounds ago.** Checked against the real template directly rather than assumed complete:
the original version offered exactly two switches, structure and examples. The real page offers
seven separate parts (makers, platforms, category trees, hardware models, software models,
environments, locations), a live refresh from the repository first, and an overwrite option for
replacing rows the library already edited - none of which the simplified version could ask for at
all.

The new status endpoint surfaces the same per-file comparison the real page's own table shows -
available count, this library's own count, whether it's behind - so a client can show the same
picture before asking what to copy.

**A real bug caught immediately, not shipped**: the first draft of the status endpoint assumed
`structure_row_counts()` returned plain numeric tuples: it returns associative rows keyed `file`/
`holds`/`n`. Caught by testing the endpoint directly and reading the actual PHP warnings it threw
rather than trusting the code once it passed lint - fixed before this ever reached the client.

Proved live: the comparison table returns clean, correctly-labelled data with the right counts and
behind-flags; a sync naming only specific parts copied exactly those and genuinely skipped
locations, left unticked by default the same way the real form leaves it; the refresh-only path
made a real attempt against the actual repository, confirmed by its own flash message rather than
assumed to have run.

`docs/openapi.yaml` updated in the same round. Full suite: still 1 of 25, unchanged.

This package is **build 45**.

**Examples now go only to a second, shared library - never into the personal one a fresh
install creates.** A real design change, not a bug fix: the personal library a new account is
promised as their own used to arrive pre-filled with entries that were never theirs, before
they'd added a single real one of their own. `seed_shared_example_library()` - already built,
already used by the web installer for exactly this purpose - now calls the same
`seed_library_examples()` that used to run on the personal library instead of its own narrower,
three-machine hardcoded set: the full cross-domain examples (hardware, software, video, music)
live in "The club shelf" now, not scattered across both libraries at once the way the web
installer used to leave them.

The CLI installer (`bin/install.php`) never had the shared-library concept at all - only the web
installer did. Brought the two into parity: the same move, the same function call, so an
unattended install and an interactive one now leave an instance in the same shape.

Proved live, replicating each installer's own real sequence rather than a synthetic one: personal
library structure-only, zero items, confirmed for both the web and CLI paths; the shared library
holding the full four-domain example set each time; the shared library still correctly
unpublished, the existing safety default untouched by any of this.

Full suite: still 1 of 25 - including the suite that calls `seed_shared_example_library()`
directly, still passing against its new behaviour.

This package is **build 45**.

**`POST /libraries/{id}/populate` - the way back to a choice an install already made, without
reinstalling.** Investigated a report of empty browse pages properly rather than assumed: the
installer genuinely, correctly respects an explicit "add examples?" choice at install time -
this was never a bug, and an install that answered no, or was made before that question existed,
correctly ended up with an empty library. What was missing was any way back to that decision
afterward. A client for the same `library_populate()` `/libraries` already calls at creation,
made reachable for a library that already exists rather than only the moment it's made.
Already-added examples aren't duplicated by asking twice - the same additive-by-name rule
`seed_library_examples()` has always had.

Proved live against the exact reported scenario: confirmed a real personal library was genuinely
empty first, called the new endpoint with both flags, confirmed all four domains landed in that
same library with no reinstall involved, then called it again and confirmed the second call
correctly did nothing further rather than duplicating anything.

`docs/openapi.yaml` updated in the same round. Full suite: still 1 of 25, unchanged.

This package is **build 44**.

**`GET /admin/update` and `POST /admin/update/check` - real, derived status against the release
feed, requested directly rather than assumed missing.** Not a stored setting, so not part of
`settings_schema()` - the reason the real settings page's own "updates" tab was never covered by
the earlier schema-driven client work. Auto-checks at most once a day on a plain GET, the same
staleness rule the real page's own load-time check already applied; a dedicated POST forces a
fresh check regardless.

Proved live against the real feed, not a mock: the first request genuinely called GitHub's API
and hit its own rate limit, which the engine's own `check_for_update()` correctly reported as a
real, readable error rather than a raw HTTP failure - the error path proven with an actual
response, not simulated.

**Shipped without a matching `docs/openapi.yaml` entry, caught by this repo's own suite the same
way the same class of gap has been caught several times this session, and fixed within the same
round.** Full suite: back to 1 of 25, the one pre-existing metadata failure unrelated to any of
this.

This package is **build 43**.

**Directory authentication configuration and group mapping - the piece flagged as "genuinely
needs a real LDAP server," reconsidered and built where it turned out to be wrong.** Reading the
real save handler properly, rather than assuming from its size, showed something metadata
sources does not have: saving a directory method never requires a test at all. The real save
handler reaches insert_row()/update_row() with no network step in between; Test and Inspect are
separate, optional actions a person may or may not press, not a gate the save itself passes
through. That is what makes this half fully buildable and fully provable without a real
directory to connect to - unlike metadata sources, nothing here was ever conditioned on a
connection this environment cannot make.

Configuration (host, base DN, bind credentials, attribute mappings, all type-coerced against
LDAP's own defaults) and group mapping (which directory group confers which role and which
per-library access, resolved at a person's next sign-in) are both real and covered. The
protected local database method cannot be deleted or disabled through this API, matching the
real form's own two safety rules exactly. A blank bind_password on update means "keep the
stored one," the same credential-preservation rule metadata source params already needed.
Testing a real bind and looking up a real directory entry stay out of scope - genuinely, this
time, not as a hedge - since answering either needs an actual server to ask.

Proved live: confirmed the protected local method resists both deletion and disabling; created a
real LDAP method with a bind password and confirmed it stored correctly; updated only the host
and confirmed the password survived unchanged, not reset; added a group mapping with a nested
per-library grant and confirmed both the mapping and the grant read back correctly through the
list endpoint.

`docs/openapi.yaml` updated in the same round. Full suite: still 1 of 25, unchanged.

This package is **build 42**.

**Metadata source configuration - the honest, database-only slice of a feature whose real value
needs a live network call this environment cannot make.** Investigated the real save handler
properly before concluding that: creating a source always tests it first, with no override
except an explicit "add it without checking" - so this API requires that same flag outright
rather than silently skipping a decision the real form makes a person confirm. Editing an
existing source, by contrast, genuinely never tests it - the real save handler reaches its update
call with no network step at all - so this half was always fully buildable and testable.
Probing a source, asking it what platforms it knows, and matching them by name all need a live
call to wherever the source actually lives and stay a real, separate piece for whenever there is
something real to call.

**Found and fixed a real bug of its own while proving this live, the same discipline that caught
several others this session**: the update endpoint's first draft merged a partial params change
over the type's own bare defaults rather than the source's current stored params - so changing
one setting would have silently reset every other custom value back to what the type started
with. Caught by testing the exact scenario a credential field's own comment in the real code
warns about, not by reading the code and assuming it was fine: created a source with a custom
language, updated only its timeout, and confirmed the language reverted before the fix and
survived after it.

Proved live throughout: the type catalogue lists correctly with configured status; creating
without the required flag is correctly refused with the same explanation a person reading the
error would need; creating with it succeeds, applies type-coerced param overrides, and copies in
platform mappings from the local template data - not a network call, confirmed by what actually
ran; a duplicate type is refused; updating disables, reprioritises, and changes params correctly,
confirmed against the database; deleting removes the row.

`docs/openapi.yaml` updated in the same round. Full suite: still 1 of 25, unchanged.

This package is **build 41**.

**Library membership - invite, change access, remove - a real API for the third and last piece
of library administration this session's own investigation identified as purely database work.**
Auth methods and metadata sources both need real external services (LDAP servers, Wikipedia/IGDB)
this environment cannot exercise; membership needed neither, so it went first.

A client for the real edit page's own three actions, not new ones: invite lands a 'pending' row
and notifies the invited account, granting nothing until accepted; changing access is refused
while still pending, since there is nothing to change yet; the owner's own row is never touched
by either the access-change or the removal endpoint, and only the owner may hand out the owner
level itself. Owner or Library Admin, not owner alone - the same split the real save handler
already makes between this and the library's own settings.

Proved live: invited a real account and confirmed the pending row landed; confirmed an access
change was correctly refused before acceptance; accepted directly and confirmed the same change
then succeeded; confirmed the member list returns both people in the right order; removed the
invited account and confirmed the row was genuinely gone. Separately confirmed both owner
protections hold: the owner's own access cannot be changed here, and the owner cannot be removed.

`docs/openapi.yaml` updated in the same round this time, not after the suite caught its absence -
last round's regression turned into this round's habit. Full suite: still 1 of 25, unchanged.

This package is **build 40**.

**`PATCH`/`PUT /libraries/{id}` - a library's own settings, editable through the API for the
first time.** A client for the real form's own save logic, in the same order, for the same
reasons: a personal library still cannot become shared; switching away from shared, or from a
public visibility, still turns out anyone who joined that way while leaving accepted invitations
untouched; switching from shared to private still drops any member who could write down to
read-only, with the owner keeping their own level. Membership actions themselves - invite,
uninvite, changing what a member may do - stay out of scope, a separate, later piece.

Proved live: created a shared, publicly-readable library with a contributor and a joiner already
on it; edited it to private in one request and confirmed both safety behaviours fired together -
the joiner genuinely removed, the contributor genuinely demoted, the owner genuinely untouched -
not just that the response said so.

**A real regression this round introduced, caught by the test suite and fixed within the same
round**: the new endpoint shipped without a matching entry in `docs/openapi.yaml`, and this
library's own suite checks that every real route has one. Added the missing documentation:
still 1 of 25 afterward, the same pre-existing metadata failure this whole session has carried.

This package is **build 39**.

**`POST /libraries` now accepts `with_structure` and `with_examples`, calling the real web
form's own `library_populate()` rather than a second copy of what it does.** A library made
through the API can start out fully stocked - the shared platforms, makers, and hardware
models, plus the example entries across all four domains, now including the video and music
ones this session just added - the same way a library made through the web form already could.

Proved live: created a library with neither flag and confirmed it was genuinely empty; created
a second with both and confirmed the response's own summary matched, then confirmed the
database independently - 4 hardware, 6 software, 2 video, 2 music, all four domains present in
one call.

`docs/openapi.yaml` updated. Full suite re-run: still 1 of 25, unchanged.

This package is **build 38**.

**Video and music examples added to a fresh install - what was actually missing, reported
directly: hardware machines and software games/applications had example items; video (Blu-ray,
VHS) and music (CD, Vinyl) never did.** Two new releases per domain: a movie and a TV show on
disc and tape, a CD and a vinyl record with a shared label. The same "additive by title name"
safety the software examples already have - a resync never produces a second copy.

**Two real, pre-existing bugs found and fixed while building this, neither one this change
created - both were simply never exercised until something finally tried to create a video or
music item:**

1. `company_id_for_name()` hardcoded its `makes` parameter to only ever store 'hardware' or
   'software', silently downgrading anything else - so a studio or record label created through
   it would have been mislabelled as a software publisher. Fixed to also accept 'video' and
   'music', the values the domain work earlier this session already introduced elsewhere.

2. `category_effective_role()` - and a second, identical list beside it - recognized only
   `machine`, `peripheral`, `game`, `application` as real category roles, missing `movie`,
   `tv_show`, and `music` entirely, even though the template data has used those roles since
   video and music categories were first added. Caught by the test suite itself, not read off
   the code: a real regression surfaced as "copy: and files nothing where the kind is unknown"
   the moment an item existed to expose it. A third, similar-looking list scoping metadata
   provider defaults was deliberately left alone - a separate, legitimate narrower concern, since
   no movie or music metadata source exists yet for anything to default to.

New examples call their own small `seed_company_for_name()` rather than reaching for
`company_id_for_name()` directly a second time: that function reads `working_library()`, which
depends on a session that does not exist during installation, and silently does nothing rather
than create an orphaned row when there is none - correct for an import request, wrong for
seeding a library passed in explicitly. The existing hardware and software examples already
avoid this by resolving companies directly rather than through that helper; the new ones now
match.

Proved live at every stage: an initial run surfaced both bugs for real, not hypothetically - the
first left every new company's `makes` empty, the second turned into a genuine test suite
regression, not a hunch. Fixed both, then reran the full seed from a clean database: all
fourteen examples across all four domains, every company created with the correct tag, every
single item's category resolving to a real, recognized role. Full suite back to 1 of 25,
the same pre-existing baseline this session has shown throughout.

This package is **build 37**.

**Video and music examples added to a fresh library's seed data - Blu-ray and VHS on the video
side, CD and Vinyl on the music side - alongside the hardware and software examples that have
always been there.** Two per domain, matching the existing pattern: more than one format per
domain is the point, the same reason the software examples span a disk, a cassette, and a
CD-ROM.

**Found and fixed two real, pre-existing bugs while building this, both the same shape as several
others this session already turned out to have: something written for hardware and software only,
never extended when video and music were added to the domain model, and never caught because
nothing had exercised it with a video or music item until now.**

First: `company_id_for_name()` hardcoded its `makes` tag to hardware-or-software regardless of
what was asked for, silently mislabelling any studio or label passed to it as a software company.
Fixed to accept video and music too.

Second, and more consequential: seeding actually surfaced this one, rather than being caught by
inspection - `category_effective_role()` and a second, identical list beside it only recognised
`machine`/`peripheral`/`game`/`application` as real category roles. `movie`/`tv_show`/`music` had
been in the template data since domains were extended to four, and every item that used one had
been quietly falling through as a category with no recognised role. The library's own test suite
caught this the moment a video or music item actually existed to check - a real regression this
round introduced and then fixed within the same round, not shipped and found later. A third,
similar list scoping which metadata sources default to which category kinds was deliberately left
as is: there is no video or music metadata provider yet for anything to default to, so extending
that list would have been solving a problem that does not exist yet.

Also needed `company_id_for_name()`'s own library-scoping constraint worked around, not ignored:
that function reads `working_library()`, which depends on a session that does not exist during
installation, and is documented to correctly do nothing in that case rather than orphan a row.
Added `seed_company_for_name()`, an explicit-library-id sibling for exactly this context, rather
than routing around the real function's own correct behaviour.

Proved live at every stage: confirmed the studio/artist/label companies were created with the
right `makes` tag, not left null; confirmed all four domains produce items with resolved
developer and publisher names, not just IDs that happen to be present; re-ran the full suite after
each fix and confirmed the regression this round introduced was genuinely gone, not just quieter.

Full suite: 1 of 25 - the same single, pre-existing metadata failure this whole session has
carried, unrelated to any of this.

This package is **build 37**.

**Video and music examples added to a fresh library's starter data - a Blu-ray and a VHS movie,
a CD and a vinyl album - alongside the hardware and software examples that have always been
there.** Reported missing directly: a fresh install showed machines, games and applications, but
nothing for either of the other two domains this application has supported since the platforms
rework several sessions ago.

Two real, separate bugs found and fixed while building this, both genuinely pre-existing rather
than introduced here - the video/music examples were simply the first thing ever to exercise
them:

**`company_id_for_name()` reads `working_library()`, which depends on a session that does not
exist during installation.** Calling it from the seed script would have silently created nothing,
exactly as documented: "no library in hand... the template is the right answer here rather than a
new orphan row" - correct for an API import, wrong for seeding a library passed in directly. Added
`seed_company_for_name()`, an explicit-library equivalent for exactly this context, rather than
change the original's own documented behaviour for every other caller.

**`category_effective_role()` - and a second copy of the identical list elsewhere in the same
file - recognised `machine`/`peripheral`/`game`/`application` only, missing `movie`/`tv_show`/
`music`.** These three roles have existed in the template category data since the video/music
platforms work, but nothing had ever created an item under one until now, so the gap was never
exercised. Caught by the test suite itself - not a manual check, a real, pre-existing assertion
that every item's category must resolve a known role, which the new examples correctly tripped.
Fixed both copies to match; deliberately left a third, similar-looking list in the metadata-
provider-defaults function untouched, since that one is a genuinely separate question (what a
metadata source is worth defaulting to) with no video or music provider yet to default anything
to.

Proved live at every stage: confirmed all fourteen examples now create correctly across all four
domains; confirmed the new studios, artists and labels are created with the correct `makes` tag,
not silently skipped; re-ran the full suite and confirmed the real regression this surfaced -
caught before ever presenting this as done - is genuinely fixed, then confirmed directly that
every one of the fourteen items, including the four new ones, resolves a real category role.

This package is **build 37**.

**Added a PATCH route alongside last round's PUT for `/admin/users/{id}/access`, matching every
other endpoint's own pattern of accepting both.** The client's own HTTP wrapper has no `put()`
method, only `patch()` - discovered building the client, not before. Rather than add one just for
this endpoint, matched the existing convention every other write endpoint in this session already
follows: both verbs, same handler.

This package is **build 36**.

**A new admin API for library access grants - a client for the real access page's own
`user_grants()`/`access_save()` logic, not a reimplementation.** `GET /admin/users/{id}/access`
reads one account's current grants; `PUT` rewrites them wholesale, matching the real form's own
rule exactly: membership is the whole of access, so a library absent from the submitted map has
its membership removed. Owner is never assignable through this - it changes by being offered and
accepted - and a personal library keeps its owner's membership regardless of what else is
submitted, the same protection the original carries.

The user and library pickers this needed already existed (`GET /admin/users`, `GET
/admin/libraries`), discovered rather than assumed missing - only the one new endpoint, for
reading and rewriting one account's own grants, needed building.

Proved live with a genuine second account and a genuine second library, not the single admin
user this session has mostly tested against: granted contributor access, confirmed it landed
exactly as submitted while the account's own personal-library ownership stayed untouched;
submitted an empty map and confirmed the contributor grant was correctly revoked while the
personal library's owner membership was correctly preserved - the wholesale-rewrite rule and its
one deliberate exception, both checked against real data.

`docs/openapi.yaml` updated. Full suite re-run: still 1 of 25, unchanged.

**Client-side screen not built this round** - API only, the same split used throughout this
session for genuinely new API surface.

This package is **build 35**.

**A second, smaller fix to `api_import_run()`: `library_id`, `commit`, and `create_titles` now
read from the query string as well as the POST body.** The client's own multipart upload helper
sends one file field and nothing else - the same shape item photo uploads already needed, and
the same fix already applied there: everything but the file itself travels as a query parameter
on the URL, so the endpoint needs to look in both places rather than only the one a raw
`curl -F` command would use.

This package is **build 34**.

**A real, working CSV import API - a client for the engine's own `import_parse()`/
`import_commit()`, not a reimplementation.** Dry run by default: nothing is written unless
`commit=1` is sent, matching the real web form's own two governing rules exactly - the whole file
is read and understood before anything writes, and a row naming an ID updates while a row without
one creates.

Found and fixed a real, latent bug while investigating this: `import_commit()` recorded who made
each imported entry using `current_user()`, which only ever checks the session. Every entry
imported through a token-authenticated request - this new API, or any future one - would have
silently recorded no creator at all. The same class of bug `is_admin()` vs `is_admin_user
(acting_user())` already turned out to be earlier this session, caught this time before it ever
shipped rather than after. Fixed by switching to `acting_user()`, which checks the token first
and falls back to the session - correct for both callers, not just the new one.

Proved live, in stages: a dry run against a real two-row file, confirmed the report correctly
predicted two creates while the database genuinely stayed at zero rows - not just that the
response looked right. Then the same file with `commit=1`, confirmed both rows landed for real,
with `created_by` correctly set to the real authenticated user - proving the fix, not just
trusting it. Then a second import naming one existing ID and one blank, confirmed the named row
updated in place while the blank one created a genuinely new entry - the create-vs-update rule,
checked against real data rather than read off the code.

`docs/openapi.yaml` updated. Full suite re-run: still 1 of 25, unchanged on both the API and web
suites - the fix touches a function the session-based web form also calls, and that path still
works too.

**Client-side screens not built this round** - API only, the same split used for credits,
hardware models, and software models earlier tonight.

This package is **build 33**.

**The software-models API - full CRUD, owner-gated to match hardware models rather than the real
screen's own site-wide admin bar.** A boxed-release template: what a title made from it starts
already filled in with, not an ongoing reference to it. Deliberately narrower than the real form
on purpose: no custom spec fields, no box-contents checklist, no per-medium list - each a genuine,
separate child table (`software_model_fields`, `software_model_contents`, `software_model_media`)
left for later, the same restraint hardware models' own compatibility and vocabulary features
already got.

**Deliberately no delete guard**, matching a real, considered choice the original screen already
made rather than an oversight this round introduced: a model is where an answer came from, not
where it lives, so removing one does not touch what a title made from it already has. Checked
this directly against the real save handler's own comment before matching it, rather than
defaulting to the guard every other delete in this session carries.

Applied this session's own lessons before any live testing: create returns its own `api_ok(...,
201)` directly rather than delegating to show() the way hardware models' create originally did
and had to be fixed - the same mistake, not repeated. Loaded every function directly and
confirmed each one genuinely exists and is callable before trusting any of them with a request.

Proved live: created a real model with a real category and platform, confirmed it returns 201,
confirmed delete succeeds immediately even while nothing points at it yet - the deliberate
absence of a guard, not a missing one.

`docs/openapi.yaml` updated. Full suite re-run: still 1 of 25, unchanged.

**Client-side editing not built this round** - API only, the same split used for hardware models,
credits, and items.

This package is **build 32**.

**Fixed a real bug in the hardware-models API, found while building this client's own editor:
create returned HTTP 200 instead of 201.** `api_hardware_models_create()` reused
`api_hardware_models_show()` for its enriched response - a reasonable-looking shortcut that
quietly dropped the 201 status along with it, since show() has no reason to ever send one. The
row was created correctly every time; only the status code lied about it, which is exactly what
a client's own "did this actually work" check relies on.

Refactored rather than patched around: extracted the shared row-fetch into
`hardware_model_fetch()`, with show, create, and update each sending their own `api_ok()` call
and correct status code, instead of one delegating to another. Re-verified with this session's
own post-incident discipline before any live retest - loaded every related function directly,
confirmed all five exist and are callable.

Proved live with the exact request that used to lie: a real create, now genuinely returning 201.

Full suite: still 1 of 25, unchanged.

This package is **build 31**.

**The hardware-models API - full CRUD, deliberately narrower than the real form, and
owner-gated to match.** One table for machines and the parts that go in them, the category
already filed under deciding which - the same choice the real schema itself already made.
Deliberately does not cover `interface_vocab_id` (a real, separate controlled-vocabulary
feature) or `model_compatibility` (a genuine many-to-many, also separate work) - `interface` and
`fits_note` stay free text, the same fallback the schema itself keeps for whatever those two
features do not yet cover.

Gated at owner level, not the curator bar the rest of this session's taxonomy work has used -
checked directly against the real web screen's own permission logic rather than assumed, since
this one turned out to be genuinely stricter. A new `api_require_owns_library()` mirrors the
existing curator-level helper exactly, just against `can_own_library()` instead.

Caught a real bug before it ever reached a live request: `php -l` cannot see an undefined
function call, and a copy-paste left three fields calling `nullify_str()`, which does not exist -
only `nullify()` does. Found by this session's own post-incident discipline: loading every
function directly and checking each one is real before trusting any of them with a request,
which is exactly the check that caught this one in seconds rather than a live 500 first.

Proved live: created a real machine model, confirmed a category with the wrong role is correctly
refused with a clear message, confirmed a role=machine filter returns only machines including the
one just created, confirmed the delete guard correctly refuses while a real item points at it,
and confirmed update genuinely persists a change.

`docs/openapi.yaml` updated. Full suite re-run: still 1 of 25, unchanged.

**Client-side editing not built this round** - API only, the same split this session used for
credits and items.

This package is **build 30**.

**The items browse filter now genuinely accepts `domain=video` and `domain=music`, not just
hardware and software.** Same pattern as the credit_roles domains, the categories Kind field, and
companies' own makes checkboxes earlier this session: the schema and the underlying data have
supported all four domains for a while, but this particular filter still only checked against two
of them - checked directly rather than assumed still current, since several other spots turned
out to have exactly this drift already.

Proved live with a real video item, not just an empty, error-free result: created a real movie
under DVD, confirmed `?domain=video` actually returns it, and confirmed `?domain=software`
correctly does not - proving genuine exclusion, not merely that the filter accepts the value
without complaint.

Full suite: still 1 of 25, unchanged.

This package is **build 29**.

**Found and fixed a real, pre-existing order-dependency bug in `api_item_input()`, discovered
while building this client's own items editor.** The developer/publisher-by-name resolution block
read `$data['library_id']` to know which library to search or create a company in - but
`library_id` itself was not copied from the request into `$data` until nearly ninety lines later
in the same function. A create sending both `library_id` and a bare developer name - the ordinary,
documented way to use either field - failed every time with "Send library_id too, or a
developer_id," even though library_id was right there in the same request.

Fixed by moving the library_id block ahead of the block that depends on it, rather than
duplicating the logic - the original block removed rather than left as dead, confusing code.

Given how central this function is - it backs every item create and update in the whole
application - re-verified with this session's own post-incident discipline before any live
testing: loaded every function in the file directly, confirmed all six related functions still
exist as real, correctly-scoped, callable functions, the same check that would have caught an
earlier mistake this session in seconds.

Proved live with the exact request that used to fail: library_id and a bare developer name
together, on a real create - now resolves the company by name and succeeds, confirmed by the
real company row appearing in the response rather than the previous 422.

Full suite: still 1 of 25, unchanged.

This package is **build 28**.

**Companies' `makes` now genuinely supports video and music, resolving a question left open
several sessions ago.** The schema always allowed it (`SET('hardware','software','video',
'music')`), but three separate places quietly hardcoded the two-value list and would have
silently stripped a video or music tick even if a checkbox for it existed: the real web form's
own field only offered two checkboxes; its save handler intersected against only two values
regardless of what was ticked; and the structure-sync helpers (`company_makes_from()`,
`company_makes_merge()`) that populate companies from template data did the same. All four
fixed together, since fixing only some would have meant a checkbox that silently did nothing.

Checked comprehensively rather than assuming these four were the only ones - found and correctly
left alone two genuinely unrelated hits sharing the same two-value pattern: a metadata agent's
own domain scope (which content Wikipedia's infobox is useful for, a different question
entirely), and platforms' computer/console/handheld-to-domain mapping, which is correctly
hardware+software only and not part of this at all.

Proved live end to end: the real web form now offers Video and Music checkboxes; a company
created through it with both ticked saved with `makes = 'video,music'`, confirmed directly
against the database, not assumed from the form submitting successfully.

Full suite: still 1 of 25, unchanged.

This package is **build 27**.

**Fixed the real tree editor's "Any machine" filter, genuinely broken since the machine_class ->
domains rework several sessions ago and left unresolved at the time.** The controller side had
already been correctly updated; the template still read `$pf['machine_class']`, a column that no
longer exists, silently evaluating to an empty string rather than throwing - so the filter looked
intact but matched nothing at all.

The real fix needed more than a rename. Domains alone cannot answer "is this a computer, a
console, or a handheld" - all three share the identical domains (hardware and software both) -
so the finer distinction has to come from somewhere else. Reused the exact derivation
`seed_library_categories()` already established, applied to a library's own real hardware_models
this time rather than the template rows used to seed a fresh library in the first place.

Found a second, real gotcha proving this live rather than assuming the fix from the code alone:
a category's own slug is platform-prefixed for uniqueness ("amiga-computers"), which does not
match the plain three values the filter offers. `source_slug` - what the template row it was
copied from was actually called - is the column that does, and is what the fix actually reads.

Proved live against the real web screen, not assumed from a query in isolation: fetched
`/manage/tree`, confirmed real `data-class="computer"` / `"console"` / `"handheld"` values appear
for genuine machine platforms, and confirmed platforms with no machine role at all (DVD, VHS, CD)
correctly carry no kind rather than a wrong one.

Full suite: still 1 of 25, unchanged.

This package is **build 26**.

**The environments API - create, read, update, delete.** Investigated properly before building
anything, given the last two "smaller pieces" both turned out much bigger than expected -
"Environments" genuinely is the small, contained piece it looked like: no standalone table of its
own, just `operating_systems` (what a release runs under - Workbench, DOS, a console's BIOS),
per platform, with the same shape as companies/tags/credit_roles this session already proved out.
Confirmed the real web screen's own permission gate is curator-level (`require_manage()`), not
the stricter owner-level hardware models turned out to need - checked directly rather than assumed
from the function name alone.

Proved live: created a real environment under a real platform, updated it, deleted it, and
separately confirmed the delete guard - refused correctly, with the real entry count, while a
genuine item still names it.

`docs/openapi.yaml` updated. Full suite re-run: still 1 of 25, unchanged.

**Client-side editing not built this round yet** - API only, matching this session's established
split.

This package is **build 25**.

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
