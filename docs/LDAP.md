# Directory sign-in

RetroHive can authenticate against LDAP or Active Directory instead of, or
alongside, its own password database. The structure follows phpIPAM's: one row
per configured backend in `auth_methods`, its settings held as JSON, and a
foreign key on each user saying which backend owns it.

Nothing here is active until you add and enable a method. A fresh install has a
single protected `local` row and behaves exactly as it did before.

---

## What the database already supports

Even if you never enable LDAP, the schema is ready for it:

| Table | Purpose |
|---|---|
| `auth_methods` | One row per backend: type, name, JSON params, enabled flag |
| `users.auth_method_id` | Which backend authenticates this account |
| `users.password_hash` | Nullable — directory accounts have no local password |
| `users.email`, `external_dn`, `external_uid`, `external_groups` | Synced from the directory |
| `users.auto_created`, `last_sync_at` | Bookkeeping for accounts the directory created |
| `auth_group_map` | Directory group → role and default library access |
| `auth_group_library_access` | That group's grants on specific libraries |
| `auth_log` | Every sign-in attempt, with reason and client IP |

The `local` method (id 1) is protected: it cannot be deleted or disabled. That
is deliberate — if the directory goes down or a filter is wrong, local accounts
are the way back in.

---

## Prerequisites

```bash
apt install php-ldap && systemctl restart apache2      # Debian/Ubuntu
zypper install php8-ldap && systemctl restart apache2  # openSUSE
```

Confirm with `php -m | grep ldap`. The Authentication screen tells you plainly
if the extension is missing, and lets you configure a method anyway.

You need a service account in the directory with read access. It does not need
to be privileged — it only searches for the user entry and reads group
membership. The user's own password is verified by binding as them, never by
reading a password attribute.

---

## Setting it up

**Manage → Authentication → Add a directory.**

### Active Directory

| Field | Value |
|---|---|
| Type | Active Directory |
| Host | `dc01.example.com dc02.example.com` (space separated for failover) |
| Port / Encryption | 389 with STARTTLS, or 636 with LDAPS |
| Base DN | `dc=example,dc=com` |
| Users base DN | *blank* — the base DN is usually enough |
| Service account DN | `cn=retrohive,ou=service,dc=example,dc=com` |
| Login attribute | `sAMAccountName` |
| User filter | `(&(objectClass=user)(sAMAccountName=%s))` |
| Member-of attribute | `memberOf` |
| Follow referrals | off |

AD publishes group membership on the user object via `memberOf`, so the reverse
group search is usually unnecessary. Referrals should stay off; AD hands out
referrals that a non-domain-joined server generally cannot follow, and the
search hangs until it times out.

### OpenLDAP

| Field | Value |
|---|---|
| Type | LDAP |
| Users base DN | `ou=people,dc=example,dc=com` — or blank for the whole tree |
| Login attribute | `uid` |
| User filter | `(&(objectClass=inetOrgPerson)(uid=%s))` |
| Group base DN | `ou=groups,dc=example,dc=com` |
| Group filter | `(&(objectClass=groupOfNames)(member=%s))` |
| Member-of attribute | leave blank |

OpenLDAP normally has no `memberOf` overlay, so groups are found by searching
for entries that list the user as a `member`. Leaving the member-of attribute
blank tells RetroHive to do that reverse search.

### Test before enabling

**Save and test connection** binds as the service account and reports what it
can see. Give it a username to probe and it also reports that user's DN and the
groups that resolve for them — which is the fastest way to find out that your
group filter is wrong before anyone tries to sign in.

---

## Group mappings

This is the part worth the trouble. A mapping turns a directory group into a
role *and* a set of library grants, so access is managed in AD rather than here.

```
Group                Role    Default access   Per-library
retro-admins         admin   none             Amiga shelf: owner
retro-curators       user    none             Amiga shelf: curator, C64 shelf: curator
retro-readers        user    viewer           —
retro-contributors   user    none             Club shelf: contributor
```

Two things about that table are worth reading twice.

**The role column has two values.** `admin` means "may configure this instance"
— accounts, authentication, metadata sources, shared taxonomy — and nothing
else. It confers no catalogue access at all, which is why `retro-admins` still
needs a library grant. (Before 1.3 the roles were `viewer` / `editor` / `admin`
and the middle one governed writing. Mappings written against those values are
rejected now and fall back to `user`.)

**Default access `none` is usually what you want.** It makes the per-library
column an allow-list. Setting it to `viewer` grants read on *every* library,
including ones created later, which is occasionally right — `retro-readers`
above — and more often a surprise.

The per-library grants name **libraries**, not platforms. A library is the shelf
that has members; a platform is the machine an entry runs on and confers
nothing. Levels are `viewer`, `contributor` and `curator`; `owner` is
deliberately not offered, because a directory group should not be able to confer
the right to hand out further grants.

The first matching mapping wins, lowest priority number first. Someone in both
`retro-admins` and `retro-readers` gets admin if its priority is lower.

Mappings accept either a bare CN (`retro-admins`) or a full DN
(`cn=retro-admins,ou=groups,dc=example,dc=com`); matching is case-insensitive
and tries both forms.

With **Re-apply group mappings on every sign-in** enabled, moving someone
between AD groups changes what they can reach the next time they sign in, with
no action here. Turn it off if you would rather set roles manually after the
account is created.

**Role when no group matches** and **Default access when no group matches**
decide what an authenticated user gets if none of the mappings apply. The
defaults are `user` and *no libraries*, which is the safe combination: someone
who authenticates but is in none of your groups gets an account that can see
nothing, rather than the whole collection.

Grants from a matched group are applied on account creation, and re-applied on
every sign-in when **Re-apply group mappings** is on. With it off, a grant an
administrator added by hand survives the next login rather than being wiped.

---

## How a sign-in resolves

1. If a local `users` row exists, its `auth_method_id` decides the backend.
   Local accounts check the password hash; directory accounts go to the
   directory.
2. If no row exists, each enabled directory is tried in turn. On success the
   account is created, if the method allows autocreation.
3. On success, group membership is read and mapped to a role and library grants.
4. The attempt is written to `auth_log` either way.

A username already owned by a local account is never taken over by a directory
user. If `tommy` exists as a local account, a directory `tommy` is refused
rather than silently inheriting the local account's access.

Empty passwords are rejected before any bind is attempted. Many LDAP servers
treat a bind with an empty password as an *anonymous* bind and return success,
which would otherwise let anyone in as any username.

---

## Troubleshooting

Every attempt lands in `auth_log`, shown at the bottom of the Authentication
screen with the reason and the client IP.

| Reason | What it means |
|---|---|
| `unknown user` | No local row, and no enabled directory recognised the name |
| `The directory rejected that password` | Found in the directory, bind failed |
| `No directory entry for that username` | The user filter or users base DN is wrong |
| `That username matches more than one directory entry` | The filter is too loose |
| `The service account could not bind` | Wrong service account DN or password |
| `STARTTLS was refused` | The server does not offer STARTTLS on that port, or the certificate is not trusted |
| `Authenticated, but not a member of …` | The required group gate rejected them |
| `sync refused` | A local account already owns that username |
| `auth method disabled` | The account is bound to a method that is switched off |

**Certificate problems.** Turning off certificate verification is offered but
discouraged: it sets a process-wide LDAP option, so it affects every connection
the PHP process makes. Better to put the CA certificate somewhere OpenLDAP will
find it:

```bash
cp noh-ca.crt /usr/local/share/ca-certificates/
update-ca-certificates
# or explicitly, in /etc/ldap/ldap.conf:
#   TLS_CACERT /etc/ssl/certs/noh-ca.crt
```

**Locked out after enabling a directory.** Local accounts still work, and the
local method cannot be disabled. Sign in with one, or from the command line:

```bash
php bin/create-user.php rescue --role admin
```

---

## What is not implemented

The plumbing stops at authentication and group mapping. There is no scheduled
directory sync, no nested-group expansion beyond what the server itself
publishes, and no SAML or RADIUS backend — though the `auth_methods.type` enum
and the JSON params column are the right shape to add them without a schema
change.
