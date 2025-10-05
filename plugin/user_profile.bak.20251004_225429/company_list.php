<?php
/* For licensing terms, see /license.txt */
require_once __DIR__.'/config.php';
require_once __DIR__.'/UserProfilePlugin.php';

if (!api_get_configuration_value('plugin_user_profile_enabled')) {
    api_not_allowed(true);
}

// Allow platform admins, session admins and teachers (COURSEMANAGER) to access
if (!api_is_platform_admin(true) && !api_is_teacher()) {
    api_not_allowed(true);
}

$plugin = UserProfilePlugin::create();
$plugin->ensureEntrepriseSchema();

global $htmlHeadXtra;
$htmlHeadXtra[] = '<link rel="stylesheet" href="'.api_get_path(WEB_PLUGIN_PATH).'user_profile/assets/css/common.css">';
$htmlHeadXtra[] = '<link rel="stylesheet" href="'.api_get_path(WEB_PLUGIN_PATH).'user_profile/assets/css/company_list.css">';

$trade = trim($_GET['trade_name'] ?? '');
$legal = trim($_GET['legal_name'] ?? '');
$companyIdFilter = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$singleMode = $companyIdFilter > 0;

Display::display_header(UserProfilePlugin::create()->get_lang('UserProfile')); 
// Top navigation menu for admins and non-teachers (same display logic, unified renderer)
if (api_is_platform_admin() || api_is_session_admin() || api_is_drh() || !api_is_teacher()) {
    echo $plugin->renderTopMenu();
}

$addUrl = api_get_path(WEB_PLUGIN_PATH).'user_profile/company.php';
$addIcon = Display::url(
    Display::return_icon('add.png', get_lang('Add'), [], ICON_SIZE_MEDIUM),
    $addUrl
);

if (!$singleMode) {
    echo '<div class="company-section">';
    echo Display::page_subheader(get_plugin_lang('SearchCompany', 'UserProfilePlugin'));
    echo '<div class="mb-2 text-left">'.$addIcon.'</div>';
    echo '<div class="text-center mb-3">';
    echo '<form method="get" class="search-form form-inline justify-content-center">';
    echo '<div class="form-group mr-2 mb-2">';
    echo '<input type="text" name="trade_name" value="'.Security::remove_XSS($trade).'" class="form-control" placeholder="'.get_plugin_lang('TradeName', 'UserProfilePlugin').'">';
    echo '</div>';
    echo '<div class="form-group mr-2 mb-2">';
    echo '<input type="text" name="legal_name" value="'.Security::remove_XSS($legal).'" class="form-control" placeholder="'.get_plugin_lang('LegalName', 'UserProfilePlugin').'">';
    echo '</div>';
    echo '<button type="submit" class="btn btn-primary mb-2">'.UserProfilePlugin::create()->get_lang('Search').'</button>';
    echo '</form>';
    echo '</div>';
    echo '</div>';
}

$tbl = Database::get_main_table(UserProfilePlugin::TABLE_ENTREPRISE);
$urlId = (int) api_get_current_access_url_id();

$wheres = ["access_url_id = ?" => $urlId];
if ($companyIdFilter > 0) {
    $wheres[" AND id = ?"] = $companyIdFilter;
} else {
    if ($trade !== '') {
        $wheres[" AND trade_name LIKE ?"] = '%'.Database::escape_string($trade).'%';
    }
    if ($legal !== '') {
        $wheres[" AND legal_name LIKE ?"] = '%'.Database::escape_string($legal).'%';
    }
}
$whereSql = Database::parse_where_conditions($wheres);

$perPageOptions = [10, 20, 30, 50, 'all'];
$perPage = $_GET['per_page'] ?? 10;
if (!in_array($perPage, $perPageOptions, true)) { $perPage = 10; }
$page = max(1, (int) ($_GET['page'] ?? 1));

$totalPages = 1;
if (!$singleMode) {
    $countSql = "SELECT COUNT(*) AS count FROM $tbl $whereSql";
    $countRes = Database::query($countSql);
    $countRow = Database::fetch_array($countRes, 'ASSOC');
    $totalCount = isset($countRow['count']) ? (int) $countRow['count'] : 0;
    if ($perPage !== 'all') {
        $totalPages = (int) ceil($totalCount / (int) $perPage);
        $page = min($page, max($totalPages, 1));
    } else {
        $totalPages = 1;
    }
}

$sql = "SELECT * FROM $tbl $whereSql ORDER BY trade_name, legal_name, id DESC";
if (!$singleMode && $perPage !== 'all') {
    $offset = ((int) $perPage) * ($page - 1);
    $sql .= " LIMIT ".(int) $perPage." OFFSET ".$offset;
}
$res = Database::query($sql);
$rows = Database::store_result($res);

if (!$singleMode) {
    echo '<div class="mb-3 per-page-container">';
    echo '<form method="get" id="per-page-form">';
    if ($trade !== '') { echo '<input type="hidden" name="trade_name" value="'.Security::remove_XSS($trade).'">'; }
    if ($legal !== '') { echo '<input type="hidden" name="legal_name" value="'.Security::remove_XSS($legal).'">'; }
    echo '<select name="per_page" class="form-control per-page-select">';
    foreach ($perPageOptions as $opt) {
        $sel = ($opt == $perPage) ? ' selected' : '';
        $label = ($opt === 'all') ? UserProfilePlugin::create()->get_lang('All') : (string) $opt;
        echo '<option value="'.Security::remove_XSS((string) $opt).'"'.$sel.'>'.$label.'</option>';
    }
    echo '</select>';
    echo '</form>';
    echo '</div>';
    echo Display::page_subheader(get_plugin_lang('CompanyList', 'UserProfilePlugin'));
}

echo '<div class="row company-cards">';
foreach ($rows as $row) {
    echo '<div class="'.($singleMode ? 'col-md-12' : 'col-md-6').'">';
    echo '<div class="card company">';
    $title = trim(($row['trade_name'] ?? '').' '.($row['legal_name'] ? '('.$row['legal_name'].')' : ''));
    $title = $title !== '' ? $title : ('#'.$row['id']);
    $editUrl = api_get_path(WEB_PLUGIN_PATH).'user_profile/company.php?id='.(int)$row['id'];
    $canEdit = api_is_platform_admin() || api_is_session_admin() || api_is_drh();
    $editSpan = '';
    if ($canEdit) {
        $editIcon = Display::url(
            Display::return_icon('edit.png', get_lang('Edit'), [], ICON_SIZE_SMALL),
            $editUrl
        );
        $editSpan = '<span>'.$editIcon.'</span>';
    }
    echo '<div class="card-title d-flex justify-content-between align-items-center">'
        .'<strong>'.Security::remove_XSS($title).'</strong>'
        .$editSpan
        .'</div>';
    echo '<div class="card-body">';
    echo '<ul class="list-group list-group-flush">';
    $fields = [
        'trade_name' => get_plugin_lang('TradeName', 'UserProfilePlugin'),
        'legal_name' => get_plugin_lang('LegalName', 'UserProfilePlugin'),
        'address' => get_plugin_lang('Address', 'UserProfilePlugin'),
        'tutor_last_name' => get_plugin_lang('TutorLastName', 'UserProfilePlugin'),
        'tutor_first_name' => get_plugin_lang('TutorFirstName', 'UserProfilePlugin'),
        'tutor_email' => get_plugin_lang('TutorEmail', 'UserProfilePlugin'),
        'tutor_phone' => get_plugin_lang('TutorPhone', 'UserProfilePlugin'),
        'director_last_name' => get_plugin_lang('DirectorLastName', 'UserProfilePlugin'),
        'director_first_name' => get_plugin_lang('DirectorFirstName', 'UserProfilePlugin'),
        'director_email' => get_plugin_lang('DirectorEmail', 'UserProfilePlugin'),
        'director_phone' => get_plugin_lang('DirectorPhone', 'UserProfilePlugin'),
        'other_contact_last_name' => get_plugin_lang('OtherContactLastName', 'UserProfilePlugin'),
        'other_contact_first_name' => get_plugin_lang('OtherContactFirstName', 'UserProfilePlugin'),
        'other_contact_email' => get_plugin_lang('OtherContactEmail', 'UserProfilePlugin'),
        'other_contact_phone' => get_plugin_lang('OtherContactPhone', 'UserProfilePlugin'),
    ];
    foreach ($fields as $key => $label) {
        $val = $row[$key] ?? '';
        echo '<li class="list-group-item"><strong>'.$label.':</strong> '.Security::remove_XSS((string) $val).'</li>';
    }
    echo '</ul>';
    echo '</div>';
    echo '</div>';
    echo '</div>';
}
echo '</div>';

if (!$singleMode && $totalPages > 1) {
    echo '<nav aria-label="Company pagination"><ul class="pagination">';
    for ($i = 1; $i <= $totalPages; $i++) {
        $active = $i == $page ? ' active' : '';
        $url = '?page='.$i.'&per_page='.urlencode((string) $perPage);
        if ($trade !== '') { $url .= '&trade_name='.urlencode($trade); }
        if ($legal !== '') { $url .= '&legal_name='.urlencode($legal); }
        echo '<li class="page-item'.$active.'"><a class="page-link" href="'.$url.'">'.$i.'</a></li>';
    }
    echo '</ul></nav>';
}

Display::display_footer();
