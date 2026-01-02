# KUBIK Inventory Viewer

A lightweight, high-performance inventory viewer for WordPress/WooCommerce using a custom REST API endpoint.

## Features

- **Fast Search**: Uses optimized SQL queries to search products by title, SKU, and attributes (GNISIOS).
- **Infinite Scroll**: Seamlessly load more products as you scroll, replacing traditional pagination.
- **Category Browsing**: Dedicated sidebar to browse products by category with a clean hierarchy.
- **Responsive UI**: Works great on Desktop and Mobile devices.
- **Secure**: Uses a custom API Key header (`X-INVENTORY-KEY`) to restrict access.
- **Price Groups**: Supports WCB2B group prices and role-based pricing.
- **Frontend-only Viewer**: The viewer is a single static HTML file (`index.html`) that can be hosted anywhere (e.g., a subdomain).

## Installation

### 1. Backend (WordPress)
1. Copy `inventory-api-plugin.php` to your `wp-content/plugins/` directory.
2. Edit `inventory-api-plugin.php` and set your desired `INV_ACCCES_KEY`.
3. Activate the plugin in WordPress Admin.
4. Ensure WooCommerce is installed and active.

### 2. Frontend (Viewer)
1. Open `index.html`.
2. Locate the `CONFIG` block at the bottom of the script section.
3. Update `URL` to your WordPress site's API endpoint (e.g., `https://your-site.com/wp-json/kubik/v1/inventory`).
4. Update `KEY` to match the `INV_ACCCES_KEY` you set in the WordPress plugin.
5. Upload `index.html` to your hosting or run it locally.

## Configuration

### Hiding Prices
You can hide specific price groups by adding them to the `HIDDEN_PRICES` array in `index.html`.

```javascript
const HIDDEN_PRICES = ['Group 11324', 'Group 11322'];
```

### Price Labels
Map dynamic price keys to human-readable labels in the `PRICE_MAPPING` object.

```javascript
const PRICE_MAPPING = {
    'retail': 'Retail Price',
    'Group 11327': 'Wholesale',
    // ...
};
```

## Credits

Developed by **[KUBIK](https://kubik.gr)**.
