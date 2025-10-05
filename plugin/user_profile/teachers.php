<?php
/* For licensing terms, see /license.txt */
require_once __DIR__.'/config.php';
require_once __DIR__.'/UserProfilePlugin.php';

$enabled = api_get_configuration_value('plugin_user_profile_enabled');
if (!$enabled) {
    api_not_allowed(true);
}
api_protect_admin_script(true);

$plugin = UserProfilePlugin::create();
$token = Security::get_token();

global $htmlHeadXtra;

// Messages (will be JSON-encoded)
$successMsg = UserProfilePlugin::create()->get_lang('UpdateSuccess');
$errorMsg   = UserProfilePlugin::create()->get_lang('UpdateError');
$ajaxUrl    = api_get_path(WEB_PLUGIN_PATH).'user_profile/ajax.php';

// Prepare JSON-encoded values with safe flags
$jsonFlags = JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP;
$tokenJs   = json_encode($token, $jsonFlags);
$successJs = json_encode($successMsg, $jsonFlags);
$errorJs   = json_encode($errorMsg, $jsonFlags);
$ajaxUrlJs = json_encode($ajaxUrl, $jsonFlags);

// Inject CSS and pass dynamic values for JS
$htmlHeadXtra[] = '<link rel="stylesheet" href="'.api_get_path(WEB_PLUGIN_PATH).'user_profile/assets/css/teachers.css">';
$htmlHeadXtra[] = '<script>window.USER_PROFILE_AJAX_URL = '.json_encode($ajaxUrl).'; window.userProfileToken = '.json_encode($token).'; window.UP_I18N = { teacherUpdateSuccess: '.json_encode($successMsg).', teacherUpdateError: '.json_encode($errorMsg).' };</script>';
$htmlHeadXtra[] = '<script src="'.api_get_path(WEB_PLUGIN_PATH).'user_profile/assets/js/common.js"></script>';
$htmlHeadXtra[] = '<script src="'.api_get_path(WEB_PLUGIN_PATH).'user_profile/assets/js/teachers.js"></script>';

$search = trim($_GET['search'] ?? '');
$limit = $_GET['limit'] ?? 10;
$validLimits = ['10', '20', '30', '50', 'all'];
if (!in_array((string) $limit, $validLimits, true)) {
    $limit = 10;
}
$limitSql = '';
$page = max(1, (int) ($_GET['page'] ?? 1));
if ($limit !== 'all') {
    $limit = (int) $limit;
    $offset = ($page - 1) * $limit;
    $limitSql = " LIMIT $limit OFFSET $offset";
}

$tblUser = Database::get_main_table(TABLE_MAIN_USER);

$urlId = (int) api_get_current_access_url_id();
$from = "$tblUser u";
if (api_is_multiple_url_enabled()) {
    $tblUrl = Database::get_main_table(TABLE_MAIN_ACCESS_URL_REL_USER);
    $from .= " INNER JOIN $tblUrl url ON (u.id = url.user_id AND url.access_url_id = $urlId)";
}

$where = [];
if ($search !== '') {
    $escaped = Database::escape_string($search);
    $where[] = "(u.firstname LIKE '%$escaped%' OR u.lastname LIKE '%$escaped%')";
}
$whereSql = $where ? ' WHERE '.implode(' AND ', $where) : '';

$countSql = "SELECT COUNT(*) FROM $from$whereSql";
$res = Database::query($countSql);
$total = (int) Database::fetch_row($res)[0];

$sql = "SELECT u.id, u.firstname, u.lastname
        FROM $from
        $whereSql
        ORDER BY u.lastname, u.firstname$limitSql";
$res = Database::query($sql);
$users = Database::store_result($res);

$teacherOptions = $plugin->getTeacherOptions();

Display::display_header(UserProfilePlugin::create()->get_lang('UserTracking'));

// Top navigation menu
echo $plugin->renderTopMenu();

echo '<div class="user-profile-section">';
echo '<form method="get" class="form-inline mb-3">';
echo '<input type="text" name="search" value="'.Security::remove_XSS($search).'" class="form-control mr-2" placeholder="'.UserProfilePlugin::create()->get_lang('SearchUser').'">';
echo '<select name="limit" class="form-control mr-2">';
foreach (['10','20','30','50','all'] as $opt) {
    $selected = ($opt == ($_GET['limit'] ?? '10')) ? ' selected' : '';
    $label = $opt === 'all' ? UserProfilePlugin::create()->get_lang('All') : $opt;
    echo '<option value="'.$opt.'"'.$selected.'>'.$label.'</option>';
}
echo '</select>';
echo '<button type="submit" class="btn btn-primary">'.UserProfilePlugin::create()->get_lang('Search').'</button>';
echo '</form>';

echo '<table class="table table-striped">';
echo '<thead><tr><th>'.get_lang('FirstName').'</th><th>'.get_lang('LastName').'</th><th>'.UserProfilePlugin::create()->get_lang('Teachers').'</th></tr></thead><tbody>';
foreach ($users as $user) {
    $selected = $plugin->getUserTeachers((int) $user['id']);
    echo '<tr>';
    echo '<td>'.Security::remove_XSS($user['firstname']).'</td>';
    echo '<td>'.Security::remove_XSS($user['lastname']).'</td>';
    echo '<td>';
    echo '<div class="teacher-list" data-user-id="'.(int) $user['id'].'">';
    foreach ($teacherOptions as $tid => $name) {
        $checked = in_array((int) $tid, $selected, true) ? ' checked' : '';
        echo '<div class="teacher-item'.$checked.'" data-teacher-id="'.(int)$tid.'">';
        echo '<span class="teacher-name">'.Security::remove_XSS($name).'</span>';
        echo '<span class="teacher-check">&#10003;</span>';
        echo '<span class="teacher-msg"></span>';
        echo '</div>';
    }
    echo '</div>';
    echo '</td>';
    echo '</tr>';
}
if (empty($users)) {
    echo '<tr><td colspan="3">'.UserProfilePlugin::create()->get_lang('NoResultsFound').'</td></tr>';
}
echo '</tbody>';
echo '</table>';

if ($limit !== 'all' && $total > $limit) {
    $totalPages = (int) ceil($total / $limit);
    echo '<nav><ul class="pagination">';
    for ($p = 1; $p <= $totalPages; $p++) {
        $active = $p === $page ? ' active' : '';
        $url = api_get_self().'?page='.$p.'&limit='.($_GET['limit'] ?? '10');
        if ($search !== '') {
            $url .= '&search='.urlencode($search);
        }
        echo '<li class="page-item'.$active.'"><a class="page-link" href="'.$url.'">'.$p.'</a></li>';
    }
    echo '</ul></nav>';
}

echo '</div>';

Display::display_footer();
