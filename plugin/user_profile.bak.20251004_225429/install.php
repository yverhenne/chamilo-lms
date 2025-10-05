<?php
/* For licensing terms, see /license.txt */
require_once __DIR__.'/config.php';
require_once __DIR__.'/UserProfilePlugin.php';

if (!api_is_platform_admin()) {
    exit('You must have admin permissions to install plugins');
}
UserProfilePlugin::create()->install();
