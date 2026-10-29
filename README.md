# Brandix PowerKit (Brandix Kit)

In-house WordPress utility plugin by Brandix Soft.

## Releasing an update

1. Change the code.
2. Bump `Version:` in `sticky.php` (and `BX_POWERKIT_DB_VERSION` below it), e.g. `11.29` → `11.30`.
3. Commit to `main` with a short message describing the change (it becomes the release notes).

GitHub Actions builds `brandix-sticky.zip` and publishes release `v11.30`.
Every site with Brandix Kit shows **"Update available"** on the Plugins screen within ~6 hours
(or straight away with **Plugins → Brandix Kit → Check for updates**).

## Site setup (once per site)

Nothing to set: the plugin checks `Asfund7788/brandix-kit` by default. (To use another repo: Brandix Kit → Updates.)

Or in `wp-config.php`: `define('BX_POWERKIT_REPO', 'Asfund7788/brandix-kit');`
