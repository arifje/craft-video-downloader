/**
 * Video Downloader — CP integration.
 *
 * Injects a "Scrape URL" button into Assets fields, opens a modal to collect a
 * social-media video URL, enqueues a server-side yt-dlp download, polls for the
 * result, and attaches the finished asset to the field using the same
 * get-element-html → selectElements() path Craft itself uses after an upload —
 * so the asset persists on a normal Save.
 */
(function ($) {
  'use strict';

  if (typeof Craft === 'undefined') {
    return;
  }

  var settings = window.videoDownloaderSettings || { mode: 'all', handles: [] };
  var POLL_INTERVAL = 1500; // ms
  var POLL_TIMEOUT = 10 * 60 * 1000; // give up after 10 minutes

  /**
   * The handle of the Assets field an element-select belongs to, parsed from its
   * input name (e.g. "fields[videos]" → "videos"). Returns null for nested
   * contexts we don't target (e.g. Matrix) where the name isn't a plain field.
   */
  function fieldHandleFor(instance) {
    var name = instance && instance.settings && instance.settings.name;
    if (!name) {
      return null;
    }
    var m = /^fields\[([^\[\]]+)\]$/.exec(name);
    return m ? m[1] : null;
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
          '<div class="vd-feedback" hidden></div>' +
        '</div>' +
        '<div class="footer">' +
          '<div class="buttons right">' +
            '<button type="button" class="btn vd-cancel">' + Craft.t('app', 'Cancel') + '</button>' +
            '<button type="submit" class="btn submit vd-submit">' + Craft.t('app', 'Download') + '</button>' +
          '</div>' +
        '</div>' +
      '</form>'
    );

    var modal = new Garnish.Modal($modal, {
      resizable: false,
      onHide: function () {
        if (poll.timer) {
          clearTimeout(poll.timer);
          poll.timer = null;
        }
      },
    });

    var poll = { timer: null };
    var $url = $modal.find('.vd-url');
    var $submit = $modal.find('.vd-submit');
    var $cancel = $modal.find('.vd-cancel');
    var $feedback = $modal.find('.vd-feedback');

    setTimeout(function () {
      $url.trigger('focus');
    }, 100);

    function feedback(type, message) {
      $feedback
        .attr('hidden', false)
        .removeClass('error notice vd-progress')
        .addClass(type)
        .text(message);
    }

    function busy(isBusy, label) {
      $submit
        .toggleClass('loading', isBusy)
        .prop('disabled', isBusy)
        .text(label || Craft.t('app', 'Download'));
      $url.prop('disabled', isBusy);
    }

    $cancel.on('click', function () {
      modal.hide();
    });

    $modal.on('submit', function (ev) {
      ev.preventDefault();
      var url = $.trim($url.val());
      if (!url) {
        feedback('error', Craft.t('app', 'Please enter a URL.'));
        $url.trigger('focus');
        return;
      }

      busy(true, Craft.t('app', 'Starting…'));
      feedback('vd-progress', Craft.t('app', 'Starting…'));

      var ctx = editContext($container);

      Craft.sendActionRequest('POST', 'video-downloader/download/create', {
        data: {
          url: url,
          fieldHandle: handle,
          elementId: ctx.elementId,
          siteId: ctx.siteId,
        },
      })
        .then(function (resp) {
          var jobId = resp.data && resp.data.jobId;
          if (!jobId) {
            throw new Error(Craft.t('app', 'Could not start the download.'));
          }
          feedback('vd-progress', Craft.t('app', 'Downloading…'));
          startPolling(jobId, Date.now());
        })
        .catch(function (err) {
          busy(false);
          feedback('error', errorMessage(err));
        });
    });

    function startPolling(jobId, startedAt) {
      poll.timer = setTimeout(function () {
        if (Date.now() - startedAt > POLL_TIMEOUT) {
          busy(false);
          feedback('error', Craft.t('app', 'Timed out waiting for the download.'));
          return;
        }

        Craft.sendActionRequest('POST', 'video-downloader/download/status', {
          data: { jobId: jobId },
        })
          .then(function (resp) {
            var data = resp.data || {};
            if (data.status === 'done') {
              attachAsset(data.result, modal, feedback, busy);
            } else if (data.status === 'failed') {
              busy(false);
              feedback('error', data.error || Craft.t('app', 'The download failed.'));
            } else {
              feedback('vd-progress', stageLabel(data.stage));
              startPolling(jobId, startedAt);
            }
          })
          .catch(function (err) {
            busy(false);
            feedback('error', errorMessage(err));
          });
      }, POLL_INTERVAL);
    }

    function attachAsset(result, modal, feedback, busy) {
      if (!result || !result.assetId) {
        busy(false);
        feedback('error', Craft.t('app', 'The download finished but no asset was returned.'));
        return;
      }

      feedback('vd-progress', Craft.t('app', 'Adding to field…'));

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
          feedback(
            'error',
            Craft.t('app', 'The video downloaded but could not be added to the field: ') + errorMessage(err)
          );
        });
    }
  }

  /* -------------------------------------------------------------- helpers */

  /** Read the element id + site id of the entry being edited from the form. */
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
      case 'saving':
        return Craft.t('app', 'Saving asset…');
      case 'downloading':
        return Craft.t('app', 'Downloading…');
      default:
        return Craft.t('app', 'Working…');
    }
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

    // Catch fields that render after the initial load (e.g. when a slideout or
    // tab is opened). Debounced so a burst of mutations triggers one scan.
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
