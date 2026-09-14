# B2B WooCommerce Inventory Viewer

A portfolio project demonstrating a small, accessible inventory viewer and a read-only WooCommerce REST API.

The hosted frontend runs entirely on fictional sample data. It does not connect to a store, contain credentials, or expose live inventory.

## Demo

[Open the GitHub Pages demo](https://dimitrisnimas.github.io/B2B-WooCommerce-Inventory-Viewer/)

The demo supports:

- Product search by name or SKU
- Hierarchical category filtering
- Predictable pagination with a “load more” action
- Retail and wholesale price presentation
- Responsive product cards and an accessible details dialog
- Safe DOM rendering without injecting API values as HTML

## Project structure

```text
.
├── inventory-api-plugin.php   # Reference WordPress/WooCommerce REST plugin
├── viewer/
│   ├── index.html             # Static demo shell
│   ├── demo-data.json         # Fictional portfolio data
│   └── assets/                # JavaScript, CSS and local artwork
└── .github/workflows/         # Quality checks and GitHub Pages deployment
```

The frontend and backend are intentionally independent. GitHub Pages publishes only `viewer/`; the PHP plugin is never executed by the hosted demo.

The frontend defaults to demo mode. A same-origin WordPress integration can define `window.INVENTORY_VIEWER_CONFIG` before loading `assets/app.js` and set `mode`, `apiBaseUrl`, `nonce`, `perPage` or display labels without changing the application module.

## Run the demo locally

Serve the repository over HTTP because browsers do not allow `fetch()` to read JSON reliably from a `file://` URL.

```bash
python -m http.server 8080 --directory viewer
```

Then visit `http://localhost:8080`.

## Reference REST API

Copy `inventory-api-plugin.php` into its own directory under `wp-content/plugins/`, then activate it with WooCommerce installed.

The API exposes:

```text
GET /wp-json/inventory-viewer/v1/products
GET /wp-json/inventory-viewer/v1/products/{id}
GET /wp-json/inventory-viewer/v1/categories
```

Product list parameters:

| Parameter | Default | Description |
| --- | ---: | --- |
| `search` | empty | WordPress title/content search |
| `category` | `0` | Product category term ID, including descendants |
| `page` | `1` | Result page |
| `per_page` | `24` | Items per page, maximum `50` |

Only published top-level products are returned. Queries use WordPress APIs, deterministic title ordering and primed metadata/term caches instead of hand-written SQL.

### Authentication

The reference API requires a logged-in WordPress user with the `manage_woocommerce` capability. It is designed for same-origin requests using WordPress cookie authentication and an `X-WP-Nonce` header.

The permission decision can be integrated with another authentication system through:

```php
add_filter(
    'b2b_inventory_viewer_can_view_inventory',
    static function (bool $allowed): bool {
        return $allowed;
    }
);
```

Do not place shared API keys, WordPress application passwords or other credentials in browser JavaScript.

### Adding a price tier

The plugin exposes retail price data by default and stays independent of specific B2B extensions. A store integration can add non-sensitive fields through the product filter:

```php
add_filter(
    'b2b_inventory_viewer_product_data',
    static function (array $data, WC_Product $product): array {
        $data['prices']['wholesale'] = get_post_meta(
            $product->get_id(),
            '_example_wholesale_price',
            true
        );

        return $data;
    },
    10,
    2
);
```

Authorization and data visibility must be reviewed before exposing additional price tiers.

## Development

Install the optional PHP quality tools with Composer:

```bash
composer install
composer lint
composer phpcs
```

The GitHub workflow also checks PHP syntax, WordPress coding standards, JavaScript syntax and the validity of the demo JSON.

## Scope

This repository is an educational portfolio sample, not a drop-in production inventory system. A production integration would additionally need an agreed authorization model, audit logging, cache strategy, monitoring and tests against the target WooCommerce/B2B extensions.

## License

[MIT](LICENSE)
