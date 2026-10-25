# Changelog

All notable changes. Versions follow [Semantic Versioning](https://semver.org/).

## 1.0.1

- Installer: the PHP-extension check could report a false "missing PHP extension" on some runs (a pipe closed early). Fixed.
- The website File Manager frame is taller; row menus open as native-style menus; the Sites side-menu entry is optional (`--with-menu`).

## 1.0.0

First release.

### ISPConfig integration
- **File Manager tab** on every website's page (Domain, Redirect, SSL, … **File Manager**), added without editing any
  ISPConfig file (loaded from the panel's `js/js.d/` drop-in folder), plus an optional **Sites → File Manager** menu entry with a website picker (`install.sh --with-menu`; off by default).
- **Automatic sign-in:** the panel page creates a temporary, hidden, expiring FTP account for the chosen website and hands
  the file manager a single-use, encrypted, 60-second token. Nothing is typed and no password is stored.
- Permissions follow ISPConfig's own rules: admins see every website, clients only their own.
- Installer (`install.sh`, `get.sh`) that checks the server, installs idempotently, **tests itself** (including a real FTP
  login and a request through the panel's web server) before switching anything on, and an uninstaller.
- Full screen from the editor expands over the whole panel window, not just its frame.
- Follows the panel's light/dark theme (`data-theme` / class on `<html>` or `<body>`, or `postMessage`).

### File manager
- Modern grid and list views with type icons and image thumbnails; light, dark and automatic themes; responsive down to phones.
- Code editor (CodeMirror 5): syntax colours for 20+ languages, bracket matching and auto-closing, folding,
  find & replace (match case, regular expressions), go to line, full screen, Windows line endings preserved, binary files refused.
- Safer saving (written in place, keeps file permissions).
- Upload from URL with live progress, cancel, size/time limits, one download per person and three server-wide;
  public web addresses only (private networks, metadata services, redirects and DNS tricks are blocked).
- Storage over FTP, FTPS and SFTP (phpseclib 3); standalone mode with a connection form (see `examples/`).

### Fixed in the build
- Menus in the grid view open correctly at every width.
- Declared front-end dependencies that were previously only present by accident (`moment`, `lodash`) and updated them to patched releases.
