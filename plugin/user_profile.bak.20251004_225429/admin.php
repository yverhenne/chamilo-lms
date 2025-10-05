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

if (!(api_is_platform_admin() || api_is_session_admin())) {
    api_not_allowed(true);
}
$plugin = UserProfilePlugin::create();
$urlId = (int) api_get_current_access_url_id();
$htmlHeadXtra[] = api_get_jquery_ui_js();
$htmlHeadXtra[] = '<link rel="stylesheet" href="'.api_get_path(WEB_PLUGIN_PATH).'user_profile/assets/css/admin.css">';

$table = Database::get_main_table(UserProfilePlugin::TABLE_FIELD);
$catTable = Database::get_main_table(UserProfilePlugin::TABLE_CATEGORY);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $check) {
    if (isset($_POST['action']) && $_POST['action'] === 'reorder') {
        $ids = array_map('intval', explode(',', $_POST['order']));
        foreach ($ids as $pos => $id) {
            Database::update(
                $table,
                ['field_order' => $pos],
                ['id = ? AND access_url_id = ?' => [$id, $urlId]]
            );
        }
        Security::clear_token();
        header('Content-Type: application/json');
        echo json_encode(['token' => Security::get_token()]);
        exit;
    }
    if (isset($_POST['action']) && $_POST['action'] === 'reorder_cat') {
        $ids = array_map('intval', explode(',', $_POST['order']));
        foreach ($ids as $pos => $id) {
            Database::update(
                $catTable,
                ['cat_order' => $pos],
                ['id = ? AND access_url_id = ?' => [$id, $urlId]]
            );
        }
            Security::clear_token();
            header('Content-Type: application/json');
            echo json_encode(['token' => Security::get_token()]);
        exit;
    }

    // Delete a field (POST only)
    if (isset($_POST['action']) && $_POST['action'] === 'delete' && isset($_POST['id'])) {
        $id = (int) $_POST['id'];
        Database::delete($table, ['id = ? AND access_url_id = ?' => [$id, $urlId]]);
        Security::clear_token();
        header('Location: '.api_get_self());
        exit;
    }

    // Delete a category and all related values/fields (POST only)
    if (isset($_POST['action']) && $_POST['action'] === 'delete_cat' && isset($_POST['id'])) {
        $categoryId = (int) $_POST['id'];
        $valueTable = Database::get_main_table(UserProfilePlugin::TABLE_VALUE);

        // Fetch all fields linked to the category so their values can be removed
        $fields = Database::select('id', $table, [
            'where' => ['category_id = ? AND access_url_id = ?' => [$categoryId, $urlId]],
        ]);

        foreach ($fields as $field) {
            Database::delete($valueTable, ['field_id = ?' => $field['id']]);
        }

        Database::delete($table, ['category_id = ? AND access_url_id = ?' => [$categoryId, $urlId]]);
        Database::delete($catTable, ['id = ? AND access_url_id = ?' => [$categoryId, $urlId]]);
        Security::clear_token();
        header('Location: '.api_get_self());
        exit;
    }

    $name = trim($_POST['name'] ?? '');
    $type = $_POST['field_type'] ?? 'text';
    $categoryId = (int) ($_POST['category'] ?? 0);
    if ($name !== '' && in_array($type, ['text', 'date']) && $categoryId) {
        $res = Database::query("SELECT MAX(field_order) AS max_order FROM $table WHERE access_url_id = $urlId");
        $row = Database::fetch_array($res);
        $order = (int) $row['max_order'] + 1;
        Database::insert($table, [
            'access_url_id' => $urlId,
            'name' => $name,
            'field_type' => $type,
            'category_id' => $categoryId,
            'field_order' => $order,
            'include_tracking' => isset($_POST['include_tracking']) ? 1 : 0,
        ]);
        Security::clear_token();
        header('Location: '.api_get_self());
        exit;
    }

    if (isset($_POST['new_category'])) {
        $name = trim($_POST['new_category']);
        if ($name !== '') {
            $res = Database::query("SELECT MAX(cat_order) AS max_order FROM $catTable WHERE access_url_id = $urlId");
            $row = Database::fetch_array($res);
            $order = (int) $row['max_order'] + 1;
            Database::insert($catTable, [
                'access_url_id' => $urlId,
                'name' => $name,
                'cat_order' => $order,
            ]);
        }
        Security::clear_token();
        header('Location: '.api_get_self());
        exit;
    }

}


$fields = Database::query("SELECT f.*, c.name AS category_name FROM $table f LEFT JOIN $catTable c ON (f.category_id = c.id) WHERE f.access_url_id = $urlId AND c.access_url_id = $urlId ORDER BY f.field_order, f.id");

$search = trim($_GET['search'] ?? '');
$results = [];
if (strlen($search) >= 3) {
    $tblUser = Database::get_main_table(TABLE_MAIN_USER);
    $tblUrl = Database::get_main_table(TABLE_MAIN_ACCESS_URL_REL_USER);
    $escaped = Database::escape_string($search);
    $condition = "(u.firstname LIKE '%$escaped%' OR u.lastname LIKE '%$escaped%')";
    if (api_is_multiple_url_enabled()) {
        $sql = "SELECT u.id, u.firstname, u.lastname FROM $tblUser u INNER JOIN $tblUrl url ON (u.id = url.user_id) WHERE url.access_url_id = $urlId AND $condition ORDER BY u.lastname, u.firstname";
    } else {
        $sql = "SELECT id, firstname, lastname FROM $tblUser u WHERE $condition ORDER BY u.lastname, u.firstname";
    }
    $res = Database::query($sql);
    $results = Database::store_result($res);
}

Display::display_header($plugin->get_lang('UserProfile'));

echo '<div class="user-profile-section">';
echo Display::page_subheader($plugin->get_lang('SearchUser'));
echo '<div class="text-center mb-3">';
echo '<form method="get" class="search-form">';
echo '<input type="text" name="search" value="'.Security::remove_XSS($search).'" class="form-control mb-2 search-input" placeholder="'.$plugin->get_lang('SearchUser').'">';
echo '<button type="submit" class="btn btn-primary">'.$plugin->get_lang('Search').'</button>';
echo '</form>';
echo '</div>';

if (!empty($results)) {
    echo Display::page_subheader($plugin->get_lang('SearchResults'));
    echo '<table class="table table-hover table-striped">';
    echo '<thead><tr><th>'.get_lang('FirstName').'</th><th>'.get_lang('LastName').'</th></tr></thead><tbody>';
    foreach ($results as $user) {
        $url = $plugin->getViewUrl((int) $user['id'], true);
        $first = Display::url(Security::remove_XSS($user['firstname']), $url);
        $last = Display::url(Security::remove_XSS($user['lastname']), $url);
        echo "<tr><td>$first</td><td>$last</td></tr>";
    }
    echo '</tbody></table>';
}
echo '</div>';

echo '<div class="user-profile-section">';
$editUrl = api_get_path(WEB_PLUGIN_PATH).$plugin->get_name().'/fields_edit.php';
$editIcon = Display::return_icon('edit.png', $plugin->get_lang('EditFields'));
echo Display::page_subheader(
    get_lang('CurrentFields').' '.Display::url($editIcon, $editUrl)
);
echo '<table id="fields-table" class="table table-hover table-striped data_table">';
echo '<thead><tr><th width="30"></th><th>'.get_lang('Name').'</th><th>'.get_lang('Type').'</th><th>'.get_lang('Category').'</th><th>'.$plugin->get_lang('ShowTracking').'</th><th>'.get_lang('Actions').'</th></tr></thead><tbody>';
while ($row = Database::fetch_array($fields)) {
    echo '<tr id="field_'.$row['id'].'">';
    echo '<td class="handle"><span class="handle-icon">&#9776;</span></td>';
    echo '<td>'.Security::remove_XSS($row['name']).'</td>';
    echo '<td>'.Security::remove_XSS($row['field_type']).'</td>';
    $catLabel = UserProfilePlugin::getCategoryLabel(['name' => $row['category_name']]);
    echo '<td>'.Security::remove_XSS($catLabel).'</td>';
    $checked = !empty($row['include_tracking']) ? ' checked' : '';
    echo '<td><input type="checkbox" disabled'.$checked.'></td>';
    // Inline POST form for deletion with CSRF token
    echo '<td>';
    echo '<form method="post" action="'.api_get_self().'" class="d-inline" onsubmit="return confirm(\''.addslashes($plugin->get_lang('ConfirmYourChoice')).'\');">';
    echo '<input type="hidden" name="sec_token" value="'.$token.'">';
    echo '<input type="hidden" name="action" value="delete">';
    echo '<input type="hidden" name="id" value="'.(int) $row['id'].'">';
    echo '<button type="submit" class="btn btn-link p-0" title="'.$plugin->get_lang('Delete').'">'
        .Display::return_icon('delete.png', $plugin->get_lang('Delete'), [], ICON_SIZE_SMALL)
        .'</button>';
    echo '</form>';
    echo '</td>';
    echo '</tr>';
}
echo '</tbody></table>';

echo "<script>
    $(function() {
        var secToken = '$token';
        $('#fields-table tbody').sortable({
            handle: '.handle',
            placeholder: 'ui-sortable-placeholder',
            update: function() {
                var ids = $(this).sortable('toArray');
                var order = ids.map(function(id){ return id.replace('field_', ''); }).join(',');
                $.post('".api_get_self()."', {sec_token: secToken, action: 'reorder', order: order}, function(res){secToken=res.token;}, 'json');
            }
        }).disableSelection();

        $('#cats-table tbody').sortable({
            handle: '.handle',
            placeholder: 'ui-sortable-placeholder',
            update: function(){
                var ids = $(this).sortable('toArray');
                var order = ids.map(function(id){return id.replace('cat_','');}).join(',');
                $.post('".api_get_self()."', {sec_token: secToken, action:'reorder_cat', order:order}, function(res){secToken=res.token;}, 'json');
            }
        }).disableSelection();
    });
</script>";

$form = new FormValidator('add_field', 'post', api_get_self());
$form->addHidden('sec_token', $token);  // ✅ Correct
$form->addHidden('action', 'add');
$form->addText('name', get_lang('Name'), false);
$form->addSelect(
    'field_type',
    get_lang('Type'),
    [
        'text' => get_lang('Text'),
        'date' => get_lang('Date'),
    ]
);
$categories = $plugin->getCategoryOptions();
$form->addSelect('category', get_lang('Category'), $categories);
$tracking = $form->createElement('checkbox', 'include_tracking', '', $plugin->get_lang('ShowTracking'));
$tracking->setAttribute('id', 'include_tracking');
$form->addElement($tracking);
$form->addButtonSave(get_lang('Add'));
$form->setConstants(['sec_token' => $token]);
$form->setRequiredNote('');

echo Display::page_subheader(get_lang('AddField'));
$form->display();

echo '</div>';

echo '<div class="user-profile-section">';
// Category management
$categoriesRows = $plugin->getCategories();
echo Display::page_subheader(get_lang('CurrentCategories'));
echo '<table id="cats-table" class="table table-hover table-striped">';
echo '<thead><tr><th width="30"></th><th>'.get_lang('Name').'</th><th>'.get_lang('Actions').'</th></tr></thead><tbody>';
foreach ($categoriesRows as $cat) {
    echo '<tr id="cat_'.$cat['id'].'">';
    echo '<td class="handle"><span class="handle-icon">&#9776;</span></td>';
    $label = UserProfilePlugin::getCategoryLabel($cat);
    echo '<td>'.Security::remove_XSS($label).'</td>';
    echo '<td>';
    echo '<form method="post" action="'.api_get_self().'" class="d-inline" onsubmit="return confirm(\''.addslashes(get_lang('ConfirmYourChoice')).'\');">';
    echo '<input type="hidden" name="sec_token" value="'.$token.'">';
    echo '<input type="hidden" name="action" value="delete_cat">';
    echo '<input type="hidden" name="id" value="'.(int) $cat['id'].'">';
    echo '<button type="submit" class="btn btn-link p-0" title="'.get_lang('Delete').'">'
        .Display::return_icon('delete.png', get_lang('Delete'), [], ICON_SIZE_SMALL)
        .'</button>';
    echo '</form>';
    echo '</td>';
    echo '</tr>';
}
echo '</tbody></table>';


$catForm = new FormValidator('add_cat', 'post', api_get_self());
$catForm->addHidden('sec_token', $token);
$catForm->addText('new_category', get_lang('Name'), false);
$catForm->addButtonSave(get_lang('Add'));
$catForm->setConstants(['sec_token' => $token]);
$catForm->setRequiredNote('');
echo Display::page_subheader(get_lang('AddCategory'));
$catForm->display();
echo '</div>';

Display::display_footer();
