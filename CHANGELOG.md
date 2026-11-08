# Changelog

All notable changes. Versions follow [Semantic Versioning](https://semver.org/).

## 1.5.0

- **Uploads are much faster.** Several causes, all fixed:
  - Every upload request held the PHP session, so the pieces the browser sends side by side were handled one after the other, and every other request of the file manager waited behind them. The upload now lets go of the session first.
  - Pieces were 1 MB, so a 1 GB file took over a thousand requests, each one starting the app and checking the scratch folder. The default is now 8 MB (an update raises the old shipped 1 MB; a value you set yourself is kept). The installer sets PHP's upload limits for the file manager's folder only, kept below the panel's nginx `client_max_body_size`, and the app lowers the piece size by itself where PHP allows less.
  - Each piece is written straight to its place in the file, so nothing is joined together at the end (the last request used to copy the whole file once more before saving it).
- **Leaving the File Manager tab no longer stops uploads.** The panel used to throw the file manager away, upload included, when another tab or page was opened. While uploads are running it is now kept open in the background, and a small card in the corner shows the progress with a **Show** button to go back to it. Without running uploads the tab closes as before. Closing or reloading the browser tab still stops an upload (the browser sends the file from that page), and the browser now asks before doing that.
- **Large uploads are saved into the website in the background.** When the last piece of a file of 32 MB or more arrives, the copy into the website runs as a background job with its own progress card ("Saving the upload"), like zip and unzip. The page does not wait for it, and the web server's time limit cannot cut it off.
- **Interrupted uploads resume.** Pieces are kept per website and per file, so adding the same file again (also after a reload) sends only the missing pieces.
- **Delete runs in the background**, like zip and unzip. The items leave the list at once, and a progress card (counting files, then "n / m files") shows the job, with a Cancel button. Leaving, reloading or closing the page does not stop it. Deleting a large folder over FTP used to run inside the browser's request, where the web server's time limit or leaving the page could stop it half-way without saying so.
## 1.4.1

- **The File Manager tab works on vhost subdomains and alias domains.** Their page showed "You do not have access to that website", because only websites were looked up. Such a domain has no folder tree of its own: the tab now opens the website it belongs to, in the subdomain's own folder (for example `updates` for updates.qlopy.com), or in `web` for an alias. The domain is checked with ISPConfig's own permissions, and the folder comes from the panel's database, never from the browser.

## 1.4.0

- **The File Manager tab opens in the website's `web` folder** (the document root) instead of its home folder with `log`, `ssl`, `private` and the rest; the home folder is one click away ("Home"). A site without `web` opens its home folder, as does a remembered folder that no longer exists. Reloading the file manager keeps the folder you were in. Optional setting `start_folder` in `configuration.php` chooses another folder (`''` for the home folder); an update keeps your configuration, so add it only if you want something other than `web`.

## 1.3.1

- **The installer finds the panel's port by itself.** It reads it from the panel's nginx (or Apache) vhost, so a panel on 2087 or any other port no longer stops the install at the last test with "HTTP 000". The port is shown in the first step; `--panel-port` still overrides it. If nothing answers, the message now says so and names the address it tried.

## 1.3.0

- **Zip runs in the background too.** "Zip" answers at once and the server builds the zip in a process of its own, like Unzip since 1.2.0. Zipping a whole website used to run inside the browser's request: the page showed a spinner with no progress, the web server's time limit could cut it off half-way, and while it ran the file manager could not be used at all, not even after reloading the panel page (it stayed empty). Now a progress card (counting files, then "n / m files" with the bytes, then saving the zip) shows it, with a Cancel button, and finds the job again after a reload.
- The zip is written as each file is read, so the server needs disk space for the zip only (it used to keep a copy of every file until the end), and it is stored in the website only when it is complete: a zip that fails or is cancelled leaves nothing behind. Zips over 4 GB and with more than 65,535 files are supported (zip64). Images, videos and archives are stored without compressing them again, which saves a lot of time on media-heavy sites.
- Names inside the zip start at the folder the items were picked in, so unzipping it in the same place puts everything back where it was (they used to include the full path from the website's root).
- A name that is already taken is never overwritten: the new zip gets a number ("site (1).zip").
- **The file manager no longer freezes during long operations.** Copy, move, delete, permissions, and the job status polls let go of the PHP session before they start. PHP locks the session for the whole request, so one long operation used to make every other request of the file manager wait, including a reload.

## 1.2.1

- **Updating raises the old upload default.** The 2 GB upload default from 1.2.0 only reached new installs, because an update keeps `configuration.php`. The installer now changes `upload_max_size` to 2 GB when it still holds the old shipped value (exactly 100 MB); a value you set yourself is never changed.
- README: step-by-step instructions for updating a server.
- CI: the PHP libraries are installed before the tests run.

## 1.2.0

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
