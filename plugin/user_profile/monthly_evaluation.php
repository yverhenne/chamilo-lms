<?php
/* For licensing terms, see /license.txt */
require_once __DIR__.'/config.php';
require_once __DIR__.'/UserProfilePlugin.php';

if (!api_get_configuration_value('plugin_user_profile_enabled')) {
    api_not_allowed(true);
}

// Allow platform admins, session admins, and teachers to access
if (!(api_is_platform_admin() || api_is_session_admin() || api_is_teacher())) {
    api_not_allowed(true);
}

$plugin = UserProfilePlugin::create();

$studentId = isset($_GET['student']) ? (int) $_GET['student'] : 0;
if ($studentId <= 0) {
    api_not_allowed(true);
}

$urlId = (int) api_get_current_access_url_id();
$tblMonthly = Database::get_main_table(UserProfilePlugin::TABLE_MONTHLY_EVALUATION);

// CSRF tokens: check first, then generate a fresh token for the form
$isPost = $_SERVER['REQUEST_METHOD'] === 'POST';
$validPost = $isPost && Security::check_token('post');
$token = Security::get_token();

// Helpers
$currentUserId = api_get_user_id();
$isSessionAdmin = api_is_session_admin();
$isTeacher = api_is_teacher();
$months = [
    1 => 'Janvier', 2 => 'Février', 3 => 'Mars', 4 => 'Avril', 5 => 'Mai', 6 => 'Juin',
    7 => 'Juillet', 8 => 'Août', 9 => 'Septembre', 10 => 'Octobre', 11 => 'Novembre', 12 => 'Décembre',
];
$months = api_get_months_long();

/**
 * Check if an evaluation already exists for the same student/month/year on this access_url.
 */
function user_profile_monthly_eval_exists(string $table, int $studentId, int $urlId, int $month, int $year, ?int $excludeId = null): bool
{
    $whereSql = 'id_student = ? AND access_url_id = ? AND month = ? AND year = ?';
    $params = [$studentId, $urlId, $month, $year];
    if (!empty($excludeId)) {
        $whereSql .= ' AND id <> ?';
        $params[] = $excludeId;
    }
    $row = Database::select('id', $table, ['where' => [$whereSql => $params]], 'first');
    return (bool) $row;
}

// Handle actions: add/update/delete
if ($validPost) {
    $action = $_POST['action'] ?? '';
    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    if ($action === 'delete' && $id > 0) {
        // Author OR session admin can delete, only if not validated
        $row = Database::select('*', $tblMonthly, ['where' => ['id = ? AND id_student = ? AND access_url_id = ?' => [$id, $studentId, $urlId]]], 'first');
        if (
            $row
            && (int) $row['validation'] === 0
            && ($isSessionAdmin || $isTeacher || (int) $row['author_id'] === $currentUserId)
        ) {
            Database::delete($tblMonthly, ['id = ?' => $id]);
            Display::addFlash(Display::return_message(get_lang('Deleted')));
        } else {
            Display::addFlash(Display::return_message(get_lang('NotAllowed'), 'error'));
        }
        Security::clear_token();
        if (!empty($_REQUEST['popup'])) {
            echo '<script>if (window.opener) { try { window.opener.location.reload(); } catch(e){} } window.close();</script>';
            exit;
        }
        header('Location: '.api_get_self().'?student='.$studentId);
        exit;
    }

    if ($action === 'save') {
        $month = (int) ($_POST['month'] ?? 0);
        $year = (int) ($_POST['year'] ?? 0);
        $comment = trim((string) ($_POST['comment'] ?? ''));
        $validation = 0; // Always set to not validated on save from this page

        if ($month < 1 || $month > 12) {
            $month = (int) date('n');
        }
        $currentYear = (int) date('Y');
        $lastYear = $currentYear - 1;
        $allowedYears = [$lastYear, $currentYear];
        if (!in_array($year, $allowedYears, true)) {
            $year = $currentYear;
        }

        // Prevent duplicate month/year for same student
        $excludeId = $id > 0 ? $id : null;
        if (user_profile_monthly_eval_exists($tblMonthly, $studentId, $urlId, $month, $year, $excludeId)) {
            Display::addFlash(Display::return_message(UserProfilePlugin::create()->get_lang('AlreadyCommentedThisMonth'), 'warning'));
            Security::clear_token();
            if (!empty($_REQUEST['popup'])) {
                echo '<script>if (window.opener) { try { window.opener.location.reload(); } catch(e){} } window.close();</script>';
                exit;
            }
            header('Location: '.api_get_self().'?student='.$studentId);
            exit;
        }

        if ($id > 0) {
            // Update: author OR session admin and not validated
            $row = Database::select('*', $tblMonthly, ['where' => ['id = ? AND id_student = ? AND access_url_id = ?' => [$id, $studentId, $urlId]]], 'first');
            if (
                $row
                && (int) $row['validation'] === 0
                && ($isSessionAdmin || $isTeacher || (int) $row['author_id'] === $currentUserId)
            ) {
                Database::update(
                    $tblMonthly,
                    [
                        'month' => $month,
                        'year' => $year,
                        'comment' => $comment,
                    ],
                    ['id = ?' => $id]
                );
                Display::addFlash(Display::return_message(get_lang('Saved')));
            } else {
                Display::addFlash(Display::return_message(get_lang('NotAllowed'), 'error'));
            }
        } else {
            // Create
            Database::insert($tblMonthly, [
                'id_student' => $studentId,
                'access_url_id' => $urlId,
                'author_id' => $currentUserId,
                'comment' => $comment,
                'month' => $month,
                'year' => $year,
                'validation' => 0,
            ]);
            Display::addFlash(Display::return_message(get_lang('Saved')));
        }

        Security::clear_token();
        if (!empty($_REQUEST['popup'])) {
            echo '<script>if (window.opener) { try { window.opener.location.reload(); } catch(e){} } window.close();</script>';
            exit;
        }
        header('Location: '.api_get_self().'?student='.$studentId);
        exit;
    }
}

// Load record to edit if any
$editId = isset($_GET['edit']) ? (int) $_GET['edit'] : 0;
$editRow = null;
if ($editId > 0) {
    $editRow = Database::select('*', $tblMonthly, ['where' => ['id = ? AND id_student = ? AND access_url_id = ?' => [$editId, $studentId, $urlId]]], 'first');
    if (!$editRow) {
        $editId = 0;
    }
}

// Display page
// Title with fallback if missing from lang file
$title = UserProfilePlugin::create()->get_lang('MonthlyEvaluation');
if (preg_match('/^\[.*\]$/', $title)) { $title = 'Evaluation mensuelle'; }
Display::display_header($title);

// Removed explicit close button; rely on browser/OS window controls

// Build form
$form = new FormValidator('monthly_eval', 'post', api_get_self().'?student='.$studentId);
$form->addHidden('sec_token', $token);
$form->addHidden('action', 'save');
if (!empty($_GET['popup'])) {
    $form->addHidden('popup', '1');
}
if ($editId > 0) {
    $form->addHidden('id', (string) $editId);
}

$form->addSelect('month', UserProfilePlugin::create()->get_lang('Month'), $months, ['required' => true]);
// Year dropdown with two choices: last year and current year
$currentYear = (int) date('Y');
$lastYear = $currentYear - 1;
$yearOptions = [
    $lastYear => (string) $lastYear,
    $currentYear => (string) $currentYear,
];
$form->addSelect('year', UserProfilePlugin::create()->get_lang('Year'), $yearOptions, ['required' => true]);
$form->addTextarea('comment', UserProfilePlugin::create()->get_lang('Comment'), ['rows' => 3]);
// No validation option in the form

// Defaults
$defaults = [
    'month' => (int) date('n'),
    'year' => (int) date('Y'),
];
if ($editRow) {
    $defaults['month'] = (int) $editRow['month'];
    $defaults['year'] = (int) $editRow['year'];
    $defaults['comment'] = (string) $editRow['comment'];
}
$form->setDefaults($defaults);
$form->addButtonSave(get_lang('Save'));
$form->setRequiredNote('');
$form->setConstants(['sec_token' => $token]);

echo Display::page_subheader($title);
$form->display();

// List of records
$sql = "SELECT id, month, year, comment, validation, author_id FROM $tblMonthly"
    ." WHERE id_student = $studentId AND access_url_id = $urlId"
    ." ORDER BY year DESC, month DESC, id DESC";
$res = Database::query($sql);
$rows = Database::store_result($res);

echo '<hr>';
echo '<h4>'.UserProfilePlugin::create()->get_lang('Overview').'</h4>';

if (empty($rows)) {
    echo '<p>'.UserProfilePlugin::create()->get_lang('NoResultsFound').'</p>';
} else {
    echo '<table class="table table-striped table-hover">';
    echo '<thead><tr>'
        .'<th>'.UserProfilePlugin::create()->get_lang('Month').'</th>'
        .'<th>'.UserProfilePlugin::create()->get_lang('Year').'</th>'
        .'<th>'.UserProfilePlugin::create()->get_lang('Comment').'</th>'
        .'<th>'.get_lang('Status').'</th>'
        .'<th>'.get_lang('Actions').'</th>'
        .'</tr></thead><tbody>';
    foreach ($rows as $r) {
        $canEdit = ((int) $r['validation'] === 0) && ($isSessionAdmin || $isTeacher || ((int) $r['author_id'] === $currentUserId));
        $status = (int) $r['validation'] === 1 ? '<span class="badge badge-success">'.get_lang('Validated').'</span>' : '<span class="badge badge-secondary">'.get_lang('NotValidated').'</span>';
        $commentHtml = nl2br(Security::remove_XSS((string) $r['comment']));
        echo '<tr>'
            .'<td>'.Security::remove_XSS($months[(int)$r['month']] ?? (string)$r['month']).'</td>'
            .'<td>'.(int)$r['year'].'</td>'
            .'<td>'.$commentHtml.'</td>'
            .'<td>'.$status.'</td>'
            .'<td>';
        if ($canEdit) {
            // Edit link: reload page with edit id; keep icons on same line
            echo '<div class="d-inline-flex align-items-center">';
            $editUrl = api_get_self().'?student='.$studentId.'&edit='.(int)$r['id'];
            echo '<a href="'.$editUrl.'" class="mr-2" title="'.get_lang('Edit').'">'
                .Display::return_icon('edit.png', get_lang('Edit'), ['class' => 'align-middle'], ICON_SIZE_SMALL)
                .'</a>';
            // Delete form (inline)
            echo '<form method="post" action="'.api_get_self().'?student='.$studentId.'" '
                .'class="d-inline-block m-0 p-0" '
                .'onsubmit="return confirm(\''.addslashes(get_lang('ConfirmYourChoice')).'\');">'
                .'<input type="hidden" name="sec_token" value="'.$token.'">'
                .'<input type="hidden" name="action" value="delete">'
                .'<input type="hidden" name="id" value="'.(int)$r['id'].'">'
                .'<button type="submit" class="btn btn-link p-0 align-middle" title="'.get_lang('Delete').'">'
                .Display::return_icon('delete.png', get_lang('Delete'), ['class' => 'align-middle'], ICON_SIZE_SMALL)
                .'</button>'
                .'</form>';
            echo '</div>';
        } else {
            echo '-';
        }
        echo '</td>'
            .'</tr>';
    }
    echo '</tbody></table>';
}

Display::display_footer();
