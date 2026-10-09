/**
 * Headless tests for the standalone download tool (src/assets/dist/js/tool.js),
 * driven against the real tool template markup with a mocked Craft backend.
 *
 *   cd tests/js && npm install && node test-tool.js
 */
const fs = require('fs');
const path = require('path');
const { JSDOM } = require('jsdom');

const SRC = fs.readFileSync(path.join(__dirname, '../../src/assets/dist/js/tool.js'), 'utf8');
const TWIG = fs.readFileSync(path.join(__dirname, '../../src/templates/tool/index.twig'), 'utf8');

// Reduce the Twig template to plain HTML: keep the content block, drop tags,
// turn `{{ 'Text'|t('app') }}` into Text and `{{ var }}` into a value.
function templateHtml() {
  const body = TWIG.split('{% block content %}')[1].split('{% endblock %}')[0];
  return body
    .replace(/\{\{\s*'([^']*)'\|t\('app'\)\s*\}\}/g, '$1')
    .replace(/\{\{\s*maxResolution\s*\}\}/g, '1080')
    .replace(/\{\{\s*maxFilesizeMb\s*\}\}/g, '500')
    .replace(/\{%[^%]*%\}/g, '');
}

const INSPECT = {
  success: true,
  url: 'https://videos.example.test/v/1',
  meta: { title: 'Clip', uploader: 'Someone', duration: 75, extractor: 'Fake', thumbnail: '' },
  options: [
    { resolution: 2160, label: '4K', width: 3840, height: 2160, fps: 30, estimatedBytes: 9e6, h264: false, allowed: false, reason: 'ceiling' },
    { resolution: 1080, label: '1080p', width: 1920, height: 1080, fps: 60, estimatedBytes: 3.2e6, h264: true, allowed: true, reason: null },
    { resolution: 360, label: '360p', width: 640, height: 360, fps: 30, estimatedBytes: 6e5, h264: true, allowed: true, reason: null },
  ],
  audio: { estimatedBytes: 2e5, ext: 'm4a', allowed: true },
  maxResolution: 1080,
  maxFilesizeMb: 500,
};

let pass = 0;
let fail = 0;
const failures = [];
function check(name, ok, detail = '') {
  if (ok) { pass++; console.log(`  ✓ ${name}`); } else { fail++; failures.push(name); console.log(`  ✗ ${name}${detail ? ' — ' + detail : ''}`); }
}

const tick = (win, ms = 0) => new Promise((r) => win.setTimeout(r, ms));

/** Boot the page. `share` = simulate a phone with Web Share Level 2. */
function boot({ share = false } = {}) {
  const dom = new JSDOM(`<!doctype html><html><body>${templateHtml()}</body></html>`, {
    runScripts: 'outside-only', url: 'https://cms.example.test/admin/video-downloader',
  });
  const win = dom.window;
  const calls = [];
  const statusQueue = [];
  win.Craft = {
    t: (cat, str, params) => (params ? str.replace(/\{(\w+)\}/g, (m, k) => params[k]) : str),
    getActionUrl: (action, params) => `/actions/${action}?jobId=${params.jobId}`,
    sendActionRequest: (method, action, opts) => {
      calls.push({ action, data: opts && opts.data });
      if (action === 'video-downloader/tool/inspect') return Promise.resolve({ data: INSPECT });
      if (action === 'video-downloader/tool/create') return Promise.resolve({ data: { success: true, jobId: 'a'.repeat(32) } });
      if (action === 'video-downloader/download/status') return Promise.resolve({ data: statusQueue.shift() || { status: 'running', stage: 'downloading' } });
      return Promise.reject(new Error('unexpected ' + action));
    },
  };
  const clicked = [];
  win.HTMLAnchorElement.prototype.click = function () { clicked.push(this.getAttribute('href')); };
  const shared = [];
  if (share) {
    win.navigator.canShare = () => true;
    win.navigator.share = (d) => { shared.push(d); return Promise.resolve(); };
    win.fetch = () => Promise.resolve({ ok: true, blob: () => Promise.resolve(new win.Blob(['x'], { type: 'video/mp4' })) });
  }
  win.eval(SRC);
  const q = (sel) => win.document.querySelector(sel);
  return { win, q, calls, statusQueue, clicked, shared };
}

async function inspect(t) {
  t.q('#vdt-url').value = 'https://videos.example.test/v/1';
  t.q('#vdt-inspect').dispatchEvent(new t.win.Event('submit', { cancelable: true }));
  await tick(t.win, 10);
}

(async () => {
  console.log('Inspect + options');
  {
    const t = boot();
    await inspect(t);
    const radios = [...t.win.document.querySelectorAll('input[name="vdt-res"]')];
    check('one radio per resolution', radios.length === 3);
    check('4K shown but disabled', radios[0].disabled === true && /server limit/.test(radios[0].closest('label').textContent));
    check('highest allowed (1080p) preselected', radios[1].checked === true);
    check('choose panel visible', t.q('#vdt-choose').hidden === false);
    check('video card shows title', t.q('#vdt-title').textContent === 'Clip');
    check('limits line mentions ceiling + portrait', /1080p/.test(t.q('#vdt-limits').textContent) && /1080×1920/.test(t.q('#vdt-limits').textContent));
    check('estimated size shown', /~3\.1 MB/.test(radios[1].closest('label').textContent));

    // Audio preset hides the resolution list.
    const audio = t.q('input[name="vdt-preset"][value="audio"]');
    audio.checked = true;
    audio.dispatchEvent(new t.win.Event('change'));
    check('audio preset hides resolutions', t.q('#vdt-res-wrap').hidden === true);
  }

  console.log('Download (desktop): auto-download');
  {
    const t = boot();
    await inspect(t);
    t.q('input[name="vdt-res"][value="360"]').checked = true;
    t.statusQueue.push({ status: 'running', stage: 'downloading', download: { percent: 50, downloaded: 1, total: 2 } });
    t.statusQueue.push({ status: 'done', result: { filename: 'Clip [1].mp4', size: 600000, ext: 'mp4' } });
    t.q('#vdt-download').click();
    await tick(t.win, 10);
    const create = t.calls.find((c) => c.action === 'video-downloader/tool/create');
    check('create sends url, preset, resolution', create && create.data.url === INSPECT.url && create.data.preset === 'compatible' && create.data.resolution === 360);
    check('progress visible while running', t.q('#vdt-progress').hidden === false);
    await tick(t.win, 1100);
    check('progress reflects percent', /50%/.test(t.q('#vdt-stats').textContent));
    await tick(t.win, 1100);
    check('done panel visible', t.q('#vdt-done').hidden === false);
    check('download link points at owner-scoped file action', t.q('#vdt-save').getAttribute('href') === '/actions/video-downloader/tool/file?jobId=' + 'a'.repeat(32));
    check('download attribute carries the filename', t.q('#vdt-save').getAttribute('download') === 'Clip [1].mp4');
    check('desktop auto-starts the download', t.clicked.length === 1);
    check('no share button on desktop', t.q('#vdt-share').hidden === true);
  }

  console.log('Download (phone): Save to Photos via share sheet');
  {
    const t = boot({ share: true });
    await inspect(t);
    t.statusQueue.push({ status: 'done', result: { filename: 'Clip [1].mp4', size: 600000, ext: 'mp4' } });
    t.q('#vdt-download').click();
    await tick(t.win, 1200);
    check('share button shown', t.q('#vdt-share').hidden === false);
    check('share button enabled after prefetch', t.q('#vdt-share').disabled === false);
    check('no auto-download on phone', t.clicked.length === 0);
    t.q('#vdt-share').click();
    await tick(t.win, 5);
    check('share sheet gets the video file', t.shared.length === 1 && t.shared[0].files[0].name === 'Clip [1].mp4' && t.shared[0].files[0].type === 'video/mp4');
  }

  console.log('CSS guard');
  {
    // Craft's .btn { display: inline-flex } beats the UA [hidden] rule, which
    // once left "Save to Photos" visible on desktops that can't share files.
    const css = fs.readFileSync(path.join(__dirname, '../../src/assets/dist/css/tool.css'), 'utf8');
    check('tool.css forces [hidden] inside the tool', /\.vdt \[hidden\]\s*\{\s*display:\s*none\s*!important/.test(css));
  }

  console.log('Failure path');
  {
    const t = boot();
    await inspect(t);
    t.statusQueue.push({ status: 'failed', error: 'yt-dlp failed: ERROR: nope' });
    t.q('#vdt-download').click();
    await tick(t.win, 1200);
    check('error shown', /nope/.test(t.q('#vdt-error').textContent) && t.q('#vdt-error').hidden === false);
    check('options visible again to retry', t.q('#vdt-choose').hidden === false);
  }

  console.log(`\n${pass} passed, ${fail} failed`);
  if (fail) { failures.forEach((f) => console.log('FAIL: ' + f)); process.exit(1); }
})();
