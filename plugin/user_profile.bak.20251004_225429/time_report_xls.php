<?php
/* For licensing terms, see /license.txt */

require_once __DIR__.'/config.php';

if (!api_get_configuration_value('plugin_user_profile_enabled')) {
    api_not_allowed(true);
}

// Prepare input
$userId = (int) ($_GET['id'] ?? api_get_user_id());
$info = api_get_user_info($userId);
if (empty($info)) {
    api_not_allowed(true);
}

// Compute dates
$startDate = substr((string) ($info['registration_date'] ?? ''), 0, 10);
if (empty($startDate)) {
    $startDate = date('Y-m-d');
}
$endDate = date('Y-m-d');

// Build report (same data as time_report.php)
$data = Tracking::generateReport('time_report', [$userId], $startDate, $endDate);
if (empty($data) || empty($data['headers'])) {
    Display::addFlash(Display::return_message(get_lang('NoDataToExport'), 'warning'));
    api_not_allowed(true);
}

$headers = $data['headers'];
$rows = $data['rows'];
array_unshift($rows, $headers);

$fileName = 'time_report_'.api_replace_dangerous_char(($info['username'] ?? (string) $userId)).'_'.$startDate.'_'.$endDate;

// Proper XLSX export
Export::arrayToXls($rows, $fileName);
exit;

