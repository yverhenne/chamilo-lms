<?php
/* For licensing terms, see /license.txt */
require_once __DIR__.'/config.php';
require_once __DIR__.'/UserProfilePlugin.php';
require_once api_get_path(LIBRARY_PATH).'TicketManager.php';
use Chamilo\CoreBundle\Component\Utils\ChamiloApi;

if (!api_get_configuration_value('plugin_user_profile_enabled')) {
    api_not_allowed(true);
}

// Only platform admins and session admins
if (!(api_is_platform_admin() || api_is_session_admin())) {
    api_not_allowed(true);
}

$plugin = UserProfilePlugin::create();
$urlId = (int) api_get_current_access_url_id();
$tblMonthly = Database::get_main_table(UserProfilePlugin::TABLE_MONTHLY_EVALUATION);
$tblUser = Database::get_main_table(TABLE_MAIN_USER);

$search = trim((string) ($_GET['search'] ?? ''));
$perPage = $_GET['per_page'] ?? '10';
$allowedPerPage = ['10','20','30','50','all'];
if (!in_array($perPage, $allowedPerPage, true)) { $perPage = '10'; }
$page = max(1, (int) ($_GET['page'] ?? 1));

// CSRF
$check = Security::check_token('post');
$token = Security::get_token();
// Page-specific CSS for admin monthly evaluation UI
global $htmlHeadXtra;
$htmlHeadXtra[] = '<link rel="stylesheet" href="'.api_get_path(WEB_PLUGIN_PATH).'user_profile/assets/css/admin_monthly.css">';
$htmlHeadXtra[] = '<script src="'.api_get_path(WEB_PLUGIN_PATH).'user_profile/assets/js/common.js"></script>';


// Handle actions
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $check) {
    $action = $_POST['action'] ?? '';
    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    if ($id > 0) {
        if ($action === 'validate') {
            $evaluationRow = Database::select(
                'id_student, validation, month, year',
                $tblMonthly,
                ['where' => ['id = ? AND access_url_id = ?' => [$id, $urlId]]],
                'first'
            );

            Database::update($tblMonthly, ['validation' => 1], ['id = ? AND access_url_id = ?' => [$id, $urlId]]);

            if ($evaluationRow && (int) ($evaluationRow['validation'] ?? 0) === 0) {
                $studentId = (int) ($evaluationRow['id_student'] ?? 0);
                $config = $plugin->getConfiguration();
                $projectId = (int) ($config['id_ticket_learner_tracking'] ?? 0);
                $categoryId = (int) ($config['category2'] ?? 0);

                if ($projectId > 0 && $categoryId > 0 && $studentId > 0) {
                    try {
                        $ticketBody = $plugin->renderMonthlyEvaluationBody($studentId, $id, false, true);
                        if ($ticketBody !== null) {
                            $studentInfo = api_get_user_info($studentId);
                            if (!empty($studentInfo)) {
                                $subjectPrefix = UserProfilePlugin::create()->get_lang('MonthlyEvaluation');
                                if (!is_string($subjectPrefix) || preg_match('/^\[.*\]$/', $subjectPrefix)) {
                                    $subjectPrefix = UserProfilePlugin::create()->get_lang('MonthlyEvaluation');
                                }
                                $firstName = Security::remove_XSS((string) ($studentInfo['firstname'] ?? ''));
                                $lastName = Security::remove_XSS((string) ($studentInfo['lastname'] ?? ''));

                                $monthLabel = '';
                                if (isset($evaluationRow['month'], $evaluationRow['year'])) {
                                    $months = api_get_months_long();
                                    $monthIndex = (int) $evaluationRow['month'];
                                    $monthName = ($monthIndex >= 1 && $monthIndex <= 12)
                                        ? $months[$monthIndex - 1]
                                        : (string) $monthIndex;

                                    $monthLabel = trim($monthName.' '.(int) $evaluationRow['year']);
                                }


                                $subjectParts = [$subjectPrefix];
                                if ($monthLabel !== '') {
                                    $subjectParts[] = $monthLabel;
                                }
                                $namePart = trim($firstName.' '.$lastName);
                                if ($namePart !== '') {
                                    $subjectParts[] = $namePart;
                                }
                                $subject = trim(implode(' ', array_filter($subjectParts, static function ($part) {
                                    return $part !== '';
                                })));
                                if ($subject === '') {
                                    $subject = $subjectPrefix;
                                }

                                // Fetch tutor email but do NOT set it in the ticket's personal email field
                                $tutorEmail = $plugin->getTutorEmailForUser($studentId);
                                $personalEmail = '';

                                // Send email to tutor with PDF attachment of the evaluation preview
                                if (!empty($tutorEmail)) {
                                    try {
                                        // Build PDF content (reuse preview template and styles)
                                        $title = UserProfilePlugin::create()->get_lang('MonthlyEvaluation');
                                        if (preg_match('/^\[.*\]$/', (string) $title)) {
                                            $title = 'Monthly evaluation';
                                        }

                                        $studentNameParts = array_filter([
                                            $studentInfo['firstname'] ?? '',
                                            $studentInfo['lastname'] ?? '',
                                        ]);
                                        $studentName = trim(implode(' ', $studentNameParts));
                                        $subtitle = $studentName !== '' ? Security::remove_XSS($studentName) : '';

                                        $logo = ChamiloApi::getPlatformLogoPath('', true);
                                        $header = '<div class="pdf-header" style="text-align:right;"><img src="'.$logo.'" height="50" alt="logo"></div>';
                                        $date = api_format_date(api_get_local_time(), DATE_TIME_FORMAT_LONG);
                                        $footer = '<table class="pdf-footer" width="100%"><tr><td>'.$date.'</td><td style="text-align:right">{PAGENO}/{nb}</td></tr></table>';

                                        $view = new Template('', false, false, false, false, true, false);
                                        $view->assign('title', Security::remove_XSS($title));
                                        $view->assign('subtitle', $subtitle);
                                        $view->assign('evaluation_body', $ticketBody);
                                        $html = $view->fetch('user_profile/view/monthly_evaluation_pdf.tpl');

                                        $css = <<<'CSS'
body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; color: #333; background-color: #fff; }
.pdf-header, .pdf-footer { font-size: 10px; color: #666; }
.pdf-footer { margin-top: 24px; border-top: 1px solid #dbe1e6; padding-top: 8px; }
.monthly-evaluation-pdf { padding: 8px 0; }
.monthly-evaluation-pdf__title { font-size: 20px; margin: 0 0 6px; text-align: center; font-weight: 600; color: #2a4a5c; }
.monthly-evaluation-pdf__subtitle { text-align: center; font-size: 13px; margin-bottom: 16px; color: #54697a; }
.monthly-evaluation { padding: 4px 0; }
.monthly-evaluation--pdf .card, .monthly-evaluation--pdf .panel { box-shadow: none; border-color: #dbe1e6; }
.card { background-color: #fff; border: 1px solid #dbe1e6; border-radius: 6px; margin: 0 0 16px; }
.user-profile.card { margin-top: 0; }
.card-body { padding: 14px; }
.card-title { font-weight: 600; text-align: center; background: #e1f0f5; margin: 0; padding: 10px 12px; }
.list-group { list-style: none; margin: 0; padding: 0; }
.list-group-item { border: 0; font-size: 13px; padding: 6px 0; border-bottom: 1px solid #e5edf2; }
.list-group-item:last-child { border-bottom: 0; }
.comment-body { white-space: pre-wrap; line-height: 1.45; }
.panel { border: 1px solid #dbe1e6; border-radius: 6px; margin: 0 0 16px; background: #fff; }
.panel-heading { background: #f3f7fa; padding: 10px 12px; font-weight: 600; border-bottom: 1px solid #dbe1e6; }
.panel-body { padding: 12px; }
.text-center { text-align: center; }
.avg-progress-pdf { display: flex; justify-content: center; align-items: center; padding: 18px 0; }
.avg-progress-value { display: inline-block; min-width: 80px; border: 2px solid #3ba557; border-radius: 999px; padding: 8px 18px; font-size: 20px; font-weight: 600; color: #3ba557; }
.progress { background-color: #eef3f6; border-radius: 999px; height: 16px; overflow: hidden; margin-bottom: 8px; }
.progress-bar { background: #3ba557; color: #fff; font-size: 11px; line-height: 16px; text-align: center; }
.progress-bar-success { background: #3ba557; }
.session-progress-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.session-progress-table__label { padding: 6px 8px; border-bottom: 1px solid #e5edf2; width: 70%; }
.session-progress-table__value { padding: 6px 8px; border-bottom: 1px solid #e5edf2; text-align: right; font-weight: 600; color: #3ba557; }
.session-progress-table tr:last-child td { border-bottom: 0; }
CSS;

                                        $pdf = new PDF('A4', 'P');
                                        $pdf->set_custom_header($header);
                                        $pdf->set_custom_footer($footer);

                                        $studentIdentifier = !empty($studentInfo['username']) ? $studentInfo['username'] : (string) $studentId;
                                        $pdfBaseName = 'monthly_evaluation_'.$studentIdentifier.'_'.$id;
                                        $pdf->params['filename'] = $pdfBaseName;

                                        // Save PDF to a temporary file and attach as stream
                                        $tmpFile = api_get_path(SYS_ARCHIVE_PATH).uniqid('me_pdf_').'.pdf';
                                        $pdf->content_to_pdf($html, $css, $pdfBaseName, null, 'F', true, $tmpFile, false, false);

                                        $attachments = [];
                                        if (is_file($tmpFile)) {
                                            $pdfContent = @file_get_contents($tmpFile);
                                            if ($pdfContent !== false) {
                                                $attachments[] = [
                                                    'stream' => $pdfContent,
                                                    'filename' => $pdfBaseName.'.pdf',
                                                ];
                                            }
                                            @unlink($tmpFile);
                                        }

                                        $mailSubject = UserProfilePlugin::create()->get_lang('MonthlyEvaluation');
                                        if (preg_match('/^\[.*\]$/', (string) $mailSubject)) {
                                            $mailSubject = 'Monthly evaluation';
                                        }
                                        if ($studentName !== '') {
                                            $mailSubject .= ' - '.$studentName;
                                        }

                                        // Use the PDF-friendly body and add student name at the top
                                        $nameHeader = '';
                                        if ($studentName !== '') {
                                            $nameHeader = '<div style="background-color:#fff;border:1px solid #dbe1e6;border-radius:6px;margin:0 0 16px;">'
                                                .'<div style="font-weight:600;text-align:center;background:#e1f0f5;margin:0;padding:10px 12px;">'
                                                .Security::remove_XSS($studentName)
                                                .'</div>'
                                                .'</div>';
                                        }
                                        // Add inline CSS to make sections match PDF styling in email clients
                                        $emailBody = '<style>'.$css.'</style>'.$nameHeader.$ticketBody;
                                        @api_mail_html('', $tutorEmail, $mailSubject, $emailBody, '', '', [], $attachments);
                                    } catch (Throwable $e) {
                                        error_log('Monthly evaluation tutor email failed: '.$e->getMessage());
                                    }
                                }

                                // Build ticket content with same header and styles as the email
                                $ticketContent = '';
                                if (isset($emailBody) && $emailBody !== '') {
                                    // Inline critical styles for sections in case ticket view strips <style>
                                    $ticketTransformed = $ticketBody;
                                    $ticketTransformed = str_replace(
                                        'class="avg-progress-pdf"',
                                        'class="avg-progress-pdf" style="display:flex;justify-content:center;align-items:center;padding:18px 0;"',
                                        $ticketTransformed
                                    );
                                    $ticketTransformed = str_replace(
                                        'class="avg-progress-value"',
                                        'class="avg-progress-value" style="display:inline-block;min-width:80px;border:2px solid #3ba557;border-radius:999px;padding:8px 18px;font-size:20px;font-weight:600;color:#3ba557;"',
                                        $ticketTransformed
                                    );
                                    $ticketTransformed = str_replace(
                                        'class="session-progress-table"',
                                        'class="session-progress-table" style="width:100%;border-collapse:collapse;font-size:13px;"',
                                        $ticketTransformed
                                    );
                                    $ticketTransformed = str_replace(
                                        'class="session-progress-table__label"',
                                        'class="session-progress-table__label" style="padding:6px 8px;border-bottom:1px solid #e5edf2;width:70%;"',
                                        $ticketTransformed
                                    );
                                    $ticketTransformed = str_replace(
                                        'class="session-progress-table__value"',
                                        'class="session-progress-table__value" style="padding:6px 8px;border-bottom:1px solid #e5edf2;text-align:right;font-weight:600;color:#3ba557;"',
                                        $ticketTransformed
                                    );
                                    $ticketContent = '<style>'.$css.'</style>'.$nameHeader.$ticketTransformed;
                                } else {
                                    $studentName = trim($firstName.' '.$lastName);
                                    $nameHeader = '';
                                    if ($studentName !== '') {
                                        $nameHeader = '<div style="background-color:#fff;border:1px solid #dbe1e6;border-radius:6px;margin:0 0 16px;">'
                                            .'<div style="font-weight:600;text-align:center;background:#e1f0f5;margin:0;padding:10px 12px;">'
                                            .Security::remove_XSS($studentName)
                                            .'</div>'
                                            .'</div>';
                                    }
                                    $css2 = <<<'CSS'
body { font-family: 'DejaVu Sans', sans-serif; font-size: 12px; color: #333; background-color: #fff; }
.pdf-header, .pdf-footer { font-size: 10px; color: #666; }
.pdf-footer { margin-top: 24px; border-top: 1px solid #dbe1e6; padding-top: 8px; }
.monthly-evaluation-pdf { padding: 8px 0; }
.monthly-evaluation-pdf__title { font-size: 20px; margin: 0 0 6px; text-align: center; font-weight: 600; color: #2a4a5c; }
.monthly-evaluation-pdf__subtitle { text-align: center; font-size: 13px; margin-bottom: 16px; color: #54697a; }
.monthly-evaluation { padding: 4px 0; }
.monthly-evaluation--pdf .card, .monthly-evaluation--pdf .panel { box-shadow: none; border-color: #dbe1e6; }
.card { background-color: #fff; border: 1px solid #dbe1e6; border-radius: 6px; margin: 0 0 16px; }
.user-profile.card { margin-top: 0; }
.card-body { padding: 14px; }
.card-title { font-weight: 600; text-align: center; background: #e1f0f5; margin: 0; padding: 10px 12px; }
.list-group { list-style: none; margin: 0; padding: 0; }
.list-group-item { border: 0; font-size: 13px; padding: 6px 0; border-bottom: 1px solid #e5edf2; }
.list-group-item:last-child { border-bottom: 0; }
.comment-body { white-space: pre-wrap; line-height: 1.45; }
.panel { border: 1px solid #dbe1e6; border-radius: 6px; margin: 0 0 16px; background: #fff; }
.panel-heading { background: #f3f7fa; padding: 10px 12px; font-weight: 600; border-bottom: 1px solid #dbe1e6; }
.panel-body { padding: 12px; }
.text-center { text-align: center; }
.avg-progress-pdf { display: flex; justify-content: center; align-items: center; padding: 18px 0; }
.avg-progress-value { display: inline-block; min-width: 80px; border: 2px solid #3ba557; border-radius: 999px; padding: 8px 18px; font-size: 20px; font-weight: 600; color: #3ba557; }
.progress { background-color: #eef3f6; border-radius: 999px; height: 16px; overflow: hidden; margin-bottom: 8px; }
.progress-bar { background: #3ba557; color: #fff; font-size: 11px; line-height: 16px; text-align: center; }
.progress-bar-success { background: #3ba557; }
.session-progress-table { width: 100%; border-collapse: collapse; font-size: 13px; }
.session-progress-table__label { padding: 6px 8px; border-bottom: 1px solid #e5edf2; width: 70%; }
.session-progress-table__value { padding: 6px 8px; border-bottom: 1px solid #e5edf2; text-align: right; font-weight: 600; color: #3ba557; }
.session-progress-table tr:last-child td { border-bottom: 0; }
CSS;
                                    // Apply inline styles as fallback
                                    $ticketTransformed = $ticketBody;
                                    $ticketTransformed = str_replace(
                                        'class="avg-progress-pdf"',
                                        'class="avg-progress-pdf" style="display:flex;justify-content:center;align-items:center;padding:18px 0;"',
                                        $ticketTransformed
                                    );
                                    $ticketTransformed = str_replace(
                                        'class="avg-progress-value"',
                                        'class="avg-progress-value" style="display:inline-block;min-width:80px;border:2px solid #3ba557;border-radius:999px;padding:8px 18px;font-size:20px;font-weight:600;color:#3ba557;"',
                                        $ticketTransformed
                                    );
                                    $ticketTransformed = str_replace(
                                        'class="session-progress-table"',
                                        'class="session-progress-table" style="width:100%;border-collapse:collapse;font-size:13px;"',
                                        $ticketTransformed
                                    );
                                    $ticketTransformed = str_replace(
                                        'class="session-progress-table__label"',
                                        'class="session-progress-table__label" style="padding:6px 8px;border-bottom:1px solid #e5edf2;width:70%;"',
                                        $ticketTransformed
                                    );
                                    $ticketTransformed = str_replace(
                                        'class="session-progress-table__value"',
                                        'class="session-progress-table__value" style="padding:6px 8px;border-bottom:1px solid #e5edf2;text-align:right;font-weight:600;color:#3ba557;"',
                                        $ticketTransformed
                                    );
                                    $ticketContent = '<style>'.$css2.'</style>'.$nameHeader.$ticketTransformed;
                                }
                                $ticketCreated = TicketManager::add(
                                    $categoryId,
                                    0,
                                    0,
                                    $projectId,
                                    0,
                                    $subject,
                                    $ticketContent,
                                    $personalEmail,
                                    [],
                                    TicketManager::SOURCE_PLATFORM,
                                    '',
                                    '',
                                    $studentId
                                );

                                if ($ticketCreated) {
                                    $tblTicket = Database::get_main_table(TABLE_TICKET_TICKET);
                                    $currentUserId = api_get_user_id();
                                    $safeSubject = Database::escape_string($subject);
                                    $res = Database::query(
                                        "SELECT id FROM $tblTicket WHERE sys_insert_user_id = $currentUserId AND subject = '$safeSubject' ORDER BY id DESC LIMIT 1"
                                    );
                                    $ticketRow = Database::fetch_array($res, 'ASSOC');
                                    if ($ticketRow && !empty($ticketRow['id'])) {
                                        $ticketId = (int) $ticketRow['id'];
                                        $tblRelation = Database::get_main_table(UserProfilePlugin::TABLE_RELATION_STUDENT_TICKET);
                                        $existsRelation = Database::select(
                                            'ticket_id',
                                            $tblRelation,
                                            ['where' => ['user_id = ? AND ticket_id = ?' => [$studentId, $ticketId]]],
                                            'first'
                                        );
                                        if (!$existsRelation) {
                                            Database::insert($tblRelation, [
                                                'user_id' => $studentId,
                                                'ticket_id' => $ticketId,
                                                'access_url_id' => $urlId,
                                            ]);
                                        }
                                    }
                                }
                            }
                        }
                    } catch (Throwable $e) {
                        error_log('Monthly evaluation ticket creation failed: '.$e->getMessage());
                    }
                }
            }

            Display::addFlash(Display::return_message(get_lang('Saved')));
        } elseif ($action === 'invalidate') {
            Database::update($tblMonthly, ['validation' => 0], ['id = ? AND access_url_id = ?' => [$id, $urlId]]);
            Display::addFlash(Display::return_message(get_lang('Saved')));
        } elseif ($action === 'delete') {
            // Allow delete if not validated; platform admin can delete even if validated
            $row = Database::select('validation', $tblMonthly, ['where' => ['id = ? AND access_url_id = ?' => [$id, $urlId]]], 'first');
            $canDelete = $row && ((int) $row['validation'] === 0 || api_is_platform_admin());
            if ($canDelete) {
                Database::delete($tblMonthly, ['id = ?' => $id]);
                Display::addFlash(Display::return_message(UserProfilePlugin::create()->get_lang('Deleted')));
            } else {
                Display::addFlash(Display::return_message(UserProfilePlugin::create()->get_lang('NotAllowed'), 'error'));
            }
        }
        Security::clear_token();
        header('Location: '.api_get_self().'?'.http_build_query(['search' => $search, 'per_page' => $perPage, 'page' => $page]));
        exit;
    }
}

// Build users list for this URL (all students), with optional search
$fromUsers = "$tblUser u";
if (api_is_multiple_url_enabled()) {
    $tblUrl = Database::get_main_table(TABLE_MAIN_ACCESS_URL_REL_USER);
    $fromUsers .= " INNER JOIN $tblUrl url ON (u.id = url.user_id AND url.access_url_id = $urlId)";
}
$whereUsers = "u.status = ".STUDENT;
if ($search !== '') {
    $escaped = Database::escape_string('%'.$search.'%');
    $whereUsers .= " AND (u.firstname LIKE '$escaped' OR u.lastname LIKE '$escaped' OR u.username LIKE '$escaped' OR u.official_code LIKE '$escaped')";
}

// Count students
$countSql = "SELECT COUNT(*) AS total FROM $fromUsers WHERE $whereUsers";
$countRes = Database::query($countSql);
$totalStudents = (int) (Database::fetch_array($countRes, 'ASSOC')['total'] ?? 0);

// Pagination
$limitSql = '';
if ($perPage !== 'all') {
    $per = (int) $perPage;
    $offset = ($page - 1) * $per;
    $limitSql = " LIMIT $offset, $per";
}

// Fetch students page
$studentsSql = "SELECT u.id AS id_student, u.firstname, u.lastname, u.username FROM $fromUsers WHERE $whereUsers ORDER BY u.lastname, u.firstname".$limitSql;
$studentsRes = Database::query($studentsSql);
$students = Database::store_result($studentsRes);
$studentIds = array_map(static fn($r) => (int) $r['id_student'], $students);

// Fetch evaluations for listed students
$evalsByStudent = [];
if (!empty($studentIds)) {
    $ids = implode(',', array_map('intval', $studentIds));
    $evalSql = "SELECT id, id_student, month, year, comment, validation FROM $tblMonthly WHERE access_url_id = $urlId AND id_student IN ($ids) ORDER BY year DESC, month DESC, id DESC";
    $evalRes = Database::query($evalSql);
    while ($row = Database::fetch_array($evalRes, 'ASSOC')) {
        $evalsByStudent[(int) $row['id_student']][] = $row;
    }
}

Display::display_header(UserProfilePlugin::create()->get_lang('MonthlyEvaluation'));

echo UserProfilePlugin::create()->renderTopMenu();

// Search + per-page form
echo '<div class="card user-profile mb-3"><div class="card-body">';
echo '<form method="get" class="form-inline">';
echo '<input type="text" class="form-control" name="search" placeholder="'.UserProfilePlugin::create()->get_lang('SearchUser').'" value="'.Security::remove_XSS($search).'">';
echo '<select name="per_page" class="form-control">';
foreach ($allowedPerPage as $opt) {
    $label = $opt === 'all' ? get_lang('All') : $opt;
    $sel = $perPage === $opt ? ' selected' : '';
    echo '<option value="'.$opt.'"'.$sel.'>'.Security::remove_XSS((string) $label).'</option>';
}
echo '</select>';
echo '<button type="submit" class="btn btn-primary">'.UserProfilePlugin::create()->get_lang('Search').'</button>';
echo '</form>';
echo '</div></div>';

if (empty($students)) {
    echo Display::return_message(UserProfilePlugin::create()->get_lang('NoResultsFound'), 'normal');
} else {
    foreach ($students as $stu) {
        $sid = (int) $stu['id_student'];
        $name = Security::remove_XSS(trim($stu['firstname'].' '.$stu['lastname']).' ('.$stu['username'].')');
        $addUrl = api_get_path(WEB_PLUGIN_PATH).'user_profile/monthly_evaluation.php?student='.$sid.'&popup=1';
        $addIcon = Display::url(
            Display::return_icon('add.png', get_lang('Add'), [], ICON_SIZE_SMALL),
            Security::remove_XSS($addUrl),
            ['class' => 'js-popup', 'data-width' => '900', 'data-height' => '700', 'data-popup' => 'me'.$sid]
        );
        echo '<div class="card user-profile mb-4">';
        echo '<div class="card-title am-title">'
            .'<strong class="am-title__label">'.$name.'</strong>'
            .'<span class="am-title__actions">'.$addIcon.'</span>'
            .'</div>';
        echo '<div class="card-body">';

        $rows = $evalsByStudent[$sid] ?? [];
        $last3 = array_slice($rows, 0, 3);
        $others = array_slice($rows, 3);

        // Fixed frame: last 3 (as list for consistency)
        echo '<div class="mb-3 am-box">';
        if (empty($last3)) {
            echo '<div>'.UserProfilePlugin::create()->get_lang('NoComment').'</div>';
        } else {
            echo '<ul class="list-group mb-0">';
            foreach ($last3 as $r) {
                $canEdit = ((int) $r['validation'] === 0) || api_is_platform_admin();
                $status = (int) $r['validation'] === 1 ? '<span class="badge badge-success">'.get_lang('Validated').'</span>' : '<span class="badge badge-secondary">'.get_lang('NotValidated').'</span>';
                $comment = nl2br(Security::remove_XSS((string) $r['comment']));
                $label = (int) $r['month'].'/'.(int) $r['year'];
                echo '<li class="list-group-item">';
                echo '<div class="am-flex">';
                echo '<div class="am-flex-1">';
                echo '<strong>'.Security::remove_XSS($label).'</strong><br>'.$comment;
                echo '</div>';
                echo '<div class="am-flex-auto">';
                echo '<div class="am-actions-top">';
                // Preview button (before validation)
                $previewUrl = api_get_path(WEB_PLUGIN_PATH)
                    .'user_profile/preview_monthly_evaluation.php?student='.$sid.'&id='.(int) $r['id'].'&popup=1';
                echo '<a href="'.Security::remove_XSS($previewUrl).'" class="btn btn-sm btn-outline-info mr-2 js-popup" data-width="1000" data-height="800" data-popup="preview'.(int)$r['id'].'">Prévisualisation</a>';
                if ((int) $r['validation'] === 0) {
                    echo '<form method="post" class="d-inline" onsubmit="return confirm(\''.addslashes(get_lang('ConfirmYourChoice')).'\');">'
                        .'<input type="hidden" name="sec_token" value="'.$token.'">'
                        .'<input type="hidden" name="action" value="validate">'
                        .'<input type="hidden" name="id" value="'.(int) $r['id'].'">'
                        .'<button type="submit" class="btn btn-sm btn-outline-success" title="'.UserProfilePlugin::create()->get_lang('ValidateTooltip').'">'.UserProfilePlugin::create()->get_lang('Validation').'</button>'
                        .'</form> ';
                } else {
                    // When validated, show a button that allows to invalidate on click
                    echo '<form method="post" class="d-inline" onsubmit="return confirm(\''.addslashes(get_lang('ConfirmYourChoice')).'\');">'
                        .'<input type="hidden" name="sec_token" value="'.$token.'">'
                        .'<input type="hidden" name="action" value="invalidate">'
                        .'<input type="hidden" name="id" value="'.(int) $r['id'].'">'
                        .'<button type="submit" class="btn btn-sm btn-success" title="'.UserProfilePlugin::create()->get_lang('InvalidateTooltip').'">'.UserProfilePlugin::create()->get_lang('Validated').'</button>'
                        .'</form> ';
                }
                echo '</div>';
                echo '<div class="am-actions-bottom">';
                $editUrl = api_get_path(WEB_PLUGIN_PATH).'user_profile/monthly_evaluation.php?student='.$sid.'&edit='.(int) $r['id'].'&popup=1';
                if ($canEdit && (int) $r['validation'] === 0) {
                    echo '<a href="'.Security::remove_XSS($editUrl).'" class="mr-2 js-popup" data-width="900" data-height="700" data-popup="me'.(int)$r['id'].'" title="'.get_lang('Edit').'">'.Display::return_icon('edit.png', get_lang('Edit'), [], ICON_SIZE_SMALL).'</a>';
                    echo '<form method="post" class="d-inline" onsubmit="return confirm(\''.addslashes(get_lang('ConfirmYourChoice')).'\');">'
                        .'<input type="hidden" name="sec_token" value="'.$token.'">'
                        .'<input type="hidden" name="action" value="delete">'
                        .'<input type="hidden" name="id" value="'.(int) $r['id'].'">'
                        .'<button type="submit" class="btn btn-link p-0" title="'.get_lang('Delete').'">'.Display::return_icon('delete.png', get_lang('Delete'), [], ICON_SIZE_SMALL).'</button>'
                        .'</form>';
                } else {
                    echo '-';
                }
                echo '</div>';
                echo '</div>';
                echo '</div>';
                echo '</li>';
            }
            echo '</ul>';
        }
        echo '</div>';

        // Others list (collapsed like a dropdown after the first 3)
        if (!empty($others)) {
            $collapseId = 'me-others-'.(int) $sid;
            echo '<div class="mt-2">';
            echo '<a class="btn btn-link p-0" data-toggle="collapse" href="#'.$collapseId.'" role="button" aria-expanded="false" aria-controls="'.$collapseId.'">'
                .Security::remove_XSS(UserProfilePlugin::create()->get_lang('More')).'</a>';
            echo '<div class="collapse" id="'.$collapseId.'">';
            echo '<ul class="list-group mb-0 comments-list">';
            foreach ($others as $r) {
                $canEdit = ((int) $r['validation'] === 0) || api_is_platform_admin();
                $status = (int) $r['validation'] === 1 ? '<span class="badge badge-success">'.get_lang('Validated').'</span>' : '<span class="badge badge-secondary">'.get_lang('NotValidated').'</span>';
                $comment = nl2br(Security::remove_XSS((string) $r['comment']));
                $label = (int) $r['month'].'/'.(int) $r['year'];
                echo '<li class="list-group-item">';
                echo '<div class="am-flex">';
                echo '<div class="am-flex-1">';
                echo '<strong>'.Security::remove_XSS($label).'</strong><br>'.$comment;
                echo '</div>';
                echo '<div class="am-flex-auto">';
                echo '<div class="am-actions-top">';
                // Preview button (before validation)
                $previewUrl = api_get_path(WEB_PLUGIN_PATH)
                    .'user_profile/preview_monthly_evaluation.php?student='.$sid.'&id='.(int) $r['id'].'&popup=1';
                echo '<a href="'.Security::remove_XSS($previewUrl).'" class="btn btn-sm btn-outline-info mr-2 js-popup" data-width="1000" data-height="800" data-popup="preview'.(int)$r['id'].'">Prévisualisation</a>';
                if ((int) $r['validation'] === 0) {
                echo '<form method="post" class="d-inline" onsubmit="return confirm(\''.addslashes(get_lang('ConfirmYourChoice')).'\');">'
                        .'<input type="hidden" name="sec_token" value="'.$token.'">'
                        .'<input type="hidden" name="action" value="validate">'
                        .'<input type="hidden" name="id" value="'.(int) $r['id'].'">'
                        .'<button type="submit" class="btn btn-sm btn-outline-success" title="'.UserProfilePlugin::create()->get_lang('ValidateTooltip').'">'.UserProfilePlugin::create()->get_lang('Validation').'</button>'
                        .'</form> ';
                } else {
                    // When validated, show a button that allows to invalidate on click
                    echo '<form method="post" class="d-inline" onsubmit="return confirm(\''.addslashes(get_lang('ConfirmYourChoice')).'\');">'
                        .'<input type="hidden" name="sec_token" value="'.$token.'">'
                        .'<input type="hidden" name="action" value="invalidate">'
                        .'<input type="hidden" name="id" value="'.(int) $r['id'].'">'
                        .'<button type="submit" class="btn btn-sm btn-success" title="'.UserProfilePlugin::create()->get_lang('InvalidateTooltip').'">'.UserProfilePlugin::create()->get_lang('Validated').'</button>'
                        .'</form> ';
                }
                echo '</div>';
                echo '<div class="am-actions-bottom">';
                $editUrl = api_get_path(WEB_PLUGIN_PATH).'user_profile/monthly_evaluation.php?student='.$sid.'&edit='.(int) $r['id'].'&popup=1';
                if ($canEdit && (int) $r['validation'] === 0) {
                    echo '<a href="'.Security::remove_XSS($editUrl).'" class="mr-2 js-popup" data-width="900" data-height="700" data-popup="me'.(int)$r['id'].'" title="'.get_lang('Edit').'">'.Display::return_icon('edit.png', get_lang('Edit'), [], ICON_SIZE_SMALL).'</a>';
                    echo '<form method="post" class="d-inline" onsubmit="return confirm(\''.addslashes(get_lang('ConfirmYourChoice')).'\');">'
                        .'<input type="hidden" name="sec_token" value="'.$token.'">'
                        .'<input type="hidden" name="action" value="delete">'
                        .'<input type="hidden" name="id" value="'.(int) $r['id'].'">'
                        .'<button type="submit" class="btn btn-link p-0" title="'.get_lang('Delete').'">'.Display::return_icon('delete.png', get_lang('Delete'), [], ICON_SIZE_SMALL).'</button>'
                        .'</form>';
                } else {
                    echo '-';
                }
                echo '</div>';
                echo '</div>';
                echo '</div>';
                echo '</li>';
            }
            echo '</ul>';
            // Script to set max-height equal to 3 items on this specific collapse
            echo '<script>$(function(){var $c=$("#'.Security::remove_XSS($collapseId).'");var $ul=$c.find(".comments-list");function resize(){var h=0;var $it=$ul.children("li");$ul.css({"max-height":"","overflow-y":""});if($it.length>3){for(var i=0;i<3;i++){h+=$it.eq(i).outerHeight(true);}if(h>0){$ul.css({"max-height":h+"px","overflow-y":"auto"});}}}resize();$c.on("shown.bs.collapse",resize);$(window).on("resize",resize);});</script>';
            echo '</div>';
            echo '</div>';
        }

        echo '</div>';
        echo '</div>';
    }

    // Pagination
    if ($perPage !== 'all') {
        $per = (int) $perPage;
        $pages = (int) ceil($totalStudents / max(1, $per));
        if ($pages > 1) {
            echo '<nav aria-label="User pagination"><ul class="pagination">';
            for ($i = 1; $i <= $pages; $i++) {
                $active = $i === $page ? ' active' : '';
                $url = api_get_self().'?'.http_build_query(['search' => $search, 'per_page' => $perPage, 'page' => $i]);
                echo '<li class="page-item'.$active.'"><a class="page-link" href="'.$url.'">'.$i.'</a></li>';
            }
            echo '</ul></nav>';
        }
    }
}

Display::display_footer();








