# Orderloft – Archive Old Orders for WooCommerce

Move old WooCommerce orders out of your live order tables into an archive, automatically or by hand. Restore any order exactly as it was, in one click. WooCommerce Analytics keeps counting archived orders.

![Version 1.0.0](https://img.shields.io/badge/version-1.0.0-blue)
![Tested up to WordPress 7.1](https://img.shields.io/badge/WordPress-7.1-blue)
![WooCommerce 8.2+](https://img.shields.io/badge/WooCommerce-8.2%2B-7f54b3)
![PHP 7.4 to 8.5](https://img.shields.io/badge/PHP-7.4%E2%80%938.5-777bb4)
![License GPLv2 or later](https://img.shields.io/badge/license-GPLv2%2B-green)

## Why

On busy stores the order tables grow with every sale. The Orders screen, order search, customer pages and every plugin that queries orders get slower. Archiving old, finished orders keeps the live tables small while the history stays safe and searchable.

## Features

- Archive orders by age and status (default: completed, refunded, cancelled and failed orders older than 12 months).
- Free nightly automatic archiving, in background batches through Action Scheduler, with a per-run limit.
- Each order moves with its refunds, items, item meta, addresses, order meta and notes, inside one database transaction. Row IDs are kept, so a restored order is the same order.
- WooCommerce Analytics keeps archived orders: Orderloft never deletes orders through WooCommerce.
- Archived orders screen: search by order number, email or name, read-only order view, CSV export, single and bulk restore.
- "Archive this order" box on the order edit screen. Edit links of archived orders open the archived copy.
- Customers see archived orders under My Account → Orders as a read-only "Older orders" list.
- WooCommerce Subscriptions aware: orders linked to a live subscription are skipped.
- Privacy: archived orders are included in personal data exports; erasure follows WooCommerce's "remove order data" setting.
- WP-CLI: `wp orderloft status`, `wp orderloft archive`, `wp orderloft restore`.
- Works with High-Performance Order Storage (HPOS), HPOS with compatibility mode, and legacy posts storage.

## Install

1. Download the latest release zip from [Releases](https://github.com/bealinawaz/orderloft/releases).
2. In WordPress, go to Plugins → Add New → Upload Plugin and upload the zip.
3. Activate it, then open **WooCommerce → Order Archive**.

Take a database backup before your first archive.

## WP-CLI

```bash
wp orderloft status
wp orderloft archive --older-than=24 --dry-run
wp orderloft archive --status=completed,refunded --limit=5000
wp orderloft restore 1234 1235
wp orderloft restore --all
```

## How it works

Archived orders are stored in tables in the same database, named `{prefix}orderloft_*`, created with the same structure as WooCommerce's own order tables. An index table keeps a summary of each archived order for search, display and export. Only InnoDB tables are supported, so every move can be rolled back.

Deleting the plugin removes its tables only when the archive is empty. Restore archived orders first (`wp orderloft restore --all`) to remove everything.

## Hooks

- `orderloft_skip_order` (filter): return a reason to keep an order in the live tables.
- `orderloft_order_archived` (action): after an order and its refunds were archived.
- `orderloft_order_restored` (action): after an order was restored.

## Author

Built and maintained by [Ali Nawaz](https://bealinawaz.com), WordPress and WooCommerce developer. Need custom archiving rules, archiving to another database or cloud storage, or help with a slow store? [Get in touch](https://bealinawaz.com/contact-me/).

## License

GPLv2 or later. See [LICENSE](LICENSE).
