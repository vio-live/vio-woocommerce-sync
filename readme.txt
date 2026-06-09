=== Vio WooCommerce Sync ===
Contributors: vio
Tags: woocommerce, sync, products, inventory, ecommerce
Requires at least: 6.0
Tested up to: 6.7
Requires PHP: 8.0
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Sincroniza productos de WooCommerce con la plataforma Vio: inventario, precios, variantes e imágenes.

== Description ==

Vio WooCommerce Sync conecta tu tienda WooCommerce con Vio y mantiene tu catálogo
sincronizado de forma automática.

* Exporta productos (individuales o en lote) a Vio.
* Sincroniza precios, stock, variantes, atributos e imágenes.
* Actualiza automáticamente los productos al guardarlos.
* Elimina productos de Vio desde la lista de productos.
* Soporta entornos de **producción** y **staging**.
* Compatible con HPOS (High-Performance Order Storage).

== Configuración ==

1. Instala y activa el plugin (requiere WooCommerce).
2. Ve a **WooCommerce → Ajustes → Vio**.
3. Introduce tu **API Key**, elige el **entorno** y la **moneda**, y conecta tu tienda.

El entorno puede forzarse desde `wp-config.php`:

`define( 'VIO_WC_SYNC_ENV', 'staging' );`

== Changelog ==

= 1.0.0 =
* Versión inicial: reescritura modular del conector, con marca Vio, manejo de
  entornos prod/staging y refuerzos de seguridad.
