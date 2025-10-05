/* Common JS for user_profile plugin */
(function(window, $){
  'use strict';

  // Lightweight inline notifier (avoids blocking browser alerts)
  function notifyInline($anchor, text, type) {
    try {
      var $ref = ($anchor && $anchor.length) ? $anchor : $(document.body);
      // Prefer to append inside actions row or card body for better context
      var $target = $ref.closest('.actions-row');
      if ($target.length === 0) { $target = $ref.closest('.card-body'); }
      if ($target.length === 0) { $target = $ref; }
      var cls = type === 'error' ? 'danger' : (type === 'warning' ? 'warning' : 'success');
      var $msg = $('<div/>')
        .addClass('alert alert-' + cls + ' up-inline-alert')
        .attr('role', 'alert')
        .text(text || '');
      // Remove any existing sibling inline alerts to avoid piling up
      $target.find('.up-inline-alert').remove();
      // Insert message just after anchor if possible, else at the beginning of target
      if ($anchor && $anchor.length) {
        $anchor.after($msg);
      } else {
        $target.prepend($msg);
      }
      // Auto-hide after a short delay
      setTimeout(function(){
        try { $msg.fadeOut(400, function(){ $(this).remove(); }); } catch (e) { $msg.remove(); }
      }, 3000);
    } catch (err) {
      // Fallback to alert if something goes wrong
      try { window.alert(text || ''); } catch (e2) {}
    }
  }

  // Tiny toast helper (always visible, independent of page layout)
  function showToast(text, type) {
    try {
      var cls = type === 'error' ? 'danger' : (type === 'warning' ? 'warning' : 'success');
      var id = 'up-toast-container';
      var el = document.getElementById(id);
      if (!el) {
        el = document.createElement('div');
        el.id = id;
        // Inline styles to avoid CSS dependency
        el.setAttribute('style', 'position:fixed;z-index:2147483647;top:12px;right:12px;display:flex;flex-direction:column;gap:8px;');
        document.body.appendChild(el);
      }
      var item = document.createElement('div');
      var base = 'min-width:200px;max-width:360px;padding:8px 12px;border-radius:4px;color:#fff;font-size:14px;box-shadow:0 2px 6px rgba(0,0,0,.2);opacity:0.95;';
      var color = '#28a745';
      if (cls === 'warning') { color = '#ffc107'; item.style.color = '#212529'; }
      if (cls === 'danger') { color = '#dc3545'; }
      item.setAttribute('style', base + 'background:'+color+';');
      item.textContent = text || '';
      el.appendChild(item);
      setTimeout(function(){ try { el.removeChild(item); } catch(e) {} }, 2800);
    } catch (e) {
      try { window.alert(text || ''); } catch (e2) {}
    }
  }

  function getAjaxUrl() {
    return window.USER_PROFILE_AJAX_URL || (typeof WEB_PLUGIN_PATH !== 'undefined' ? WEB_PLUGIN_PATH + 'user_profile/ajax.php' : '/plugin/user_profile/ajax.php');
  }

  function updateToken(resp) {
    if (resp && resp.token) {
      window.userProfileToken = resp.token;
    }
  }

  function postAction(action, data, callback) {
    var payload = $.extend({}, data || {}, { action: action, sec_token: (window.userProfileToken || '') });
    function safeParseJson(text) {
      var obj = {};
      try { obj = JSON.parse(text); return obj; } catch(e1) {}
      if (typeof text === 'string') {
        var s = text.indexOf('{');
        var e = text.lastIndexOf('}');
        if (s !== -1 && e !== -1 && e > s) {
          try { obj = JSON.parse(text.substring(s, e + 1)); return obj; } catch(e2) {}
        }
      }
      return {};
    }
    try {
      $.ajax({
        url: getAjaxUrl(),
        method: 'POST',
        data: payload,
        dataType: 'text'
      }).done(function(text){
        var resp = safeParseJson(text);
        updateToken(resp);
        if (typeof callback === 'function') { callback(resp || {}); }
      }).fail(function(jq){
        var resp = safeParseJson(jq && jq.responseText ? jq.responseText : '{}');
        if (!resp || typeof resp !== 'object') { resp = { status: 'error' }; }
        if (typeof callback === 'function') { callback(resp); }
      });
    } catch (err) {
      if (typeof callback === 'function') { callback({ status: 'error' }); }
    }
  }

  function equalizeCards() {
    var $cards = $(".user-cards .user-profile.card");
    if ($cards.length === 0) { return; }
    $cards.css('height','auto');
    if ($(window).width() < 768) { return; }
    var maxH = 0;
    $cards.each(function(){ var h = $(this).outerHeight(); if (h > maxH) { maxH = h; } });
    if (maxH > 0) { $cards.css('height', maxH + 'px'); }
  }

  function debounce(fn, wait){ var t; return function(){ var a=arguments, ctx=this; clearTimeout(t); t=setTimeout(function(){ fn.apply(ctx,a); }, wait||100); }; }

  $(function(){
    // Per-page selector
    $(document).on('change', '.per-page-select', function(){
      $('#per-page-form').submit();
    });

    // Toggle untracked checkbox
    $(document).on('change', '.untracked-checkbox', function(){
      var $cb = $(this);
      var $card = $cb.closest('.card.user-profile');
      var userId = parseInt($card.data('user-id'), 10) || 0;
      var fieldId = parseInt($cb.data('field-id'), 10) || 0;
      var checked = $cb.is(':checked') ? 1 : 0;
      var $status = $cb.closest('td').find('.save-status');
      postAction('toggle', { user_id: userId, field_id: fieldId, checked: checked }, function(resp){
        if (resp && resp.status === 'ok') {
          if ($status.length) {
            $status.text('ok').removeClass('d-none').stop(true,true).fadeIn().delay(2000).fadeOut();
          }
        }
      });
    });

    // Warn button: create tickets or send message
    $(document).on('click', '.warn-btn', function(e){
      e.preventDefault();
      var $btn = $(this);
      var $card = $btn.closest('.user-profile');
      // Robust data-user-id retrieval (jQuery data() camelCase and attribute fallback)
      var userId = parseInt($btn.data('user'), 10)
        || parseInt($card.data('userId'), 10)
        || parseInt($card.attr('data-user-id'), 10)
        || 0;
      // Disable button while sending to avoid duplicates
      $btn.prop('disabled', true);
      postAction('warn', { user_id: userId }, function(resp){
        var t = (window.UP_I18N && window.UP_I18N.ticketsCreatedAssigned) || 'Tickets created';
        var m = (window.UP_I18N && window.UP_I18N.messageSent) || 'Message sent';
        var eMsg = (window.UP_I18N && window.UP_I18N.sendError) || 'Error';
        var isOk = (resp && resp.status === 'ok') || (resp && (resp.mode === 'ticket' || resp.mode === 'message'));
        if (isOk) {
          var text = resp.mode === 'ticket' ? t : m;
          notifyInline($btn, text, 'success');
          showToast(text, 'success');
          // Temporary visual feedback on the button
          var orig = $btn.data('orig-text');
          if (!orig) { $btn.data('orig-text', $btn.text()); orig = $btn.text(); }
          $btn.text(text);
          setTimeout(function(){ try { $btn.text(orig); } catch(e){} }, 2500);
        } else if (resp.status === 'no_teacher') {
          var nt = (window.UP_I18N && window.UP_I18N.noTeacher) || 'No teacher';
          notifyInline($btn, nt, 'warning');
          showToast(nt, 'warning');
        } else {
          notifyInline($btn, eMsg, 'error');
          showToast(eMsg, 'error');
        }
        $btn.prop('disabled', false);
      });
    });

    // Agenda reminder
    $(document).on('click', '.agenda-remind-btn', function(e){
      e.preventDefault();
      var $btn = $(this);
      var $card = $btn.closest('.user-profile');
      var userId = parseInt($btn.data('user'), 10)
        || parseInt($card.data('userId'), 10)
        || parseInt($card.attr('data-user-id'), 10)
        || 0;
      $btn.prop('disabled', true);
      postAction('remind_agenda', { user_id: userId }, function(resp){
        var m = (window.UP_I18N && window.UP_I18N.messageSent) || 'Message sent';
        var eMsg = (window.UP_I18N && window.UP_I18N.sendError) || 'Error';
        var sentFlag = !!(resp && resp.debug && resp.debug.remind_agenda && resp.debug.remind_agenda.sent);
        if ((resp && resp.status === 'ok') || sentFlag) {
          notifyInline($btn, m, 'success');
          showToast(m, 'success');
          var orig = $btn.data('orig-text');
          if (!orig) { $btn.data('orig-text', $btn.text()); orig = $btn.text(); }
          $btn.text(m);
          setTimeout(function(){ try { $btn.text(orig); } catch(e){} }, 2500);
        } else {
          notifyInline($btn, eMsg, 'error');
          showToast(eMsg, 'error');
        }
        $btn.prop('disabled', false);
      });
    });

    // Equalize heights
    var eq = debounce(equalizeCards, 100);
    equalizeCards();
    $(window).on('load resize', eq);

    // Open speed_check in popup (guarded inside DOM-ready to ensure jQuery is present)
    $(document).on('click', '.speed-check-open', function(e){
      e.preventDefault();
      var mode = $(this).data('mode') || 'agenda';
      var base = (window.USER_PROFILE_SPEED_CHECK_BASE) || '/plugin/user_profile/speed_check.php';
      var url = base + '?mode=' + encodeURIComponent(mode);
      var features = 'width=1000,height=700,menubar=no,toolbar=no,location=no,status=no,scrollbars=yes,resizable=yes';
      try { window.open(url, 'speed_check_' + mode, features); } catch (err) { window.location.href = url; }
    });

    // Generic popup links handler
    $(document).on('click', 'a.js-popup', function(e){
      e.preventDefault();
      var $a = $(this);
      var url = $a.attr('href') || $a.data('url');
      if (!url) { return; }
      var name = $a.data('popup') || 'popup';
      var w = parseInt($a.data('width'), 10) || 900;
      var h = parseInt($a.data('height'), 10) || 700;
      var features = $a.data('features') || ('width=' + w + ',height=' + h + ',resizable=yes,scrollbars=yes');
      try { window.open(url, name, features); } catch (err) { window.location.href = url; }
    });
  });
})(window, window.jQuery || window.$);
