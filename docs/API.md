# RetroHive API

A JSON REST API for native clients. Everything the web interface can do is
available here, so a macOS, iOS or Android app can be a full peer rather than a
read-only token.

Base URL: `https://your-host/api/v1`

---

## Contents

- [Authentication](#authentication)
- [Response shape](#response-shape)
- [Errors](#errors)
- [Endpoints](#endpoints)
- [Settings](#settings)
- [Offline sync](#offline-sync)
- [Caching](#caching)
- [Client examples](#client-examples)
- [Notes for app developers](#notes-for-app-developers)

---

## Authentication

Every endpoint except `GET /meta` needs a bearer token.

```
Authorization: Bearer rvt_1a2b3c...
```

### Getting a token

Two ways. For an app with a sign-in screen, exchange credentials:

```http
POST /api/v1/auth/login
Content-Type: application/json

{
  "username": "tommy",
  "password": "...",
  "device_name": "Tommy's iPhone",
  "platform": "ios",
  "scope": "write"
}
```

```json
{
  "data": {
    "token": "rvt_9f8e7d6c5b4a...",
    "token_id": 3,
    "token_type": "Bearer",
    "scope": "write",
    "expires_at": null,
    "user": { "id": 1, "username": "tommy", "role": "admin", "can_edit": true, "is_admin": true }
  }
}
```

Store the token in the Keychain (iOS/macOS) or EncryptedSharedPreferences
(Android) and discard the password. Tokens do not expire unless
`api.token_days` is set, or you pass an expiry when creating one by hand.

Alternatively, generate one in the web interface under **Manage → App access**.
That is the better route for a personal device: you can name it, scope it, and
revoke it later without changing your password.

### Scopes, roles and libraries

Three independent things decide whether a call succeeds. All three must allow
it.

1. **Token scope.** A token is issued `read` or `write`. A read token can never
   write, whatever the account behind it may do.
2. **Account role.** `admin` may configure the instance — accounts,
   authentication, metadata sources, shared taxonomy. `user` may not. That is
   the whole of what the role governs; it does **not** decide what the account
   can read or change in the catalogue.
3. **Library membership.** `viewer`, `contributor`, `curator` or `owner`, one
   per library. This is what decides catalogue access, and it is decided per
   library rather than globally.

> **Changed.** Roles used to be `viewer` / `editor` / `admin` and access
> used to be read from the platform. Both are gone. If your client branches on
> `user.role` to decide whether to show an edit button, switch to the
> `can_edit` flag on each item and the `access` field on each library — a person
> can be a curator of one shelf and a viewer of another, so no global flag can
> answer the question.

`GET /libraries` carries an `access` field per library. Every item carries
`can_edit`, computed for that entry — which for a contributor means "you added
this one", not "you can write to this library".

```json
{
  "user": { "id": 4, "role": "user", "can_edit": true,
            "libraries": [
              { "id": 1, "name": "My Amiga shelf", "slug": "amiga-shelf",
                "color": "#cba6f7", "access": "owner" },
              { "id": 3, "name": "Club shelf", "slug": "club",
                "color": "#94e2d5", "access": "contributor" }
            ],
            "platforms": [
              { "id": 1, "name": "Amiga", "slug": "amiga" }
            ] }
}
```

Note that `libraries` and `platforms` are separate lists and mean different
things. A library is a shelf with members; a platform is a machine. Only the
first carries access.

## Response shape

Success:

```json
{ "data": { ... } }
```

Collections add a `meta` block:

```json
{
  "data": [ ... ],
  "meta": { "page": 1, "per_page": 24, "total": 312, "pages": 13, "has_more": true }
}
```

`204 No Content` is returned for deletes and logout, with no body.

Timestamps are ISO 8601 in UTC with a `Z` suffix: `2026-07-25T09:30:00Z`.
Dates without a time (`release_date`, `acquired_on`) are plain `YYYY-MM-DD`.
Booleans are real JSON booleans. Money is a number, with the currency in a
separate field.

---

## Errors

```json
{
  "error": {
    "code": "validation_failed",
    "message": "Some fields need attention.",
    "details": { "rating": "Between 1 and 10." }
  }
}
```

| Status | Code | Meaning |
|---|---|---|
| 400 | `invalid_json` | Body was not parseable JSON |
| 401 | `unauthenticated` | Missing, malformed, expired or revoked token |
| 401 | `invalid_credentials` | Wrong username or password at login |
| 403 | `forbidden` | Authenticated but not allowed (role or token scope) |
| 404 | `not_found` | No such resource or endpoint |
| 405 | `method_not_allowed` | Path exists, method does not; see the `Allow` header |
| 422 | `validation_failed` | Field-level problems, listed in `details` |
| 422 | `upload_failed` | Photo rejected; reason in `message` |

Treat any 401 as "sign in again": clear the stored token and show the login
screen. Do not retry.

---

## Endpoints

### Meta

| | |
|---|---|
| `GET /meta` | Server name, API version, upload limit, and the valid values for `kind`, `condition` and `completeness`. **No auth required.** |

Call this at startup and build your pickers from it rather than hard-coding
enum values, so adding a condition grade on the server does not require an app
release.

### Items

| | |
|---|---|
| `GET /items` | Paginated, filtered list |
| `POST /items` | Create |
| `GET /items/{id}` | One entry, with images and tags |
| `PATCH /items/{id}` | Partial update — only the keys you send change |
| `PUT /items/{id}` | Same as PATCH |
| `DELETE /items/{id}` | Delete, including its photos |

Query parameters for `GET /items`:

| Parameter | Example | Notes |
|---|---|---|
| `q` | `turrican` | Title, studio, catalog number, notes |
| `library` | `amiga-shelf` | Library slug — the shelf it is on |
| `platform` | `amiga` | Platform slug — the machine it runs on |
| `category` | `games` | Software type slug |
| `developer` | `team17` | Company slug |
| `year` | `1993` | Exact release year |
| `decade` | `1990` | 1990–1999 |
| `min_rating` | `8` | 8 and above |
| `condition` | `mint` | Exact grade |
| `photos` | `none` \| `some` | Missing or having photos |
| `status` | `owned` | `owned`, `wishlist`, `ordered`, `lent`, `sold`, or `all`. Omit for "on the shelf": owned, lent and on order |
| `tag` | `big-box,sealed` | Tag slugs; all must match |
| `barcode` | `5013442110416` | Exact match |
| `list` | `wishlist` | Deprecated alias for `status=wishlist` |
| `sort` | `rating` | `title`, `title_desc`, `year`, `year_desc`, `rating`, `rating_asc`, `value`, `price`, `added`, `updated`, `platform` |
| `page`, `per_page` | `2`, `50` | `per_page` caps at 200 |
| `include` | `images` | Embed full image arrays in list results |

An item looks like this:

```json
{
  "id": 42,
  "title": "Turrican II",
  "subtitle": null,
  "sort_title": null,
  "library":   { "id": 1, "name": "Commodore Amiga", "slug": "amiga", "color": "#f38ba8" },
  "category":  { "id": 1, "name": "Games", "slug": "games" },
  "developer": { "id": 12, "name": "Factor 5", "slug": "factor-5", "website": null },
  "publisher": { "id": 13, "name": "Rainbow Arts", "slug": "rainbow-arts" },
  "release_year": 1991,
  "release_date": null,
  "rating": 10,
  "condition": "near_mint",
  "condition_label": "Near mint",
  "components": {
    "box":    { "value": "very_good", "label": "Very good" },
    "manual": { "value": "missing",   "label": "Missing" },
    "media":  { "value": "mint",      "label": "Mint" }
  },
  "completeness": "cib",
  "completeness_label": "Complete in box",
  "media_type": "3.5\" floppy",
  "media_count": 2,
  "catalog_number": null,
  "barcode": null,
  "language": "English",
  "region": "PAL",
  "acquired_on": "2019-04-12",
  "acquired_price": 250.0,
  "currency": "SEK",
  "storage_location": "Shelf B, box 3",
  "is_original": true,
  "status": "owned",
  "status_label": "Owned",
  "is_wishlist": false,
  "copies": 1,
  "sold_on": null,
  "sold_price": null,
  "current_value": 450,
  "valued_on": "2026-07-01",
  "external_url": "https://www.lemonamiga.com/games/details.php?id=1113",
  "description": "Superfrog is a platform game developed by Team17.",
  "notes": "The Chris Huelsbeck soundtrack alone.",
  "image_count": 3,
  "cover": {
    "thumb":   "https://host/uploads/thumb_42_ab12cd.jpg",
    "display": "https://host/uploads/disp_42_ab12cd.jpg"
  },
  "media": [
    { "medium": "3.5\" floppy", "quantity": 2 }
  ],
  "links": [
    { "label": "Service manual", "url": "https://amiga.resource.cx/x.pdf", "source": "Wikipedia" }
  ],

  "created_at": "2026-07-25T09:30:00Z",
  "updated_at": "2026-07-25T11:02:14Z",
  "url": "https://host/items/42",
  "can_edit": true,
  "images": [ ... ],
  "tags": ["Big box", "Nordic release"]
}
```

`media` and `links` come back with `images` and `tags` — that is, on a single entry
and on a list only when images were asked for, because they are per-row queries.

`media_type` and `media_count` are still there and still hold the first medium, so a
client written before these lists existed keeps working.

Writing them follows the usual PATCH rule, and the middle case is the one worth
knowing:

| what you send            | what happens          |
| ------------------------ | --------------------- |
| the key is absent        | the list is untouched |
| `"media": []`            | the list is emptied   |
| `"media": [ ... ]`       | the list is replaced  |

A link whose `url` is not http or https is dropped, exactly as on the form. Malformed
input comes back as a 422 rather than being quietly ignored.

`description` is the release's own blurb — what a metadata lookup writes — and `notes`
stays whatever you wrote about your copy. A picture also carries `provenance`
(`official` or `personal`), which is what separates publisher artwork from your own
photographs.

Writes accept a flatter shape. Send ids, or send names and let the server
resolve them:

```json
{
  "title": "Turrican II",
  "library_id": 1,
  "platform_id": 1,
  "category_id": 1,
  "developer_name": "Factor 5",
  "publisher_name": "Rainbow Arts",
  "release_year": 1991,
  "rating": 10,
  "condition": "near_mint",
  "completeness": "cib",
  "tags": ["big box", "classic"]
}
```

`title`, `library_id`, `platform_id` and `category_id` are required on create.
`library_id` says which shelf the entry goes on and is what access is checked
against; `platform_id` says which machine it runs on. Everything else
is optional. `developer_name` and `publisher_name` create the company if it does
not exist; tags match case-insensitively on an existing tag before creating a
new one.

Component grades can be sent nested or flat, whichever suits your model:

```json
{ "components": { "box": "very_good", "manual": "missing", "media": "mint" } }
{ "condition_box": "very_good", "condition_manual": "missing" }
```

Component grades accept the usual conditions plus `missing`. `status` is one of
`owned`, `wishlist`, `ordered`, `lent`, `sold`. The old `is_wishlist` boolean is
still accepted on write and still returned, derived from `status`.

Money fields (`acquired_price`, `current_value`, `sold_price`) are JSON numbers.
PHP emits whole amounts without a decimal part, so `450` and `450.5` can both
appear — decode them as `Double`/`Decimal`, never `Int`.

PATCH only touches keys you actually send, so `{"rating": 9}` leaves every other
field alone. Send `null` to clear a field.

### Photos

| | |
|---|---|
| `GET /items/{id}/images` | List |
| `POST /items/{id}/images` | Upload one or more |
| `PATCH /images/{id}` | Change `kind`, `caption`, `sort_order`, `is_primary` |
| `DELETE /images/{id}` | Delete the row and the files |

Two upload styles. Multipart, which is what a file picker gives you:

```
POST /api/v1/items/42/images
Content-Type: multipart/form-data

file: <binary>
kind: box_front
```

Or JSON with base64, which is easier when the image is already in memory —
straight from `UIImagePickerController` or a camera capture:

```json
{
  "file_base64": "iVBORw0KGgoAAAANS...",
  "kind": "box_front",
  "caption": "Front of the big box",
  "filename": "IMG_4821.jpg"
}
```

A `data:image/jpeg;base64,` prefix is tolerated. JPEG, PNG, WebP and GIF are
accepted, validated by inspecting the file rather than trusting its name. The
first photo on an item becomes the cover automatically.

Each image comes back with three URLs:

```json
{
  "id": 7,
  "item_id": 42,
  "kind": "box_front",
  "kind_label": "Box front",
  "caption": null,
  "is_primary": true,
  "sort_order": 10,
  "width": 2400, "height": 1800, "filesize": 884120,
  "urls": {
    "thumb":    "https://host/uploads/thumb_42_ab12cd.jpg",
    "display":  "https://host/uploads/disp_42_ab12cd.jpg",
    "original": "https://host/uploads/42_ab12cd.jpg"
  },
  "created_at": "2026-07-25T09:31:00Z"
}
```

Use `thumb` (480 px) in lists, `display` (1600 px) in a detail view, and
`original` only if the user asks to see it full size. All three are absolute.

### Bulk, barcode and random

| | |
|---|---|
| `POST /items/bulk` | Create up to 100 entries in one request |
| `GET /barcode/{code}` | Find entries by barcode |
| `GET /items/random` | One entry at random, honouring the same filters as `/items` |

Bulk creation is for a barcode-scanning session, where one round trip per title
over a mobile connection is painful. Partial success is normal, so each entry
reports its own outcome and nothing is rolled back:

```json
{
  "data": [
    { "index": 0, "ok": true,  "id": 51, "title": "Bulk One" },
    { "index": 1, "ok": false, "error": "validation_failed",
      "details": { "title": "This field is required." } }
  ],
  "meta": { "created": 1, "failed": 1 }
}
```

Barcode lookup returns an array rather than a single entry, because duplicates
and regional variants legitimately share a barcode:

```json
{ "data": { "barcode": "5013442110416", "found": true, "items": [ ... ] } }
```

A miss is `"found": false` with a 200, not a 404 — "no match" is a normal answer
for a scanner, not an error. Results respect library access, so a scan cannot be
used to probe for entries in a library you cannot see.

### Taxonomy

| | |
|---|---|
| `GET /libraries` | Libraries you can read, with access and item counts |
| `GET /platforms` | Platforms, with counts of what you can see on each |
| `GET /titles` | Canonical titles; `?q=` and `?platform_id=` to narrow |
| `POST /titles` | Create a title |
| `GET /categories` | The filing tree. Each row carries `parent_id`, `domain`, `role`, `depth`, `platform_id` and a readable `path`, so a client can tell "Games" from "Amiga › Software › Games › Racing". Filters: `?domain=`, `?parent_id=`, `?platform_id=`, `?role=` |
| `GET /companies` | Developers and publishers; `?q=` to search |
| `GET /companies/{id}` | One company plus everything they developed and published |
| `GET /tags` | Tags |
| `POST /platforms` `/categories` `/companies` `/tags` | Create one |

Taxonomy is small and changes rarely. Fetch it once at launch, cache it, and
refresh on sync.

### Stats

`GET /stats` returns the dashboard figures: totals, average rating, spend, year
range, breakdowns by library, category and decade, and counts of entries missing
photos, a year or a developer. Useful for a home screen or a widget.

### Settings

Three screens that used to be web only.

`GET /profile` and `PATCH /profile` are your own details. The body names only what
changes: `display_name`, `email`, or `password` — the last of which also wants
`current_password`, because holding a valid token and being the account holder are
not the same thing when a phone is face up on a table. The username is not
editable here; it is what other people's memberships point at by sight.

`GET /profile/notifications` returns every kind of notice with its label, its
description, and whether this account wants it in the app and by mail. `explicit`
is false when the account has never said and is taking the kind's default.
`PATCH` takes `{"prefs": {"library.invited": {"in_app": true}}}` — naming one kind
changes that kind, and leaves the rest alone.

`GET /admin/settings` is the instance settings, **described rather than dumped**:
sections, each holding fields with a `name`, a `kind` (`text`, `url`, `int`,
`bool`, `select`, `secret`), a label, help, and the current `value`. A `select`
carries its `options`; an `int` carries `min` and `max`. A `secret` never sends
its value — only `is_set`, so a form can tell "change it" from "set it".

That shape exists so a client can draw the form without knowing anything about
RetroHive's settings in advance, and so a setting added to `settings_schema()`
appears in an app nobody rebuilt.

`PATCH /admin/settings` takes `{"settings": {"smtp_port": 587}}`. Everything is
checked before anything is written, so a request setting a good host and a bad
port changes neither, and the refusal names the field:

```json
{"error":{"code":"validation_failed","message":"Some settings were refused.",
          "details":{"smtp_port":"smtp_port cannot be above 65535."}}}
```

Both `/admin/settings` calls need an administrator and a write scope.

---

## Offline sync

`GET /sync` is built for a client that keeps a local database.

First call, with no parameters, returns everything: all items with their images
and tags, plus the full taxonomy. Store the `server_time` from the response.

```
GET /api/v1/sync
```

Later calls pass that value back:

```
GET /api/v1/sync?since=2026-07-25T09:30:00Z
```

and receive only what changed, plus what was deleted:

```json
{
  "data": {
    "server_time": "2026-07-25T14:02:00Z",
    "since": "2026-07-25T09:30:00Z",
    "full_sync": false,
    "items": [ ... ],
    "deleted": { "items": [17, 23], "item_images": [88] },
    "libraries": [ ... ],
    "platforms": [ ... ],
    "titles":    [ ... ],
    "categories": [ ... ],
    "companies": [ ... ]
  },
  "meta": { "items_changed": 4, "items_deleted": 2 }
}
```

Deletions are reported separately because a client cannot infer them from a list
of changed rows. They come from a `tombstones` table that both the API and the
web interface write to, so a deletion made in a browser reaches the app.

`server_time` is captured before the query runs, so anything written mid-request
is picked up by the next sync rather than being missed. Always store the value
the server sends rather than using the device clock — phone clocks drift and
time zones differ.

There is no conflict resolution. Writes are last-one-wins. For a personal
collection with one or two devices that is the right amount of machinery; if you
later need more, add a version column and compare it on PATCH.

---

## Caching

`GET /items` and `GET /items/{id}` return an `ETag`. Send it back as
`If-None-Match` and an unchanged resource costs you a `304` and no body:

```
GET /api/v1/items?platform=amiga
If-None-Match: "8f14e45fceea167a5a36dedd4bea2543"
```

Image URLs contain a random filename component and are never rewritten, so they
can be cached indefinitely. When a photo changes it gets a new URL.

---

## Client examples

### Swift (iOS / macOS)

```swift
struct Envelope<T: Decodable>: Decodable {
    let data: T
    let meta: Meta?
    struct Meta: Decodable { let total: Int?; let hasMore: Bool? }
}

struct Item: Decodable, Identifiable {
    let id: Int
    let title: String
    let releaseYear: Int?
    let rating: Int?
    let library: Library
    let cover: Cover

    struct Library: Decodable { let id: Int; let name: String; let color: String }
    struct Cover: Decodable { let thumb: URL?; let display: URL? }
}

actor RetroHive {
    private let baseURL: URL
    private let token: String

    init(host: URL, token: String) {
        self.baseURL = host.appendingPathComponent("api/v1")
        self.token = token
    }

    private func request(_ path: String, method: String = "GET", body: Data? = nil) -> URLRequest {
        var r = URLRequest(url: baseURL.appendingPathComponent(path))
        r.httpMethod = method
        r.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")
        r.setValue("application/json", forHTTPHeaderField: "Content-Type")
        r.httpBody = body
        return r
    }

    func items(page: Int = 1, search: String? = nil) async throws -> [Item] {
        var comps = URLComponents(url: baseURL.appendingPathComponent("items"),
                                  resolvingAgainstBaseURL: false)!
        comps.queryItems = [URLQueryItem(name: "page", value: String(page))]
        if let search { comps.queryItems?.append(URLQueryItem(name: "q", value: search)) }

        var r = URLRequest(url: comps.url!)
        r.setValue("Bearer \(token)", forHTTPHeaderField: "Authorization")

        let (data, response) = try await URLSession.shared.data(for: r)
        guard (response as? HTTPURLResponse)?.statusCode == 200 else { throw VaultError.http }

        let decoder = JSONDecoder()
        decoder.keyDecodingStrategy = .convertFromSnakeCase
        decoder.dateDecodingStrategy = .iso8601          // the Z format parses natively
        return try decoder.decode(Envelope<[Item]>.self, from: data).data
    }

    func rate(itemID: Int, _ rating: Int) async throws {
        let body = try JSONSerialization.data(withJSONObject: ["rating": rating])
        let (_, response) = try await URLSession.shared.data(
            for: request("items/\(itemID)", method: "PATCH", body: body))
        guard (response as? HTTPURLResponse)?.statusCode == 200 else { throw VaultError.http }
    }

    func upload(image: Data, to itemID: Int, kind: String = "box_front") async throws {
        let payload: [String: Any] = ["file_base64": image.base64EncodedString(), "kind": kind]
        let body = try JSONSerialization.data(withJSONObject: payload)
        _ = try await URLSession.shared.data(
            for: request("items/\(itemID)/images", method: "POST", body: body))
    }
}

enum VaultError: Error { case http }
```

Sign-in, storing the token in the Keychain:

```swift
func signIn(host: URL, username: String, password: String) async throws -> String {
    var r = URLRequest(url: host.appendingPathComponent("api/v1/auth/login"))
    r.httpMethod = "POST"
    r.setValue("application/json", forHTTPHeaderField: "Content-Type")
    r.httpBody = try JSONSerialization.data(withJSONObject: [
        "username": username,
        "password": password,
        "device_name": UIDevice.current.name,
        "platform": "ios"
    ])
    let (data, _) = try await URLSession.shared.data(for: r)
    struct Login: Decodable { struct D: Decodable { let token: String }; let data: D }
    return try JSONDecoder().decode(Login.self, from: data).data.token
}
```

### Kotlin (Android)

```kotlin
data class Envelope<T>(val data: T, val meta: Meta?)
data class Meta(val total: Int?, val has_more: Boolean?)

interface RetroHiveApi {
    @GET("items")
    suspend fun items(
        @Query("page") page: Int = 1,
        @Query("q") search: String? = null,
        @Query("platform") library: String? = null
    ): Envelope<List<Item>>

    @PATCH("items/{id}")
    suspend fun update(@Path("id") id: Int, @Body fields: Map<String, Any?>): Envelope<Item>

    @Multipart
    @POST("items/{id}/images")
    suspend fun upload(
        @Path("id") id: Int,
        @Part file: MultipartBody.Part,
        @Part("kind") kind: RequestBody
    ): Envelope<List<Image>>

    @GET("sync")
    suspend fun sync(@Query("since") since: String?): Envelope<SyncPayload>
}

val client = OkHttpClient.Builder()
    .addInterceptor { chain ->
        chain.proceed(
            chain.request().newBuilder()
                .addHeader("Authorization", "Bearer $token")
                .build()
        )
    }
    .build()

val api = Retrofit.Builder()
    .baseUrl("https://retro.example.se/api/v1/")
    .client(client)
    .addConverterFactory(MoshiConverterFactory.create())
    .build()
    .create(RetroHiveApi::class.java)
```

### curl

```bash
HOST=https://retro.example.se
TOKEN=rvt_...

curl -s "$HOST/api/v1/items?platform=amiga&min_rating=8&sort=rating" \
     -H "Authorization: Bearer $TOKEN" | jq '.data[].title'

curl -s -X POST "$HOST/api/v1/items" \
     -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
     -d '{"title":"Shadow of the Beast","library_id":1,"platform_id":1,"category_id":1,"developer_name":"Reflections","release_year":1989,"rating":8}'

curl -s -X POST "$HOST/api/v1/items/42/images" \
     -H "Authorization: Bearer $TOKEN" \
     -F "file=@box-front.jpg" -F "kind=box_front"
```

---

## Notes for app developers

**Check `can_edit` before showing edit controls.** A user may have write access
to one library and read-only access to another, so a global "am I an editor"
flag is not enough — and as of 1.3 there is no such role to check anyway. Every
item carries `can_edit`, and `/libraries` carries
`access` per library.

**Filter the library picker.** When adding an entry, only offer libraries where
`access` is `write` — otherwise the create call fails with 403 after the user
has filled in the whole form.

**Build pickers from `/meta`.** Condition grades, completeness values and photo
kinds all come back with labels. Hard-coding them means an app update every time
the server gains a value.

**Set `base_url` if you use a reverse proxy.** The API works out its own
absolute URLs from the request. Behind a proxy that rewrites `Host` or
terminates TLS, that detection can produce URLs a phone cannot reach — usually
`http://` instead of `https://`, which App Transport Security then blocks. Set
`base_url` in `src/config.local.php` and the guessing stops.

**Uploads are the slow path.** A modern phone photo is 3–5 MB and the server
generates two resized copies on receipt. Upload in the background, show optimistic
UI, and do not block the interface on the response.

**Use `include=images` sparingly.** It saves a round trip on a detail screen but
makes a 50-item list response several times larger. For lists, the `cover` object
is usually all you need.

**Method override.** If your HTTP stack cannot send PATCH or DELETE, POST with
`X-HTTP-Method-Override: PATCH` works everywhere in the API.

**A note on TLS.** Tokens are bearer credentials: anyone holding one has your
access. Do not run this over plain HTTP outside a trusted network. If you expose
it to the internet, put it behind HTTPS and set `base_url` to the `https://`
address.


---

## Titles

A **title** is the game itself — name, developer, publisher, year,
and where it is filed — recorded once. An **item** is your copy of it, with its own condition,
completeness and price. Two copies of one game are two items pointing at one
title, and they are not duplicates: they differ exactly where copies differ.

This mirrors `hardware_models`, which has always done the same job for hardware.

```json
{
  "id": 12,
  "name": "Speedball 2",
  "subtitle": "Brutal Deluxe",
  "slug": "speedball-2-amiga",
  "work_key": "speedball-2",
  "platform": { "id": 1, "name": "Amiga", "slug": "amiga" },
  "developer": "The Bitmap Brothers",
  "publisher": "Image Works",
  "release_year": 1990,
  "copy_count": 2
}
```

`work_key` is shared across platforms, so the Amiga and C64 releases of one game
can be found together while remaining separate records — they are genuinely
different artefacts, with different media, packaging and sometimes developers.

**Linking an item to a title.** Send `title_id` on create or update. Anything
you leave out is inherited from the title; anything you send explicitly wins, so
a regional variant keeps its own language, region and barcode.

```bash
curl -X POST "$HOST/api/v1/items" \
     -H "Authorization: Bearer $TOKEN" -H 'Content-Type: application/json' \
     -d '{"library_id":1,"title_id":12,"condition":"good","completeness":"cib"}'
```

`platform_id` and `category_id` may be omitted when `title_id` is given: the
title supplies them.

**Before adding a second copy**, `GET /titles?q=speedball` tells you what already
exists and how many copies you hold. Whether that is a duplicate or a legitimate
second copy is a judgement for the person, not the server — so the API reports
and does not refuse.
