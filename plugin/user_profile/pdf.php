<?php
/* For licensing terms, see /license.txt */
use Chamilo\CoreBundle\Component\Utils\ChamiloApi;
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
$tblCat = Database::get_main_table(UserProfilePlugin::TABLE_CATEGORY);
$sql = "SELECT f.id, f.name, f.field_type, f.category_id, v.value, c.name AS category_name
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

ob_start();
?>
<h2 style="text-align:center;font-weight:bold;">FICHE UTILISATEUR</h2>
<div style="border:1px solid #ccd9e6;margin-bottom:15px;">
    <div style="background-color:#e6f2ff;text-align:center;font-weight:bold;padding:4px;">
        <strong><?php echo UserProfilePlugin::create()->get_lang('PlatformFields'); ?></strong>
    </div>
    <table width="100%" cellpadding="4" cellspacing="0" style="border-collapse:collapse;">
        <tr>
            <td><strong><?php echo get_lang('FirstName'); ?>:</strong> <?php echo Security::remove_XSS($info['firstname']); ?></td>
        </tr>
        <tr>
            <td><strong><?php echo get_lang('LastName'); ?>:</strong> <?php echo Security::remove_XSS($info['lastname']); ?></td>
        </tr>
        <tr>
            <td><strong><?php echo get_lang('Email'); ?>:</strong> <?php echo Security::remove_XSS($info['email']); ?></td>
        </tr>
        <tr>
            <td><strong><?php echo get_lang('OfficialCode'); ?>:</strong> <?php echo Security::remove_XSS($info['official_code']); ?></td>
        </tr>
        <tr>
            <td><strong><?php echo get_lang('Phone'); ?>:</strong> <?php echo Security::remove_XSS($info['phone']); ?></td>
        </tr>
        <tr>
            <td><strong><?php echo get_lang('RegistrationDate'); ?>:</strong> <?php echo Security::remove_XSS($info['registration_date']); ?></td>
        </tr>
        <tr>
            <td><strong><?php echo get_lang('LastLogins'); ?>:</strong> <?php echo Security::remove_XSS($info['last_login']); ?></td>
        </tr>
        <tr>
            <td><strong><?php echo UserProfilePlugin::create()->get_lang('Teachers'); ?>:</strong> <?php echo Security::remove_XSS($teacherDisplay); ?></td>
        </tr>
    </table>
</div>
<div style="border:1px solid #ccd9e6;margin-bottom:15px;">
    <div style="background-color:#e6f2ff;text-align:center;font-weight:bold;padding:4px;">
        <strong><?php echo api_utf8_encode(get_plugin_lang('FicheEntreprise', 'UserProfilePlugin')); ?></strong>
    </div>
    <?php $entreprise = $plugin->getEntreprise(); ?>
    <?php if (!empty($entreprise)): ?>
    <table width="100%" cellpadding="4" cellspacing="0" style="border-collapse:collapse;">
        <?php
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
        foreach ($companyFields as $key => $label):
            $val = $entreprise[$key] ?? '';
        ?>
        <tr>
            <td><strong><?php echo $label; ?>:</strong> <?php echo Security::remove_XSS((string) $val); ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
    <?php else: ?>
        <div style="padding:6px;">-</div>
    <?php endif; ?>
</div>
<?php foreach ($categories as $cat): ?>
<div style="border:1px solid #ccd9e6;margin-bottom:15px;">
    <div style="background-color:#e6f2ff;text-align:center;font-weight:bold;padding:4px;">
        <strong><?php echo Security::remove_XSS(UserProfilePlugin::getCategoryLabel($cat)); ?></strong>
    </div>
    <?php if (!empty($fieldsByCat[$cat['id']])): ?>
    <table width="100%" cellpadding="4" cellspacing="0" style="border-collapse:collapse;">
        <?php foreach ($fieldsByCat[$cat['id']] as $field): ?>
        <?php
        $val = $field['value'];
        if ($field['field_type'] === 'date' && !empty($val)) {
            $val = api_format_date($val, DATE_FORMAT_LONG);
        }
        ?>
        <tr>
            <td><strong><?php echo Security::remove_XSS($field['name']); ?>:</strong> <?php echo Security::remove_XSS($val); ?></td>
        </tr>
        <?php endforeach; ?>
     </table>
    <?php endif; ?>
</div>
<?php endforeach; ?>
<?php echo MyStudents::getBlockForSynthesis($userId, true); ?>
<?php
/* For licensing terms, see /license.txt */
/* For licensing terms, see /license.txt */
// Monthly evaluations block (PDF export)
$tblMonthly = Database::get_main_table(UserProfilePlugin::TABLE_MONTHLY_EVALUATION);
$evalRes = Database::query("SELECT month, year, comment FROM $tblMonthly WHERE id_student = $userId AND access_url_id = $urlId AND validation = 1 ORDER BY year DESC, month DESC, id DESC");
$evals = Database::store_result($evalRes);
if (!empty($evals)):
?>
<div style="border:1px solid #ccd9e6;margin-bottom:15px;">
    <div style="background-color:#e6f2ff;text-align:center;font-weight:bold;padding:4px;">
        <strong><?php echo api_utf8_encode(UserProfilePlugin::create()->get_lang('MonthlyEvaluation')); ?></strong>
    </div>
    <table width="100%" cellpadding="4" cellspacing="0" style="border-collapse:collapse;">
        <thead>
            <tr>
                <th style="text-align:left;">&nbsp;<?php echo api_utf8_encode(get_lang('Date')); ?></th>
                <th style="text-align:left;">&nbsp;<?php echo api_utf8_encode(UserProfilePlugin::create()->get_lang('Comment')); ?></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($evals as $r): ?>
            <tr>
                <td><?php echo sprintf('%02d/%04d', (int) $r['month'], (int) $r['year']); ?></td>
                <td><?php echo nl2br(Security::remove_XSS((string) $r['comment'])); ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>
<?php echo MyStudents::getBlockForComments($userId, true); ?>
<?php
$html = ob_get_clean();

$logo = ChamiloApi::getPlatformLogoPath('', true);
$header = '<div style="text-align:right;"><img src="'.$logo.'" height="50"></div>';
$date = api_format_date(api_get_local_time(), DATE_TIME_FORMAT_LONG);
$footer = '<table width="100%"><tr><td>'.$date.'</td><td style="text-align:right">{PAGENO}/{nb}</td></tr></table>';

$tpl = new Template('', false, false, false, false, true, false);
$tpl->assign('pdf_header', $header);
$tpl->assign('pdf_footer', $footer);
$pdf = new PDF('A4', 'P', [], $tpl);
$pdf->params['filename'] = 'user_profile_'.$info['username'];
$pdf->html_to_pdf_with_template($html, false, false, true);
