# Integration checklist — requires a real Craft installation

The suites in `tests/php` and `tests/js` run standalone and are executed in CI /
locally. Everything below exercises live Craft APIs (permissions, folder
resolution, element saving) and therefore **requires a disposable Craft 4 and a
disposable Craft 5 install**. Run it against BOTH majors before a release.

Never run these against a production database or volume. Use a scratch install
(e.g. `composer create-project craftcms/craft`, a local DB, a local volume) and
a **stub yt-dlp** so no live videos are downloaded:

```bash
# stub yt-dlp for end-to-end runs (writes a tiny mp4, no network):
cp tests/php/fake-yt-dlp /usr/local/bin/yt-dlp-fake && chmod +x /usr/local/bin/yt-dlp-fake
# then set the plugin's "yt-dlp path" setting to /usr/local/bin/yt-dlp-fake
# and add the fake host to "Allowed hosts": example-cdn.test
```

Fixtures per install: one local volume ("Test uploads"), an Assets field
`videos` (restricted to Video), an Assets field `images` (restricted to Image),
a Matrix field with a block/entry type containing a nested `blockVideo` field,
a section + entry, an admin, and a non-admin editor account.

## Both Craft 4 and Craft 5

1. **Button placement** — `videos` shows *Scrape URL* next to Add/Upload;
   `images` does not; the nested Matrix field shows it (Craft 5: also inside
   the nested-entry slideout in cards view); a read-only/static render shows
   no button.
2. **Happy path** — submit `https://videos.example-cdn.test/clip/1` with the
   stub binary: modal shows progress, the asset chip lands in the field, Save
   persists it, the file exists in the volume, `uploaderId` is the editor.
3. **Unsaved element** — on a brand-new entry (before first save), the download
   lands in the user's temporary-uploads folder and moves into the volume on
   save (Craft's native temp-upload flow).
4. **Unauthorized requests**
   - anonymous POST to `video-downloader/download/create` → 403/redirect;
   - a user without CP access → 403;
   - an editor **without** `Save assets` on the destination volume → 403;
   - `fieldId` of an Assets field not on the edited element's layout → 400;
   - `fieldId` of a non-Assets field → 400;
   - missing CSRF token → 400.
5. **Job ownership** — user A starts a download; user B polling the same
   `jobId` gets 404; user A gets normal status.
6. **Disabled settings between enqueue and run** — enqueue, then disable the
   plugin (or de-select the field / turn on video-only for an image field)
   before the worker runs: the job fails with the corresponding message and no
   asset is created.
7. **Folder resolution** — field restricted to a subfolder → asset lands there;
   field with dynamic subpath (e.g. `{slug}`) → resolved against the edited
   element; delete the destination folder after enqueue → job fails clearly.
8. **Console worker** — run everything via `php craft queue/listen` (no web
   session): downloads still land in the configured location.
9. **Download failures** — stub modes: `VD_FAKE_MODE=fail` (clean error in the
   modal, no temp files left under `storage/runtime/temp/video-downloader/`),
   `sleep` with a 1-s timeout (timeout error, cleanup), `badext` (rejected
   before import), `big` with a 1 MB cap (rejected).
10. **Retry safety** — kill the worker mid-download and let the queue retry:
    exactly one asset exists afterwards; re-running a completed job is a no-op.
11. **Field limits** — set the field's limit to 1 with one asset selected: the
    modal refuses; with the limit reached only after download, the modal
    reports it and no selection is overwritten.
12. **Editor experience** — existing selections survive the attach; polling
    stops when the modal is closed mid-download; the CP queue shows progress.

## Craft 5 specific

13. Matrix **cards view** (default): open the nested entry's slideout — the
    button appears on the nested Assets field and the attach works inside the
    slideout.
14. A field layout with a **handle override**: the "only these fields" list
    matches the override handle shown in the layout.
15. Multi-site: an editor without `editSite:` on the entry's site cannot start
    a download for that entry (element save check).

## Download tool (automated)

`tests/craft/e2e.sh <harness-dir>` covers the CP download tool end to end on
a disposable install: nav item + page for admin and for a user with the
"Access Video Downloader" permission, 403 and no nav item without it, inspect
(resolution list, 4K disabled by the ceiling), SSRF and preset validation,
queued download, owner-only file delivery as an attachment, other users
getting 404 for file and status, and the "Download tool" switch. It creates
and removes its own test users. Still manual: the share sheet on a real
iPhone/Android ("Save Video" button → "Save Video" in the share sheet).

## Version/API smoke (per install)

```bash
php craft plugin/install video-downloader   # installs cleanly
# settings page renders, lists top-level + nested Assets fields, saves
```
