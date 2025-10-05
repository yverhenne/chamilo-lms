<?php
/* For licensing terms, see /license.txt */
require_once __DIR__.'/config.php';
if (!api_get_configuration_value('plugin_user_profile_enabled')) {
    api_not_allowed(true);
}
require_once __DIR__.'/UserProfilePlugin.php';
require_once api_get_path(LIBRARY_PATH).'sessionmanager.lib.php';
require_once api_get_path(LIBRARY_PATH).'tracking.lib.php';

global $htmlHeadXtra;
$ajaxUrl = api_get_path(WEB_PLUGIN_PATH).'user_profile/ajax.php';
$token = Security::get_token();
$htmlHeadXtra[] = '<link rel="stylesheet" href="'.api_get_path(WEB_PLUGIN_PATH).'user_profile/assets/css/common.css">';
$htmlHeadXtra[] = '<link rel="stylesheet" href="'.api_get_path(WEB_PLUGIN_PATH).'user_profile/assets/css/tracking.css">';
$htmlHeadXtra[] = '<script>window.USER_PROFILE_AJAX_URL = "'.Security::remove_XSS($ajaxUrl).'"; window.userProfileToken = "'.Security::remove_XSS($token).'";</script>';
$htmlHeadXtra[] = '<script src="'.api_get_path(WEB_PLUGIN_PATH).'user_profile/assets/js/common.js"></script>';

api_protect_admin_script(true);
$plugin = UserProfilePlugin::create();
$urlId = (int) api_get_current_access_url_id();

$tblUser = Database::get_main_table(TABLE_MAIN_USER);

$dateDisplayFormat = '%A %d %B %Y';

$search = trim($_GET['search'] ?? '');
$perPageOptions = [10, 20, 30, 50, 'all'];
$perPage = $_GET['per_page'] ?? 10;
if (!in_array($perPage, $perPageOptions, true)) {
    $perPage = 10;
}
$page = max(1, (int) ($_GET['page'] ?? 1));
// Collect IDs matching search if any
$searchResults = [];
if (strlen($search) >= 3) {
    $tblUrl = Database::get_main_table(TABLE_MAIN_ACCESS_URL_REL_USER);
    $escaped = Database::escape_string($search);
    $condition = "(u.firstname LIKE '%$escaped%' OR u.lastname LIKE '%$escaped%')";
    if (api_is_multiple_url_enabled()) {
        $sql = "SELECT u.id FROM $tblUser u INNER JOIN $tblUrl url ON (u.id = url.user_id) WHERE url.access_url_id = $urlId AND $condition";
    } else {
        $sql = "SELECT id FROM $tblUser u WHERE $condition";
    }
    $res = Database::query($sql);
    $searchResults = Database::store_result($res);
}

// Fetch users to display (all or search-filtered)
$userSql = "SELECT id, firstname, lastname, email, phone, registration_date, last_login FROM $tblUser";
$where = '';
if (!empty($searchResults)) {
    $ids = array_map('intval', array_column($searchResults, 'id'));
    $where = " WHERE id IN (".implode(',', $ids).")";
} elseif ($search !== '' && strlen($search) >= 3) {
    // Search performed but no users found
    $where = " WHERE 0";
}
$userSql .= $where;

$countSql = "SELECT COUNT(*) AS count FROM $tblUser".$where;
$countRes = Database::query($countSql);
$countRow = Database::fetch_array($countRes);
$totalCount = (int) $countRow['count'];

if ($perPage !== 'all') {
    $totalPages = (int) ceil($totalCount / (int) $perPage);
    $page = min($page, max($totalPages, 1));
    $offset = ((int) $perPage) * ($page - 1);
    $userSql .= " ORDER BY lastname, firstname LIMIT $perPage OFFSET $offset";
} else {
    $totalPages = 1;
    $userSql .= " ORDER BY lastname, firstname";
}
$users = Database::query($userSql);

Display::display_header(api_utf8_encode(UserProfilePlugin::create()->get_lang('UserTracking')));



// Render
echo $plugin->renderTopMenu();

echo '<div class="user-profile-section text-center">';
echo Display::page_subheader(api_utf8_encode(UserProfilePlugin::create()->get_lang('SearchUser')));
echo '<div class="mb-3">';
echo '<form method="get" class="search-form">';
echo '<input type="text" name="search" value="'.Security::remove_XSS($search).'" class="form-control mb-2 search-input" placeholder="'.api_utf8_encode(UserProfilePlugin::create()->get_lang('SearchUser')).'">';
echo '<input type="hidden" name="per_page" value="'.Security::remove_XSS($perPage).'">';
echo '<button type="submit" class="btn btn-primary">'.UserProfilePlugin::create()->get_lang('Search').'</button>';
echo '</form>';
echo '</div>';
if ($search !== '' && strlen($search) >= 3 && empty($searchResults)) {
    echo Display::return_message(api_utf8_encode(UserProfilePlugin::create()->get_lang('NoResultsFound')), 'warning');
}
echo '</div>';

echo '<div class="mb-3 per-page-container">';
echo '<form method="get" id="per-page-form">';
echo '<input type="hidden" name="search" value="'.Security::remove_XSS($search).'">';
echo '<select name="per_page" class="form-control per-page-select">';
foreach ($perPageOptions as $opt) {
    $sel = ($opt === $perPage) ? ' selected' : '';
    $label = ($opt === 'all') ? UserProfilePlugin::create()->get_lang('All') : (string) $opt;
    echo '<option value="'.Security::remove_XSS($opt).'"'.$sel.'>'.$label.'</option>';
}
echo '</select>';
echo '</form>';
echo '</div>';
echo '<br>';

echo '<div class="row user-cards">';
// Calculate last week's start (Monday) and end (Sunday) in UTC
$start = new DateTime('monday last week', new DateTimeZone('UTC'));
$start->setTime(0, 0, 0);
$end = clone $start;
$end->modify('+6 days')->setTime(23, 59, 59);
$startUtc = api_get_utc_datetime($start->format('Y-m-d H:i:s'));
$endUtc = api_get_utc_datetime($end->format('Y-m-d H:i:s'));

// Calculate current and next week ranges in UTC

while ($user = Database::fetch_array($users)) {
    $userId = (int) $user['id'];
    echo '<div class="col-md-6">';
    echo '<div class="card user-profile" data-user-id="'.$userId.'">';
    echo '<div class="card-title"><strong>'.Security::remove_XSS($user['firstname'].' '.$user['lastname']).'</strong></div>';
    echo '<div class="card-body"><div class="row">';

    echo '<div class="col-sm-8">';
    echo '<ul class="list-group list-group-flush">';
    echo '<li class="list-group-item"><strong>'.get_lang('Email').':</strong> '.Security::remove_XSS($user['email']).'</li>';
    echo '<li class="list-group-item"><strong>'.get_lang('Phone').':</strong> '.Security::remove_XSS($user['phone']).'</li>';
    $registrationDate = (!empty($user['registration_date']) && $user['registration_date'] !== '0000-00-00 00:00:00')
        ? api_format_date($user['registration_date'], $dateDisplayFormat)
        : '';
    $lastLoginRaw = $user['last_login'];
    $lastLoginFormatted = (!empty($lastLoginRaw) && $lastLoginRaw !== '0000-00-00 00:00:00')
        ? api_format_date($lastLoginRaw, $dateDisplayFormat)
        : get_lang('Never');
    $lastLoginTimestamp = ($lastLoginRaw && $lastLoginRaw !== '0000-00-00 00:00:00')
        ? strtotime($lastLoginRaw)
        : 0;
    $lastLoginLate = $lastLoginTimestamp < $start->getTimestamp();
    if ($lastLoginLate) {
        $lastLogin = '<span class="text-danger">'.Security::remove_XSS($lastLoginFormatted)
            .' <em class="fa fa-exclamation-triangle"></em></span>';
    } else {
        $lastLogin = Security::remove_XSS($lastLoginFormatted);
    }

    echo '<li class="list-group-item"><strong>'.get_lang('RegistrationDate').':</strong> '.Security::remove_XSS($registrationDate).'</li>';
    echo '<li class="list-group-item"><strong>'.get_lang('LastLogins').':</strong> '.$lastLogin.'</li>';

    // Company name (clickable popup, like tracking.php)
    try {
        if (method_exists($plugin, 'ensureEntrepriseSchema')) {
            $plugin->ensureEntrepriseSchema();
        }
        $companyId = method_exists($plugin, 'getUserCompanyId') ? $plugin->getUserCompanyId($userId) : null;
        $tradeName = '';
        if (!empty($companyId)) {
            $tblEnt = Database::get_main_table(UserProfilePlugin::TABLE_ENTREPRISE);
            $row = Database::select('trade_name', $tblEnt, ['where' => ['id = ?' => (int) $companyId]], 'first');
            if ($row && !empty($row['trade_name'])) {
                $tradeName = (string) $row['trade_name'];
            }
        }
        $companyHtml = '-';
        if (!empty($companyId) && $tradeName !== '') {
            $popupUrl = api_get_path(WEB_PLUGIN_PATH).'user_profile/company_list.php?id='.(int) $companyId;
            $safeName = Security::remove_XSS($tradeName);
            $companyHtml = '<a href="'.Security::remove_XSS($popupUrl).'" class="js-popup" data-width="900" data-height="700" data-popup="companyView">'.$safeName.'</a>';
        }
        echo '<li class="list-group-item"><strong>'.UserProfilePlugin::create()->get_lang('Entreprise').':</strong> '
            .$companyHtml.'</li>';
    } catch (Exception $e) {
        // ignore DB errors
    }

    $teacherNames = $plugin->getTeacherNamesForUser($userId);
    if ($teacherNames === '') {
        $teacherNames = '-';
    }
    echo '<li class="list-group-item"><strong>'.get_lang('Teachers').':</strong> '
        .Security::remove_XSS($teacherNames).'</li>';
    echo '</ul>';

    // Time spent last week
    $tblTrackCourseAccess = Database::get_main_table(TABLE_STATISTIC_TRACK_E_COURSE_ACCESS);
    $sqlTime = "SELECT SUM(UNIX_TIMESTAMP(logout_course_date) - UNIX_TIMESTAMP(login_course_date)) AS time
        FROM $tblTrackCourseAccess
        WHERE login_course_date >= '$startUtc'
          AND login_course_date <= '$endUtc'
          AND logout_course_date >= '$startUtc'
          AND logout_course_date <= '$endUtc'
          AND user_id = $userId";
    $resTime = Database::query($sqlTime);
    $rowTime = Database::fetch_array($resTime, 'ASSOC');
    $timeSpent = (int) $rowTime['time'];
    $timeLabel = UserProfilePlugin::create()->get_lang('TimeSpentLastWeek');
    $detailsUrl = api_get_path(WEB_CODE_PATH).'mySpace/myStudents.php?student='.$userId;
    $profileUrl = api_get_path(WEB_PLUGIN_PATH).'user_profile/view.php?id='.$userId;
    echo '<div class="time-block">';
    echo '<span><strong>'.Security::remove_XSS($timeLabel).':</strong> '.Security::remove_XSS(gmdate('H:i:s', $timeSpent)).'</span>';
    echo '</div>';
    echo '</div>'; // col-sm-8

    // Average progress circle
    $sessions = SessionManager::get_sessions_by_user($userId);
    $overall = 0;
    $count = 0;
    foreach ($sessions as $session) {
        $sessionId = (int) $session['session_id'];
        $courses = SessionManager::get_course_list_by_session_id($sessionId);
        $progressTotal = 0;
        $courseCount = 0;
        foreach ($courses as $course) {
            $progressTotal += Tracking::get_avg_student_progress($userId, $course['course_code'], [], $sessionId);
            $courseCount++;
        }
        $sessionProgress = $courseCount ? round($progressTotal / $courseCount) : 0;
        $overall += $sessionProgress;
        $count++;
    }
    $avg = $count ? round($overall / $count) : 0;
    echo '<div class="col-sm-4 d-flex flex-column align-items-center justify-content-center">';
    echo '<div class="progress-circle mb-2" style="--p:'.$avg.'%"><span>'.$avg.'%</span></div>';
    echo '<div>'.Security::remove_XSS(UserProfilePlugin::create()->get_lang('AverageProgress')).'</div>';
    echo '</div>'; // col-sm-4

    echo '</div>'; // row

    // Untracked custom fields by category
    $tblField = Database::get_main_table(UserProfilePlugin::TABLE_FIELD);
    $tblValue = Database::get_main_table(UserProfilePlugin::TABLE_VALUE);
    $tblCat = Database::get_main_table(UserProfilePlugin::TABLE_CATEGORY);
    $sql = "SELECT f.id, f.name, f.field_type, f.category_id, v.value, COALESCE(v.checked,0) AS checked, c.name AS category_name
            FROM $tblField f
            LEFT JOIN $tblValue v ON (f.id = v.field_id AND v.user_id = $userId)
            LEFT JOIN $tblCat c ON (f.category_id = c.id)
            WHERE f.access_url_id = $urlId AND c.access_url_id = $urlId AND f.include_tracking = 0
            ORDER BY c.cat_order, f.field_order, f.id";
    $res = Database::query($sql);
    $fields = Database::store_result($res);
    $catFields = [];
    foreach ($fields as $field) {
        $catFields[$field['category_id']]['label'] = UserProfilePlugin::getCategoryLabel(['name' => $field['category_name']]);
        $catFields[$field['category_id']]['fields'][] = $field;
    }
    if (!empty($catFields)) {
        foreach ($catFields as $cat) {
            echo '<div class="mt-3">';
            echo '<div class="list-group-item active text-center py-10">'.Security::remove_XSS($cat['label']).'</div>';
            echo '<div class="table-responsive"><table class="table table-hover mb-0">';
            echo '<thead><tr><th></th><th class="text-right">'.UserProfilePlugin::create()->get_lang('Completed').'</th></tr></thead><tbody>';
            foreach ($cat['fields'] as $field) {
                $rawVal = $field['value'];
                $val = '';
                if ($field['field_type'] === 'date' && !empty($rawVal)) {
                    $formatted = api_format_date($rawVal, $dateDisplayFormat);
                    if (empty($field['checked']) && strtotime($rawVal) < time()) {
                        $val = '<span class="text-danger">'.Security::remove_XSS($formatted).' <em class="fa fa-exclamation-triangle"></em></span>';
                    } else {
                        $val = Security::remove_XSS($formatted);
                    }
                } else {
                    $val = Security::remove_XSS($rawVal);
                }
                $checkedAttr = !empty($field['checked']) ? ' checked' : '';
                echo '<tr>';
                echo '<td>'.Security::remove_XSS($field['name']);
                if ($val !== '') {
                    echo ' : '.$val;
                }
                echo '</td>';
                echo '<td class="text-right"><input type="checkbox" class="untracked-checkbox" data-field-id="'.$field['id'].'"'.$checkedAttr.'> <span class="save-status text-success d-none"></span></td>';
                echo '</tr>';
            }
            echo '</tbody></table></div>';
            echo '</div>';
        }
    }
    echo '<div class="text-center mt-3 actions-row">';
    echo '<a class="btn btn-danger" title="'.UserProfilePlugin::create()->get_lang('AccessDetails').'" href="'.Security::remove_XSS($detailsUrl).'">'.UserProfilePlugin::create()->get_lang('FollowUp').'</a> ';
    echo '<a class="btn btn-primary ml-2" href="'.Security::remove_XSS($profileUrl).'">'.UserProfilePlugin::create()->get_lang('UserSheet').'</a>';
    echo '</div>';
    echo '</div>'; // card-body
    echo '</div></div>';
}
echo '</div>';

if ($totalPages > 1) {
    echo '<nav aria-label="User pagination"><ul class="pagination">';
    for ($i = 1; $i <= $totalPages; $i++) {
        $active = $i == $page ? ' active' : '';
        $url = '?page='.$i.'&per_page='.urlencode((string) $perPage);
        if ($search !== '') {
            $url .= '&search='.urlencode($search);
        }
        echo '<li class="page-item'.$active.'"><a class="page-link" href="'.$url.'">'.$i.'</a></li>';
    }
    echo '</ul></nav>';
}

Display::display_footer();

