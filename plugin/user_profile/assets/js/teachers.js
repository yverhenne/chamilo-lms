(function(window, $){
  'use strict';
  $(function(){
    $(document).on('click', '.teacher-item', function(){
      var $item = $(this);
      var $list = $item.closest('.teacher-list');
      var userId = parseInt($list.data('user-id'), 10) || 0;

      $item.toggleClass('checked');
      var teachers = $list.find('.teacher-item.checked').map(function(){ return $(this).data('teacher-id'); }).get();

      // Fallback if common.js is not present
      var postFn = (typeof postAction === 'function') ? postAction : function(action, data, cb){
        data = $.extend({}, data || {}, { action: action, sec_token: (window.userProfileToken || '') });
        $.post(window.USER_PROFILE_AJAX_URL, data, function(resp){ if (resp && resp.token) { window.userProfileToken = resp.token; } if (cb) { cb(resp||{}); } }, 'json');
      };

      postFn('save_teachers', { user_id: userId, teachers: teachers }, function(resp){
        var ok = resp && resp.status === 'ok';
        var msg = ok ? (window.UP_I18N && window.UP_I18N.teacherUpdateSuccess) || 'Update successful' : (window.UP_I18N && window.UP_I18N.teacherUpdateError) || 'Update failed';
        $item.find('.teacher-msg').text(msg).removeClass('text-success text-danger').addClass(ok ? 'text-success' : 'text-danger');
        if (!ok) { $item.toggleClass('checked'); }
        setTimeout(function(){ $item.find('.teacher-msg').text(''); }, 3000);
      });
    });
  });
})(window, window.jQuery || window.$);

