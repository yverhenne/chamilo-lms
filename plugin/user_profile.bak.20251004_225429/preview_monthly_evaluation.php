<?php
/* For licensing terms, see /license.txt */
require_once __DIR__.'/config.php';
require_once __DIR__.'/UserProfilePlugin.php';
require_once api_get_path(LIBRARY_PATH).'MyStudents.php';
require_once api_get_path(LIBRARY_PATH).'sessionmanager.lib.php';
require_once api_get_path(LIBRARY_PATH).'tracking.lib.php';
require_once api_get_path(LIBRARY_PATH).'course.lib.php';

if (!api_get_configuration_value('plugin_user_profile_enabled')) {
    api_not_allowed(true);
}

// Restrict access to platform admins or session admins (same as admin page)
if (!(api_is_platform_admin() || api_is_session_admin())) {
    api_not_allowed(true);
}

global $htmlHeadXtra;
$htmlHeadXtra[] = '<script src="'.api_get_path(WEB_PUBLIC_PATH)
    .'assets/jquery.easy-pie-chart/dist/jquery.easypiechart.js"></script>';
$htmlHeadXtra[] = '<style>
    .user-profile.card { border: 1px solid #eee; border-radius: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); margin-bottom: 20px; margin-top: 10px; }
    .user-profile .card-title { font-weight: bold; text-align: center; background: #E1F0F5; margin: 0; padding: 10px; }
    .user-profile .card-title span { display: block; background: #E1F0F5; border-radius: 5px; padding: 5px; }
    .list-group-item { border: 0; font-size: 14px; }
    .comment-body { white-space: pre-wrap; }
    .monthly-evaluation__identity { display: flex; flex-wrap: wrap; gap: 20px; margin-bottom: 20px; }
    .monthly-evaluation__identity-column { flex: 1 1 calc(50% - 10px); min-width: 260px; }
    .monthly-evaluation__identity-column .card { height: 100%; margin: 0; }
    /* Hide any user profile tracking link if present in footer or elsewhere */
    a[href*="plugin/user_profile/view.php"],
    a[href*="user_profile/view.php"] { display: none !important; }
  </style>';

$studentId = isset($_GET['student']) ? (int) $_GET['student'] : 0;
$evalId    = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($studentId <= 0 || $evalId <= 0) {
    api_not_allowed(true);
}

$info = api_get_user_info($studentId);
if (empty($info)) {
    api_not_allowed(true);
}

$plugin = UserProfilePlugin::create();
$urlId = (int) api_get_current_access_url_id();

// Build evaluation body using shared renderer
$body = $plugin->renderMonthlyEvaluationBody($studentId, $evalId, true);

if ($body === null) {
    Display::display_header(UserProfilePlugin::create()->get_lang('MonthlyEvaluation'));
    echo Display::return_message(UserProfilePlugin::create()->get_lang('NoResultsFound'), 'warning');
    Display::display_footer();
    exit;
}

$title = UserProfilePlugin::create()->get_lang('MonthlyEvaluation');
if (preg_match('/^\[.*\]$/', $title)) {
    $title = 'Monthly evaluation';
}
Display::display_header($title);

$pdfUrl = api_get_path(WEB_PLUGIN_PATH).'user_profile/preview_monthly_evaluation_pdf.php?student='.$studentId.'&id='.$evalId;

echo '<div class="text-right mb-3">'.
    '<a class="btn btn-link" href="'.Security::remove_XSS($pdfUrl).'" target="_blank" rel="noopener" title="'.get_lang('ExportToPdf').'">'.
    Display::return_icon('icons/32/export_pdf.png', get_lang('ExportToPdf')).
    '</a>'.
    '</div>';

echo $body;

Display::display_footer();

