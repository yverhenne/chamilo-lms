<?php

/* For licensing terms, see /license.txt */
require_once __DIR__.'/config.php';

if (!api_get_configuration_value('plugin_user_profile_enabled')) {

    api_not_allowed(true);

}

require_once __DIR__.'/UserProfilePlugin.php';

require_once api_get_path(LIBRARY_PATH).'sessionmanager.lib.php';

require_once api_get_path(LIBRARY_PATH).'tracking.lib.php';

require_once api_get_path(LIBRARY_PATH).'MyStudents.php';



global $htmlHeadXtra;

$token = '';

// Plugin instance for translations
$plugin = UserProfilePlugin::create();

$noTeacherMsg = addslashes($plugin->get_lang('NoTeacher'));

// Styles and common handlers
$htmlHeadXtra[] = '<link rel="stylesheet" href="'.api_get_path(WEB_PLUGIN_PATH).'user_profile/assets/css/common.css">';
$htmlHeadXtra[] = '<link rel="stylesheet" href="'.api_get_path(WEB_PLUGIN_PATH).'user_profile/assets/css/tracking.css">';
$htmlHeadXtra[] = '<script>window.UP_I18N = { ticketsCreatedAssigned: "'.addslashes($plugin->get_lang('TicketsCreatedAssigned')).'", messageSent: "'.addslashes($plugin->get_lang('MessageSent')).'", sendError: "'.addslashes($plugin->get_lang('SendError')).'", noTeacher: "'.$noTeacherMsg.'" };</script>';
$htmlHeadXtra[] = '<script>window.USER_PROFILE_AJAX_URL = "'.api_get_path(WEB_PLUGIN_PATH).'user_profile/ajax.php"; window.userProfileToken = "'.$token.'"; window.USER_PROFILE_SPEED_CHECK_BASE = "'.api_get_path(WEB_PLUGIN_PATH).'user_profile/speed_check.php";</script>';
$htmlHeadXtra[] = '<script src="'.api_get_path(WEB_PLUGIN_PATH).'user_profile/assets/js/common.js"></script>';

 



// Allow platform admins; also allow session admins to access this page

api_protect_admin_script(true);

$urlId = (int) api_get_current_access_url_id();

$messageHtml = '';



// Handle add comment submission (before output)

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'add_comment') {

    $messageHtml = '';

    $check = Security::check_token('post');

    $targetUserId = (int) ($_POST['user_id'] ?? 0);

    $content = trim((string) ($_POST['comment'] ?? ''));

    if ($check && $targetUserId > 0 && $content !== '') {

        $tblComment = Database::get_main_table(UserProfilePlugin::TABLE_COMMENT);

        Database::insert($tblComment, [

            'author_id'    => api_get_user_id(),

            'user_id'      => $targetUserId,

            'comment_date' => api_get_utc_datetime(),

            'content'      => $content,

            'is_public'    => 0,

        ]);

        $messageHtml = Display::return_message($plugin->get_lang('UpdateSuccess'), 'confirmation');

    } else {

        $messageHtml = Display::return_message($plugin->get_lang('UpdateError'), 'error');

    }

    Security::clear_token();

}



// Handle delete comment (admins and session admins)

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete_comment') {

    $messageHtml = '';

    $check = Security::check_token('post');

    $commentId = (int) ($_POST['comment_id'] ?? 0);

    $targetUserId = (int) ($_POST['user_id'] ?? 0);

    if ($check && $commentId > 0 && $targetUserId > 0) {

        if (api_is_platform_admin() || api_is_session_admin()) {

            $tblComment = Database::get_main_table(UserProfilePlugin::TABLE_COMMENT);

            // Extra safety: ensure we only delete comments for the posted user id

            $deleted = Database::delete($tblComment, ['id = ? AND user_id = ?' => [$commentId, $targetUserId]]);

            if ($deleted) {

                $messageHtml = Display::return_message($plugin->get_lang('Deleted'), 'confirmation');

            } else {

                $messageHtml = Display::return_message($plugin->get_lang('UpdateError'), 'error');

            }

        } else {

            $messageHtml = Display::return_message($plugin->get_lang('NotAllowed'), 'error');

        }

    } else {

        $messageHtml = Display::return_message($plugin->get_lang('UpdateError'), 'error');

    }

    Security::clear_token();

}



// Ensure we have a fresh token now (after POST check)

if (empty($token)) {

    $token = Security::get_token();

}

// Seed the JS token in the head extras and sync local var if present

$htmlHeadXtra[] = '<script>window.userProfileToken = "'.$token.'";try{userProfileToken=window.userProfileToken;}catch(e){}</script>';



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

    $users = Database::query($userSql);

} else {

    $totalPages = 1;

    $userSql .= " ORDER BY lastname, firstname";

}

$users = Database::query($userSql);



Display::display_header($plugin->get_lang('UserTracking'));



// Show feedback after comment submission, if any

if (!empty($messageHtml)) {

    echo $messageHtml;

}



// Top navigation menu (uniform across plugin pages)
echo $plugin->renderTopMenu();



// Search + Quick look side by side
echo '<div class="row user-profile-cols">';
echo '<div class="col-md-6 d-flex">';
echo '<div class="user-profile-section text-center">';
echo Display::page_subheader($plugin->get_lang('SearchUser'));
echo '<div class="text-center mb-3">';
echo '<form method="get" class="search-form">';
echo '<input type="text" name="search" value="'.Security::remove_XSS($search).'" class="form-control mb-2 search-input" placeholder="'.$plugin->get_lang('SearchUser').'">';
echo '<input type="hidden" name="per_page" value="'.Security::remove_XSS($perPage).'">';
echo '<button type="submit" class="btn btn-primary">'.$plugin->get_lang('Search').'</button>';
echo '</form>';
echo '</div>';
if ($search !== '' && strlen($search) >= 3 && empty($searchResults)) {
    echo Display::return_message($plugin->get_lang('NoResultsFound'), 'warning');
}
echo '</div>';
echo '</div>';

// Quick look panel (same layout)
echo '<div class="col-md-6 d-flex">';
echo '<div class="user-profile-section text-center">';
echo Display::page_subheader($plugin->get_lang('QuickCheck'));
echo '<div class="mb-2">';
$base = api_get_path(WEB_PLUGIN_PATH).'user_profile/speed_check.php';
echo '<a class="btn btn-primary speed-check-open" data-mode="agenda" href="#">'.$plugin->get_lang('Agenda').'</a> ';
echo '<a class="btn btn-primary ml-2 speed-check-open" data-mode="suivi" href="#">'.$plugin->get_lang('FollowUp').'</a> ';
echo '<a class="btn btn-primary ml-2 speed-check-open" data-mode="monthly" href="#">'.$plugin->get_lang('MonthlyEvaluation').'</a>';
echo '</div>';
echo '</div>';
echo '</div>';
echo '</div>'; // .row



echo '<div class="mb-3" style="max-width:80px;">';

echo '<form method="get" id="per-page-form">';

echo '<input type="hidden" name="search" value="'.Security::remove_XSS($search).'">';

echo '<select name="per_page" class="form-control per-page-select">';

foreach ($perPageOptions as $opt) {

    $sel = ($opt == $perPage) ? ' selected' : '';

    $label = ($opt === 'all') ? $plugin->get_lang('All') : (string) $opt;

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



while ($user = Database::fetch_array($users)) {

    $userId = (int) $user['id'];

    echo '<div class="col-md-6">';

    echo '<div class="card user-profile" data-user-id="'.$userId.'">';

    echo '<div class="card-title"><strong>'.Security::remove_XSS($user['firstname'].' '.$user['lastname']).'</strong></div>';

    echo '<div class="card-body"><div class="row">';



    echo '<div class="col-sm-8">';

    echo '<ul class="list-group list-group-flush">';

    echo '<li class="list-group-item"><strong>'.$plugin->get_lang('Email').':</strong> '.Security::remove_XSS($user['email']).'</li>';

    echo '<li class="list-group-item"><strong>'.$plugin->get_lang('Phone').':</strong> '.Security::remove_XSS($user['phone']).'</li>';

    $registrationDate = (!empty($user['registration_date']) && $user['registration_date'] !== '0000-00-00 00:00:00')

        ? api_format_date($user['registration_date'], $dateDisplayFormat)

        : '';

    $lastLoginRaw = $user['last_login'];

    $lastLoginFormatted = (!empty($lastLoginRaw) && $lastLoginRaw !== '0000-00-00 00:00:00')

        ? api_format_date($lastLoginRaw, $dateDisplayFormat)

        : $plugin->get_lang('Never');

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



    [$thisWeekBox, $nextWeekBox] = MyStudents::getAgendaStatusBoxes($userId);

    // Compute "Suivi" status box (green if a tracking ticket exists in configured project/category)
    $config = $plugin->getConfiguration();
    $projectIdTracking = (int) ($config['id_ticket_learner_tracking'] ?? 0);
    $categoryTracking = (int) ($config['category2'] ?? 0);
    $hasTrackingTicket = false;
    $trackingTitle = '';
    if ($projectIdTracking > 0 && $categoryTracking > 0) {
        $tblRel = Database::get_main_table(UserProfilePlugin::TABLE_RELATION_STUDENT_TICKET);
        $tblTkt = Database::get_main_table(TABLE_TICKET_TICKET);
        // Only consider tickets created during last week (start_date in [$startUtc, $endUtc])
        $sqlList = "SELECT t.subject, t.start_date, t.code FROM $tblRel r INNER JOIN $tblTkt t ON (t.id = r.ticket_id) "
            ."WHERE r.user_id = $userId AND r.access_url_id = $urlId "
            ."AND t.project_id = $projectIdTracking AND t.category_id = $categoryTracking "
            ."AND t.start_date >= '$startUtc' AND t.start_date <= '$endUtc' ORDER BY t.start_date ASC LIMIT 5";
        $resList = Database::query($sqlList);
        $rows = Database::store_result($resList);
        $hasTrackingTicket = !empty($rows);
        if ($hasTrackingTicket) {
            $labels = [];
            foreach ($rows as $r) {
                $t = isset($r['subject']) ? trim($r['subject']) : '';
                $dateFmt = api_convert_and_format_date($r['start_date'], DATE_TIME_FORMAT_LONG);
                $code = isset($r['code']) ? trim($r['code']) : '';
                $prefix = $code !== '' ? ('#'.$code.' - ') : '';
                $labels[] = ($prefix.($t !== '' ? $t.' ' : '').'('.$dateFmt.')');
            }
            // Count total to add "+N" indicator if needed
            $sqlCount = "SELECT COUNT(*) AS cnt FROM $tblRel r INNER JOIN $tblTkt t ON (t.id = r.ticket_id) "
                ."WHERE r.user_id = $userId AND r.access_url_id = $urlId "
                ."AND t.project_id = $projectIdTracking AND t.category_id = $categoryTracking "
                ."AND t.start_date >= '$startUtc' AND t.start_date <= '$endUtc'";
            $resCount = Database::query($sqlCount);
            $rowCnt = Database::fetch_array($resCount, 'ASSOC');
            $total = (int) ($rowCnt['cnt'] ?? 0);
            if ($total > count($labels)) {
                $labels[] = '+'.($total - count($labels)).' …';
            }
            $trackingTitle = implode("\n", $labels);
        }
    }
    $trackingBox = '<span style="display:inline-block;width:12px;height:12px;background:'.($hasTrackingTicket ? '#28a745' : '#dc3545').'"'
        .($hasTrackingTicket && $trackingTitle !== '' ? ' title="'.htmlspecialchars($trackingTitle, ENT_QUOTES).'" aria-label="'.htmlspecialchars($trackingTitle, ENT_QUOTES).'"' : '')
        .'></span>';

   

    echo '<li class="list-group-item"><strong>'.$plugin->get_lang('RegistrationDate').':</strong> '.Security::remove_XSS($registrationDate).'</li>';

    echo '<li class="list-group-item"><strong>'.$plugin->get_lang('LastLogins').':</strong> '.$lastLogin.'</li>';

    $agendaBlock = '<span class="status-block">'
        .'<strong>'.$plugin->get_lang('Agenda').':</strong> '.$thisWeekBox.' | '.$nextWeekBox
        .'</span>';
    $suiviBlock = '<span class="status-block ml-2" style="margin-left:20px;">'
        .'<strong>'.$plugin->get_lang('FollowUp').':</strong> '.$trackingBox
        .'</span>';
    echo '<li class="list-group-item">'
        .$agendaBlock
        .' <button class="btn btn-success ml-2 agenda-remind-btn" data-user="'.$userId.'">'.$plugin->get_lang('Remind').'</button>'
        .' '.$suiviBlock
        .'</li>';



    // Company trade name (between Agenda and Teachers)

    try {

        // Ensure tables are present (legacy instances)

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

        echo '<li class="list-group-item"><strong>'.$plugin->get_lang('Entreprise').':</strong> '

            .$companyHtml.'</li>';

    } catch (Exception $e) {

        // ignore any DB errors and continue

    }

    $teacherNames = $plugin->getTeacherNamesForUser($userId);

    if ($teacherNames === '') {

        $teacherNames = '-';

    }

    echo '<li class="list-group-item"><strong>'.$plugin->get_lang('Teachers').':</strong> '

        .Security::remove_XSS($teacherNames).'</li>';

    echo '</ul>';



    // Time spent last weekÃ¢ÂÅ 

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

    $timeLabel = $plugin->get_lang('TimeSpentLastWeek');

    $detailsUrl = api_get_path(WEB_CODE_PATH).'mySpace/myStudents.php?student='.$userId;

    $profileUrl = api_get_path(WEB_PLUGIN_PATH).'user_profile/view.php?id='.$userId;

    echo '<div class="time-block d-flex justify-content-between align-items-center">';

    echo '<span><strong>'.Security::remove_XSS($timeLabel).':</strong> '.Security::remove_XSS(gmdate('H:i:s', $timeSpent)).'</span>';

    echo '</div>';

    echo '</div>';

    $detailsUrl = api_get_path(WEB_CODE_PATH).'mySpace/myStudents.php?student='.$userId;   



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

    echo '</div>'; // col-sm-4    

    echo '</div>'; // row



    

    // Tracked custom fields by category

    $tblField = Database::get_main_table(UserProfilePlugin::TABLE_FIELD);

    $tblValue = Database::get_main_table(UserProfilePlugin::TABLE_VALUE);

    $tblCat = Database::get_main_table(UserProfilePlugin::TABLE_CATEGORY);

    $sql = "SELECT f.id, f.name, f.field_type, f.category_id, v.value, COALESCE(v.checked,0) AS checked, c.name AS category_name

            FROM $tblField f

            LEFT JOIN $tblValue v ON (f.id = v.field_id AND v.user_id = $userId)

            LEFT JOIN $tblCat c ON (f.category_id = c.id)

            WHERE f.access_url_id = $urlId AND c.access_url_id = $urlId AND f.include_tracking = 1

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

            echo '<div class="list-group-item user-section-title text-center" style="padding:10px;">'.Security::remove_XSS($cat['label']).'</div>';

            echo '<div class="table-responsive"><table class="table table-hover mb-0">';

            echo '<thead><tr><th></th><th class="text-right">'.$plugin->get_lang('Completed').'</th></tr></thead><tbody>';

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

                echo '<td class="text-right"><input type="checkbox" disabled'.$checkedAttr.'></td>';

                echo '</tr>';

            }

            echo '</tbody></table></div>';

            echo '</div>';

        }

    }



    // Comments section (after custom fields, before action buttons)

    echo '<div class="mt-3 comments-box">';

    echo '<div class="list-group-item user-section-title" style="padding:10px;">'.$plugin->get_lang('Comments').'</div>';

    // Existing comments

    $tblComment = Database::get_main_table(UserProfilePlugin::TABLE_COMMENT);

    $tblUserMain = Database::get_main_table(TABLE_MAIN_USER);

    $sqlComments = "SELECT c.id, c.content, c.comment_date, c.author_id, u.firstname, u.lastname

                    FROM $tblComment c

                    LEFT JOIN $tblUserMain u ON (u.id = c.author_id)

                    WHERE c.user_id = $userId

                    ORDER BY c.comment_date DESC

                    LIMIT 3";

    $resComments = Database::query($sqlComments);

    if (Database::num_rows($resComments) > 0) {

        echo '<div class="list-group-item comments-content">';

        echo '<div class="table-responsive"><table class="table table-sm mb-0 comments-table">';

        $canDelete = api_is_platform_admin() || api_is_session_admin();

        echo '<thead><tr>'

            .'<th class="comment-meta" style="width:120px;">'.$plugin->get_lang('Date').'</th>'

            .'<th class="comment-meta" style="width:65%;">'.$plugin->get_lang('Comment').'</th>'

            .'<th class="comment-meta" style="width:140px;">'.$plugin->get_lang('Author').'</th>'

            .($canDelete ? '<th style="width:40px;" class="text-right">&nbsp;</th>' : '')

            .'</tr></thead><tbody>';

        while ($c = Database::fetch_array($resComments, 'ASSOC')) {

            $dateStr = api_format_date($c['comment_date'], '%d/%m/%y');

            $authorName = trim(($c['firstname'] ?? '').' '.($c['lastname'] ?? ''));

            echo '<tr>';

            echo '<td class="comment-meta">'.Security::remove_XSS($dateStr).'</td>';

            echo '<td class="comment-text">'.nl2br(Security::remove_XSS($c['content'])).'</td>';

            echo '<td class="comment-meta">'.Security::remove_XSS($authorName).'</td>';

            if ($canDelete) {

                $formAction = Security::remove_XSS(api_get_self()).'?search='.urlencode($search).'&per_page='.urlencode((string) $perPage).'&page='.urlencode((string) $page);

                echo '<td class="text-right">'

                    .'<form method="post" class="d-inline" action="'.$formAction.'" onsubmit="return confirm(\''.addslashes($plugin->get_lang('ConfirmYourChoice')).'\');">'

                    .'<input type="hidden" name="action" value="delete_comment">'

                    .'<input type="hidden" name="user_id" value="'.$userId.'">'

                    .'<input type="hidden" name="comment_id" value="'.(int) $c['id'].'">'

                    .'<input type="hidden" name="sec_token" value="'.$token.'">'

                    .'<button type="submit" class="btn btn-link p-0" title="'.$plugin->get_lang('Delete').'">'

                    .Display::return_icon('delete.png', $plugin->get_lang('Delete'), [], ICON_SIZE_SMALL)

                    .'</button>'

                    .'</form>'

                    .'</td>';

            }

            echo '</tr>';

        }

        echo '</tbody></table></div>';

        echo '</div>';

    } else {

        echo '<div class="list-group-item comments-content d-flex align-items-center justify-content-center text-muted">'.$plugin->get_lang('NoComments').'</div>';

    }

    // Add comment form

    echo '<div class="list-group-item">';

    echo '<form method="post" class="form comment-form" action="'.Security::remove_XSS(api_get_self()).'?search='.urlencode($search).'&per_page='.urlencode((string) $perPage).'&page='.urlencode((string) $page).'">';

    echo '<input type="hidden" name="action" value="add_comment">';

    echo '<input type="hidden" name="user_id" value="'.$userId.'">';

    echo '<input type="hidden" name="sec_token" value="'.$token.'">';

    echo '<input type="hidden" name="search" value="'.Security::remove_XSS($search).'">';

    echo '<input type="hidden" name="per_page" value="'.Security::remove_XSS($perPage).'">';

    echo '<input type="hidden" name="page" value="'.Security::remove_XSS((string) $page).'">';

    echo '<div class="form-group">';

    echo '<textarea name="comment" class="form-control" rows="2" placeholder="'.$plugin->get_lang('AddComment').'"></textarea>';

    echo '</div>';

    echo '<button type="submit" class="btn btn-primary">'.$plugin->get_lang('Add').'</button>';

    echo '</form>';

    echo '</div>';

    echo '</div>';

    echo '<div class="text-center mt-3 actions-row">';

    echo '<a class="btn btn-danger" title="'.$plugin->get_lang('AccessDetails').'" href="'.Security::remove_XSS($detailsUrl).'">'.$plugin->get_lang('FollowUp').'</a> ';
    $backTo = api_get_self().'?search='.urlencode($search).'&per_page='.urlencode((string) $perPage).'&page='.urlencode((string) $page);

    $synthUrl = api_get_path(WEB_PLUGIN_PATH).'user_profile/resume_tracking.php?'.http_build_query([

        'student' => (int) $userId,

        'back' => $backTo,

    ]);

    echo '<a class="btn btn-warning ml-2" href="'.Security::remove_XSS($synthUrl).'">'.$plugin->get_lang('TrackingSynthesis').'</a> ';

    echo '<a class="btn btn-primary ml-2" href="'.Security::remove_XSS($profileUrl).'">'.$plugin->get_lang('UserSheet').'</a> ';

    echo '<button class="btn btn-success ml-2 warn-btn" title="'.$plugin->get_lang('WarnTeacherLateDeadlines').'">'.$plugin->get_lang('Warn').'</button>';
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






