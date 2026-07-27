# WordPress.org listing assets

Images shown on the plugin's **WordPress.org page** and in the WP admin plugin
browser. They are **NOT part of the plugin ZIP** — they live in the SVN
`/assets/` folder and are uploaded when publishing (the
`10up/action-wordpress-plugin-deploy` GitHub Action copies this folder there).

Drop the files here with these **exact** names:

## Icon — square (search results + page header)
- `icon.svg` — vector, **preferred** (crisp at every size), **or**
- `icon-256x256.png` + `icon-128x128.png` — raster fallback

## Banner — top of the plugin page
- `banner-1544x500.png` — retina / high-res
- `banner-772x250.png` — standard

## Screenshots — must match the order in `readme.txt` → `== Screenshots ==`
- `screenshot-1.png` … `screenshot-4.png`
  1. The Vio configuration screen — connection status, settings and sync overview.
  2. Connecting the store with your Vio API key.
  3. The product list with the Vio sync column and bulk actions.
  4. Syncing products with live progress.

> Tip: the in-plugin admin logo is a **separate** file — `assets/img/icon.svg` —
> and IS bundled in the ZIP (used on the Vio config page and the product column).
