<?php
/* For licensing terms, see /license.txt */
require_once __DIR__.'/config.php';
require_once __DIR__.'/UserProfilePlugin.php';
$enabled = api_get_configuration_value('plugin_user_profile_enabled');
if (!$enabled) {
    api_not_allowed(true);
}
$check = Security::check_token('request');
$token = Security::get_token();

api_protect_admin_script();
$plugin = UserProfilePlugin::create();
$urlId = (int) api_get_current_access_url_id();
$htmlHeadXtra[] = '<link rel="stylesheet" href="'.api_get_path(WEB_PLUGIN_PATH).'user_profile/assets/css/common.css">';

$table = Database::get_main_table(UserProfilePlugin::TABLE_FIELD);
$catTable = Database::get_main_table(UserProfilePlugin::TABLE_CATEGORY);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $check && !empty($_POST['fields'])) {
    foreach ($_POST['fields'] as $id => $data) {
        $id = (int) $id;
        $name = trim($data['name'] ?? '');
        $type = $data['type'] === 'date' ? 'date' : 'text';
        $categoryId = (int) ($data['category'] ?? 0);
        $tracking = !empty($data['include_tracking']) ? 1 : 0;
        if ($name !== '' && $categoryId) {
            Database::update(
                $table,
                [
                    'name' => $name,
                    'field_type' => $type,
                    'category_id' => $categoryId,
                    'include_tracking' => $tracking,
                ],
                ['id = ? AND access_url_id = ?' => [$id, $urlId]]
            );
        }
    }
    Security::clear_token();
    header('Location: '.api_get_path(WEB_PLUGIN_PATH).$plugin->get_name().'/admin.php');
    exit;
}

$fields = Database::query("SELECT * FROM $table WHERE access_url_id = $urlId ORDER BY field_order, id");
$categories = $plugin->getCategoryOptions();

Display::display_header(get_lang('UserProfile'));
echo '<div class="user-profile-section">';
echo Display::page_subheader(UserProfilePlugin::create()->get_lang('EditFields'));
echo '<form method="post">';
echo '<input type="hidden" name="sec_token" value="'.$token.'">';
echo '<table class="table table-hover table-striped">';
echo '<thead><tr><th>'.get_lang('Name').'</th><th>'.get_lang('Type').'</th><th>'.get_lang('Category').'</th><th>'.UserProfilePlugin::create()->get_lang('ShowTracking').'</th></tr></thead><tbody>';
while ($row = Database::fetch_array($fields)) {
    echo '<tr>';
    echo '<td><input type="text" class="form-control" name="fields['.$row['id'].'][name]" value="'.Security::remove_XSS($row['name']).'"></td>';
    echo '<td><select class="form-control type-select" name="fields['.$row['id'].'][type]">';
    $selectedText = $row['field_type'] === 'text' ? ' selected' : '';
    $selectedDate = $row['field_type'] === 'date' ? ' selected' : '';
    echo '<option value="text"'.$selectedText.'>Text</option>';
    echo '<option value="date"'.$selectedDate.'>Date</option>';
    echo '</select></td>';
    echo '<td><select class="form-control" name="fields['.$row['id'].'][category]">';
    foreach ($categories as $catId => $catName) {
        $selected = $row['category_id'] == $catId ? ' selected' : '';
        echo '<option value="'.$catId.'"'.$selected.'>'.Security::remove_XSS($catName).'</option>';
    }
    echo '</select></td>';
    $checked = !empty($row['include_tracking']) ? ' checked' : '';
    echo '<td class="track-cell"><label><input type="checkbox" name="fields['.$row['id'].'][include_tracking]" value="1"'.$checked.'> '.UserProfilePlugin::create()->get_lang('IncludeTracking').'</label></td>';
    echo '</tr>';
}
echo '</tbody></table>';
echo '<button type="submit" class="btn btn-primary">'.get_lang('Save').'</button>';
echo '</form>';
echo '</div>';
Display::display_footer();
