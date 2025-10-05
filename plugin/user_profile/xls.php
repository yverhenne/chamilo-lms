<?php
/* For licensing terms, see /license.txt */
require_once __DIR__.'/config.php';
if (!api_get_configuration_value('plugin_user_profile_enabled')) {
    api_not_allowed(true);
}
require_once __DIR__.'/UserProfilePlugin.php';
require_once api_get_path(LIBRARY_PATH).'MyStudents.php';

$userId = (int) ($_GET['id'] ?? api_get_user_id());
$info = api_get_user_info($userId);
if (empty($info)) {
    api_not_allowed(true);
}

$plugin = UserProfilePlugin::create();
$urlId = (int) api_get_current_access_url_id();
$tblField = Database::get_main_table(UserProfilePlugin::TABLE_FIELD);
$tblValue = Database::get_main_table(UserProfilePlugin::TABLE_VALUE);
$tblCat   = Database::get_main_table(UserProfilePlugin::TABLE_CATEGORY);
$sql = "SELECT f.id, f.name, f.field_type, f.category_id, v.value
        FROM $tblField f
        LEFT JOIN $tblValue v ON (f.id = v.field_id AND v.user_id = $userId)
        LEFT JOIN $tblCat c ON (f.category_id = c.id)
        WHERE f.access_url_id = $urlId AND c.access_url_id = $urlId
        ORDER BY f.field_order, f.id";
$result = Database::query($sql);
$fields = Database::store_result($result);
$fieldsByCat = [];
foreach ($fields as $field) {
    $fieldsByCat[$field['category_id']][] = $field;
}
$categories = $plugin->getCategories();
$teacherNames = $plugin->getTeacherNamesForUser($userId);
$teacherDisplay = $teacherNames !== '' ? $teacherNames : '-';

// Build a simple HTML table for Excel without style tags.
$html  = '<table border="1">';
$html .= '<tr><th colspan="2">'.api_utf8_encode(UserProfilePlugin::create()->get_lang('PlatformFields')).'</th></tr>';
$html .= '<tr><td>'.get_lang('FirstName').'</td><td>'.Security::remove_XSS($info['firstname']).'</td></tr>';
$html .= '<tr><td>'.get_lang('LastName').'</td><td>'.Security::remove_XSS($info['lastname']).'</td></tr>';
$html .= '<tr><td>'.get_lang('Email').'</td><td>'.Security::remove_XSS($info['email']).'</td></tr>';
$html .= '<tr><td>'.get_lang('OfficialCode').'</td><td>'.Security::remove_XSS($info['official_code']).'</td></tr>';
$html .= '<tr><td>'.get_lang('Phone').'</td><td>'.Security::remove_XSS($info['phone']).'</td></tr>';
$html .= '<tr><td>'.get_lang('RegistrationDate').'</td><td>'.Security::remove_XSS($info['registration_date']).'</td></tr>';
$html .= '<tr><td>'.get_lang('LastLogins').'</td><td>'.Security::remove_XSS($info['last_login']).'</td></tr>';
$html .= '<tr><td>'.get_lang('Teachers').'</td><td>'.Security::remove_XSS($teacherDisplay).'</td></tr>';

// Entreprise section for export
$entreprise = $plugin->getEntreprise();
if (!empty($entreprise)) {
    $html .= '<tr><th colspan="2">'.api_utf8_encode(get_plugin_lang('FicheEntreprise', 'UserProfilePlugin')).'</th></tr>';
    $companyFields = [
        'trade_name' => api_utf8_encode(get_plugin_lang('TradeName', 'UserProfilePlugin')),
        'legal_name' => api_utf8_encode(get_plugin_lang('LegalName', 'UserProfilePlugin')),
        'address' => api_utf8_encode(get_plugin_lang('Address', 'UserProfilePlugin')),
        'tutor_last_name' => api_utf8_encode(get_plugin_lang('TutorLastName', 'UserProfilePlugin')),
        'tutor_first_name' => api_utf8_encode(get_plugin_lang('TutorFirstName', 'UserProfilePlugin')),
        'tutor_email' => api_utf8_encode(get_plugin_lang('TutorEmail', 'UserProfilePlugin')),
        'tutor_phone' => api_utf8_encode(get_plugin_lang('TutorPhone', 'UserProfilePlugin')),
        'director_last_name' => api_utf8_encode(get_plugin_lang('DirectorLastName', 'UserProfilePlugin')),
        'director_first_name' => api_utf8_encode(get_plugin_lang('DirectorFirstName', 'UserProfilePlugin')),
        'director_email' => api_utf8_encode(get_plugin_lang('DirectorEmail', 'UserProfilePlugin')),
        'director_phone' => api_utf8_encode(get_plugin_lang('DirectorPhone', 'UserProfilePlugin')),
        'other_contact_last_name' => api_utf8_encode(get_plugin_lang('OtherContactLastName', 'UserProfilePlugin')),
        'other_contact_first_name' => api_utf8_encode(get_plugin_lang('OtherContactFirstName', 'UserProfilePlugin')),
        'other_contact_email' => api_utf8_encode(get_plugin_lang('OtherContactEmail', 'UserProfilePlugin')),
        'other_contact_phone' => api_utf8_encode(get_plugin_lang('OtherContactPhone', 'UserProfilePlugin')),
    ];
    foreach ($companyFields as $key => $label) {
        $val = $entreprise[$key] ?? '';
        $html .= '<tr><td>'.$label.'</td><td>'.Security::remove_XSS((string) $val).'</td></tr>';
    }
}

foreach ($categories as $cat) {
    $html .= '<tr><th colspan="2">'.Security::remove_XSS(api_utf8_encode(UserProfilePlugin::getCategoryLabel($cat))).'</th></tr>';
    if (!empty($fieldsByCat[$cat['id']])) {
        foreach ($fieldsByCat[$cat['id']] as $field) {
            $val = $field['value'];
            if ($field['field_type'] === 'date' && !empty($val)) {
                $val = api_format_date($val, DATE_FORMAT_LONG);
            }
            $html .= '<tr><td>'.Security::remove_XSS($field['name']).'</td><td>'.Security::remove_XSS($val).'</td></tr>';
        }
            }
}

// Synthesis section.
$orderCondition = null;
if (api_get_configuration_value('session_list_order')) {
    $orderCondition = ' ORDER BY s.position ASC';
}
$sessions = SessionManager::getSessionsFollowedByUser(
    $userId,
    null,
    null,
    null,
    false,
    false,
    false,
    $orderCondition
);
$sessionProgressList = [];
$totalSessionsProgress = 0;
foreach ($sessions as $sessionItem) {
    $courses = SessionManager::get_course_list_by_session_id($sessionItem['id']);
    $courseProgressSum = 0;
    $courseCount = 0;
    foreach ($courses as $courseItem) {
        $courseInfoItem = api_get_course_info_by_id($courseItem['real_id']);
        $courseCodeItem = $courseInfoItem['code'];
        if (CourseManager::is_user_subscribed_in_course($userId, $courseCodeItem, true, $sessionItem['id'])) {
            $progressValue = Tracking::get_avg_student_progress(
                $userId,
                $courseCodeItem,
                [],
                $sessionItem['id']
            );
            if (is_numeric($progressValue)) {
                $courseProgressSum += $progressValue;
            }
            $courseCount++;
        }
    }
    $progress = $courseCount > 0 ? round($courseProgressSum / $courseCount, 2) : 0;
    $sessionProgressList[] = [
        'name' => $sessionItem['name'],
        'progress' => $progress,
    ];
    $totalSessionsProgress += $progress;
}
$avgSessionsProgress = !empty($sessionProgressList) ? round($totalSessionsProgress / count($sessionProgressList), 2) : 0;

$aLastWeek = get_last_week();
$startWeek = date('Y-m-d', $aLastWeek[0]);
$endWeek = date('Y-m-d', $aLastWeek[6]);
$report = Tracking::generateReport('time_report', [$userId], $startWeek, $endWeek);
$timeSeconds = 0;
foreach ($report['rows'] as $reportRow) {
    $timeParts = explode(':', $reportRow[6]);
    if (count($timeParts) === 3) {
        [$hours, $minutes, $seconds] = array_map('intval', $timeParts);
        $timeSeconds += ($hours * 3600) + ($minutes * 60) + $seconds;
    }
}
$timeSpentLastWeek = api_time_to_hms($timeSeconds);

$weeksToShow = 52;
$currentMonday = strtotime('monday this week');
$weekData = [];
for ($i = 1; $i <= $weeksToShow; $i++) {
    $weekStart = strtotime('-'.$i.' week', $currentMonday);
    $weekEnd = $weekStart + (6 * 86400);
    $startDate = date('Y-m-d', $weekStart);
    $endDate = date('Y-m-d', $weekEnd);
    $reportWeek = Tracking::generateReport('time_report', [$userId], $startDate, $endDate);
    $weekSeconds = 0;
    foreach ($reportWeek['rows'] as $reportRow) {
        $parts = explode(':', $reportRow[6]);
        if (count($parts) === 3) {
            [$h, $m, $s] = array_map('intval', $parts);
            $weekSeconds += ($h * 3600) + ($m * 60) + $s;
        }
    }
    $label = date('Y', $weekStart).' - '.date('W', $weekStart);
    $weekData[] = [
        'label' => $label,
        'time'  => api_time_to_hms($weekSeconds),
    ];
}

$html .= '<tr><th colspan="2">'.api_utf8_encode(UserProfilePlugin::create()->get_lang('TrackingSynthesis')).'</th></tr>';
$html .= '<tr><td>'.get_lang('TimeSpentInCoursesLastWeek').'</td><td>'.$timeSpentLastWeek.'</td></tr>';
$html .= '<tr><td>'.get_lang('AverageProgressInSessions').'</td><td>'.$avgSessionsProgress.'%</td></tr>';
if (!empty($sessionProgressList)) {
    $html .= '<tr><th colspan="2">'.get_lang('ProgressionInSessions').'</th></tr>';
    foreach ($sessionProgressList as $item) {
        $html .= '<tr><td>'.Security::remove_XSS($item['name']).'</td><td>'.$item['progress'].'%</td></tr>';
    }
}
if (!empty($weekData)) {
    $html .= '<tr><th colspan="2">'.get_lang('WeeklyTimeSummary').'</th></tr>';
    $html .= '<tr><th>'.get_lang('Week').'</th><th>'.get_lang('LatencyTimeSpent').'</th></tr>';
    foreach ($weekData as $week) {
        $html .= '<tr><td>'.Security::remove_XSS($week['label']).'</td><td>'.$week['time'].'</td></tr>';
    }
}
$html .= '</table>';
$html .= MyStudents::getBlockForComments($userId, true);

// Monthly evaluations for export
$tblMonthly = Database::get_main_table(UserProfilePlugin::TABLE_MONTHLY_EVALUATION);
$evalRes = Database::query("SELECT month, year, comment FROM $tblMonthly WHERE id_student = $userId AND access_url_id = $urlId AND validation = 1 ORDER BY year DESC, month DESC, id DESC");
$evals = Database::store_result($evalRes);
if (!empty($evals)) {
    $html .= '<table border="1">';
    $html .= '<tr><th colspan="2">'.UserProfilePlugin::create()->get_lang('MonthlyEvaluation').'</th></tr>';
    $html .= '<tr><th>'.get_lang('Date').'</th><th>'.UserProfilePlugin::create()->get_lang('Comment').'</th></tr>';
    foreach ($evals as $r) {
        $comment = nl2br(Security::remove_XSS((string) $r['comment']));
        $html .= '<tr><td>'.sprintf('%02d/%04d', (int) $r['month'], (int) $r['year']).'</td><td>'.$comment.'</td></tr>';
    }
    $html .= '</table>';
}

header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
header('Content-Disposition: attachment; filename="user_profile_'.$info['username'].'.xls"');
header('Pragma: no-cache');
header('Expires: 0');

echo "\xEF\xBB\xBF";
echo $html;
