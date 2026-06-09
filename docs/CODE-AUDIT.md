# Code Audit — base `Reachu Export v3.8` → `Vio WooCommerce Sync`

Auditoría de código del plugin original y cómo la reescritura la resuelve.
(El inventario completo de rebranding está en el repo `woo-vio`:
`docs/audit-reachu-to-vio.md`.)

## Hallazgos del plugin original

### 🔴 Seguridad
| # | Hallazgo | Riesgo | Resolución en la reescritura |
|---|---|---|---|
| 1 | `wp_ajax_nopriv_*` en **todas** las acciones | Usuarios **no autenticados** podían disparar sync/delete/logout | Solo `wp_ajax_*` (sin `nopriv`) — `class-ajax.php` |
| 2 | Sin `current_user_can()` | Cualquier usuario logueado podía operar | `Ajax::guard()` exige `manage_woocommerce` |
| 3 | `reachu_save_settings` y `logout_reachu` sin nonce | CSRF | `check_ajax_referer` / `check_admin_referer` en todas |
| 4 | Datos impresos sin escape (`$loggedUser->username`, `$currency`) | XSS | `esc_html`/`esc_attr`/`esc_url` en toda salida |
| 5 | SQL directo (`woocommerce_api_keys`) | Inyección/robustez | `$wpdb->prepare()` + `esc_like()` |

### 🟡 Calidad / arquitectura
- Monolito de **1196 líneas** en un único `index.php` con una clase estática.
- **HTML y CSS embebidos** en PHP (`echo '<style>…'`, `admin_head`).
- Mezcla de idiomas (ES/EN) en logs y comentarios; typos en UI (*conection*, *conected*).
- `sleep(2)` bloqueante en `handle_save_product` para productos variables.
- Prefijo opaco `OSEWCPHJC_` y nombres inconsistentes.
- Sin internacionalización real (text-domain mezclado: `woocommerce` y `woocommerce-settings-reachu`).

### 🟢 Dependencias
- `vendor/` de **5.9 MB** (Google Cloud, Firebase, Guzzle, Monolog, JWT…) **sin uso**
  (0 imports, 0 referencias). Eliminado.

## Arquitectura de la reescritura

```
vio-woocommerce-sync.php        Bootstrap: header, constantes, HPOS, carga, ciclo de vida
includes/
  class-logger.php              Wrapper del WC logger
  class-api-client.php          HTTP + entornos (prod/staging) + auth + endpoints
  class-product-mapper.php      WC product → DTO Vio + diffing
  class-sync.php                Servicio: push / update / delete
  class-settings.php            Pestaña de ajustes (API key, entorno, moneda, conexión)
  class-products-table.php      Columna, bulk actions y auto-sync
  class-ajax.php                Acciones AJAX (nonce + capability, sin nopriv)
  class-plugin.php              Orquestador + constantes + activación/desactivación
assets/{js,css,img}/  ·  readme.txt  ·  languages/
```

### Best practices aplicadas
- `declare(strict_types=1)` y tipado en todas las clases.
- Guard `defined('ABSPATH') || exit` en cada archivo.
- Prefijos/namespace únicos (`Vio\WooSync`, `VIO_WC_SYNC_`, `vio_*`, `vio-*`).
- **Nonce + capability** en cada acción; sin `wp_ajax_nopriv`.
- Sanitización de entrada (`absint`, `sanitize_text_field`, `sanitize_key`, `wp_unslash`)
  y escape de salida (`esc_*`).
- i18n con text-domain único `vio-woocommerce-sync`.
- Assets vía `wp_enqueue_*` (no inline).
- HPOS declarado.
- Sin dependencias muertas; versionado semántico (1.0.0).

## Pendiente (⏳)
- URL real de la API de Vio (prod/staging) en `Api_Client::ENVIRONMENTS`.
- Confirmar que los **paths** de los endpoints de Vio coinciden con los de Reachu.
- Assets de marca definitivos (`assets/img/`).
- URLs de marca (signup, docs, legales).
