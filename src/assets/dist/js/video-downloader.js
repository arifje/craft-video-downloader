/**
 * Video Downloader — CP integration for Craft 4 and Craft 5.
 *
 * Injects a "Scrape URL" button into Assets fields, opens a modal to collect a
 * social-media video URL, enqueues a server-side yt-dlp download, polls for
 * metadata + live progress, and attaches the finished asset to the field using
 * the same render + selectElements() path Craft itself uses after an upload —
 * so the asset persists on a normal Save and never overwrites the editor's
 * other selections.
 *
 * Version notes:
 *  - Craft 4 renders chips via `elements/get-element-html`; Craft 5 removed it
 *    in favour of `app/render-elements`. The fork keys off the Craft version
 *    the plugin passes along in its settings.
 *  - Field handles are parsed from the element-select's namespaced input name,
 *    which covers top-level fields (`fields[videos]`), Craft 4 Matrix blocks
 *    (`…[blocks][123][fields][videos]`), Craft 5 Matrix entries
 *    (`…[entries][uid:…][fields][videos]`), and slideout namespaces
 *    (`ns123[fields][videos]`) with one pair of patterns.
 */
(function ($) {
  'use strict';

  if (typeof Craft === 'undefined') {
    return;
  }

  var POLL_INTERVAL = 1000; // ms
  var POLL_INTERVAL_SLOW = 2500; // back-off interval once a job runs long
  var POLL_SLOW_AFTER = 20; // polls before backing off
  var POLL_TIMEOUT = 15 * 60 * 1000; // give up after 15 minutes

  /**
   * The plugin settings Craft registers as a JS var. Read lazily on every use
   * so script/var ordering can never freeze us on the defaults.
   */
  function cfg() {
    return window.videoDownloaderSettings || { mode: 'all', handles: [] };
  }

  function isCraft5() {
    return parseInt(String(cfg().craft || '4'), 10) >= 5;
  }

  /**
   * The handle of the Assets field an element-select belongs to, parsed from
   * its input name. Used only for the "list" filter; the server is told which
   * field to use by its numeric id (see fieldIdFor). Note: on Craft 5 a field
   * layout may override the handle — the override is what appears here.
   */
  function fieldHandleFor(instance) {
    var name = instance && instance.settings && instance.settings.name;
    if (!name) {
      return null;
    }
    var m = /^fields\[([^\[\]]+)\]$/.exec(name) || /\[fields\]\[([^\[\]]+)\]$/.exec(name);
    return m ? m[1] : null;
  }

  /** The numeric field id Craft puts on every element-select's JS settings. */
  function fieldIdFor(instance) {
    return instance && instance.settings ? instance.settings.fieldId : null;
  }

  function shouldEnhance(handle) {
    if (!handle) {
      return false;
    }
    var settings = cfg();
    if (settings.mode === 'list') {
      return (settings.handles || []).indexOf(handle) !== -1;
    }
    return true; // 'all'
  }

  /**
   * Whether an Assets field accepts video. Craft passes the field's allowed
   * file kinds to the element-select as `criteria.kind`: empty/absent means no
   * restriction (anything, incl. video); otherwise it must list "video".
   */
  function allowsVideo(instance) {
    var crit = instance && instance.settings && instance.settings.criteria;
    var kinds = crit && crit.kind;
    if (!kinds || !kinds.length) {
      return true; // unrestricted
    }
    return kinds.indexOf('video') !== -1;
  }

  /** Find Assets-field element-selects on the page and add the button. */
  function scan() {
    $('.elementselect').each(function () {
      var $container = $(this);
      if ($container.data('vdEnhanced')) {
        return;
      }
      var instance = $container.data('elementSelect');
      if (!instance || !(instance instanceof Craft.AssetSelectInput)) {
        return; // not an Assets field (entries/categories/etc.)
      }
      if (instance.settings && instance.settings.allowAdd === false) {
        return; // static/read-only rendering (Craft 5) — nothing to add to
      }
      var handle = fieldHandleFor(instance);
      if (!shouldEnhance(handle)) {
        return;
      }
      if (cfg().videoFieldsOnly !== false && !allowsVideo(instance)) {
        return; // skip image-only / non-video fields
      }
      injectButton($container, instance);
      $container.data('vdEnhanced', true);
    });
  }

  function injectButton($container, instance) {
    var $addBtn = $container.find('.btn.add').first();
    var $row = $addBtn.length ? $addBtn.parent() : $container.children('.flex').first();
    if (!$row.length) {
      $row = $('<div class="flex"/>').appendTo($container);
    }

    var $btn = $(
      '<button type="button" class="btn dashed icon vd-scrape-btn" data-icon="download">' +
        Craft.t('app', 'Scrape URL') +
        '</button>'
    );
    $btn.on('click', function () {
      if ($container.data('vdModalOpen')) {
        return; // one modal per field
      }
      openModal($container, instance);
    });

    // Place it after the last real button (Add / Upload files) rather than at
    // the end of the row — Craft 5.8+ can render a search input in the same row.
    var $anchor = $row.children('.btn').last();
    if ($anchor.length) {
      $btn.insertAfter($anchor);
    } else {
      $row.append($btn);
    }
  }

  /* ------------------------------------------------------------------ modal */

  function openModal($container, instance) {
    var uid = Math.random().toString(36).slice(2, 8); // unique radio-group names
    var $modal = $(
      '<form class="modal vd-modal">' +
        '<div class="body">' +
          '<h2 class="vd-title">' + Craft.t('app', 'Scrape video from URL') + '</h2>' +
          '<p class="light vd-intro">' +
            Craft.t('app', 'Paste a link to a social-media video. It will be downloaded on the server and added to this field.') +
          '</p>' +
          '<div class="field"><div class="input ltr">' +
            '<input type="url" class="text fullwidth vd-url" placeholder="https://…" autocomplete="off">' +
          '</div></div>' +
          '<div class="vd-loading" role="status" aria-live="polite" hidden>' +
            '<span class="vd-spinner" aria-hidden="true"></span>' +
            '<span><span class="vd-loading-text">' + Craft.t('app', 'Reading video information…') + '</span>' +
            '<span class="light vd-loading-time"></span></span>' +
          '</div>' +
          '<div class="vd-feedback error" hidden></div>' +
          '<div class="vd-options" hidden>' +
            '<div class="vd-head">' +
              '<div class="vd-thumb vd-othumb" hidden><img alt=""></div>' +
              '<div class="vd-headtext">' +
                '<div class="vd-vtitle vd-otitle"></div>' +
                '<div class="vd-sub vd-osub light"></div>' +
              '</div>' +
            '</div>' +
            '<div class="vd-h">' + Craft.t('app', 'Format') + '</div>' +
            '<div class="vd-choices vd-presets">' +
              '<label class="vd-choice"><input type="radio" name="vd-preset-' + uid + '" value="compatible" checked>' +
                '<span class="vd-ctitle">' + Craft.t('app', 'MP4') + '</span>' +
                '<span class="vd-cdetail light">' + Craft.t('app', 'H.264 + AAC, plays everywhere.') + '</span></label>' +
              '<label class="vd-choice"><input type="radio" name="vd-preset-' + uid + '" value="best">' +
                '<span class="vd-ctitle">' + Craft.t('app', 'Best quality') + '</span>' +
                '<span class="vd-cdetail light">' + Craft.t('app', 'Any codec (VP9, AV1).') + '</span></label>' +
            '</div>' +
            '<div class="vd-h">' + Craft.t('app', 'Resolution') + '</div>' +
            '<div class="vd-choices vd-res"></div>' +
            '<p class="light vd-limits"></p>' +
          '</div>' +
          '<div class="vd-panel" hidden>' +
            '<div class="vd-head">' +
              '<div class="vd-thumb" hidden><img alt=""></div>' +
              '<div class="vd-headtext">' +
                '<div class="vd-stage">' + Craft.t('app', 'Starting…') + '</div>' +
                '<div class="vd-vtitle"></div>' +
                '<div class="vd-sub light"></div>' +
              '</div>' +
            '</div>' +
            '<div class="vd-bar vd-bar--indeterminate"><div class="vd-bar-fill"></div></div>' +
            '<div class="vd-stats light"></div>' +
          '</div>' +
        '</div>' +
        '<div class="footer">' +
          '<div class="buttons right">' +
            '<button type="button" class="btn vd-cancel">' + Craft.t('app', 'Cancel') + '</button>' +
            '<button type="submit" class="btn submit vd-submit">' + Craft.t('app', 'Show options') + '</button>' +
          '</div>' +
        '</div>' +
      '</form>'
    );

    var poll = { timer: null, count: 0 };
    $container.data('vdModalOpen', true);
    var modal = new Garnish.Modal($modal, {
      resizable: false,
      onHide: function () {
        $container.data('vdModalOpen', false);
        if (inspectTimer) {
          clearInterval(inspectTimer);
          inspectTimer = null;
        }
        if (poll.timer) {
          clearTimeout(poll.timer);
          poll.timer = null;
        }
      },
    });

    var $url = $modal.find('.vd-url');
    var $submit = $modal.find('.vd-submit');
    var $cancel = $modal.find('.vd-cancel');
    var $feedback = $modal.find('.vd-feedback');
    var $panel = $modal.find('.vd-panel');
    var $stage = $modal.find('.vd-stage');
    var $vtitle = $modal.find('.vd-vtitle');
    var $sub = $modal.find('.vd-sub');
    var $thumb = $modal.find('.vd-thumb');
    var $bar = $modal.find('.vd-bar');
    var $barFill = $modal.find('.vd-bar-fill');
    var $stats = $modal.find('.vd-stats');
    var $loading = $modal.find('.vd-loading');
    var $loadingTime = $modal.find('.vd-loading-time');
    var $options = $modal.find('.vd-options');
    var $res = $modal.find('.vd-res');
    var $limits = $modal.find('.vd-limits');
    var metaShown = false;
    var submitting = false;
    // 'url' = waiting for "Show options"; 'options' = choices shown, next is Download.
    var step = 'url';
    var inspected = null;
    var inspectTimer = null;

    setTimeout(function () {
      $url.trigger('focus');
    }, 100);

    function error(message) {
      $panel.attr('hidden', true);
      $feedback.attr('hidden', false).text(message);
    }

    function busy(isBusy, label) {
      submitting = isBusy;
      var idle = step === 'options' ? Craft.t('app', 'Download') : Craft.t('app', 'Show options');
      $submit.toggleClass('loading', isBusy).prop('disabled', isBusy).text(label || idle);
      $url.prop('disabled', isBusy);
    }

    function setInspecting(on) {
      $loading.attr('hidden', !on);
      if (inspectTimer) {
        clearInterval(inspectTimer);
        inspectTimer = null;
      }
      $loadingTime.text('');
      if (on) {
        var started = Date.now();
        inspectTimer = setInterval(function () {
          var sec = Math.round((Date.now() - started) / 1000);
          $loadingTime.text(sec >= 3 ? ' ' + sec + ' s' + (sec >= 10 ? ', ' + Craft.t('app', 'some sites take up to a minute') : '') : '');
        }, 1000);
      }
    }

    function backToUrlStep() {
      step = 'url';
      inspected = null;
      $options.attr('hidden', true);
      if (!submitting) {
        busy(false);
      }
    }

    function renderOptions(data) {
      $modal.find('.vd-otitle').text((data.meta && data.meta.title) || data.url);
      var sub = [];
      var meta = data.meta || {};
      if (meta.uploader) sub.push(meta.uploader);
      if (meta.duration) sub.push(formatDuration(meta.duration));
      if (meta.extractor) sub.push(meta.extractor);
      $modal.find('.vd-osub').text(sub.join('  ·  '));
      var $othumb = $modal.find('.vd-othumb');
      $othumb.attr('hidden', true);
      if (meta.thumbnail) {
        var oimg = $othumb.find('img')[0];
        oimg.onload = function () { $othumb.attr('hidden', false); };
        oimg.onerror = function () { $othumb.attr('hidden', true); };
        oimg.src = meta.thumbnail;
      }

      var options = data.options || [];
      if (!options.length) {
        options = [{ resolution: 0, label: Craft.t('app', 'Best available'), allowed: true }];
      }
      $res.empty();
      var picked = false;
      options.forEach(function (o) {
        var bits = [];
        if (o.width && o.height) bits.push(o.width + '×' + o.height);
        if (o.fps) bits.push(o.fps + ' fps');
        if (o.estimatedBytes) bits.push('~' + formatBytes(o.estimatedBytes));
        if (!o.allowed) {
          bits.push(o.reason === 'ceiling'
            ? Craft.t('app', 'Above the {res} limit', { res: data.maxResolution + 'p' })
            : Craft.t('app', 'Over the size limit'));
        }
        var $input = $('<input type="radio">')
          .attr('name', 'vd-res-' + uid)
          .val(String(o.resolution || 0))
          .prop('disabled', !o.allowed);
        if (o.allowed && !picked) {
          $input.prop('checked', true);
          picked = true;
        }
        $('<label class="vd-choice"/>')
          .toggleClass('vd-choice--disabled', !o.allowed)
          .append($input)
          .append($('<span class="vd-ctitle"/>').text(o.label))
          .append($('<span class="vd-cdetail light"/>').text(bits.join('  ·  ')))
          .appendTo($res);
      });

      var limits = [];
      if (data.maxResolution) limits.push(Craft.t('app', 'Limit: {res}.', { res: data.maxResolution + 'p' }));
      limits.push(Craft.t('app', 'Sizes are estimates.'));
      $limits.text(limits.join(' '));

      $options.attr('hidden', false);
      if (!picked) {
        error(Craft.t('app', 'No resolution of this video fits within the server limits.'));
        $submit.prop('disabled', true);
      }
    }

    // Changing the URL invalidates the options shown for the previous one.
    $url.on('input', function () {
      if (step === 'options') {
        backToUrlStep();
        $feedback.attr('hidden', true);
      }
    });

    function applyStatus(data) {
      $feedback.attr('hidden', true);
      $panel.attr('hidden', false);
      $stage.text(stageLabel(data.stage));

      if (data.meta && !metaShown) {
        renderMeta(data.meta);
        metaShown = true;
      }

      var d = data.download;
      if (d && typeof d.percent === 'number') {
        $bar.removeClass('vd-bar--indeterminate');
        $barFill.css('width', Math.max(0, Math.min(100, d.percent)) + '%');
      } else {
        $bar.addClass('vd-bar--indeterminate');
      }
      $stats.text(d ? statsLine(d) : '');
    }

    function renderMeta(meta) {
      $vtitle.text(meta.title || '');
      var sub = [];
      if (meta.uploader) sub.push(meta.uploader);
      if (meta.duration) sub.push(formatDuration(meta.duration));
      if (meta.width && meta.height) sub.push(meta.width + '×' + meta.height);
      if (meta.extractor) sub.push(meta.extractor);
      $sub.text(sub.join('  ·  '));
      if (meta.thumbnail) {
        var img = $thumb.find('img')[0];
        img.onerror = function () { $thumb.attr('hidden', true); };
        img.onload = function () { $thumb.attr('hidden', false); };
        img.src = meta.thumbnail;
      }
    }

    $cancel.on('click', function () {
      modal.hide();
    });

    $modal.on('submit', function (ev) {
      ev.preventDefault();
      if (submitting) {
        return; // no overlapping submissions
      }
      var url = $.trim($url.val());
      if (!url) {
        error(Craft.t('app', 'Please enter a URL.'));
        $url.trigger('focus');
        return;
      }
      if (typeof instance.canAddMoreElements === 'function' && !instance.canAddMoreElements()) {
        error(Craft.t('app', 'This field is full. Remove an asset before downloading another.'));
        return;
      }

      if (step === 'url') {
        // Step 1: read the URL's formats and show the choices.
        $feedback.attr('hidden', true);
        $panel.attr('hidden', true);
        busy(true, Craft.t('app', 'Loading…'));
        setInspecting(true);
        Craft.sendActionRequest('POST', 'video-downloader/download/inspect', {
          data: { url: url, fieldId: fieldIdFor(instance) },
        })
          .then(function (resp) {
            inspected = resp.data;
            step = 'options';
            renderOptions(resp.data);
          })
          .catch(function (err) {
            error(errorMessage(err));
          })
          .then(function () {
            setInspecting(false);
            busy(false);
            if (step === 'options' && !$res.find('input:checked').length) {
              $submit.prop('disabled', true);
            }
          });
        return;
      }

      // Step 2: download the chosen version.
      var preset = $modal.find('input[name="vd-preset-' + uid + '"]:checked').val() || 'compatible';
      var resolution = parseInt($res.find('input:checked').val(), 10) || 0;
      $options.attr('hidden', true);

      busy(true, Craft.t('app', 'Starting…'));
      metaShown = false;
      poll.count = 0;
      $bar.addClass('vd-bar--indeterminate');
      $barFill.css('width', '0%');
      $vtitle.text('');
      $sub.text('');
      $stats.text('');
      $thumb.attr('hidden', true);
      applyStatus({ stage: 'queued', meta: inspected && inspected.meta });

      var ctx = editContext($container);
      Craft.sendActionRequest('POST', 'video-downloader/download/create', {
        data: {
          url: (inspected && inspected.url) || url,
          fieldId: fieldIdFor(instance),
          elementId: ctx.elementId,
          siteId: ctx.siteId,
          preset: preset,
          resolution: resolution,
        },
      })
        .then(function (resp) {
          var jobId = resp.data && resp.data.jobId;
          if (!jobId) {
            throw new Error(Craft.t('app', 'Could not start the download.'));
          }
          startPolling(jobId, Date.now());
        })
        .catch(function (err) {
          busy(false);
          error(errorMessage(err));
        });
    });

    function startPolling(jobId, startedAt) {
      var delay = ++poll.count > POLL_SLOW_AFTER ? POLL_INTERVAL_SLOW : POLL_INTERVAL;
      poll.timer = setTimeout(function () {
        if (Date.now() - startedAt > POLL_TIMEOUT) {
          busy(false);
          error(Craft.t('app', 'Timed out waiting for the download.'));
          return;
        }
        Craft.sendActionRequest('POST', 'video-downloader/download/status', { data: { jobId: jobId } })
          .then(function (resp) {
            var data = resp.data || {};
            if (data.status === 'done') {
              attachAsset(data.result);
            } else if (data.status === 'failed') {
              busy(false);
              error(data.error || Craft.t('app', 'The download failed.'));
              if (inspected) {
                $options.attr('hidden', false);
              }
            } else {
              applyStatus(data);
              startPolling(jobId, startedAt);
            }
          })
          .catch(function (err) {
            busy(false);
            error(errorMessage(err));
          });
      }, delay);
    }

    function attachAsset(result) {
      if (!result || !result.assetId) {
        busy(false);
        error(Craft.t('app', 'The download finished but no asset was returned.'));
        return;
      }
      $stage.text(Craft.t('app', 'Adding to field…'));
      $bar.removeClass('vd-bar--indeterminate');
      $barFill.css('width', '100%');

      var siteId = result.siteId
        || (instance.settings && instance.settings.criteria && instance.settings.criteria.siteId)
        || editContext($container).siteId
        || Craft.siteId;

      fetchElementInfo(instance, result.assetId, siteId)
        .then(function (info) {
          if (typeof instance.canAddMoreElements === 'function' && !instance.canAddMoreElements()) {
            busy(false);
            error(Craft.t('app', 'The video was downloaded, but this field is now full. Add it from the volume after making room.'));
            return;
          }
          // selectElements() is async on Craft 5 — wait for the chip to land.
          return Promise.resolve(instance.selectElements([info])).then(function () {
            modal.hide();
            Craft.cp.displayNotice(Craft.t('app', 'Video added — save to keep it.'));
          });
        })
        .catch(function (err) {
          busy(false);
          error(Craft.t('app', 'The video downloaded but could not be added to the field: ') + errorMessage(err));
        });
    }
  }

  /**
   * Render one asset chip/card and return the element info selectElements()
   * expects. Craft 4 and Craft 5 use different endpoints for this.
   */
  function fetchElementInfo(instance, assetId, siteId) {
    if (!isCraft5()) {
      return Craft.sendActionRequest('POST', 'elements/get-element-html', {
        data: { elementId: assetId, siteId: siteId, context: 'field', thumbSize: 'small' },
      }).then(function (resp) {
        var data = resp.data || {};
        if (data.headHtml) {
          Craft.appendHeadHtml(data.headHtml);
        }
        return Craft.getElementInfo($(data.html));
      });
    }

    // Craft 5: app/render-elements — same call AssetSelectInput makes after an
    // upload, with ui/size derived from the field's view mode.
    var s = instance.settings || {};
    var viewMode = s.viewMode || 'list';
    var chipModes = ['list', 'list-inline', 'large', 'thumbs'];
    var largeModes = ['large', 'thumbs'];
    return Craft.sendActionRequest('POST', 'app/render-elements', {
      data: {
        elements: [{
          type: 'craft\\elements\\Asset',
          id: assetId,
          siteId: siteId,
          instances: [{
            context: 'field',
            ui: chipModes.indexOf(viewMode) !== -1 ? 'chip' : 'card',
            size: largeModes.indexOf(viewMode) !== -1 ? 'large' : 'small',
            showActionMenu: !!s.showActionMenu,
          }],
        }],
      },
    }).then(function (resp) {
      var data = resp.data || {};
      var html = data.elements && data.elements[assetId] && data.elements[assetId][0];
      if (!html) {
        throw new Error(Craft.t('app', 'Could not render the new asset.'));
      }
      var info = Craft.getElementInfo(html);
      if (data.headHtml) {
        Craft.appendHeadHtml(data.headHtml);
      }
      if (data.bodyHtml && Craft.appendBodyHtml) {
        Craft.appendBodyHtml(data.bodyHtml);
      }
      return info;
    });
  }

  /* -------------------------------------------------------------- helpers */

  /**
   * The element id + site id of the element being edited. Prefers the
   * Craft.ElementEditor instance attached to the surrounding form (works in
   * slideouts, where hidden inputs are namespaced); falls back to the hidden
   * inputs with a namespace-tolerant selector.
   */
  function editContext($container) {
    var $form = $container.closest('form');

    var editor = $form.data('elementEditor');
    if (editor && editor.settings) {
      return {
        elementId: editor.settings.elementId || editor.settings.canonicalId || '',
        siteId: editor.settings.siteId || (typeof Craft.siteId !== 'undefined' ? Craft.siteId : ''),
      };
    }

    var elementId =
      $form.find('input[name=elementId], input[name$="[elementId]"]').first().val() ||
      $form.find('input[name=canonicalId], input[name$="[canonicalId]"]').first().val() ||
      '';
    var siteId =
      $form.find('input[name=siteId], input[name$="[siteId]"]').first().val() ||
      (typeof Craft.siteId !== 'undefined' ? Craft.siteId : '');
    return { elementId: elementId, siteId: siteId };
  }

  function stageLabel(stage) {
    switch (stage) {
      case 'extracting':
        return Craft.t('app', 'Reading video info…');
      case 'downloading':
        return Craft.t('app', 'Downloading…');
      case 'saving':
        return Craft.t('app', 'Saving asset…');
      case 'done':
        return Craft.t('app', 'Done');
      default:
        return Craft.t('app', 'Starting…');
    }
  }

  /** "45%  ·  2.8 MB/s  ·  ETA 0:04  ·  2.1 / 5.0 MB" — only the known parts. */
  function statsLine(d) {
    var parts = [];
    if (typeof d.percent === 'number') parts.push(Math.round(d.percent) + '%');
    if (d.speed) parts.push(formatBytes(d.speed) + '/s');
    if (typeof d.eta === 'number') parts.push(Craft.t('app', 'ETA') + ' ' + formatDuration(d.eta));
    if (d.downloaded && d.total) {
      parts.push(formatBytes(d.downloaded) + ' / ' + formatBytes(d.total));
    } else if (d.downloaded) {
      parts.push(formatBytes(d.downloaded));
    }
    return parts.join('  ·  ');
  }

  function formatBytes(n) {
    if (!n && n !== 0) return '';
    var u = ['B', 'KB', 'MB', 'GB', 'TB'];
    var i = 0;
    n = Number(n);
    while (n >= 1024 && i < u.length - 1) {
      n /= 1024;
      i++;
    }
    return (i === 0 ? n : n.toFixed(1)) + ' ' + u[i];
  }

  function formatDuration(sec) {
    sec = Math.max(0, Math.round(Number(sec)));
    var h = Math.floor(sec / 3600);
    var m = Math.floor((sec % 3600) / 60);
    var s = sec % 60;
    var mm = (h > 0 && m < 10 ? '0' : '') + m;
    var ss = (s < 10 ? '0' : '') + s;
    return (h > 0 ? h + ':' : '') + mm + ':' + ss;
  }

  function errorMessage(err) {
    if (err && err.response && err.response.data) {
      return err.response.data.error || err.response.data.message || Craft.t('app', 'Something went wrong.');
    }
    if (err && err.message) {
      return err.message;
    }
    return Craft.t('app', 'Something went wrong.');
  }

  /* ----------------------------------------------------------------- boot */

  Garnish.$doc.ready(function () {
    scan();

    // Fields nested in Matrix / Neo / Super Table blocks may initialise their
    // element-select instance shortly after page load, and attaching that
    // instance isn't a DOM mutation the observer would see — so re-scan a few
    // times. scan() is idempotent (guarded by the vdEnhanced flag).
    [400, 1200, 3000].forEach(function (ms) {
      setTimeout(scan, ms);
    });

    if (typeof MutationObserver !== 'undefined') {
      var t = null;
      var observer = new MutationObserver(function () {
        if (t) {
          clearTimeout(t);
        }
        t = setTimeout(scan, 300);
      });
      observer.observe(document.body, { childList: true, subtree: true });
    }
  });
})(jQuery);
