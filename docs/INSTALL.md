# Installing RetroHive

A manual install onto a LAMP server you already run. There is no installer and
no container: copy the files, create a database, point a vhost at `public/`.

- [Requirements](#requirements)
- [1. Database](#1-database)
- [2. Files and permissions](#2-files-and-permissions)
- [3. Configuration](#3-configuration)
- [4. Web server](#4-web-server)
- [5. PHP settings](#5-php-settings)
- [6. First run](#6-first-run)
- [Users and library access](#users-and-library-access)
- [Connecting native apps](#connecting-native-apps)
- [Behind a reverse proxy](#behind-a-reverse-proxy)
- [Backups](#backups)
- [Upgrading](#upgrading)
- [Troubleshooting](#troubleshooting)
- [Data model reference](#data-model-reference)

---

## Requirements

- PHP 8.1 or newer with `pdo_mysql`, `mbstring`, `gd` and `dom`; `exif`
  recommended. `dom` is used only by the HTML-scraping metadata providers —
  without it they return nothing and log the reason, and the rest of the
  application is unaffected.
- MariaDB 10.6+ or MySQL 8+
- Apache with `mod_rewrite`, or nginx with php-fpm

openSUSE / SLES:

```bash
zypper install php8 php8-mysql php8-gd php8-mbstring php8-exif \
               apache2-mod_php8 mariadb
```

Debian / Ubuntu:

```bash
apt install php php-mysql php-gd php-mbstring php-cli \
            libapache2-mod-php mariadb-server
```

RHEL / Rocky:

```bash
dnf install php php-mysqlnd php-gd php-mbstring httpd mariadb-server
```

Check what you have:

```bash
php -m | grep -E '^(pdo_mysql|gd|mbstring|dom|exif)$'
```

---

## The quick way: the web installer

`public/install.php` walks through the whole thing in a browser: it checks the
PHP version, extensions, upload limits and directory permissions, tests the
database connection, loads the schema and structure data, writes
`src/config.local.php`, and creates the administrator account.

```
http://your-server/install.php
```

You do not have to go looking for it: until `src/config.local.php` exists, every
URL redirects to the installer automatically, and the API returns a JSON 503
saying so rather than a redirect. Once the config is in place the redirect stops
and the installer locks itself.

If the config file exists but the web server cannot *read* it — a common result
of copying it in as root — you get a page naming the file and the exact
`chgrp`/`chmod` to run, instead of a 500 with a stack trace.

At the end, the installer offers the finished `config.local.php` as a download.
That matters when `src/` is not writable by the web server, which is the safer
arrangement: the wizard cannot save the file itself, so it hands it to you with
the `scp` command to put it in place.

**The installer runs only when `src/config.local.php` does not exist.** That is
the whole rule. Once the file is there the installer refuses, whatever the
database contains and whoever is asking — there is no button, query parameter or
session that opens it again. It also offers to delete itself on the final page,
which is worth doing.

To run it again — moving to a different database, or starting the collection
over — move the configuration aside first:

```bash
mv src/config.local.php src/config.local.php.bak
```

That is a shell action by design: a gate that can only be opened from a terminal
cannot be opened from a browser. The wizard then notices the existing database
and offers to keep it (what you want when moving servers — only the config is
rewritten) or to erase it. Erasing needs the word ERASE typed in, drops only the
tables this application owns, and rebuilds them empty. Uploaded photos are left
alone unless you tick the box, since they are the part you cannot re-download.

**If you have just copied new files over an existing install, you want the
updater, not the installer.** See [Updating](#updating). **Do delete it** — an
installer sitting in a document root is a liability even when it declines to do
anything.

If `src/` is not writable by the web server user, which is the safer
arrangement, the installer shows you the exact file contents to paste in over
SSH instead of failing.

The manual steps below do the same work, and are worth reading if the installer
reports something it cannot fix for you.

## The unattended way: the command line installer

The wizard asks seven pages of questions, which is right for one machine and
wrong for the twentieth, for a container that has to come up on its own, and for
anything where the answers belong in a file somebody can review before it runs.

`bin/install.php` reads the answers instead of asking. It does the same work in
the same order using the same code — it includes `public/install.php` for its
helpers rather than keeping a second copy of them to drift out of step.

Without a file at all, it asks:

```
php bin/install.php --interactive
```

Every question carries what it will use if the answer is nothing, passwords are
not echoed and are typed twice, and each answer is checked as it is given — a run
that asks fifteen questions and then complains about the third has wasted the
other twelve. Nothing is written until the end.

Add `--save-answers install.rsp` and it writes the answers out afterwards, in the
same form the wizard hands you, so the machine after this one needs no questions
either. `--answers` and `--interactive` combine: the file fills in what it knows
and the questions cover the rest.

From a file:

```
php bin/install.php --example > install.rsp
chmod 600 install.rsp
$EDITOR install.rsp
php bin/install.php --answers install.rsp --dry-run
php bin/install.php --answers install.rsp
```

The response file is INI in shape and `.rsp` by name, in five sections. `--example`
prints a commented one, and
**the last page of the web installer writes one from the answers you just gave** —
which is the easiest way to get a correct file for the second machine.

INI rather than PHP, because the wizard accepts one by upload and `require` on an
uploaded file is remote code execution wearing a hat. `parse_ini_string()`
executes nothing.

The wizard offers it twice: on the **review** step, beside *Install now*, where
you are looking at the whole plan and deciding it is right — and again on the
last page. Either way it is streamed to the browser and never written to disk; an
installer that left a file of answers in the document root would have undone the
reason none of the credentials are in it.

**No username or password is ever written into it.** Those come out as
`change-database-user-here` and friends, and a file still carrying one is refused
rather than installed with a database user by that name. Fill them in, or leave
them and set `RETROVAULT_DB_PASS` and `RETROVAULT_ADMIN_PASS` in the environment.

What it holds:

| Section | What it decides |
| --- | --- |
| `[db]` | host, port, name, user, pass |
| `[admin]` | username, password, email, display name |
| `[instance]` | name, tagline, public address, currency, timezone, trusted proxies |
| `[server]` | `web_user`, `web_group` — who the web server runs as |
| `[install]` | `metadata_sources` (switch on the lookup sources needing no key; on by default, and a tickbox on the wizard's settings step), `deploy` (`install` on an empty database, `erase` to drop what is there first, `keep` to write the configuration only), `erase_uploads`, `force_erase`, `templates` (`remote`, `shipped` or `none`), `examples`, `delete_installer`, `sign_in` |

### Running it as root

The wizard runs *as* the web server, so everything it writes is already owned by
the right account. A shell does not: run as root, `src/config.local.php` comes out
`root:root` at 0640, the web server cannot read it, and the symptom is a **503
with nothing in any log** — the application never got far enough to write one.

So when `bin/install.php` is root it sets the owner itself. It uses `web_user`
and `web_group` from the `[server]` section if they are given, and otherwise
looks for `wwwrun`, `www-data`, `apache`, `nginx` and `http` in that order. Two
things change hands: the configuration file, and `public/uploads`, which is the
one directory the application writes to. Everything else stays owned by root and
readable, which is what section 2 above asks for.

If no account is found it says so rather than leaving you to discover it as a
503.

### After the work

Two more, both off unless a file turns them on — somebody walking the wizard by
hand has not agreed to either.

`delete_installer = 1` removes `public/install.php` once the install has
finished, in both installers. `bin/install.php` keeps working afterwards: what
the two share lives in `src/installer.php`, not in the wizard. A file that refuses to run is still better removed
than left in a document root. It will not delete itself if the configuration was
not written, since that would leave no way back in except a shell.

`sign_in = 1` signs the browser in as the administrator just created and goes
straight to the instance rather than stopping on the installer's last page. The
command line installer has no browser to sign in and ignores it.

### Handing one back to the wizard

The first page of the web installer has a drop zone at the top. Drop a file on it
and it is checked at once, and one of three things happens.

**A complete file installs on the spot.** If it carries the database account and
the administrator as well as everything else, and the database answers, the
remaining five pages have nothing left to ask — so they are skipped and the
install runs. One drag, no prompts.

**A file with the credentials still blank fills the pages in** and the wizard
carries on from there, asking only for what the file does not hold.

**An unusable file is marked as such** and the ordinary installation continues
below it. Dropping another checks that one instead; a file that was already
accepted is not thrown away by a fumbled second drop.

`deploy = erase` needs a second word before it will do that unasked. On its own
it stops: the wizard loads the answers and shows the review page with the button
yours to press, and `bin/install.php` refuses and says why. `force_erase = 1` in
the `[install]` section is the confirmation, and with it neither asks.

Two sentences rather than one because an answer file gets copied between
machines, and the collection it destroys is whichever database it happens to name
that day. `deploy` says what to do; `force_erase` says it was meant.

`--dry-run` checks the answers, the server and the database connection and writes
nothing. Worth running first in provisioning, because the alternative is finding
out about a bad timezone after the schema has loaded.

Every complaint about the answer file is reported at once rather than one per
run, and the exit status is 0 only if the install finished — which is what a
provisioning script needs and what a web page cannot give it.

A second run stops rather than overwriting `src/config.local.php`. `--force`
overrides that, and is deliberately separate from `deploy: erase`: one destroys a
configuration and the other destroys a collection, and conflating them is how the
wrong one happens.

### Silent, for provisioning

`--quiet` prints nothing when it works. The reason goes to stderr and the status
is non-zero when it does not, so a run is either invisible or explained:

```
RETROVAULT_DB_PASS=... RETROVAULT_ADMIN_PASS=... \
  php bin/install.php --answers install.rsp --quiet || exit 1
```

`RETROVAULT_DB_PASS` and `RETROVAULT_ADMIN_PASS` override `db.pass` and
`admin.password`. The environment wins over the file because it is the more
specific of the two — a file is written once, an environment is set per run — and
because it lets the answer file be templated, committed and hold no secret.

Or hand the whole thing over on standard input, so it never exists as a file:

```
vault read -field=answers secret/retrohive | php bin/install.php --answers - --quiet
```

Buffered through a private temporary file that is removed either way, because the
answers are PHP and `eval()` on something arriving down a pipe is a worse idea
than the temporary file is.

The answer file holds a database password and an administrator password unless
the environment supplies them. Delete it when the install is done, along with
`public/install.php`.

## 1. Database

```sql
CREATE DATABASE retrohive CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'retrohive'@'localhost' IDENTIFIED BY 'a-long-random-password';
GRANT ALL PRIVILEGES ON retrohive.* TO 'retrohive'@'localhost';
FLUSH PRIVILEGES;
```

`utf8mb4` is not optional — without it, Swedish and Japanese titles will be
mangled on the way in.

Load the structure, then the starter taxonomy:

```bash
mariadb -u retrohive -p retrohive < db/schema.sql
mariadb -u retrohive -p retrohive < db/seed.sql
```

`seed.sql` gives you 10 libraries, 9 software types, a filing tree per platform mapped to
their types, and a handful of studios. Every insert is keyed on a slug, so
re-running it adds nothing. Skip it entirely if you would rather build your own
taxonomy from the Manage screens.

Generate a password with `openssl rand -base64 24`.

---

## 2. Files and permissions

```bash
sudo mkdir -p /opt/retrohive
sudo cp -a . /opt/retrohive/
cd /opt/retrohive
```

The web server user (`www-data` on Debian, `wwwrun` on SUSE, `apache` on RHEL)
needs to write to exactly one directory:

```bash
sudo chown -R root:root /opt/retrohive
sudo chown -R www-data:www-data /opt/retrohive/public/uploads
sudo chmod 775 /opt/retrohive/public/uploads
```

Nothing else needs to be writable. If SELinux is enforcing:

```bash
sudo semanage fcontext -a -t httpd_sys_rw_content_t "/opt/retrohive/public/uploads(/.*)?"
sudo restorecon -Rv /opt/retrohive/public/uploads
```

---

## 3. Configuration

```bash
cp src/config.local.php.example src/config.local.php
$EDITOR src/config.local.php
```

```php
return [
    'app_name' => 'RetroHive',
    'currency' => 'SEK',
    'timezone' => 'Europe/Stockholm',
    'debug'    => false,

    'public_browse' => false,   // true = anyone on the LAN can browse read-only

    'db' => [
        'host' => '127.0.0.1',
        'port' => 3306,
        'name' => 'retrohive',
        'user' => 'retrohive',
        'pass' => 'a-long-random-password',
    ],

    'base_url' => '',           // see "Behind a reverse proxy"
];
```

Lock it down — it holds the database password:

```bash
sudo chgrp www-data src/config.local.php
sudo chmod 640 src/config.local.php
```

The `timezone` setting matters more than it looks. The app puts the database
session into the same timezone, so PHP and MariaDB agree on what a timestamp
means. Getting this wrong makes the API's delta sync silently miss recent
changes.

---

## 4. Web server

### Apache

```apache
<VirtualHost *:80>
    ServerName retro.example.com
    DocumentRoot /opt/retrohive/public

    <Directory /opt/retrohive/public>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog  /var/log/apache2/retrohive-error.log
    CustomLog /var/log/apache2/retrohive-access.log combined
</VirtualHost>
```

```bash
sudo a2enmod rewrite headers
sudo systemctl reload apache2
```

`AllowOverride All` matters — the routing and the API's Authorization header
passthrough both live in `public/.htaccess`. If you would rather not allow
overrides, paste that file's contents into the `<Directory>` block and set
`AllowOverride None`.

### nginx + php-fpm

```nginx
server {
    listen 80;
    server_name retro.example.com;
    root /opt/retrohive/public;
    index index.php;

    client_max_body_size 32M;      # must exceed your upload limit

    location / {
        try_files $uri $uri/ /index.php$is_args$args;
    }

    location ~ \.php$ {
        include fastcgi_params;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        # Bearer tokens: php-fpm drops this header unless it is passed through.
        fastcgi_param HTTP_AUTHORIZATION $http_authorization;
    }

    # Uploaded files are data, never code. .htaccess does not apply here.
    location ^~ /uploads/ {
        location ~ \.php$ { return 403; }
        add_header X-Content-Type-Options nosniff;
        expires 30d;
    }

    location ~ /\.(?!well-known) { deny all; }
}
```

That `fastcgi_param HTTP_AUTHORIZATION` line is easy to miss and its absence
produces a confusing symptom: the web interface works perfectly while every API
call returns 401.

### Installing in a subdirectory

The app works out its own prefix, so `https://host/retrohive/` needs no
changes. If a proxy rewrites paths in a way that confuses the detection, set it
explicitly:

```apache
SetEnv APP_BASE_PATH /retrohive
```

---

## 5. PHP settings

Photos of big boxes run large. In `php.ini` or a pool override:

```ini
upload_max_filesize = 16M
post_max_size = 128M
max_file_uploads = 30
memory_limit = 512M
max_execution_time = 120

expose_php = Off
session.cookie_httponly = 1
session.use_strict_mode = 1
```

`post_max_size` needs plenty of headroom because a batch of photos arrives in
one request. The app's own `uploads.max_bytes` should sit at or below
`upload_max_filesize`; PHP rejects oversized files before the app ever sees them.

---

## 6. First run

Open the site. With no accounts in the database it redirects to `/setup` and
offers to create the administrator. **Do this before the app is reachable from
anywhere you do not control** — that page is necessarily unauthenticated.

Or from the command line:

```bash
php bin/create-user.php tommy --role admin --name "Tommy"
php bin/create-user.php --list
php bin/create-user.php --reset tommy
```

Then:

1. **Manage → Libraries.** Ten come pre-loaded. Give each a shelf colour; that
   colour becomes the spine on every card and row for that library, which is
   what makes a mixed list scannable.
2. **Manage → Software types** and **Genres**, if the defaults do not match how
   you think about your collection. Genres attach to a type, which is what makes
   the entry form show only relevant options.
3. **Add title.** Only title, library and software type are required.
   Developer and publisher accept free text — type a studio that does not exist
   and it is created and linked automatically.

---

## Behind HAProxy, on separate machines

If the web server and database live on different VMs with a proxy in front,
follow **[DEPLOYMENT.md](DEPLOYMENT.md)** instead of this section — it covers
the three settings that must line up (`trusted_proxies`, `base_url`, and the
MariaDB bind address) and the HAProxy configuration to match.

## Directory sign-in

Local accounts work out of the box. To authenticate against LDAP or Active
Directory, install `php-ldap` and see **[LDAP.md](LDAP.md)**. The schema already
carries everything needed, so enabling it later requires no migration beyond
`003_auth_methods.sql`.

## Users and library access

Roles are set on **Manage → Accounts**; per-library access on
**Manage → Library access**.

Each account has a default that applies to every library, plus per-library
exceptions. Two common shapes:

**Everything except one shelf.** Default `read` or `write`, then set the one
library you want to keep private to `none`.

**Only one shelf.** Default `none`, then grant `write` on the single library
that account should reach. This is the safer pattern for an account you are
lending to someone.

The account role caps the result. A viewer with `write` granted on a library
Access is one row per account per library and there is no global default, so
removing every row is how you freeze somebody without unpicking anything.

Administrators do **not** bypass this. An `admin` may configure the instance —
accounts, authentication, metadata sources, the shared taxonomy — and that is
all the role governs. To read a private library they must grant themselves
membership, which takes one step and records who made the grant, so it stays
visible afterwards. Pretending the system could stop someone with shell and
database access would be a comfortable lie; making such access deliberate and
legible is worth more.

From the command line:

```bash
php bin/create-user.php anna --role user
mariadb -u retrohive -p retrohive -e "
  INSERT INTO library_members (library_id, user_id, access)
  SELECT l.id, u.id, 'contributor'
    FROM users u, libraries l
   WHERE u.username = 'anna' AND l.slug = 'amiga-shelf';"
```

Note what is *not* there. There is no global default to set to 'none' first:
membership is the whole of access, so a missing row already means no access and
the grants are an allow-list by construction. And the grant names a **library**,
not a platform — a platform is the machine an entry runs on and confers
nothing.

A library the account cannot read is invisible rather than forbidden: it is
missing from browsing, search, the dashboard, developer pages, CSV export and
every API endpoint, and requesting one of its entries by id returns 404. That is
intentional — a 403 would confirm the entry exists.

## Connecting native apps

The REST API lives at `/api/v1`. Full reference in
[API.md](API.md); the OpenAPI spec in [openapi.yaml](openapi.yaml) can generate
a typed client for Swift, Kotlin or anything else.

### Issuing a token

**Manage → App access** in the web interface. Name it after the device, choose
read-only or read-write, optionally set an expiry, and copy the token — it is
shown once and stored only as a hash.

Or let the app do it, which is what a sign-in screen should do:

```bash
curl -X POST https://retro.example.com/api/v1/auth/login \
     -H 'Content-Type: application/json' \
     -d '{"username":"tommy","password":"...","device_name":"Tommy'\''s iPhone","platform":"ios"}'
```

Either way the client then sends `Authorization: Bearer rvt_...` on every
request. Tokens are listed with their last-used time and IP, so a lost phone can
be revoked without disturbing anything else.

### Checking it works

```bash
TOKEN=rvt_...
HOST=https://retro.example.com

curl -s "$HOST/api/v1/meta" | jq .
curl -s "$HOST/api/v1/items?per_page=5" -H "Authorization: Bearer $TOKEN" | jq '.meta'
```

If `/api/v1/meta` works but authenticated calls return 401, the Authorization
header is being stripped before PHP sees it — see the nginx note above, or
confirm `AllowOverride All` on Apache.

### CORS

Only relevant for browser-based clients; native apps ignore it. The default
allows any origin, which is safe while every request carries a bearer token. To
restrict it, set `api.cors_origins` in `config.local.php` to a list of exact
origins.

---

## Behind a reverse proxy

If you terminate TLS at HAProxy, nginx or similar, pass the protocol through so
session cookies get the `Secure` flag:

```
http-request set-header X-Forwarded-Proto https
```

The app reads `HTTP_X_FORWARDED_PROTO`. Without it, cookies are issued without
`Secure` and a strict browser may drop them on an HTTPS site.

**More important for mobile clients:** set `base_url` in `config.local.php`.

```php
'base_url' => 'https://retro.example.com',
```

The API builds absolute image URLs from the incoming request. Behind a proxy
that rewrites `Host` or terminates TLS, that detection can produce `http://`
URLs or internal hostnames — which a phone then cannot load, and which iOS App
Transport Security blocks outright. Setting `base_url` removes the guesswork.

Nothing here uses WebSockets or long-polling, so default proxy timeouts are
fine. Do raise the request body limit to match your upload settings.

---

## Backups

`bin/backup.sh` writes a compressed SQL dump and a tarball of the photos, then
prunes anything older than 30 days. It reads the database credentials from
`config.local.php`, so it needs no arguments beyond a destination.

```bash
./bin/backup.sh /srv/backups/retrohive
```

```cron
30 3 * * * /opt/retrohive/bin/backup.sh /srv/backups/retrohive >> /var/log/retrohive-backup.log 2>&1
```

### Housekeeping

Two tables and one directory grow on their own: `auth_log` gains a row per
sign-in attempt, the notification queue keeps what it has sent, and
`public/uploads` keeps photos whose entry has since been deleted. Nothing trims
them unless something is scheduled to.

```bash
./bin/maintenance.sh              # prune the sign-in log, report orphaned files
./bin/maintenance.sh --delete     # and delete the orphans
```

```cron
15 4 * * * /opt/retrohive/bin/maintenance.sh >> /var/log/retrohive-maintenance.log 2>&1
```

Queued notification mail is separate, because it wants sending promptly rather
than nightly:

```cron
*/5 * * * * cd /opt/retrohive && php bin/notify.php send >/dev/null
```

Restoring:

```bash
gunzip -c db-20260725-033000.sql.gz | mariadb -u retrohive -p retrohive
tar -C /opt/retrohive/public -xzf uploads-20260725-033000.tar.gz
```

Restore the database and the photo directory as a pair. Rows in `item_images`
reference filenames on disk, so a mismatched restore leaves broken image links —
harmless, but tedious to clean up.

---

## Updating

There are no migrations yet: this is the first release, and `db/schema.sql` is
the whole truth. A fresh install is simply schema plus seed.

From the next release onwards, changes ship as numbered files in
`db/migrations/`, and:

```bash
php bin/migrate.php status     # what is applied, what is pending
php bin/migrate.php up         # apply everything outstanding
php bin/migrate.php doctor     # compare the live database against schema.sql
```

The application refuses to start while the database is behind the code, rather
than failing partway through a page with a missing column. `/update.php` does
the same from a browser.

`doctor` is worth running even now: it compares what is in the database against
`db/schema.sql` and reports anything missing, which catches a half-finished load
or a hand-edited table.

## Upgrading files

```bash
./bin/backup.sh /srv/backups/retrohive
sudo systemctl stop apache2

sudo rsync -a --exclude=public/uploads --exclude=src/config.local.php \
     ./ /opt/retrohive/

# apply any new migrations
php bin/migrate.php up

sudo systemctl start apache2
```

Migrations use `CREATE TABLE IF NOT EXISTS` and are safe to re-run. `schema.sql`
on its own only creates missing tables; it does not alter existing ones.

If you installed before the API existed, `001_api.sql` adds the `api_tokens` and
`tombstones` tables. `002_access_and_status.sql` adds per-library access, the
item status field, per-component condition grading and valuation — it backfills
`status` from the old `is_wishlist` flag and rebuilds the `v_items` view, and it
is safe to run more than once.

---

## Troubleshooting

**"Database unavailable" on every page**
Credentials or host are wrong, or MariaDB is not running. Set `'debug' => true`
in `config.local.php` to see the real PDO error, then set it back.

**Every URL except the front page returns 404**
`mod_rewrite` is off, or `AllowOverride` is not `All`, so `public/.htaccess` is
ignored. On nginx, the `try_files` line is missing.

**The web interface works but every API call returns 401**
The `Authorization` header is not reaching PHP. On nginx add
`fastcgi_param HTTP_AUTHORIZATION $http_authorization;`. On Apache make sure
`AllowOverride All` is set so the rewrite rule in `public/.htaccess` applies.

**Uploads silently do nothing**
Almost always `post_max_size`. When a POST exceeds it, PHP discards the entire
request body — the app receives an empty form and no error to report. Raise it
well above `upload_max_filesize` times the number of files in a batch.

**Uploads report a permissions error**
`public/uploads` is not writable by the web server user:
`sudo -u www-data touch /opt/retrohive/public/uploads/test`

**Photos appear full size and pages are slow**
`gd` is missing, so no thumbnails were generated. Install it and re-upload.

**Photos are sideways**
`exif` is missing. Install `php-exif` and re-upload the affected photos.

**API sync returns no changes even though things changed**
The `timezone` in `config.local.php` does not match reality, or the app cannot
set the database session timezone. Check the PHP error log for
`could not set session time_zone`; on a fresh MariaDB you may need to load the
timezone tables:
`mariadb-tzinfo-to-sql /usr/share/zoneinfo | mariadb -u root -p mysql`

**Mobile app shows broken images**
The API is handing out URLs the phone cannot reach. Set `base_url` in
`config.local.php` to the address clients actually use.

**Someone cannot see a library they should**
Check **Manage → Library access** for that account. Remember the role caps the
result: a viewer granted `write` still only gets read. Also check the account's
default, which applies wherever there is no explicit row.

**Locked out**
`php bin/create-user.php --list`, then `--reset USERNAME`. If every admin
account is gone:
`mariadb -u retrohive -p retrohive -e "UPDATE users SET role='admin' WHERE username='you';"`

**Swedish characters look wrong**
The database was not created with `utf8mb4`. Check with
`SHOW CREATE DATABASE retrohive;` and convert:

```sql
ALTER DATABASE retrohive CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
ALTER TABLE items CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
-- repeat for each table
```

---

## Data model reference

```
                          ┌── titles ────┐  what the software IS
platforms ──┐             ├── hardware_models   what the hardware IS
categories ─┼──< items >──┼── companies (developer_id)
        ─────┘   your copy ├── companies (publisher_id)
                          ├──< item_images
                          ├──< item_events      acquired / valued / lent / sold
                          ├──< item_links       what is fitted to what
                          └──< item_tags >── tags

libraries ──< items                  the only access boundary
users ──< library_members >── libraries
users ──< api_tokens                 tombstones (deletion log for API sync)
```

| Table | Holds |
|---|---|
| `libraries` | Shelves: name, owner, visibility, colour. The access boundary |
| `library_members` | Who may do what in which library, and who granted it |
| `platforms` | Machines: Amiga, C64, MSX, DOS. Not an access boundary |
| `categories` | The filing tree: per library, one branch per machine, `domain` and `role` recorded here |
| `companies` | Everyone who made anything, with a `makes` tag for hardware, software or both |
| `titles` | What a piece of software *is*, recorded once |
| `hardware_models` | What a piece of hardware *is*, with field and slot templates |
| `hardware_vocab` | Interfaces, sockets, form factors, features. `platform_id = 0` means anywhere |
| `model_fields`, `model_slots` | What a model asks about, and what it will take |
| `items` | Your copies: condition, completeness, price, location, status |
| `item_hardware` | Hardware detail per unit, plus `specs`: one ordered list of label/value rows for whatever this one has |
| `item_images` | Photos: filename, content hash, type, caption, cover flag |
| `item_events` | Acquired, valued, lent, returned, sold — dated, so value is a series |
| `item_links` | What is fitted to what, nested |
| `tags`, `item_tags` | Free-form labels, many-to-many |
| `operating_systems` | What software runs under, per platform |
| `auth_methods` | One row per sign-in backend: local, LDAP, AD |
| `auth_group_map` | Directory group to role, plus a default access level |
| `auth_group_library_access` | That group's grants on specific libraries |
| `auth_log` | Every sign-in attempt, with reason and client IP |
| `users` | Accounts and the instance role (`admin` or `user`) — nothing else |
| `api_tokens` | Per-device tokens, stored as SHA-256 hashes |
| `tombstones` | Deletion log so offline clients can catch up, with the library recorded |
| `metadata_*`, `provider_scopes` | Lookup sources, their scoping and their cache |

`v_items` joins the lookup tables onto `items`, falls back to the entry's title
where one is set, and reads the cover filename from a denormalised
`cover_image_id`. It carries no correlated subqueries: they used to run once per
row and made the view impossible to index. `v_titles` does the same for titles
and adds a copy count.

Deletion rules are deliberate. Deleting a **library**, platform or category
still in use is refused by a foreign key. Deleting a **title** sets `title_id`
to NULL on its copies, which keep their own details rather than vanishing.
Deleting a category or company nulls the reference. Deleting an entry cascades to
its photos, events and links. Entries themselves are soft-deleted — `deleted_at`
is set, and `v_items` filters them out — so one mis-click does not lose months
of cataloguing.
