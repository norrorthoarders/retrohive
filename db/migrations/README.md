# Migrations

Empty, deliberately.

`db/schema.sql` is the single source of truth for the current release. Until
there is a version in somebody's hands there is no history worth carrying, and
two files describing the same tables is two files that can disagree.

## Adding one

From the first release onwards, any change to `db/schema.sql` needs a matching
file here so existing installs can catch up:

```
001_short_description.sql
```

Number them in order, write them to be safe on re-run (`CREATE TABLE IF NOT
EXISTS`, `ADD COLUMN IF NOT EXISTS`), and change `db/schema.sql` in the same
commit so a fresh install and an upgraded one end up identical.

`php bin/migrate.php doctor` compares a live database against `schema.sql` and
reports anything missing, which is how you check the two have not drifted.
