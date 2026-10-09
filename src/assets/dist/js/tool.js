/**
 * Video Downloader — standalone CP download tool.
 *
 * Flow: paste a URL → "Show options" asks the server (yt-dlp) which
 * resolutions exist → pick a format preset + resolution → the download is
 * queued and polled → the finished file is delivered to this device:
 *  - phones/tablets with Web Share Level 2 get a "Save Video" button that
 *    opens the native share sheet with the file (the only way a web page can
 *    reach the photo library); the file is prefetched first so the share
 *    call stays inside the tap's user activation;
 *  - everything else gets an automatic download, plus a manual link.
 */
(function () {
  'use strict';

  var root = document.getElementById('vdt');
  if (!root || typeof Craft === 'undefined') {
    return;
  }

  var POLL_INTERVAL = 1000;
  var POLL_INTERVAL_SLOW = 2500;
  var POLL_SLOW_AFTER = 20;
  var POLL_TIMEOUT = 20 * 60 * 1000;
  // Prefetching a file into memory for the share sheet is fine for phone-sized
  // videos; above this we only offer the regular download.
  var SHARE_PREFETCH_LIMIT = 300 * 1024 * 1024;

  var MIME = {
    mp4: 'video/mp4', m4v: 'video/mp4', mov: 'video/quicktime', webm: 'video/webm',
    mkv: 'video/x-matroska', m4a: 'audio/mp4', mp3: 'audio/mpeg', opus: 'audio/ogg',
    ogg: 'audio/ogg', aac: 'audio/aac', wav: 'audio/wav',
  };

  var $ = function (id) { return document.getElementById(id); };
  var el = {
    form: $('vdt-inspect'), url: $('vdt-url'), paste: $('vdt-paste'), inspectBtn: $('vdt-inspect-btn'),
    loading: $('vdt-loading'), loadingTime: $('vdt-loading-time'),
    error: $('vdt-error'), video: $('vdt-video'), thumb: $('vdt-thumb'), title: $('vdt-title'), sub: $('vdt-sub'),
    choose: $('vdt-choose'), res: $('vdt-res'), resWrap: $('vdt-res-wrap'), limits: $('vdt-limits'),
    audioCard: $('vdt-audio-card'), audioDetail: $('vdt-audio-detail'), download: $('vdt-download'),
    progress: $('vdt-progress'), stage: $('vdt-stage'), bar: $('vdt-bar'), barFill: $('vdt-bar-fill'), stats: $('vdt-stats'),
    done: $('vdt-done'), file: $('vdt-file'), share: $('vdt-share'), save: $('vdt-save'), again: $('vdt-again'),
    shareNote: $('vdt-share-note'),
  };

  var state = { url: null, inspected: null, busy: false, pollTimer: null, shareFile: null };

  /* ------------------------------------------------------------ helpers */

  function show(node, visible) { node.hidden = !visible; }

  function t(str) { return Craft.t('app', str); }

  function error(message) {
    el.error.textContent = message;
    show(el.error, !!message);
  }

  function errorMessage(err) {
    if (err && err.response && err.response.data) {
      return err.response.data.error || err.response.data.message || t('Something went wrong.');
    }
    return (err && err.message) || t('Something went wrong.');
  }

  function formatBytes(n) {
    if (!n && n !== 0) return '';
    var u = ['B', 'KB', 'MB', 'GB', 'TB'];
    var i = 0;
    n = Number(n);
    while (n >= 1024 && i < u.length - 1) { n /= 1024; i++; }
    return (i === 0 ? n : n.toFixed(n >= 100 ? 0 : 1)) + ' ' + u[i];
  }

  function formatDuration(sec) {
    sec = Math.max(0, Math.round(Number(sec)));
    var h = Math.floor(sec / 3600), m = Math.floor((sec % 3600) / 60), s = sec % 60;
    return (h > 0 ? h + ':' + (m < 10 ? '0' : '') : '') + m + ':' + (s < 10 ? '0' : '') + s;
  }

  function selectedPreset() {
    var checked = root.querySelector('input[name="vdt-preset"]:checked');
    return checked ? checked.value : 'compatible';
  }

  function selectedResolution() {
    var checked = root.querySelector('input[name="vdt-res"]:checked');
    return checked ? parseInt(checked.value, 10) || 0 : 0;
  }

  function canShareFiles() {
    try {
      return !!(navigator.canShare && navigator.share &&
        navigator.canShare({ files: [new File([''], 'probe.mp4', { type: 'video/mp4' })] }));
    } catch (e) {
      return false;
    }
  }

  function resetBelowUrl() {
    error('');
    show(el.video, false);
    show(el.choose, false);
    show(el.progress, false);
    show(el.done, false);
    state.shareFile = null;
    if (state.pollTimer) { clearTimeout(state.pollTimer); state.pollTimer = null; }
  }

  /* ------------------------------------------------------------ inspect */

  if (navigator.clipboard && navigator.clipboard.readText) {
    show(el.paste, true);
    el.paste.addEventListener('click', function () {
      navigator.clipboard.readText().then(function (text) {
        el.url.value = (text || '').trim();
        if (el.url.value) { el.form.requestSubmit ? el.form.requestSubmit() : el.inspectBtn.click(); }
      }).catch(function () { el.url.focus(); });
    });
  }

  el.form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    if (state.busy) return;
    var url = el.url.value.trim();
    if (!url) { error(t('Please enter a URL.')); el.url.focus(); return; }

    resetBelowUrl();
    state.busy = true;
    setInspecting(true);

    Craft.sendActionRequest('POST', 'video-downloader/tool/inspect', { data: { url: url } })
      .then(function (resp) {
        state.url = resp.data.url;
        state.inspected = resp.data;
        renderVideo(resp.data.meta || {});
        renderOptions(resp.data);
      })
      .catch(function (err) { error(errorMessage(err)); })
      .then(function () {
        state.busy = false;
        setInspecting(false);
      });
  });

  /** Visible "working" state while yt-dlp reads the URL (can take a while). */
  var inspectTimer = null;
  var inspectLabel = el.inspectBtn.textContent;
  function setInspecting(on) {
    show(el.loading, on);
    el.inspectBtn.disabled = on;
    el.url.disabled = on;
    el.paste.disabled = on;
    el.inspectBtn.textContent = on ? t('Loading…') : inspectLabel;
    if (inspectTimer) { clearInterval(inspectTimer); inspectTimer = null; }
    el.loadingTime.textContent = '';
    if (on) {
      var started = Date.now();
      inspectTimer = setInterval(function () {
        var s = Math.round((Date.now() - started) / 1000);
        el.loadingTime.textContent = s >= 3 ? ' ' + s + ' s' + (s >= 10 ? ', ' + t('some sites take up to a minute') : '') : '';
      }, 1000);
    }
  }

  function renderVideo(meta) {
    el.title.textContent = meta.title || state.url;
    var sub = [];
    if (meta.uploader) sub.push(meta.uploader);
    if (meta.duration) sub.push(formatDuration(meta.duration));
    if (meta.extractor) sub.push(meta.extractor);
    el.sub.textContent = sub.join('  ·  ');
    if (meta.thumbnail) {
      el.thumb.onload = function () { show(el.thumb, true); };
      el.thumb.onerror = function () { show(el.thumb, false); };
      el.thumb.src = meta.thumbnail;
    } else {
      show(el.thumb, false);
    }
    show(el.video, true);
  }

  function renderOptions(data) {
    var options = data.options || [];
    var maxRes = data.maxResolution || 0;
    el.res.innerHTML = '';

    if (!options.length) {
      // No dimension info (e.g. a direct file): one "best available" choice.
      options = [{ resolution: 0, label: t('Best available'), allowed: true }];
    }

    var firstAllowed = null;
    options.forEach(function (o) {
      var label = document.createElement('label');
      label.className = 'vdt-card vdt-rescard' + (o.allowed ? '' : ' vdt-card--disabled');

      var input = document.createElement('input');
      input.type = 'radio';
      input.name = 'vdt-res';
      input.value = String(o.resolution || 0);
      input.disabled = !o.allowed;
      if (o.allowed && firstAllowed === null) { firstAllowed = input; }

      var title = document.createElement('span');
      title.className = 'vdt-cardtitle';
      title.textContent = o.label;

      var detail = document.createElement('span');
      detail.className = 'vdt-carddetail light';
      var bits = [];
      if (o.width && o.height) bits.push(o.width + '×' + o.height);
      if (o.fps) bits.push(o.fps + ' fps');
      if (o.estimatedBytes) bits.push('~' + formatBytes(o.estimatedBytes));
      if (o.h264) bits.push('H.264');
      if (!o.allowed) {
        bits.push(o.reason === 'ceiling'
          ? Craft.t('app', 'Above the {res} server limit', { res: maxRes + 'p' })
          : t('Larger than the server size limit'));
      }
      detail.textContent = bits.join('  ·  ');

      label.appendChild(input);
      label.appendChild(title);
      label.appendChild(detail);
      el.res.appendChild(label);
    });

    var limits = [];
    if (maxRes) limits.push(Craft.t('app', 'Server limit: {res} (portrait up to {w}×{h}).', { res: maxRes + 'p', w: maxRes, h: Math.round(maxRes * 16 / 9) }));
    if (data.maxFilesizeMb) limits.push(Craft.t('app', 'Max file size: {mb} MB.', { mb: data.maxFilesizeMb }));
    limits.push(t('Sizes are estimates.'));
    el.limits.textContent = limits.join(' ');

    var audio = data.audio;
    var audioInput = el.audioCard.querySelector('input');
    audioInput.disabled = !audio || audio.allowed === false;
    el.audioCard.classList.toggle('vdt-card--disabled', audioInput.disabled);
    if (audio && audio.estimatedBytes) {
      el.audioDetail.textContent = t('Just the soundtrack, M4A when available.') + '  ·  ~' + formatBytes(audio.estimatedBytes);
    }

    if (firstAllowed) {
      firstAllowed.checked = true;
      el.download.disabled = false;
    } else {
      el.download.disabled = selectedPreset() !== 'audio';
      error(t('No resolution of this video fits within the server limits.'));
    }
    syncPreset();
    show(el.choose, true);
  }

  function syncPreset() {
    var audio = selectedPreset() === 'audio';
    show(el.resWrap, !audio);
    if (audio) {
      el.download.disabled = false;
    } else {
      el.download.disabled = !root.querySelector('input[name="vdt-res"]:checked');
    }
  }
  root.querySelectorAll('input[name="vdt-preset"]').forEach(function (input) {
    input.addEventListener('change', syncPreset);
  });

  /* ----------------------------------------------------------- download */

  el.download.addEventListener('click', function () {
    if (state.busy || !state.url) return;
    state.busy = true;
    error('');
    show(el.choose, false);
    show(el.done, false);
    applyProgress({ stage: 'queued' });
    show(el.progress, true);

    Craft.sendActionRequest('POST', 'video-downloader/tool/create', {
      data: { url: state.url, preset: selectedPreset(), resolution: selectedResolution() },
    })
      .then(function (resp) {
        if (!resp.data || !resp.data.jobId) throw new Error(t('Could not start the download.'));
        poll(resp.data.jobId, Date.now(), 0);
      })
      .catch(function (err) { fail(errorMessage(err)); });
  });

  function poll(jobId, startedAt, count) {
    var delay = count > POLL_SLOW_AFTER ? POLL_INTERVAL_SLOW : POLL_INTERVAL;
    state.pollTimer = setTimeout(function () {
      if (Date.now() - startedAt > POLL_TIMEOUT) { fail(t('Timed out waiting for the download.')); return; }
      Craft.sendActionRequest('POST', 'video-downloader/download/status', { data: { jobId: jobId } })
        .then(function (resp) {
          var d = resp.data || {};
          if (d.status === 'done') { finish(jobId, d.result || {}); }
          else if (d.status === 'failed') { fail(d.error || t('The download failed.')); }
          else { applyProgress(d); poll(jobId, startedAt, count + 1); }
        })
        .catch(function (err) { fail(errorMessage(err)); });
    }, delay);
  }

  function applyProgress(d) {
    var labels = { downloading: t('Downloading…'), saving: t('Preparing your file…'), done: t('Done') };
    el.stage.textContent = labels[d.stage] || t('Waiting in the queue…');
    var p = d.download;
    if (p && typeof p.percent === 'number') {
      el.bar.classList.remove('vdt-bar--indeterminate');
      el.barFill.style.width = Math.max(0, Math.min(100, p.percent)) + '%';
    } else {
      el.bar.classList.add('vdt-bar--indeterminate');
    }
    var bits = [];
    if (p) {
      if (typeof p.percent === 'number') bits.push(Math.round(p.percent) + '%');
      if (p.speed) bits.push(formatBytes(p.speed) + '/s');
      if (typeof p.eta === 'number') bits.push(t('ETA') + ' ' + formatDuration(p.eta));
      if (p.downloaded && p.total) bits.push(formatBytes(p.downloaded) + ' / ' + formatBytes(p.total));
    }
    el.stats.textContent = bits.join('  ·  ');
  }

  function fail(message) {
    state.busy = false;
    show(el.progress, false);
    error(message);
    if (state.inspected) { show(el.choose, true); }
  }

  function finish(jobId, result) {
    state.busy = false;
    show(el.progress, false);

    var fileUrl = Craft.getActionUrl('video-downloader/tool/file', { jobId: jobId });
    var filename = result.filename || 'video.mp4';
    el.save.href = fileUrl;
    el.save.setAttribute('download', filename);
    el.file.textContent = filename + (result.size ? '  ·  ' + formatBytes(result.size) : '');
    show(el.done, true);

    if (canShareFiles() && (!result.size || result.size <= SHARE_PREFETCH_LIMIT)) {
      prepareShare(fileUrl, filename, result.ext);
    } else {
      // Desktop (or a file too big to hand to the share sheet): just download.
      el.save.click();
    }
  }

  function prepareShare(fileUrl, filename, ext) {
    show(el.share, true);
    show(el.shareNote, true);
    el.share.disabled = true;
    el.share.classList.add('loading');

    fetch(fileUrl, { credentials: 'same-origin' })
      .then(function (r) {
        if (!r.ok) throw new Error(t('Could not prepare the file for sharing.'));
        return r.blob();
      })
      .then(function (blob) {
        var type = MIME[(ext || '').toLowerCase()] || blob.type || 'video/mp4';
        state.shareFile = new File([blob], filename, { type: type });
        if (!navigator.canShare({ files: [state.shareFile] })) {
          throw new Error(t('This device can’t share this file type. Use “Download file” instead.'));
        }
        el.share.disabled = false;
      })
      .catch(function (err) {
        show(el.share, false);
        show(el.shareNote, false);
        error(errorMessage(err));
      })
      .then(function () { el.share.classList.remove('loading'); });
  }

  el.share.addEventListener('click', function () {
    if (!state.shareFile) return;
    navigator.share({ files: [state.shareFile], title: state.shareFile.name }).catch(function (err) {
      if (err && err.name !== 'AbortError') { error(errorMessage(err)); }
    });
  });

  el.again.addEventListener('click', function () {
    resetBelowUrl();
    state.url = null;
    state.inspected = null;
    el.url.value = '';
    el.url.focus();
  });
})();
