<?php
/* For licensing terms, see /license.txt */
require_once __DIR__.'/config.php';
require_once __DIR__.'/UserProfilePlugin.php';

if (!api_get_configuration_value('plugin_user_profile_enabled')) {
    api_not_allowed(true);
}

api_block_anonymous_users();

$studentId = isset($_GET['student']) ? (int) $_GET['student'] : 0;
$sessionId = isset($_GET['id_session']) ? (int) $_GET['id_session'] : 0;
$courseCode = isset($_GET['course']) ? Security::remove_XSS($_GET['course']) : '';

$plugin = UserProfilePlugin::create();
$config = $plugin->getConfiguration();
$projectId = (int) ($config['id_ticket_learner_tracking'] ?? 0);
$personalEmail = (string) ($config['email'] ?? '');

// Resolve course real ID from code if provided
$courseId = 0;
if (!empty($courseCode)) {
    $courseInfo = api_get_course_info($courseCode);
    if (!empty($courseInfo)) {
        $courseId = (int) $courseInfo['real_id'];
    }
}

// Build category list from the configured project
$categoryList = [];
if ($projectId > 0) {
    $categories = TicketManager::get_all_tickets_categories($projectId, 'category.name ASC');
    foreach ($categories as $cat) {
        $categoryList[(int) $cat['category_id']] = $cat['name'].(empty($cat['description']) ? '' : (': '.$cat['description']));
    }
}

$studentInfo = api_get_user_info($studentId);
if (empty($studentInfo)) {
    api_not_allowed(true);
}

// Subject: "Firstname Lastname sem XX" with previous week number
$weekPrefix = 'sem';
$prevWeek = (int) date('W', strtotime('-1 week'));
$subject = trim(($studentInfo['firstname'] ?? '').' '.($studentInfo['lastname'] ?? '')).' '.$weekPrefix.' '.$prevWeek;

// Do not output anything before handling POST (to allow header redirect)

// Form: category select, subject (read-only), message editor
$form = new FormValidator('tracking_user_ticket', 'POST', api_get_self().'?'.http_build_query([
    'student' => $studentId,
    'id_session' => $sessionId,
    'course' => $courseCode,
]));

if ($projectId <= 0) {
    Display::addFlash(Display::return_message(UserProfilePlugin::create()->get_lang('TrackingNotConfigured'), 'warning'));
}

$weekOptions = [];
for ($w = 1; $w <= 53; $w++) {
    $weekOptions[$w] = (string) $w;
}
$form->addSelect('week_number', UserProfilePlugin::create()->get_lang('WeekNumber'), $weekOptions, ['id' => 'week_number']);
$form->setDefaults(['week_number' => $prevWeek]);

$form->addSelect('category_id', get_lang('Category'), $categoryList, ['id' => 'category_id']);
$form->addText('subject', get_lang('Subject'), false, ['value' => $subject, 'readonly' => 'readonly']);
// (Removed) page-level templates override: use global editor behavior

// Editor configuration (global behavior handled in CkEditor::simpleFormatTemplates)
$form->addHtmlEditor(
    'content',
    get_lang('Message'),
    false,
    false,
    [
        'ToolbarSet' => 'Profile',
        'Height' => '250',
    ]
);
$form->addButtonSave(get_lang('Save'));

$form->addRule('category_id', get_lang('ThisFieldIsRequired'), 'required');

if ($form->validate()) {
    $values = $form->exportValues();
    $categoryId = (int) ($values['category_id'] ?? 0);
    $content = (string) ($values['content'] ?? '');
    $chosenWeek = (int) ($values['week_number'] ?? $prevWeek);

    // Build subject from selected week number
    $subjectFinal = trim(($studentInfo['firstname'] ?? '').' '.($studentInfo['lastname'] ?? '')).' '.$weekPrefix.' '.$chosenWeek;

    $ok = TicketManager::add(
        $categoryId,
        $courseId,
        $sessionId,
        $projectId,
        0, // other_area
        $subjectFinal,
        $content,
        $personalEmail,
        [], // attachments
        TicketManager::SOURCE_PLATFORM,
        '', // priority default
        '', // status default
        $studentId // assign to the user
    );

    if ($ok) {
        // Retrieve last created ticket by current user with same subject
        $tblTicket = Database::get_main_table(TABLE_TICKET_TICKET);
        $currentUserId = api_get_user_id();
        $safeSubject = Database::escape_string($subjectFinal);
        $res = Database::query(
            "SELECT id FROM $tblTicket WHERE sys_insert_user_id = $currentUserId AND subject = '$safeSubject' ORDER BY id DESC LIMIT 1"
        );
        $ticketRow = Database::fetch_array($res);
        if ($ticketRow && !empty($ticketRow['id'])) {
            $ticketId = (int) $ticketRow['id'];
            $tblRelation = Database::get_main_table(UserProfilePlugin::TABLE_RELATION_STUDENT_TICKET);
            $accessUrlId = (int) api_get_current_access_url_id();
            Database::insert($tblRelation, [
                'user_id' => $studentId,
                'ticket_id' => $ticketId,
                'access_url_id' => $accessUrlId,
            ]);
        }

        // Close the popup and reload the opener so flash messages show immediately
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><script>'
            .'(function(){'
            .'  try {'
            .'    if (window.opener && !window.opener.closed) {'
            .'      try { window.opener.focus(); } catch(e){}'
            .'      try { window.opener.location.reload(); } catch(e){}'
            .'    }'
            .'  } catch(e) {}'
            .'  window.close();'
            .'})();'
            .'</script></head><body></body></html>';
        exit;
    } else {
        Display::addFlash(Display::return_message(get_lang('ThereWasAnErrorRegisteringTheTicket'), 'error'));
    }
}

// Now render page
Display::display_header(get_lang('UserProfile'));
echo '<div class="container">';
echo Display::page_subheader(UserProfilePlugin::create()->get_lang('FillTracking'));

// Live-update subject when week changes
$studentFullName = trim(($studentInfo['firstname'] ?? '').' '.($studentInfo['lastname'] ?? ''));
echo '<script>';
echo 'document.addEventListener("DOMContentLoaded",function(){';
echo 'var weekSel=document.getElementById("week_number");';
echo 'var subj=document.querySelector("input[name=subject]");';
echo 'function updateSubject(){ if(!weekSel||!subj) return; subj.value=' . json_encode($studentFullName) . ' + " sem " + weekSel.value; }';
echo 'if(weekSel){ weekSel.addEventListener("change", updateSubject); updateSubject(); }';
echo '});';
echo '</script>';
$form->display();
echo '</div>';

Display::display_footer();
