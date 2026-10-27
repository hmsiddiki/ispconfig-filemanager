# Changelog

All notable changes. Versions follow [Semantic Versioning](https://semver.org/).

## Unreleased

- **Unzip runs in the background:** "Unzip" now answers at once and the server does the work in a process of its own, so reloading the page, closing the tab or a proxy timeout no longer stops it (a large zip used to hang the page and die half-way). A progress card (reading the archive, then "n / m files") shows it, with a Cancel button, and finds the job again after a reload. Files are extracted straight from the archive, the temporary copy is always removed, and names that point outside the target folder are skipped.
- **tar, tar.gz, tgz, tar.bz2 and tar.xz** can be unpacked too (needs the `tar` program on the server, which every Linux has).
- **Default upload limit is 2 GB** (was 100 MB) in new installs. An update keeps your `configuration.php`; to raise an existing install, change `frontend_config.upload_max_size`.
- Unzip is about 20 times faster over FTP: files going into folders the unzip itself created are written without asking the server whether the name is taken, and the FTP connection remembers folders it already knows. (A 13,000-file site took about a minute instead of 22.)
- Job status files are written atomically, so a progress poll can no longer read a half-written file; the progress card also keeps following a job through a missed answer.
- Panel: opening the file manager no longer fails with "Too many file manager sessions" after many reloads. The cap is now 100 per website, and at the cap the oldest temporary logins are retired to make room.
- Optional setting `unzip_php_cli` (full path of the command-line PHP) for servers where it is not found automatically.

## 1.1.0

- **Drag and drop to move:** drag files or folders (or everything selected) onto a folder, or onto the ".." card to move up a level. Works in grid and list views.
- **Right-click menu:** right-clicking a file or folder opens its menu at the pointer, like a native context menu (it did not open in the grid view before).

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
