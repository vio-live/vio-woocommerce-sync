=== Vio WooCommerce Sync ===
Contributors: vio
Tags: woocommerce, sync, products, inventory, ecommerce
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sync products from your WooCommerce store to the Vio platform: inventory, prices, variants and images.

== Description ==

Vio WooCommerce Sync connects your WooCommerce store with Vio and keeps your catalog
synchronized automatically.

* Export products (individually or in bulk) to Vio.
* Sync prices, stock, variants, attributes and images.
* Automatically update products whenever you save them.
* Delete products from Vio straight from the product list.
* Supports **production** and **staging** environments.
* Compatible with HPOS (High-Performance Order Storage).

== Setup ==

1. Install and activate the plugin (WooCommerce is required).
2. Go to **WooCommerce → Settings → Vio**.
3. Enter your **API Key**, pick the **environment** and **currency**, and connect your store.

The environment can be forced from `wp-config.php`:

`define( 'VIO_WC_SYNC_ENV', 'staging' );`

== Changelog ==

= 1.0.0 =
* Initial release: modular rewrite of the connector, Vio-branded, with production/staging
  environment handling and security hardening.
