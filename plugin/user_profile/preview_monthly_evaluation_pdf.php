<?php
/* For licensing terms, see /license.txt */
use Chamilo\CoreBundle\Component\Utils\ChamiloApi;

require_once __DIR__.'/config.php';
require_once __DIR__.'/UserProfilePlugin.php';

if (!api_get_configuration_value('plugin_user_profile_enabled')) {
    api_not_allowed(true);
}

if (!(api_is_platform_admin() || api_is_session_admin())) {
    api_not_allowed(true);
}

$studentId = isset($_GET['student']) ? (int) $_GET['student'] : 0;
$evalId = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($studentId <= 0 || $evalId <= 0) {
    api_not_allowed(true);
}

$info = api_get_user_info($studentId);
if (empty($info)) {
    api_not_allowed(true);
}

$plugin = UserProfilePlugin::create();
$body = $plugin->renderMonthlyEvaluationBody($studentId, $evalId, false, true);

if ($body === null) {
    api_not_allowed(true);
}

$title = UserProfilePlugin::create()->get_lang('MonthlyEvaluation');
if (preg_match('/^\[.*\]$/', $title)) {
    $title = 'Monthly evaluation';
}

$studentNameParts = array_filter([
    $info['firstname'] ?? '',
    $info['lastname'] ?? '',
]);
$studentName = trim(implode(' ', $studentNameParts));
$subtitle = $studentName !== '' ? Security::remove_XSS($studentName) : '';

$logo = ChamiloApi::getPlatformLogoPath('', true);
$header = '<div class="pdf-header" style="text-align:right;"><img src="'.$logo.'" height="50" alt="logo"></div>';
$date = api_format_date(api_get_local_time(), DATE_TIME_FORMAT_LONG);
$footer = '<table class="pdf-footer" width="100%"><tr><td>'.$date.'</td><td style="text-align:right">{PAGENO}/{nb}</td></tr></table>';

$view = new Template('', false, false, false, false, true, false);
$view->assign('title', Security::remove_XSS($title));
$view->assign('subtitle', $subtitle);
$view->assign('evaluation_body', $body);

$html = $view->fetch('user_profile/view/monthly_evaluation_pdf.tpl');

$css = <<<'CSS'
body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; color: #333; background-color: #fff; }
.pdf-header, .pdf-footer { font-size: 10px; color: #666; }
.pdf-footer { margin-top: 24px; border-top: 1px solid #dbe1e6; padding-top: 8px; }
.monthly-evaluation-pdf { padding: 8px 0; }
.monthly-evaluation-pdf__title { font-size: 20px; margin: 0 0 6px; text-align: center; font-weight: 600; color: #2a4a5c; }
.monthly-evaluation-pdf__subtitle { text-align: center; font-size: 13px; margin-bottom: 16px; color: #54697a; }
.monthly-evaluation { padding: 4px 0; }
.monthly-evaluation--pdf .card, .monthly-evaluation--pdf .panel { box-shadow: none; border-color: #dbe1e6; }
.card { background-color: #fff; border: 1px solid #dbe1e6; border-radius: 6px; margin: 0 0 16px; }
.user-profile.card { margin-top: 0; }
.card-body { padding: 14px; }
.card-title { font-weight: 600; text-align: center; background: #e1f0f5; margin: 0; padding: 10px 12px; }
.list-group { list-style: none; margin: 0; padding: 0; }
.list-group-item { border: 0; font-size: 13px; padding: 6px 0; border-bottom: 1px solid #e5edf2; }
.list-group-item:last-child { border-bottom: 0; }
.comment-body { white-space: pre-wrap; line-height: 1.45; }
.panel { border: 1px solid #dbe1e6; border-radius: 6px; margin: 0 0 16px; background: #fff; }
.panel-heading { background: #f3f7fa; padding: 10px 12px; font-weight: 600; border-bottom: 1px solid #dbe1e6; }
.panel-body { padding: 12px; }
.text-center { text-align: center; }
.avg-progress-pdf { display: flex; justify-content: center; align-items: center; padding: 18px 0; }
.avg-progress-value { display: inline-block; min-width: 80px; border: 2px solid #3ba557; border-radius: 999px; padding: 8px 18px; font-size: 20px; font-weight: 600; color: #3ba557; }
.progress { background-color: #eef3f6; border-radius: 999px; height: 16px; overflow: hidden; margin-bottom: 8px; }
.progress-bar { background: #3ba557; color: #fff; font-size: 11px; line-height: 16px; text-align: center; }
.progress-bar-success { background: #3ba557; }
.session-progress-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.session-progress-table__label { padding: 6px 8px; border-bottom: 1px solid #e5edf2; width: 70%; }
.session-progress-table__value { padding: 6px 8px; border-bottom: 1px solid #e5edf2; text-align: right; font-weight: 600; color: #3ba557; }
.session-progress-table tr:last-child td { border-bottom: 0; }
CSS;

$pdf = new PDF('A4', 'P');
$pdf->set_custom_header($header);
$pdf->set_custom_footer($footer);
$studentIdentifier = !empty($info['username']) ? $info['username'] : (string) $studentId;
$pdf->params['filename'] = 'monthly_evaluation_'.$studentIdentifier.'_'.$evalId;
$pdf->content_to_pdf($html, $css, $pdf->params['filename'], null, 'D', false, null, false, false);
