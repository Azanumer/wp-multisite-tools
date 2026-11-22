# WP Multisite Tools

A network-admin toolkit for WordPress multisite — the overview and bulk actions that core multisite is missing.

## Features

- **Per-site health table** — every site in the network with created date, user count, published posts/pages, active theme, active plugin count and uploads disk usage, in one screen (**Network Admin → Multisite Tools**)
- **Bulk plugin activate/deactivate** — turn a plugin on or off on *every* site at once, with a per-site success/failure report (network-activated plugins are untouched — this only affects per-site activation)
- **CSV export** — download the whole table for reporting or auditing
- **Multisite-only** — refuses to activate on a single-site install

## Notes

- The table shows up to 200 sites by default (each row needs a blog switch to count users/posts). Raise it with the `wmt_sites_limit` filter.
- Disk usage walks each site's uploads folder, which can be slow on very large sites. Disable it with:
  ```php
  add_filter( 'wmt_show_disk_usage', '__return_false' );
  ```

## Filters

- `wmt_sites_limit` — max sites listed (default 200)
- `wmt_show_disk_usage` — whether to measure uploads disk usage (default true)

## Requirements

- WordPress 5.8+ **multisite**
- PHP 7.4+
- Network-admin access (`manage_network_plugins` capability)

## License

MIT — see [LICENSE](LICENSE).
