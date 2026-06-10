# Tests

Integration tests for the Vio configuration data layer. They boot the real
WordPress (with WooCommerce and this plugin active) and assert against the
database — no separate WP test library is required.

## Run

From an environment where PHPUnit and `wp-load.php` are reachable (the
WordPress / CLI container), in the plugin directory:

```sh
phpunit
```

`tests/bootstrap.php` loads WordPress from `/var/www/html/wp-load.php` by
default; override with `WP_LOAD_PATH=/path/to/wp-load.php`.

In the local docker dev env:

```sh
docker compose -f ~/.wp-env/<project>/docker-compose.yml \
  --project-directory ~/.wp-env/<project> \
  exec -T cli sh -c 'cd /var/www/html/wp-content/plugins/vio-woocommerce-sync && phpunit'
```

## Coverage

| Subject | What is asserted |
| --- | --- |
| `Store_Status::stats()` | four int buckets; `synced + sent + not_synced == total`; total matches the published product count |
| `Store_Status::health_payload()` | response shape; `environment` is `production`/`staging`; `connected` is bool |
| `Store_Status::pending_product_ids()` | eligibility (published, unsynced, de-duplicated) and the `limit` cap |
| `Store_Status::save_options()` | persistence; an invalid environment is ignored, never switching the API target |
| `Logger::recent()` | result is capped and ordered newest-first |
