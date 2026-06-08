/**
 * Video Downloader — CP integration.
 *
 * Injects a "Scrape URL" button into Assets fields, opens a modal to collect a
 * social-media video URL, enqueues a server-side yt-dlp download, polls for
 * metadata + live progress, and attaches the finished asset to the field using
 * the same get-element-html → selectElements() path Craft itself uses after an
 * upload — so the asset persists on a normal Save.
 */
(function ($) {
  'use strict';

  if (typeof Craft === 'undefined') {
    return;
  }

  var settings = window.videoDownloaderSettings || { mode: 'all', handles: [] };
  var POLL_INTERVAL = 1000; // ms
  var POLL_TIMEOUT = 15 * 60 * 1000; // give up after 15 minutes

  /**
   * The handle of the Assets field an element-select belongs to, parsed from its
   * input name. Handles both top-level fields ("fields[videos]" → "videos") and
   * fields nested in Matrix/Neo/Super Table blocks, whose name ends in
   * "…[fields][blockVideo]" → "blockVideo". Used only for the "list" filter; the
   * server is told which field to use by its numeric id (see fieldIdFor).
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
    if (settings.mode === 'list') {
      return (settings.handles || []).indexOf(handle) !== -1;
    }
    return true; // 'all'
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
      var handle = fieldHandleFor(instance);
      if (!shouldEnhance(handle)) {
        return;
      }
      injectButton($container, instance, handle);
      $container.data('vdEnhanced', true);
    });
  }

  function injectButton($container, instance, handle) {
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
      openModal($container, instance, handle);
    });
    $row.append($btn);
  }

  /* ------------------------------------------------------------------ modal */

  function openModal($container, instance, handle) {
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
          '<div class="vd-feedback error" hidden></div>' +
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
            '<button type="submit" class="btn submit vd-submit">' + Craft.t('app', 'Download') + '</button>' +
          '</div>' +
        '</div>' +
      '</form>'
    );

    var poll = { timer: null };
    var modal = new Garnish.Modal($modal, {
      resizable: false,
      onHide: function () {
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
    var metaShown = false;

    setTimeout(function () {
      $url.trigger('focus');
    }, 100);

    function error(message) {
      $panel.attr('hidden', true);
      $feedback.attr('hidden', false).text(message);
    }

    function busy(isBusy, label) {
      $submit.toggleClass('loading', isBusy).prop('disabled', isBusy).text(label || Craft.t('app', 'Download'));
      $url.prop('disabled', isBusy);
    }

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
      var url = $.trim($url.val());
      if (!url) {
        error(Craft.t('app', 'Please enter a URL.'));
        $url.trigger('focus');
        return;
      }
      busy(true, Craft.t('app', 'Starting…'));
      metaShown = false;
      $bar.addClass('vd-bar--indeterminate');
      $barFill.css('width', '0%');
      $vtitle.text('');
      $sub.text('');
      $stats.text('');
      $thumb.attr('hidden', true);
      applyStatus({ stage: 'queued' });

      var ctx = editContext($container);
      Craft.sendActionRequest('POST', 'video-downloader/download/create', {
        data: { url: url, fieldId: fieldIdFor(instance), elementId: ctx.elementId, siteId: ctx.siteId },
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
            } else {
              applyStatus(data);
              startPolling(jobId, startedAt);
            }
          })
          .catch(function (err) {
            busy(false);
            error(errorMessage(err));
          });
      }, POLL_INTERVAL);
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

      Craft.sendActionRequest('POST', 'elements/get-element-html', {
        data: {
          elementId: result.assetId,
          siteId: result.siteId || editContext($container).siteId,
          context: 'field',
          thumbSize: 'small',
        },
      })
        .then(function (resp) {
          var data = resp.data || {};
          if (data.headHtml) {
            Craft.appendHeadHtml(data.headHtml);
          }
          var $element = $(data.html);
          var info = Craft.getElementInfo($element);
          instance.selectElements([info]);
          modal.hide();
          Craft.cp.displayNotice(Craft.t('app', 'Video added — Save the entry to keep it.'));
        })
        .catch(function (err) {
          busy(false);
          error(Craft.t('app', 'The video downloaded but could not be added to the field: ') + errorMessage(err));
        });
    }
  }

  /* -------------------------------------------------------------- helpers */

  function editContext($container) {
    var $form = $container.closest('form');
    var elementId =
      $form.find('input[name=elementId]').val() ||
      $form.find('input[name=draftId]').val() ||
      $form.find('input[name=canonicalId]').val() ||
      '';
    var siteId = $form.find('input[name=siteId]').val() || (typeof Craft.siteId !== 'undefined' ? Craft.siteId : '');
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
