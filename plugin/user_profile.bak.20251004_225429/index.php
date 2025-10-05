<?php
/* For licensing terms, see /license.txt */
require_once __DIR__.'/config.php';
require_once __DIR__.'/UserProfilePlugin.php';

if (!api_get_configuration_value('plugin_user_profile_enabled')) {
    api_not_allowed(true);
}

if (!api_is_anonymous()) {
    $link = api_get_path(WEB_PLUGIN_PATH).'user_profile/view.php?id='.api_get_user_id();
    echo '<p><a href="'.$link.'">'.get_lang('UserProfile').'</a></p>';
}
