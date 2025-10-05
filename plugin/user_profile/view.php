<?php
/* For licensing terms, see /license.txt */
require_once __DIR__.'/config.php';
require_once __DIR__.'/UserProfilePlugin.php';
require_once api_get_path(LIBRARY_PATH).'MyStudents.php';

if (!api_get_configuration_value('plugin_user_profile_enabled')) {
    api_not_allowed(true);
}

global $htmlHeadXtra;
$htmlHeadXtra[] = '<script src="'.api_get_path(WEB_PUBLIC_PATH)
    .'assets/jquery.easy-pie-chart/dist/jquery.easypiechart.js"></script>';
$htmlHeadXtra[] = '<link rel="stylesheet" href="'.api_get_path(WEB_PLUGIN_PATH).'user_profile/assets/css/common.css">';
$htmlHeadXtra[] = '<link rel="stylesheet" href="'.api_get_path(WEB_PLUGIN_PATH).'user_profile/assets/css/view.css">';


$userId = (int) ($_GET['id'] ?? api_get_user_id());
$info = api_get_user_info($userId);
if (empty($info)) {
    api_not_allowed(true);
}
$tblField = Database::get_main_table(UserProfilePlugin::TABLE_FIELD);
$tblValue = Database::get_main_table(UserProfilePlugin::TABLE_VALUE);
$tblCat = Database::get_main_table(UserProfilePlugin::TABLE_CATEGORY);
$urlId = (int) api_get_current_access_url_id();
$sql = "SELECT f.id, f.name, f.field_type, f.category_id, v.value, c.name AS category_name
        FROM $tblField f
        LEFT JOIN $tblValue v ON (f.id = v.field_id AND v.user_id = $userId)
        LEFT JOIN $tblCat c ON (f.category_id = c.id)
        WHERE f.access_url_id = $urlId AND c.access_url_id = $urlId
        ORDER BY f.field_order, f.id";
$result = Database::query($sql);
$fields = Database::store_result($result);
$fieldsByCat = [];

MyStudents::handleCommentPost($userId);

$plugin = UserProfilePlugin::create();
Display::display_header(get_lang('UserProfile'));

$pdfUrl = api_get_path(WEB_PLUGIN_PATH).'user_profile/pdf.php?id='.$userId;
$xlsUrl = api_get_path(WEB_PLUGIN_PATH).'user_profile/xls.php?id='.$userId;
$pdfLink = '<a href="'.$pdfUrl.'" class="mr-2">'
    .Display::return_icon('icons\\32\\export_pdf.png', get_lang('ExportToPdf')).'</a>';
$xlsLink = '<a href="'.$xlsUrl.'" class="mr-2">'
    .Display::return_icon('icons\\32\\export_excel.png', get_lang('ExportAsXLS')).'</a>';
// Global extract (ZIP) of multiple reports
$zipUrl = api_get_path(WEB_PLUGIN_PATH).'user_profile/export_zip.php?id='.$userId;
$zipTitle = api_utf8_encode(UserProfilePlugin::create()->get_lang('ExtractGlobalReport'));
$zipLink = '<a href="'.$zipUrl.'" title="'.$zipTitle.'">'
    .Display::return_icon('icons\\32\\file_zip.png', $zipTitle).'</a>';
$backLink = '<a href="javascript:history.back();" class="mr-2">'
    .Display::return_icon('back.png', get_lang('Back')).'</a>';
$editUrl = api_get_path(WEB_CODE_PATH).'admin/user_edit.php?user_id='.$userId;
// Hide edit (pencil) icon for teachers
$editLink = '';
if (!api_is_teacher()) {
    $editLink = '<a href="'.$editUrl.'" class="mr-2">'
        .Display::return_icon('icons\\32\\edit.png', get_lang('Edit')).'</a>';
}

// Export titles
$finalExportTitle = api_utf8_encode(UserProfilePlugin::create()->get_lang('FinalExport'));
$exportSheetTitle = api_utf8_encode(UserProfilePlugin::create()->get_lang('ExportSheet'));
// Final Excel icon with custom tooltip (Pedagogical report)
$finalExcelIcon = Display::return_icon(
    'icons\\32\\export_excel.png',
    api_utf8_encode(UserProfilePlugin::create()->get_lang('PedagogicalReport'))
);
// Final Excel export (link to myStudents XLS export for the current user)
$finalXlsUrl = api_get_path(WEB_CODE_PATH).'mySpace/myStudents.php?student='.$userId.'&export=xls';
$finalExcelTitle = api_utf8_encode(UserProfilePlugin::create()->get_lang('PedagogicalReport'));
$finalExcelLink = '<a href="'.$finalXlsUrl.'" class="mr-2" title="'.$finalExcelTitle.'">'.$finalExcelIcon.'</a>';
// Time report export: direct XLSX via plugin endpoint (registration date -> today)
$timeReportIcon = Display::return_icon('icons\\32\\timezone.png', get_lang('TimeReport'));
$pluginTimeUrl = api_get_path(WEB_PLUGIN_PATH).'user_profile/time_report_xls.php?id='.$userId;
$timeReportLink = '<a href="'.$pluginTimeUrl.'" class="mr-2">'.$timeReportIcon.'</a>';

echo '<div class="mb-2">';
echo '<div class="row align-items-start">';
// Row 1: full-width column (100%) with back + edit boxed
echo   '<div class="col-12 mb-2">'
        .'<div class="export-actions-box">'.$backLink.$editLink.'</div>'
      .'</div>';
// Row 2, left column (50%): PDF + Excel inside framed box with title
echo   '<div class="col-12 col-md-6 mb-2">'
        .'<div class="export-box">'
            .'<div class="export-final-title"><strong>'.$exportSheetTitle.'</strong></div>'
            .'<div class="export-final-content">'
                .'<span class="icon">'.$pdfLink.'</span>'
                .'<span class="icon">'.$xlsLink.'</span>'
            .'</div>'
        .'</div>'
      .'</div>';
// Row 2, right column (50%): Final export (ZIP + Excel icon)
echo   '<div class="col-12 col-md-6 mb-2">'
        .'<div class="export-final-box">'
            .'<div class="export-final-title"><strong>'.$finalExportTitle.'</strong></div>'
            .'<div class="export-final-content">'
                .'<span class="icon">'.$zipLink.'</span>'
                .'<span class="icon">'.$finalExcelLink.'</span>'
                .'<span class="icon">'.$timeReportLink.'</span>'
            .'</div>'
        .'</div>'
      .'</div>';
echo '</div>';

// Built-in fields
$built = [
    get_lang('FirstName') => $info['firstname'],
    get_lang('LastName') => $info['lastname'],
    get_lang('Email') => $info['email'],
    get_lang('OfficialCode') => $info['official_code'],
    get_lang('Phone') => $info['phone'],
    get_lang('RegistrationDate') => $info['registration_date'],
    get_lang('LastLogins') => $info['last_login'],
];
$teacherNames = $plugin->getTeacherNamesForUser($userId);
$built[get_lang('Teachers')] = $teacherNames !== '' ? $teacherNames : '-';
echo '<div class="card user-profile mb-3">';
$platformFieldsTitle = api_utf8_encode(UserProfilePlugin::create()->get_lang('PlatformFields'));
echo '<div class="card-title"><strong>'.$platformFieldsTitle.'</strong></div>';
echo '<ul class="list-group list-group-flush">';
foreach ($built as $name => $value) {
    echo '<li class="list-group-item"><strong>'.$name.':</strong> '.Security::remove_XSS($value).'</li>';
}
echo '</ul></div>';

// Entreprise section (same style as user section)
$entreprise = $plugin->getEntreprise();
if (!empty($entreprise)) {
    echo '<div class="card user-profile mb-3">';
    $companyTitle = api_utf8_encode(get_plugin_lang('FicheEntreprise', 'UserProfilePlugin'));
    echo '<div class="card-title"><strong>'.$companyTitle.'</strong></div>';
    echo '<ul class="list-group list-group-flush">';
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
        echo '<li class="list-group-item"><strong>'.Security::remove_XSS($label).':</strong> '.Security::remove_XSS((string) $val).'</li>';
    }
    echo '</ul>';
    echo '</div>';
}

foreach ($fields as $field) {
    $fieldsByCat[$field['category_id']][] = $field;
}
$categories = $plugin->getCategories();
foreach ($categories as $cat) {
    $catId = $cat['id'];
    $label = api_utf8_encode(UserProfilePlugin::getCategoryLabel($cat));
    echo '<div class="card user-profile mb-3">';
    echo '<div class="card-title category-title"><strong>'.Security::remove_XSS($label).'</strong></div>';
    if (!empty($fieldsByCat[$catId])) {
        echo '<ul class="list-group list-group-flush">';
        foreach ($fieldsByCat[$catId] as $field) {
            $rawVal = $field['value'];
            if ($field['field_type'] === 'date' && !empty($rawVal)) {
                $rawVal = api_format_date($rawVal, DATE_FORMAT_LONG);
            }
            // Translate field label when available; fallback to raw name
            $fieldLabel = $plugin->get_lang($field['name']);
            if (preg_match('/^\[[=]?'.preg_quote($field['name'], '/').'[=]?\]$/', (string) $fieldLabel)) {
                $fieldLabel = $field['name'];
            }
            $fieldLabel = api_utf8_encode((string) $fieldLabel);
            if ($field['field_type'] === 'select') {
                $val = '<select disabled><option>'.Security::remove_XSS($rawVal).'</option></select>';
            } else {
                $val = Security::remove_XSS($rawVal);
            }
            echo '<li class="list-group-item"><strong>'.Security::remove_XSS((string) $fieldLabel).':</strong> '.$val.'</li>';
        }
        echo '</ul>';
    }
    echo '</div>';
}

echo MyStudents::getBlockForSynthesis($userId);
// Monthly evaluations block
$tblMonthly = Database::get_main_table(UserProfilePlugin::TABLE_MONTHLY_EVALUATION);
$evalRes = Database::query("SELECT month, year, comment, validation FROM $tblMonthly WHERE id_student = $userId AND access_url_id = $urlId ORDER BY year DESC, month DESC, id DESC");
$evals = Database::store_result($evalRes);
echo '<div class="card user-profile mb-3">';
echo '<div class="card-title"><strong>'.api_utf8_encode(UserProfilePlugin::create()->get_lang('MonthlyEvaluation')).'</strong></div>';
echo '<div class="card-body">';
if (empty($evals)) {
    echo '<em>'.api_utf8_encode(UserProfilePlugin::create()->get_lang('NoResultsFound')).'</em>';
} else {
    echo '<table class="table table-striped table-hover"><thead><tr>'
        .'<th>'.UserProfilePlugin::create()->get_lang('Month').'</th>'
        .'<th>'.UserProfilePlugin::create()->get_lang('Year').'</th>'
        .'<th>'.api_utf8_encode(UserProfilePlugin::create()->get_lang('Comment')).'</th>'
        .'<th>'.get_lang('Status').'</th>'
        .'</tr></thead><tbody>';
    foreach ($evals as $r) {
        $status = (int) $r['validation'] === 1 ? '<span class="badge badge-success">'.get_lang('Validated').'</span>' : '<span class="badge badge-secondary">'.get_lang('NotValidated').'</span>';
        echo '<tr>'
            .'<td>'.(int) $r['month'].'</td>'
            .'<td>'.(int) $r['year'].'</td>'
            .'<td>'.nl2br(Security::remove_XSS((string) $r['comment'])).'</td>'
            .'<td>'.$status.'</td>'
            .'</tr>';
    }
    echo '</tbody></table>';
}
echo '</div></div>';
echo MyStudents::getBlockForComments($userId);

Display::display_footer();
