# bcbattery-main

Woodmart child theme (`woodmart-child`) for bcbattery.ir: product page, cart/checkout/pay flow, search.

Status: code taken from the `woodmart-child-test` copy on the server (not yet the live theme).

## Structure

- `functions.php` loads the modules below; each load is guarded so a failure cannot take the site down.
- `inc/bc-core.php` rollout switch + module loader for the purchase flow.
  - Option `bc_flow_rollout` = `off` (default) | `admin` | `all`
  - `?bc_flow=1` / `?bc_flow=0` preview for administrators
  - `define( 'BC_FLOW_DISABLED', true )` in wp-config.php disables it
  - modules: `inc/cart.php`, `inc/checkout.php`, `inc/pay.php`, `inc/account.php` (placeholders for now)
- `inc/pdp.php` + `woocommerce/content-single-product.php` single product page (WooCommerce hooks; `?bc_pdp=1/0`, option `bc_pdp_rollout`).
- `inc/bc-search/` product search (replaces FiboSearch); disable with `define( 'BCS_DISABLE', true )`.
- `assets/css`, `assets/js` per-module files, versioned by file modification time.
- `rtl.css` legacy RTL/font rules (font files are not in this repo).

## Not in the repo

`font/`, `languages/`, `screenshot.png` (binary, stay on the server). Do not commit wp-config, keys or backups.

## Deploy

Upload the changed files to `wp-content/themes/<theme-folder>/` and activate the theme only after testing. Keep the previous theme folder as rollback.
