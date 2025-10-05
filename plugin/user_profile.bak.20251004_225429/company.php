<?php
/* For licensing terms, see /license.txt */
require_once __DIR__.'/config.php';
require_once __DIR__.'/UserProfilePlugin.php';

if (!api_get_configuration_value('plugin_user_profile_enabled')) {
    api_not_allowed(true);
}

// Allow platform admins and session admins for consistency with other pages
api_protect_admin_script(true);

$plugin = UserProfilePlugin::create();
// Check CSRF token BEFORE generating a new one to avoid invalidating it
$check = Security::check_token('request');
$token = Security::get_token();

$plugin->ensureEntrepriseSchema();

$urlId = (int) api_get_current_access_url_id();
$table = Database::get_main_table(UserProfilePlugin::TABLE_ENTREPRISE);

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

$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

// Handle save
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $check) {
    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $data = ['access_url_id' => $urlId];
    foreach ($fields as $name => $label) {
        $data[$name] = trim((string) ($_POST[$name] ?? ''));
    }
    if ($id > 0) {
        Database::update($table, $data, ['id = ? AND access_url_id = ?' => [$id, $urlId]]);
    } else {
        $id = (int) Database::insert($table, $data);
    }
    Security::clear_token();
    Display::addFlash(Display::return_message(get_lang('Saved')));
    header('Location: '.api_get_path(WEB_PLUGIN_PATH).'user_profile/company_list.php');
    exit;
}

// Load values
$values = [];
if ($id > 0) {
    $values = Database::select('*', $table, ['where' => ['id = ? AND access_url_id = ?' => [$id, $urlId]]], 'first');
}

Display::display_header(get_plugin_lang('Entreprise', 'UserProfilePlugin'));

$backLink = '<a href="javascript:history.back();" class="ml-2">'
    .Display::return_icon('back.png', get_lang('Back')).'</a>';
echo '<div class="mb-2 text-left">'.$backLink.'</div>';

$form = new FormValidator('company', 'post', api_get_self());
$form->addHidden('sec_token', $token);
if ($id > 0) {
    $form->addHidden('id', (string) $id);
}

foreach ($fields as $name => $label) {
    if ($name === 'address') {
        $form->addTextarea($name, $label, ['rows' => 3]);
    } else {
        $form->addText($name, $label, false);
    }
}

if (!empty($values)) {
    $defaults = [];
    foreach ($fields as $name => $_label) {
        $defaults[$name] = $values[$name] ?? '';
    }
    $form->setDefaults($defaults);
}

$form->addButtonSave(get_lang('SaveSettings'));
$form->setRequiredNote('');
$form->setConstants(['sec_token' => $token]);

echo Display::page_subheader(get_plugin_lang('Entreprise', 'UserProfilePlugin'));
$form->display();

Display::display_footer();
