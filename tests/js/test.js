/**
 * Headless regression tests for src/assets/dist/js/video-downloader.js.
 *
 *   cd tests/js && npm install && npm test
 *
 * Loads the real plugin JS + real jQuery into jsdom with a mocked Craft/Garnish
 * and asserts which element-selects get the "Scrape URL" button across Craft 4
 * and Craft 5 input-name shapes, plus button placement and the modal guard.
 * No Craft installation and no network involved.
 */
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

const SRC = fs.readFileSync(path.join(__dirname, '../../src/assets/dist/js/video-downloader.js'), 'utf8');
const JQUERY = fs.readFileSync(path.join(__dirname, 'node_modules/jquery/dist/jquery.js'), 'utf8');

let pass = 0;
let fail = 0;
const failures = [];

function check(name, ok, detail = '') {
  if (ok) {
    pass++;
    console.log(`  ✓ ${name}`);
  } else {
    fail++;
    failures.push(name + (detail ? ` — ${detail}` : ''));
    console.log(`  ✗ ${name}${detail ? ' — ' + detail : ''}`);
  }
}

/**
 * Boot a page with the given plugin settings + fields, run the plugin JS, fire
 * ready, and hand back helpers.
 *
 * Field spec: { key, name, fieldId, kind (array), allowAdd, withSearchInput }
 */
function boot(settingsVar, fields) {
  const dom = new JSDOM('<!doctype html><html><body></body></html>', { runScripts: 'outside-only' });
  const win = dom.window;
  win.eval(JQUERY);
  const $ = win.jQuery;

  const readyFns = [];
  const modals = [];
  win.Craft = {
    AssetSelectInput: function (s) { this.settings = s; },
    t: (cat, str) => str,
    sendActionRequest: () => new win.Promise(() => {}), // never resolves — enough for guard tests
    siteId: 1,
  };
  win.Garnish = {
    $doc: { ready: (fn) => readyFns.push(fn) },
    Modal: function ($el, opts) {
      this.$el = $el;
      modals.push(this);
      this.opts = opts || {};
      this.hide = () => { this.opts.onHide && this.opts.onHide(); };
    },
  };
  if (settingsVar !== undefined) win.videoDownloaderSettings = settingsVar;

  fields.forEach((f) => {
    const c = win.document.createElement('div');
    c.className = 'elementselect';
    c.setAttribute('data-key', f.key);
    let row = '<div class="flex">';
    row += '<button type="button" class="btn add icon dashed">Add an asset</button>';
    row += '<button type="button" class="btn dashed" data-icon="upload">Upload files</button>';
    if (f.withSearchInput) {
      row += '<div class="texticon search icon elementselect__search-input-wrapper"><input class="text"></div>';
    }
    row += '</div>';
    c.innerHTML = '<div class="elements"></div>' + row;
    win.document.body.appendChild(c);
    const settings = { name: f.name, fieldId: f.fieldId, criteria: { kind: f.kind || [], siteId: 1 } };
    if (f.allowAdd !== undefined) settings.allowAdd = f.allowAdd;
    $(c).data('elementSelect', new win.Craft.AssetSelectInput(settings));
  });

  win.eval(SRC);
  const fireReady = () => readyFns.forEach((fn) => fn());
  const hasBtn = (key) => !!win.document.querySelector(`.elementselect[data-key="${key}"] .vd-scrape-btn`);
  const btnCount = (key) => win.document.querySelectorAll(`.elementselect[data-key="${key}"] .vd-scrape-btn`).length;
  return { win, $, fireReady, hasBtn, btnCount, modals, close: () => win.close() };
}

/* ---- Craft 4 name shapes: list mode filters nested + top-level fields ---- */
console.log('Craft 4 — list mode ["videos"], video filter off');
{
  const t = boot({ mode: 'list', handles: ['videos'], videoFieldsOnly: false, craft: '4.18.1' }, [
    { key: 'top-videos', name: 'fields[videos]', fieldId: 1, kind: ['video'] },
    { key: 'top-images', name: 'fields[images]', fieldId: 2, kind: ['image'] },
    { key: 'block-videos', name: 'fields[media][blocks][197712][fields][videos]', fieldId: 3, kind: ['video'] },
    { key: 'block-images', name: 'fields[media][blocks][197712][fields][images]', fieldId: 4, kind: ['image'] },
  ]);
  t.fireReady();
  check('top-level selected field gets button', t.hasBtn('top-videos'));
  check('top-level unselected field skipped', !t.hasBtn('top-images'));
  check('C4 matrix-nested selected field gets button', t.hasBtn('block-videos'));
  check('C4 matrix-nested unselected field skipped', !t.hasBtn('block-images'));
  t.close();
}

/* --------------------- Craft 5 name shapes + static fields --------------- */
console.log('Craft 5 — entries/uid names, slideouts, allowAdd:false');
{
  const t = boot({ mode: 'list', handles: ['videos'], videoFieldsOnly: false, craft: '5.4.0' }, [
    { key: 'c5-nested', name: 'fields[media][entries][uid:9f1c][fields][videos]', fieldId: 3, kind: ['video'] },
    { key: 'c5-nested-images', name: 'fields[media][entries][uid:9f1c][fields][images]', fieldId: 4, kind: ['image'] },
    { key: 'c5-slideout', name: 'ns12345abc[fields][videos]', fieldId: 3, kind: ['video'] },
    { key: 'c5-static', name: 'fields[videos]', fieldId: 3, kind: ['video'], allowAdd: false },
  ]);
  t.fireReady();
  check('C5 matrix-entry field gets button', t.hasBtn('c5-nested'));
  check('C5 matrix-entry unselected field skipped', !t.hasBtn('c5-nested-images'));
  check('slideout-namespaced field gets button', t.hasBtn('c5-slideout'));
  check('static field (allowAdd:false) skipped', !t.hasBtn('c5-static'));
  t.close();
}

/* --------------------------- video-only filter --------------------------- */
console.log('Video-capable filter (all mode)');
{
  const t = boot({ mode: 'all', handles: [], videoFieldsOnly: true, craft: '4.18.1' }, [
    { key: 'video', name: 'fields[videos]', fieldId: 1, kind: ['video'] },
    { key: 'image', name: 'fields[images]', fieldId: 2, kind: ['image'] },
    { key: 'any', name: 'fields[media]', fieldId: 5, kind: [] },
  ]);
  t.fireReady();
  check('video-restricted field kept', t.hasBtn('video'));
  check('image-restricted field skipped', !t.hasBtn('image'));
  check('unrestricted field kept', t.hasBtn('any'));
  t.close();
}

/* ---------------------- settings var missing = safe ---------------------- */
console.log('Settings var missing (fallback)');
{
  const t = boot(undefined, [
    { key: 'video', name: 'fields[videos]', fieldId: 1, kind: ['video'] },
    { key: 'image', name: 'fields[images]', fieldId: 2, kind: ['image'] },
  ]);
  t.fireReady();
  check('fallback still enhances video fields', t.hasBtn('video'));
  check('fallback still applies the video filter', !t.hasBtn('image'));
  t.close();
}

/* ---------------- late-registered settings var (lazy read) --------------- */
console.log('Settings registered after the script loads');
{
  const t = boot(undefined, [
    { key: 'video', name: 'fields[videos]', fieldId: 1, kind: ['video'] },
  ]);
  // var appears only after script evaluation, before ready — must be honoured
  t.win.videoDownloaderSettings = { mode: 'list', handles: [], videoFieldsOnly: false, craft: '4.18.1' };
  t.fireReady();
  check('late settings are respected (empty list ⇒ no button)', !t.hasBtn('video'));
  t.close();
}

/* -------------------- idempotency + button placement ---------------------- */
console.log('Idempotent rescans + placement');
{
  const t = boot({ mode: 'all', handles: [], videoFieldsOnly: false, craft: '5.4.0' }, [
    { key: 'plain', name: 'fields[videos]', fieldId: 1, kind: ['video'] },
    { key: 'search', name: 'fields[clips]', fieldId: 2, kind: ['video'], withSearchInput: true },
  ]);
  t.fireReady();
  t.fireReady(); // second scan must not duplicate
  check('exactly one button after rescans', t.btnCount('plain') === 1);
  const row = t.win.document.querySelector('.elementselect[data-key="search"] .flex');
  const children = Array.from(row.children).map((el) => el.className.split(' ')[0] + (el.classList.contains('vd-scrape-btn') ? '!scrape' : ''));
  const scrapeIdx = Array.from(row.children).findIndex((el) => el.classList.contains('vd-scrape-btn'));
  const searchIdx = Array.from(row.children).findIndex((el) => el.classList.contains('texticon'));
  check('button lands before the search input, after the buttons', scrapeIdx !== -1 && searchIdx !== -1 && scrapeIdx < searchIdx, JSON.stringify(children));
  t.close();
}

/* ------------------------------ modal guard ------------------------------- */
console.log('Modal open guard');
{
  const t = boot({ mode: 'all', handles: [], videoFieldsOnly: false, craft: '4.18.1' }, [
    { key: 'plain', name: 'fields[videos]', fieldId: 1, kind: ['video'] },
  ]);
  t.fireReady();
  const btn = t.win.document.querySelector('.elementselect[data-key="plain"] .vd-scrape-btn');
  t.$(btn).trigger('click');
  t.$(btn).trigger('click');
  check('double-click opens a single modal', t.modals.length === 1, `modals=${t.modals.length}`);
  t.modals[0].hide();
  t.$(btn).trigger('click');
  check('modal can reopen after closing', t.modals.length === 2, `modals=${t.modals.length}`);
  t.close();
}

/* --------------------------- two-step options flow ----------------------- */
console.log('Field modal: options step');
(async () => {
  const t = boot({ mode: 'all', handles: [], videoFieldsOnly: false, craft: '4.18.1' }, [
    { key: 'plain', name: 'fields[videos]', fieldId: 7, kind: ['video'] },
  ]);
  t.fireReady();
  const calls = [];
  let releaseInspect;
  t.win.Craft.sendActionRequest = (m, action, opts) => {
    calls.push({ action, data: opts && opts.data });
    if (action === 'video-downloader/download/inspect') {
      return new t.win.Promise((res) => { releaseInspect = () => res({ data: {
        success: true, url: 'https://x.com/a/status/1', maxResolution: 1080, maxFilesizeMb: 500,
        meta: { title: 'Portrait clip', uploader: 'DJ', duration: 30, extractor: 'Twitter' },
        options: [
          { resolution: 2160, label: '4K', width: 2160, height: 3840, allowed: false, reason: 'ceiling' },
          { resolution: 1080, label: '1080p', width: 1080, height: 1920, fps: 30, estimatedBytes: 24e6, allowed: true },
          { resolution: 480, label: '480p', width: 480, height: 852, estimatedBytes: 2e6, allowed: true },
        ],
      } }); });
    }
    return new t.win.Promise(() => {}); // create/status: keep pending
  };
  t.$(t.win.document.querySelector('.vd-scrape-btn')).trigger('click');
  const $m = t.modals[0].$el;
  const submit = () => $m.trigger('submit');
  check('submit starts as "Show options"', $m.find('.vd-submit').text() === 'Show options');
  $m.find('.vd-url').val('https://x.com/a/status/1');
  submit();
  check('inspect called with field id', calls[0] && calls[0].action === 'video-downloader/download/inspect' && calls[0].data.fieldId === 7);
  check('loading row visible while inspecting', $m.find('.vd-loading').attr('hidden') === undefined);
  releaseInspect();
  await new Promise((r) => setTimeout(r, 10));
  check('loading row hidden after', $m.find('.vd-loading').attr('hidden') !== undefined);
  check('options visible', $m.find('.vd-options').attr('hidden') === undefined);
  check('video title shown', $m.find('.vd-otitle').text() === 'Portrait clip');
  const radios = $m.find('.vd-res input');
  check('one radio per resolution', radios.length === 3);
  check('4K disabled', radios.eq(0).prop('disabled') === true);
  check('1080p preselected', radios.eq(1).prop('checked') === true);
  check('button now "Download"', $m.find('.vd-submit').text() === 'Download');

  radios.eq(2).prop('checked', true);
  $m.find('input[value="best"]').prop('checked', true);
  submit();
  const create = calls.find((c) => c.action === 'video-downloader/download/create');
  check('create sends chosen resolution + preset', create && create.data.resolution === 480 && create.data.preset === 'best' && create.data.fieldId === 7);
  check('progress panel shows the video title', $m.find('.vd-panel .vd-vtitle').text() === 'Portrait clip');
  t.close();

  // Editing the URL drops the stale options.
  const t2 = boot({ mode: 'all', handles: [], videoFieldsOnly: false, craft: '4.18.1' }, [
    { key: 'plain', name: 'fields[videos]', fieldId: 7, kind: ['video'] },
  ]);
  t2.fireReady();
  t2.win.Craft.sendActionRequest = () => t2.win.Promise.resolve({ data: { success: true, url: 'u', options: [{ resolution: 720, label: '720p', allowed: true }] } });
  t2.$(t2.win.document.querySelector('.vd-scrape-btn')).trigger('click');
  const $m2 = t2.modals[0].$el;
  $m2.find('.vd-url').val('https://a.test/1');
  $m2.trigger('submit');
  await new Promise((r) => setTimeout(r, 10));
  $m2.find('.vd-url').val('https://a.test/2').trigger('input');
  check('editing URL hides options', $m2.find('.vd-options').attr('hidden') !== undefined);
  check('editing URL resets button', $m2.find('.vd-submit').text() === 'Show options');
  t2.close();

  console.log(`\n${pass} passed, ${fail} failed`);
  if (fail > 0) {
    failures.forEach((f) => console.log(`FAIL: ${f}`));
    process.exit(1);
  }
})();
