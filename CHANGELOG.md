# Changelog

## 1.0.1 — 2026-11-15

- Translations: a translation template (languages/orderloft.pot) is included, and the readme explains how to translate the plugin.
- Right-to-left languages: the overview list uses logical CSS.

## 1.0.0 — 2026-11-14

- First release.
- Archive WooCommerce orders by age and status into archive tables in the same database, one transaction per order.
- Free nightly automatic archiving through Action Scheduler, with batch size and per-run limits.
- Archived orders screen: search, read-only order view, CSV export, single and bulk restore.
- "Archive this order" box on the order edit screen; edit links of archived orders open the archived copy.
- Read-only "Older orders" list under My Account → Orders.
- Personal data exporter and eraser for archived orders.
- WP-CLI: `wp orderloft status | archive | restore`.
- Supports HPOS, HPOS with compatibility mode, and legacy posts order storage. WooCommerce Analytics keeps archived orders.
