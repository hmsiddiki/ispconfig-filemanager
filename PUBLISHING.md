# Publishing: turning this repo into the one-line GitHub installer

The one-line command in the README only works after these steps. Do them once, in order.

## 1. Create the GitHub repository

1. On GitHub: **New repository**, name `ispconfig-filemanager`, public, **no** README/licence/.gitignore (the repo already has them).
2. Note the `OWNER/ispconfig-filemanager` name (your user or organisation).

## 2. Replace the placeholders

| File | What to change |
|---|---|
| `get.sh` line 5 and `REPO=` (line 18) | `OWNER` → your GitHub name (or leave and tell people `FM_REPO=you/name`) |
| `README.md` "Quick install" | `OWNER` → your GitHub name |
| `LICENSE` | "contributors" → your name |
| `SECURITY` text in README | a private contact (GitHub: Settings → Security → enable private vulnerability reporting) |

```bash
grep -rn OWNER --exclude-dir=node_modules --exclude-dir=vendor .      # must print nothing when you are done
```

## 3. Push

```bash
git remote add origin https://github.com/hmsiddiki/ispconfig-filemanager.git
git push -u origin main
```

Check **Actions → CI** goes green (PHP syntax, template lint, shell/JS syntax, front-end build). Fix anything red before tagging.

## 4. Release (this is what makes the installer work)

`get.sh` downloads `ispconfig-filemanager-X.Y.Z.tar.gz` and its `.sha256` from the **latest GitHub Release**. The
release workflow builds and attaches both when you push a tag.

```bash
cat VERSION                       # e.g. 1.0.0. The tag MUST be v + this number or the build refuses
git tag v1.0.0
git push origin v1.0.0
```

Then **Actions → Release** runs. When it is green, **Releases** shows the tarball and the `.sha256` file.
(If the release is created as a draft, open it and press **Publish**: `/releases/latest` ignores drafts and pre-releases.)

## 5. Test it on a real server (a throw-away VPS with ISPConfig)

```bash
curl -fsSL https://raw.githubusercontent.com/hmsiddiki/ispconfig-filemanager/main/get.sh | sudo bash -s -- --check   # changes nothing
curl -fsSL https://raw.githubusercontent.com/hmsiddiki/ispconfig-filemanager/main/get.sh | sudo bash                  # installs
```

Expected: "checksum matches", every self-test line `ok`, then the panel shows the File Manager tab and menu entry
(log out and in once). Finally try `sudo ispconfig-filemanager-uninstall`.

## 6. Releasing a new version later

1. Change code, add a `CHANGELOG.md` entry.
2. Put the new number in `VERSION` (and `package.json` `version`).
3. `git commit`, `git push`, then `git tag vX.Y.Z && git push origin vX.Y.Z`.
4. Users update by running the same one-line command again (their settings and keys are kept).

## Troubleshooting the installer chain

| Symptom | Cause |
|---|---|
| "does not know which GitHub repository" | `OWNER` still in `get.sh`, or `FM_REPO` not set |
| "could not find a release" | no published (non-draft) release yet, or repo is private |
| "download failed" | the tag has no attached tarball: look at the Release workflow log |
| "CHECKSUM MISMATCH" | the `.sha256` and tarball are from different builds: delete the release and re-run the workflow |
| Release workflow: "tag does not match VERSION" | fix `VERSION`, delete and re-create the tag |

## Optional hardening before you advertise it

- Pin people to a version: `... | sudo bash -s -- --version 1.0.0`.
- Enable branch protection on `main`, and 2FA on the GitHub account (whoever can change `get.sh` runs code as root on users' servers).
- Mirror somewhere else with `FM_DOWNLOAD_BASE` if you do not want to depend on GitHub.
