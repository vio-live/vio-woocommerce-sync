# Backend integration plan (Vio commerce API)

What the Vio backend must expose for each plugin feature. This is the
plugin → backend contract: every call the plugin makes today, its payload, the
response it expects, and the one gap that blocks a full sync.

Today the plugin talks to the **Reachu** API (`api.reachu.io` prod /
`api-qa.reachu.io` staging). The target is **`api-commerce.vio.live`**. The
plugin is already env-agnostic (a `wp-config` constant or the
`vio_wc_sync_api_base` filter repoints it with zero code change), so "the Vio
backend" below means "whatever host `Api_Client::base_url()` resolves to".

## Conventions

- **Auth.** Every request sends `Authorization: <api-key>` (the raw key the user
  pastes in settings — **not** `Bearer`-prefixed). The backend authenticates the
  store by that key.
- **Format.** `Content-Type: application/json`; responses are JSON. The plugin
  treats only HTTP `200`/`201` as success.
- **Identity.** A WooCommerce product is identified to the backend by
  `origin = "WOOCOMMERCE"` + `originId = <WP post id>` (variant `originId =
  <variation id>`). This pair is the join key the backend must persist.

## Endpoints the plugin calls

| # | Feature | Call | Body / params | Expected response | Status |
|---|---------|------|---------------|-------------------|--------|
| 1 | Who am I / health | `GET /catalog/users/me` | — | user object with `id`, `username`/`email` | ✅ works |
| 2 | Currency list | `GET /api/currencies` | — | `[{ currency_code, enabled }]` | ✅ works |
| 3 | Save store config | `PUT /woo/config` | `{ currency }` | 200 | ✅ works |
| 4 | Queue product create | `POST /api/products/create-sqs` | `{ products: [{ product: <DTO> }] }` | `{ messageId }` | ⚠️ queues, but see the gap |
| 5 | Read one product | `GET /api/products/{id}` | — | product object (incl. `variants`, `originalPrice`) | ✅ works |
| 6 | Update product | `PUT /api/products/{id}` | partial diff (changed fields only) | 200 | ✅ works |
| 7 | Delete product | `DELETE /api/products/{id}` | — | 200 | ✅ works |
| 8 | Batch delete | `DELETE /api/products?ids=a,b,c` | — | 200 | ✅ works |
| 9 | Finish first sync | `PUT /api/users/me/finish-sync?origin=WOOCOMMERCE` | — | 200 | ✅ works |
| 10 | OAuth callback | store → `POST {base}/woo/auth/callback-supplier/` | WC key payload | creates REST key + order webhooks | ✅ works |
| 11 | Order webhooks | store → backend on `order.created` / `order.updated` | WC order payload | 200 | ✅ works (naming nit below) |
| 12 | Disconnect / cleanup | `DELETE /api/users/api-credential/` | `{ fullDelete, id, ecomUser:{id} }` | 200 | ✅ exists — plugin wiring pending |

Endpoints 5–8 all depend on the backend first returning the product id (the gap).

## The product DTO (call #4)

Built by `Product_Mapper::to_dto()`:

```jsonc
{
  "title", "description",
  "price": { "amount", "compareAt", "currencyCode" },
  "origin": "WOOCOMMERCE",
  "originId": 123,                // WP post id — the join key
  "images": [{ "order", "image" }],
  "quantity", "sku", "barcode",
  "optionsEnabled": true,
  "options":  [{ "name", "order", "values": "Red,Blue" }],
  "variants": [{ "sku", "price", "priceCompareAt", "quantity",
                 "title", "originId", "images": [{ "image", "order" }] }],
  "weight", "width", "height", "depth"   // optional
}
```

The backend creates the product asynchronously from the SQS message.

## ⛔ The gap that blocks a full sync — product-id write-back

After call #4 the plugin stores the returned `messageId` (meta `vio-sqs-id`) and
shows the product as **"Sent"**. To advance to **"Synced"** it needs the Vio
**product id** back in the store (meta `vio-product-id`). Today that never
returns, so every product is stuck on "Sent" and everything keyed on the remote
id (auto-update on save, true remote delete) cannot run.

The plugin **cannot** work around it — there is no filter endpoint to poll
(`GET /api/products` returns the whole catalogue, unfiltered). The backend must
do **one** of:

- **(preferred) Write the id back** to the store after creating each product,
  via the WooCommerce REST API created at connect time, into post meta
  `vio-product-id`. Accepted formats the plugin already reads
  (`Product_Mapper::get_remote_product_id()`):
  - a plain string `"<vioProductId>"`, **or**
  - a JSON array `[{ "idusr": "<apiKey>", "idprod": "<vioProductId>" }]` when a
    product is shared across several Vio accounts.
- **(alternative) Expose a lookup** so the plugin can poll, e.g.
  `GET /api/products?origin=WOOCOMMERCE&originId={postId}` →
  `{ id }`. The plugin would then resolve the id itself after queueing.

Either unblocks #5–#8 and the "Sent → Synced" transition shown on the config
page. Until then the **Sync overview** card on the config page honestly shows
the queued count as "waiting for the backend".

## Webhooks & OAuth (call #10–#11)

- The connect flow opens a WooCommerce OAuth authorize URL with
  `callback_url = {base}/woo/auth/callback-supplier/`. The backend receives the
  WC REST credentials and creates **two order webhooks** (created/updated).
- The plugin detects "connected" robustly: a webhook whose **name** matches
  `Vio order.created` / `Vio order.updated` **or** whose delivery-URL **host**
  equals the backend host. So it already tolerates the backend currently naming
  them **"Outshifter order.*"** — but renaming them to **"Vio order.*"** is the
  clean fix (cosmetic).

## Disconnect — full teardown of both sides (resolved in the plugin)

`Ajax::logout()` tears down **both** sides, so a disconnect leaves no stale state on
either end (which previously broke reconnect — orphaned webhooks, the backend calling
the store with a deleted key):

1. **Backend** — resolve this store's connection from `GET /api/ecom-user`, then
   `DELETE /api/users/api-credential/`. Best-effort, runs first (while the key is valid).
2. **Store** — delete the WC REST API key + Vio order webhooks and clear the options
   (`Plugin::cleanup()`).

**The endpoint exists** — `DELETE /api/users/api-credential/`, authenticated by the
store's API key, with body:

```json
{ "fullDelete": true, "id": <credentialId>, "ecomUser": { "id": <userId> } }
```

Confirmed working: deleting the credential removes the connection (the store's key
then returns 401). The plugin now ships `Api_Client::delete_api_credential( $id, $userId )`.

### Resolving the ids — the gotcha

The DELETE payload needs both ids; both come from `GET /api/ecom-user` (the account's
store connections), matched to this store by `connection.url` host:

```jsonc
[ { "id": 199,                          // ← ecomUser.id  (the entry's own id)
    "ecomName": "WOOCOMMERCE",
    "connection": { "url": "https://woo-dev.vio.live" },
    "apiCredential": { "id": 412 } }    // ← the credential id
]
```

⚠️ **Gotcha:** `ecomUser.id` is the **/api/ecom-user entry id** (e.g. `199`), **not** the
Vio account id from `/catalog/users/me` (e.g. `1289`) — sending the account id is rejected
with `HTTP 417`. `Api_Client::find_woo_connection()` returns the correct
`{ credential_id, ecom_user_id }` pair, so **no backend change is required**.

## What the config page itself needs from the backend

Almost nothing — by design. The new page is backend-independent except where it
drives the sync flow:

- **Connection / health** → call #1 (`/catalog/users/me`) only.
- **Settings save** → call #3 (`/woo/config`).
- **Sync overview stats** → computed locally from product meta (no backend).
- **Sync all** → calls #4 + #9.
- **Logs** → read from the local WooCommerce log files (no backend).

So the config UI works today; only the **product-id write-back** stands between
"Sent" and a green "Synced".

## Migration to `api-commerce.vio.live` — checklist

When Vio's commerce API is live, it must reach parity on the calls above. Order
of operations:

1. Stand up endpoints #1–#9 with the same shapes (auth header, `origin/originId`
   identity, the DTO, the `messageId` queue response).
2. Implement the **product-id write-back** (or the lookup endpoint).
3. Wire the OAuth callback `{base}/woo/auth/callback-supplier/` and create
   **"Vio order.*"** webhooks.
4. Implement the **disconnect/cleanup endpoint** (#12 above) so reconnect starts
   from a clean backend state.
5. Point the plugin at it — set `define( 'VIO_WC_SYNC_API_URL_PRODUCTION',
   'https://api-commerce.vio.live' )` in `wp-config.php` (and the staging twin),
   or filter `vio_wc_sync_api_base`. No plugin code change.
6. Verify end-to-end: connect → currencies load → queue a product → it flips
   "Sent → Synced" → edit it (auto-update) → delete it (remote delete) → an
   order webhook delivers → **disconnect → reconnect** cleanly.
