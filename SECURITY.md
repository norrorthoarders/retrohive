# Security

## Reporting a vulnerability

Please report security problems privately, by email, rather than opening a public issue:

**Tommy Frössman — <frossmant@gmail.com>**

Include what you found, what it lets somebody do, and enough detail to reproduce it. You will
get an acknowledgement within a few days, and an honest answer about whether and when it will
be fixed.

Please do not test against an instance you do not own.

## What is supported

Fixes go to the current release. RetroHive is self-hosted, so applying them is up to whoever
runs the instance: `git pull` followed by `php bin/migrate.php up`.

## What this software assumes

RetroHive is written for a private or small shared instance, and some of its safety comes from
where it is deployed rather than from the code:

- **Serve it over HTTPS.** Sessions and API tokens are sent in headers and cookies; over plain
  HTTP they are readable in transit.
- **`public/` is the document root.** Nothing above it — configuration, uploads, the database
  credentials — should be reachable by URL.
- **Behind a proxy, set the trusted proxies.** Without them the sign-in rate limit sees the
  proxy's address rather than the client's, and stops being a limit.
- **Metadata sources are fetched over the network.** Endpoints are administrator-configured for
  that reason; the fetcher refuses private addresses and non-HTTP schemes.

## What is already in place

- Passwords hashed with bcrypt; sign-in attempts rate limited per account and per address.
- CSRF tokens on every form; session identifiers regenerated at sign-in.
- Access checked in the controller for every read and write, per library rather than globally.
- Uploads validated by content rather than by filename, re-encoded, and served through the
  application.
- API tokens hashed at rest, shown once, and revocable.
