# RetroHive

A self-hosted catalogue for a retro computing collection. PHP 8.3, MariaDB, no build step, no
JavaScript framework. GPL v3.0.

Renamed from RetroVault, on GitLab and GitHub alike — see `CHANGELOG.md` for what that took and
the one manual step it left on the live server.

## Clients

- [retrohive-clients-web](https://github.com/norrorthoarders/retrohive-clients-web) — the
  browser client, a prototype rebuilt as an API consumer (see its own `MIGRATION.md`)
- [retrohive-clients-ios](https://github.com/norrorthoarders/retrohive-clients-ios) — the
  iPhone client
- [retrohive-clients-android](https://github.com/norrorthoarders/retrohive-clients-android) —
  the Android client

Every client speaks the same protocol: HTTPS, JSON, a bearer token in `Authorization`. Nothing in
this API is web-client-only.

## How a request reaches the database

One PHP application today, not yet split into a separate headless service — `public/index.php`
routes to either the JSON API or the HTML pages, and both call the same `src/` functions, which
call MariaDB directly. `retrohive-clients-web`'s own `MIGRATION.md` is the record of what a real
split would take.

```mermaid
flowchart LR
    subgraph Clients
        W[Web client]
        I[iOS client]
        A[Android client]
        WUI[This repo's own\nweb pages]
    end

    W -- HTTPS/JSON --> IDX
    I -- HTTPS/JSON --> IDX
    A -- HTTPS/JSON --> IDX
    WUI -- same process --> IDX

    subgraph "retrohive (this repo)"
        IDX["public/index.php\n(front controller)"]
        API["/api/v1/* routes\nJSON in, JSON out"]
        WEB["everything else\nHTML pages"]
        SRC["src/\nmodels, rules, acl —\nthe same functions\neither path calls"]
        IDX --> API --> SRC
        IDX --> WEB --> SRC
    end

    SRC -->|"one(), all(), q()"| DB[(MariaDB)]
```

## Authentication and trust

A password is sent exactly once, to sign in. Everything after that is a bearer token — the same
mechanism for every client, including this repository's own web pages, which hold a token in the
session rather than a raw password.

```mermaid
sequenceDiagram
    participant C as Client (any of them)
    participant S as retrohive
    participant DB as MariaDB

    C->>S: POST /api/v1/auth/login {username, password}
    S->>DB: verify_credentials()
    DB-->>S: user row, or nothing
    S->>S: create_api_token() — a random token, hashed before storage
    S-->>C: {"data": {"token": "...", "user": {...}}}

    Note over C: the plain token is shown once and never stored server-side

    C->>S: GET /api/v1/items  Authorization: Bearer <token>
    S->>DB: look up the hash, check it is not expired or revoked
    DB-->>S: the account it belongs to
    S->>DB: run the request as that account, through the same ACL every other path uses
    S-->>C: {"data": [...]}
```

Revoking a token (`DELETE /api/v1/tokens/{id}`, or signing out) invalidates it immediately —
nothing client-side needs to expire on its own for a revoked session to stop working.

## What the API provides

59 routes, grouped by what they're for. `docs/openapi.yaml` is the exact, current contract;
this is the shape of it.

```mermaid
flowchart TD
    ROOT["/api/v1"]
    ROOT --> AUTH["Auth & identity\nlogin · logout · me · tokens"]
    ROOT --> ITEMS["Items — Cells\ncreate · read · update · delete\nbulk · random · barcode lookup"]
    ROOT --> LINKS["What is fitted to what\nlinks · candidates"]
    ROOT --> IMAGES["Photographs\nupload · import from a metadata source"]
    ROOT --> LIB["Libraries — Hives\nlist · create · membership"]
    ROOT --> STRUCT["Structure\ncompanies · categories · platforms · models"]
    ROOT --> META["Metadata lookup\nsearch nine external sources"]
    ROOT --> NOTIF["Notifications\nlist · mark read · preferences"]
    ROOT --> ADMIN["Admin\nusers · libraries · settings · logs · maintenance"]
    ROOT --> SYNC["Sync\nincremental, including deletions"]
```

## The data

The core of it — every table that actually matters to a client is reachable from `items`.

```mermaid
erDiagram
    LIBRARIES ||--o{ ITEMS : contains
    LIBRARIES ||--o{ LIBRARY_MEMBERS : "grants access to"
    USERS ||--o{ LIBRARY_MEMBERS : holds

    CATEGORIES ||--o{ CATEGORIES : "nests under"
    CATEGORIES ||--o{ ITEMS : "an item files under exactly one"
    PLATFORMS ||--o{ ITEMS : "an item is filed under exactly one, independently of category"
    PLATFORMS ||--o{ CATEGORIES : "a category may be platform-scoped"

    COMPANIES ||--o{ ITEMS : "develops / publishes"
    HARDWARE_MODELS ||--o{ ITEMS : "an item may be an instance of one"
    HARDWARE_MODELS ||--o{ COMPANIES : "made by"
    HARDWARE_MODELS ||--o{ CATEGORIES : "filed under"

    ITEMS ||--o{ ITEM_HARDWARE : "one side table, hardware entries only"
    ITEMS ||--o{ ITEM_IMAGES : has
    ITEMS ||--o{ ITEM_TAGS : has
    TAGS ||--o{ ITEM_TAGS : has
    ITEMS ||--o{ ITEM_LINKS : "installed in / fits"
    LOCATIONS ||--o{ ITEMS : "kept at"
    LOCATIONS ||--o{ LOCATIONS : "nests under"
    USERS ||--o{ API_TOKENS : holds
```

`hardware_models` is what tonight's naming work calls **Foundation** — the canonical "Amiga
2000" a specific owned `item` is an instance of. `categories` and `platforms` are two
independent axes, not one nested under the other: a Category is *what kind of thing* (Games,
Peripherals), a Platform is *which machine family* (Amiga, C64) — an item has exactly one of
each, and neither implies the other.

## What a client actually sees

The database above, shaped into JSON. `item_to_api()` is the one function that does this
translation — every client reads the same shape, whether it asked for one item or a page of a
hundred.

```mermaid
flowchart LR
    subgraph "Stored across several tables"
        T1[items row]
        T2[item_hardware row]
        T3[item_images rows]
        T4[hardware_models row]
        T5[companies rows]
    end
    T1 --> F["item_to_api()"]
    T2 --> F
    T3 --> F
    T4 --> F
    T5 --> F
    F --> J["one JSON object:\nid, title, category, platform,\ndeveloper, publisher, model,\nhardware {...}, images [...],\nlocation, tags [...]"]
```

## Structure data

`structure/*.json` — companies, categories, platforms, models. Vocabulary an entry is filed
against, not an example of one; see `db/migrations/README.md` for why there are no migrations
yet, and `structure/` itself for what ships. Synchronised from
`github.com/norrorthoarders/retrohive/main/structure`, or from the copies in this checkout with
no network at all.

## Requirements

PHP 8.1+, MariaDB 10.6+ or MySQL 8+, and a webserver that can rewrite everything to
`public/index.php`. No build step, no JavaScript framework, no Node.

## Installing

```
cp src/config.local.php.example src/config.local.php   # edit the database section
mysql -e "CREATE DATABASE retrohive"
php bin/install.php --interactive
```

`docs/INSTALL.md` has the rest. `docs/openapi.yaml` is the API contract clients are written
against.
