=== Orderloft – Archive Old Orders for WooCommerce ===
Contributors: imalinawaz
Donate link: https://www.buymeacoffee.com/bealinawaz
Tags: archive orders, order archive, old orders, woocommerce orders, hpos
Requires at least: 6.2
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.1
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Archive old WooCommerce orders automatically to keep your order tables lean. Restore any order in one click. Analytics keeps counting archived orders.

== Description ==

Orderloft moves old WooCommerce orders out of your live order tables into an archive. Your Orders screen, order search and every plugin that queries orders work with a smaller set of recent orders, while the old ones stay safe, searchable and one click away from being restored.

Pick an age and the statuses to archive, such as completed, refunded, cancelled and failed orders older than 12 months. Archive them now, or let Orderloft do it every night. **Scheduled automatic archiving is free.**

**What moves, and what doesn't**

Each order moves with everything that belongs to it: its refunds, line items and item meta, addresses, order meta and order notes. The move happens inside one database transaction, so if anything fails nothing changes. Row IDs are kept, so a restored order has the same ID, order number, order key, items and history it had before.

Orderloft never deletes orders through WooCommerce, so **WooCommerce Analytics keeps counting archived orders**. Your revenue, orders and product reports stay complete after archiving.

**Features**

* **Archive by age and status:** orders older than X months with the statuses you choose.
* **Free nightly automatic archiving** at 03:00 site time, with a per-run limit so a large first archive is spread over several nights.
* **Background batches** through Action Scheduler (part of WooCommerce), so big stores don't time out.
* **Archived orders screen:** search by order number, email or name, view a read-only copy of any order, and export the list to CSV.
* **One-click restore**, single or in bulk. Restored orders are kept out of later automatic runs.
* **Archive one order** from its edit screen.
* **Old links keep working:** opening the edit link of an archived order shows the archived copy instead of an error.
* **Customers still see their history:** archived orders appear under My Account → Orders as a read-only "Older orders" list (can be turned off).
* **WooCommerce Subscriptions aware:** orders linked to a subscription that is not cancelled or expired are kept in the live tables.
* **Privacy tools:** archived orders are included in WordPress personal data exports, and erasure requests follow WooCommerce's own "remove order data" setting.
* **WP-CLI:** `wp orderloft status`, `wp orderloft archive`, `wp orderloft restore`.
* **Works with every order storage:** High-Performance Order Storage (HPOS), HPOS with compatibility mode, and the legacy posts storage.

**Custom development**

Need archiving to another database or to cloud storage, custom retention rules, or help with a slow WooCommerce store? Orderloft is built and maintained by Ali Nawaz, a WordPress and WooCommerce developer. [Get in touch](https://bealinawaz.com/contact-me/?utm_source=wordpress.org&utm_medium=plugin-readme&utm_campaign=orderloft).

== Installation ==

1. Install and activate WooCommerce, then this plugin.
2. Go to **WooCommerce → Order Archive → Settings**.
3. Choose how old orders must be and which statuses to archive. Optionally turn on automatic archiving.
4. Back on the **Overview** tab, check the number of orders ready to archive and click **Archive now**.

Take a database backup before your first archive, as with any plugin that changes many orders.

== Frequently Asked Questions ==

= Are archived orders deleted? =

No. They are moved to archive tables in the same database (their names start with `orderloft_`). You can view, search, export and restore them at any time.

= Will archiving change my WooCommerce Analytics reports? =

No. Orderloft does not delete orders through WooCommerce, so the Analytics data for archived orders stays in place and your reports still include them.

= Does it make my store faster? =

It keeps the live order tables smaller, which helps the Orders screen, order search, customer pages and plugins that query orders on stores with many orders. The database as a whole is the same size, because archived orders are kept in it.

= Which orders are archived by default? =

Completed, refunded, cancelled and failed orders older than 12 months. Processing, on-hold and pending orders are not selected by default. You can change both rules.

= Can customers still see old orders? =

Yes. Archived orders appear in a read-only "Older orders" table under My Account → Orders. You can turn this off in the settings.

= What happens to refunds? =

Refunds move with their order and come back with it when you restore it.

= Does it work with HPOS? =

Yes. It works with High-Performance Order Storage, HPOS with compatibility mode turned on, and the legacy posts storage. In compatibility mode, the posts copies of an order move with it, so WooCommerce's sync stays clean.

= Does it work with WooCommerce Subscriptions? =

Orders linked to a subscription that is still active, on hold or pending cancellation are skipped. You can turn this check off.

= What does my database need? =

The order tables must use the InnoDB engine (the MySQL and MariaDB default), so every move can be rolled back. Orderloft checks this and refuses to run otherwise.

= What happens if I deactivate or delete the plugin? =

Deactivating stops automatic archiving; archived orders stay in the archive tables. Deleting the plugin removes its tables only when the archive is empty, so archived orders are never lost by accident. Restore them first (Archived orders → select all → Restore, or `wp orderloft restore --all`), then delete the plugin to remove everything.

= Can I archive from the command line? =

Yes: `wp orderloft archive --older-than=24 --dry-run` shows how many orders match, and `wp orderloft archive --status=completed,refunded --limit=5000` archives them. `wp orderloft restore <id>` or `--all` restores.

= Is the plugin available in my language? =

The plugin is ready for translation, and translations are made by volunteers on WordPress.org. You can help translate it into your language at [translate.wordpress.org](https://translate.wordpress.org/projects/wp-plugins/orderloft/). Once a language is 90% translated, WordPress installs the translation automatically through language packs. A translation template, languages/orderloft.pot, is included for tools such as Poedit and Loco Translate.

== Screenshots ==

1. Overview: live and archived order counts, orders ready to archive, and the last run.
2. Archived orders: search, view, restore and export.
3. Read-only view of an archived order.
4. Settings: age, statuses, nightly archiving and batch sizes.
5. Customers see archived orders under My Account → Orders.

== Changelog ==

= 1.0.1 (2026-11-15) =
* Translations: a translation template (languages/orderloft.pot) is included, and the readme explains how to translate the plugin.
* Right-to-left languages: the overview list uses logical CSS.

= 1.0.0 =
* First release: archive orders by age and status, free nightly automatic archiving, background batches, archived orders screen with search, view, CSV export and restore, single-order archiving, My Account history, privacy export and erasure, WP-CLI commands, and support for HPOS, HPOS compatibility mode and legacy order storage.

== Upgrade Notice ==

= 1.0.1 =
Translation and right-to-left language improvements. Nothing to change.

= 1.0.0 =
First release.
