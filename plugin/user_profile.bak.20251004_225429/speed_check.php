<?php
/* For licensing terms, see /license.txt */

require_once __DIR__.'/config.php';
require_once __DIR__.'/UserProfilePlugin.php';

if (!api_get_configuration_value('plugin_user_profile_enabled')) {
    api_not_allowed(true);
}

// Allow platform admins; also allow session admins to access this page
api_protect_admin_script(true);

require_once api_get_path(LIBRARY_PATH).'MyStudents.php';

$mode = isset($_GET['mode']) ? trim((string) $_GET['mode']) : 'agenda';
if (!in_array($mode, ['agenda', 'suivi', 'monthly'], true)) {
    $mode = 'agenda';
}

$plugin = UserProfilePlugin::create();
$config = $plugin->getConfiguration();
$urlId = (int) api_get_current_access_url_id();

$titleMap = [
    'agenda' => $plugin->get_lang('Agenda').' - '.$plugin->get_lang('NoEventNextWeek'),
    'suivi' => $plugin->get_lang('FollowUp').' - '.$plugin->get_lang('NoTicketLastWeek'),
    'monthly' => $plugin->get_lang('MonthlyEvaluation').' - '.$plugin->get_lang('NoValidatedCommentLastMonth'),
];
$pageTitle = $titleMap[$mode] ?? 'Speed check';

// Token for AJAX actions
$token = Security::get_token();

// Build users list
$tblUser = Database::get_main_table(TABLE_MAIN_USER);
$userSql = "SELECT id, firstname, lastname, email FROM $tblUser ORDER BY lastname, firstname";
$usersRes = Database::query($userSql);
$allUsers = Database::store_result($usersRes);
$allUserIds = array_map(static function ($u) { return (int) $u['id']; }, $allUsers);

// Prepare date ranges
$utc = new DateTimeZone('UTC');
$thisWeekStart = new DateTime('monday this week', $utc); $thisWeekStart->setTime(0,0,0);
$thisWeekEnd = clone $thisWeekStart; $thisWeekEnd->modify('+6 days')->setTime(23,59,59);
$nextWeekStart = clone $thisWeekStart; $nextWeekStart->modify('+7 days');
$nextWeekEnd = clone $thisWeekEnd; $nextWeekEnd->modify('+7 days');
$lastWeekStart = new DateTime('monday last week', $utc); $lastWeekStart->setTime(0,0,0);
$lastWeekEnd = clone $lastWeekStart; $lastWeekEnd->modify('+6 days')->setTime(23,59,59);

$nextWeekStartUtc = api_get_utc_datetime($nextWeekStart->format('Y-m-d H:i:s'));
$nextWeekEndUtc   = api_get_utc_datetime($nextWeekEnd->format('Y-m-d H:i:s'));
$lastWeekStartUtc = api_get_utc_datetime($lastWeekStart->format('Y-m-d H:i:s'));
$lastWeekEndUtc   = api_get_utc_datetime($lastWeekEnd->format('Y-m-d H:i:s'));

$targetIds = [];

if ($mode === 'agenda') {
    // Users who DO have at least one agenda event next week
    $tblCourseUser = Database::get_main_table(TABLE_MAIN_COURSE_USER);
    $tblAgenda = Database::get_course_table(TABLE_AGENDA);
    $sql = "SELECT DISTINCT cu.user_id AS id
            FROM $tblCourseUser cu
            INNER JOIN $tblAgenda a ON a.c_id = cu.c_id
            WHERE a.start_date <= '$nextWeekEndUtc'
              AND COALESCE(a.end_date, a.start_date) >= '$nextWeekStartUtc'";
    $res = Database::query($sql);
    $withEvents = array_map(static fn($r) => (int) $r['id'], Database::store_result($res));
    $withEvents = array_unique($withEvents);
    // Target = users without events
    $targetIds = array_values(array_diff($allUserIds, $withEvents));
} elseif ($mode === 'suivi') {
    $projectIdTracking = (int) ($config['id_ticket_learner_tracking'] ?? 0);
    $categoryTracking = (int) ($config['category2'] ?? 0);
    if ($projectIdTracking > 0 && $categoryTracking > 0) {
        $tblRel = Database::get_main_table(UserProfilePlugin::TABLE_RELATION_STUDENT_TICKET);
        $tblTkt = Database::get_main_table(TABLE_TICKET_TICKET);
        $sql = "SELECT DISTINCT r.user_id AS id
                FROM $tblRel r INNER JOIN $tblTkt t ON (t.id = r.ticket_id)
                WHERE r.access_url_id = $urlId
                  AND t.project_id = $projectIdTracking
                  AND t.category_id = $categoryTracking
                  AND t.start_date >= '$lastWeekStartUtc' AND t.start_date <= '$lastWeekEndUtc'";
        $res = Database::query($sql);
        $withTracking = array_map(static fn($r) => (int) $r['id'], Database::store_result($res));
        $withTracking = array_unique($withTracking);
        $targetIds = array_values(array_diff($allUserIds, $withTracking));
    } else {
        $targetIds = [];
    }
} elseif ($mode === 'monthly') {
    // Compute last month and year
    $month = (int) date('n');
    $year = (int) date('Y');
    $lastMonth = $month - 1; $lastYear = $year;
    if ($lastMonth <= 0) { $lastMonth = 12; $lastYear = $year - 1; }
    $tblMonthly = Database::get_main_table(UserProfilePlugin::TABLE_MONTHLY_EVALUATION);
    $sql = "SELECT DISTINCT id_student AS id FROM $tblMonthly
            WHERE access_url_id = $urlId AND month = $lastMonth AND year = $lastYear AND validation = 1";
    $res = Database::query($sql);
    $validated = array_map(static fn($r) => (int) $r['id'], Database::store_result($res));
    $validated = array_unique($validated);
    $targetIds = array_values(array_diff($allUserIds, $validated));
}

// Build a lookup for user info
$byId = [];
foreach ($allUsers as $u) { $byId[(int) $u['id']] = $u; }

// JS/CSS for agenda reminder
global $htmlHeadXtra;
$noTeacherMsg = addslashes($plugin->get_lang('NoTeacher'));
$htmlHeadXtra[] = '<link rel="stylesheet" href="'.api_get_path(WEB_PLUGIN_PATH).'user_profile/assets/css/speed_check.css">';
$htmlHeadXtra[] = '<script>window.UP_I18N = { messageSent: "'.addslashes($plugin->get_lang('MessageSent')).'", sendError: "'.addslashes($plugin->get_lang('SendError')).'", noTeacher: "'.$noTeacherMsg.'" };</script>';
$htmlHeadXtra[] = '<script>window.USER_PROFILE_AJAX_URL = "'.api_get_path(WEB_PLUGIN_PATH).'user_profile/ajax.php"; window.userProfileToken = "'.$token.'";</script>';
$htmlHeadXtra[] = '<script src="'.api_get_path(WEB_PLUGIN_PATH).'user_profile/assets/js/common.js"></script>';

// Render
Display::display_header($plugin->get_lang('UserProfile'));
echo '<div class="container">';
echo Display::page_subheader($pageTitle);

if (empty($targetIds)) {
    echo Display::return_message($plugin->get_lang('NoResultsFound'), 'normal');
} else {
    foreach ($targetIds as $uid) {
        if (!isset($byId[$uid])) { continue; }
        $u = $byId[$uid];
        $name = Security::remove_XSS(trim(($u['firstname'] ?? '').' '.($u['lastname'] ?? '')));
        $email = Security::remove_XSS($u['email'] ?? '');
        $teacherNames = Security::remove_XSS($plugin->getTeacherNamesForUser((int) $uid));
        echo '<div class="card user-profile" data-user-id="'.(int)$uid.'">';
        echo '<div class="card-title">'.$name.'</div>';
        echo '<div class="card-body">';
        echo '<div>'.$email.'</div>';
        // Assigned teachers
        $teachersLabel = $plugin->get_lang('Teachers');
        $noTeacherText = $plugin->get_lang('NoTeacher');
        $teachersLine = $teacherNames !== '' ? $teacherNames : $noTeacherText;
        echo '<div class="text-muted small">'.$teachersLabel.' : '.$teachersLine.'</div>';
        echo '<div class="actions-row mt-2">';
        if ($mode === 'agenda') {
            echo '<button class="btn btn-success agenda-remind-btn">'.$plugin->get_lang('Remind').'</button>';
        }
        echo '</div>';
        echo '</div>';
        echo '</div>';
    }
}

echo '</div>';
Display::display_footer();
