<?php
/* For licensing terms, see /license.txt */

use Chamilo\CoreBundle\Component\Utils\ChamiloApi;

require_once __DIR__.'/config.php';
require_once __DIR__.'/UserProfilePlugin.php';
require_once api_get_path(LIBRARY_PATH).'export.lib.inc.php';
require_once api_get_path(LIBRARY_PATH).'MyStudents.php';

api_block_anonymous_users();

if (!api_get_configuration_value('plugin_user_profile_enabled')) {
    api_not_allowed(true);
}

$userId = (int) ($_GET['id'] ?? 0);
if ($userId <= 0) {
    api_not_allowed(true);
}

$userInfo = api_get_user_info($userId);
if (empty($userInfo)) {
    api_not_allowed(true);
}

// Permissions: follow the same visibility rules as tracking pages
$allowedToTrackUser =
    api_is_platform_admin(true, true) ||
    api_is_allowed_to_edit(null, true) ||
    api_is_session_admin() ||
    api_is_drh() ||
    api_is_student_boss() ||
    api_is_course_admin() ||
    api_is_teacher();

if (!$allowedToTrackUser) {
    api_not_allowed(true);
}

// Prepare temp workspace
$baseDir = rtrim(api_get_path(SYS_ARCHIVE_PATH), '/').'/';
$tmpDir = $baseDir.'global_report_'.(int) $userId.'_'.time().'/';
if (!is_dir($tmpDir)) {
    @mkdir($tmpDir, 0775, true);
}

// Helper: save content to file
$saveTo = static function (string $path, string $content): bool {
    $fp = @fopen($path, 'wb');
    if (!$fp) {
        return false;
    }
    fwrite($fp, $content);
    fclose($fp);
    return true;
};

// Helper: internal HTTP download preserving session cookies
$downloadInternal = static function (
    string $relUrl,
    string $destPath,
    string $method = 'GET',
    array $postFields = []
) use ($saveTo): bool {
    // Build absolute URL
    $scheme = (!empty($_SERVER['HTTPS']) && strtolower((string) $_SERVER['HTTPS']) !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $base = rtrim(api_get_path(WEB_PATH), '/');
    $rel = substr($relUrl, 0, 1) === '/' ? $relUrl : '/'.$relUrl;
    $absUrl = $scheme.'://'.$host.$base.$rel;

    if (!function_exists('curl_init')) {
        // Fallback: try file_get_contents with stream context cookies
        $cookies = [];
        foreach ($_COOKIE as $n => $v) {
            $cookies[] = $n.'='.urlencode((string) $v);
        }
        $content = null;
        $opts = [
            'http' => [
                'method' => $method,
                'header' => 'Cookie: '.implode('; ', $cookies).($method === 'POST' ? "\r\nContent-Type: application/x-www-form-urlencoded" : ''),
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ],
        ];
        if ($method === 'POST' && !empty($postFields)) {
            $opts['http']['content'] = http_build_query($postFields);
        }
        $ctx = stream_context_create($opts);
        $data = @file_get_contents($absUrl, false, $ctx);
        return $data !== false ? $saveTo($destPath, $data) : false;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $absUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
    if (strtoupper($method) === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postFields));
    }

    // Forward cookies
    $cookiePairs = [];
    foreach ($_COOKIE as $name => $value) {
        $cookiePairs[] = $name.'='.urlencode((string) $value);
    }
    if (!empty($cookiePairs)) {
        curl_setopt($ch, CURLOPT_COOKIE, implode('; ', $cookiePairs));
    }

    $data = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($data === false) {
        error_log('export_zip: cURL error: '.$err);
        return false;
    }

    return $saveTo($destPath, (string) $data);
};

// 1) Time report PDF (from registration date to today)
$timeReportPdf = null;
try {
    $startDate = '';
    if (!empty($userInfo['registration_date'])) {
        $ts = strtotime((string) $userInfo['registration_date']);
        $startDate = $ts ? date('Y-m-d', $ts) : date('Y-m-d');
    } else {
        $startDate = date('Y-m-d');
    }
    $endDate = date('Y-m-d');
    $data = Tracking::generateReport('time_report', [$userId], $startDate, $endDate);
    if (!empty($data) && !empty($data['headers'])) {
        $rows = $data['rows'];
        array_unshift($rows, $data['headers']);
        $html = Export::convert_array_to_html($rows);
        $pdf = new PDF();
        $fileBase = 'time_report_'.($userInfo['username'] ?? (string) $userId);
        $timeReportPdf = $pdf->exportFromHtmlToFile($html, $fileBase, $tmpDir);
    }
} catch (Exception $e) {
    // Ignore and continue
}

// 2) User profile PDF (same content as plugin/user_profile/pdf.php)
$userProfilePdf = null;
try {
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
    $res = Database::query($sql);
    $fields = Database::store_result($res);
    $fieldsByCat = [];
    foreach ($fields as $f) {
        $fieldsByCat[$f['category_id']][] = $f;
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
            <tr><td><strong><?php echo get_lang('FirstName'); ?>:</strong> <?php echo Security::remove_XSS($userInfo['firstname']); ?></td></tr>
            <tr><td><strong><?php echo get_lang('LastName'); ?>:</strong> <?php echo Security::remove_XSS($userInfo['lastname']); ?></td></tr>
            <tr><td><strong><?php echo get_lang('Email'); ?>:</strong> <?php echo Security::remove_XSS($userInfo['email']); ?></td></tr>
            <tr><td><strong><?php echo get_lang('OfficialCode'); ?>:</strong> <?php echo Security::remove_XSS($userInfo['official_code']); ?></td></tr>
            <tr><td><strong><?php echo get_lang('Phone'); ?>:</strong> <?php echo Security::remove_XSS($userInfo['phone']); ?></td></tr>
            <tr><td><strong><?php echo get_lang('RegistrationDate'); ?>:</strong> <?php echo Security::remove_XSS($userInfo['registration_date']); ?></td></tr>
            <tr><td><strong><?php echo get_lang('LastLogins'); ?>:</strong> <?php echo Security::remove_XSS($userInfo['last_login']); ?></td></tr>
            <tr><td><strong><?php echo UserProfilePlugin::create()->get_lang('Teachers'); ?>:</strong> <?php echo Security::remove_XSS($teacherDisplay); ?></td></tr>
        </table>
    </div>
    <div style="border:1px solid #ccd9e6;margin-bottom:15px;">
        <div style="background-color:#e6f2ff;text-align:center;font-weight:bold;padding:4px;">
            <strong><?php echo get_plugin_lang('FicheEntreprise', 'UserProfilePlugin'); ?></strong>
        </div>
        <?php $entreprise = $plugin->getEntreprise(); ?>
        <?php if (!empty($entreprise)): ?>
        <table width="100%" cellpadding="4" cellspacing="0" style="border-collapse:collapse;">
            <?php
            $companyFields = [
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
            foreach ($companyFields as $key => $label):
                $val = $entreprise[$key] ?? '';
            ?>
            <tr><td><strong><?php echo $label; ?>:</strong> <?php echo Security::remove_XSS((string) $val); ?></td></tr>
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
    // Validated monthly evaluations
    $tblMonthly = Database::get_main_table(UserProfilePlugin::TABLE_MONTHLY_EVALUATION);
    $evalRes = Database::query("SELECT month, year, comment FROM $tblMonthly WHERE id_student = $userId AND access_url_id = $urlId AND validation = 1 ORDER BY year DESC, month DESC, id DESC");
    $evals = Database::store_result($evalRes);
    if (!empty($evals)):
    ?>
    <div style="border:1px solid #ccd9e6;margin-bottom:15px;">
        <div style="background-color:#e6f2ff;text-align:center;font-weight:bold;padding:4px;">
            <strong><?php echo UserProfilePlugin::create()->get_lang('MonthlyEvaluation'); ?></strong>
        </div>
        <table width="100%" cellpadding="4" cellspacing="0" style="border-collapse:collapse;">
            <thead>
            <tr>
                <th style="text-align:left;">&nbsp;<?php echo get_lang('Date'); ?></th>
                <th style="text-align:left;">&nbsp;<?php echo UserProfilePlugin::create()->get_lang('Comment'); ?></th>
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

    $tpl = new Template('', false, false, false, false, true, false);
    $pdf = new PDF('A4', 'P', [], $tpl);
    $fileSave = $tmpDir.'user_profile_'.($userInfo['username'] ?? (string) $userId).'.pdf';
    $pdf->params['filename'] = 'user_profile_'.($userInfo['username'] ?? (string) $userId);
    $pdf->html_to_pdf_with_template($html, true, false, true, [], 'F', $fileSave);
    $userProfilePdf = $fileSave;
} catch (Exception $e) {
    // Ignore and continue
}

// 3) Resume tracking PDF (plugin/user_profile/resume_tracking.php content)
$resumeTrackingPdf = null;
try {
$accessUrlId = (int) api_get_current_access_url_id();
    $tblRelation = Database::get_main_table(UserProfilePlugin::TABLE_RELATION_STUDENT_TICKET);
    $tblTicket   = Database::get_main_table(TABLE_TICKET_TICKET);
    $sql = "SELECT t.*
            FROM $tblRelation r
            INNER JOIN $tblTicket t ON t.id = r.ticket_id
            WHERE r.user_id = $userId AND r.access_url_id = $accessUrlId
            ORDER BY t.id DESC";
    $rs = Database::query($sql);
    $tickets = Database::store_result($rs);

    ob_start();
    ?>
    <h2 style="text-align:center;font-weight:bold; margin: 0 0 12px;">
        <?php echo Security::remove_XSS(UserProfilePlugin::create()->get_lang('TrackingSynthesis')); ?>
        <div style="font-size:13px; font-weight:normal; color:#54697a; margin-top:6px;">
            <?php
                $nameParts = array_filter([
                    $userInfo['firstname'] ?? '',
                    $userInfo['lastname'] ?? '',
                ]);
                echo Security::remove_XSS(trim(implode(' ', $nameParts)));
            ?>
        </div>
    </h2>
    <?php if (empty($tickets)): ?>
        <div style="padding:8px; border:1px solid #ccd9e6; border-radius:6px; background:#fff;">
            <em><?php echo get_lang('NoResultsFound'); ?></em>
        </div>
    <?php else: ?>
        <?php foreach ($tickets as $t): ?>
            <?php
                $title = Security::remove_XSS((string) $t['subject']);
                $date  = !empty($t['start_date']) ? api_convert_and_format_date($t['start_date'], DATE_TIME_FORMAT_LONG) : '';
            ?>
            <div style="border:1px solid #dbe1e6; border-radius:6px; background:#fff; margin:0 0 12px;">
                <div style="background:#f3f7fa; padding:10px 12px; font-weight:600; border-bottom:1px solid #dbe1e6;">
                    <span><?php echo $title; ?></span>
                    <?php if ($date !== ''): ?>
                        <span style="color:#6b7785; font-weight:400; margin-left:8px;">(<?php echo $date; ?>)</span>
                    <?php endif; ?>
                </div>
                <div style="padding:12px; line-height:1.45;">
                    <?php echo (string) $t['message']; ?>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
    <?php
    $html = ob_get_clean();
    $tpl = new Template('', false, false, false, false, true, false);
    $pdf = new PDF('A4', 'P', [], $tpl);
    $fileSave = $tmpDir.'resume_tracking_'.($userInfo['username'] ?? (string) $userId).'.pdf';
    $pdf->params['filename'] = 'user_tracking_'.($userInfo['username'] ?? (string) $userId);
    $pdf->html_to_pdf_with_template($html, true, false, true, [], 'F', $fileSave);
    $resumeTrackingPdf = $fileSave;
} catch (Exception $e) {
    // Ignore and continue
}

// 4) MyStudents XLS export (via internal HTTP call)
$myStudentsXls = null;
try {
    $relUrl = 'main/mySpace/myStudents.php?'.http_build_query([
        'student' => $userId,
        'export' => 'xls',
        'from' => 'myspace',
    ]);
    $dest = $tmpDir.'reporting_student_'.($userInfo['username'] ?? (string) $userId).'.xlsx';
    if ($downloadInternal($relUrl, $dest)) {
        $myStudentsXls = $dest;
    }
} catch (Exception $e) {
    // Ignore and continue
}

// 5) Student follow export PDF (via internal HTTP or rebuild?)
// Prefer internal HTTP to reuse existing logic with course selection defaults
$studentFollowPdf = null;
try {
    // Compute courses/sessions the user is subscribed to
    $coursesInSessions = [];
    $courseRelUser = Database::select(
        'c_id',
        Database::get_main_table(TABLE_MAIN_COURSE_USER),
        [
            'where' => [
                'relation_type <> ? AND user_id = ?' => [COURSE_RELATION_TYPE_RRHH, $userId],
            ],
        ]
    );
    foreach ($courseRelUser as $row) {
        $coursesInSessions[0][] = (int) $row['c_id'];
    }
    $sessionRelCourseRelUser = Database::select(
        ['session_id', 'c_id'],
        Database::get_main_table(TABLE_MAIN_SESSION_COURSE_USER),
        [
            'where' => [
                'user_id = ?' => $userId,
            ],
        ]
    );
    foreach ($sessionRelCourseRelUser as $row) {
        $coursesInSessions[(int) $row['session_id']][] = (int) $row['c_id'];
    }

    $selected = [];
    foreach ($coursesInSessions as $sId => $courses) {
        if (empty($courses)) {
            continue;
        }
        foreach ($courses as $courseId) {
            $selected[] = $sId.'_'.$courseId;
        }
    }

    if (!empty($selected)) {
        $relUrl = 'main/mySpace/student_follow_export.php?student='.(int) $userId;
        $dest = $tmpDir.'student_follow_'.($userInfo['username'] ?? (string) $userId).'.pdf';
        $postFields = [];
        foreach ($selected as $val) {
            $postFields['sc[]'][] = $val;
        }
        // Trigger form validation
        $postFields['submit'] = 1;
        if ($downloadInternal($relUrl, $dest, 'POST', $postFields)) {
            $studentFollowPdf = $dest;
        }
    }
} catch (Exception $e) {
    // Ignore and continue
}

// Build ZIP
$zipFile = $tmpDir.'bilan_global_'.($userInfo['username'] ?? (string) $userId).'.zip';
$zip = new ZipArchive();
if ($zip->open($zipFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    Display::addFlash(Display::return_message(get_lang('ErrorWhileBuildingReport'), 'error'));
    header('Location: '.api_get_path(WEB_PLUGIN_PATH).'user_profile/view.php?id='.(int) $userId);
    exit;
}

$addedAny = false;
if ($timeReportPdf && is_file($timeReportPdf)) {
    $label = api_utf8_encode(UserProfilePlugin::create()->get_lang('TimeReportFile'));
    $uname = $userInfo['username'] ?? (string) $userId;
    $localName = $label.'_'.$uname.'.pdf';
    $zip->addFile($timeReportPdf, $localName);
    $addedAny = true;
}
if ($userProfilePdf && is_file($userProfilePdf)) {
    $label = api_utf8_encode(UserProfilePlugin::create()->get_lang('UserProfileFile'));
    $uname = $userInfo['username'] ?? (string) $userId;
    $localName = $label.'_'.$uname.'.pdf';
    $zip->addFile($userProfilePdf, $localName);
    $addedAny = true;
}
if ($resumeTrackingPdf && is_file($resumeTrackingPdf)) {
    $label = api_utf8_encode(UserProfilePlugin::create()->get_lang('ResumeTrackingFile'));
    $uname = $userInfo['username'] ?? (string) $userId;
    $localName = $label.'_'.$uname.'.pdf';
    $zip->addFile($resumeTrackingPdf, $localName);
    $addedAny = true;
}
if ($myStudentsXls && is_file($myStudentsXls)) {
    $zip->addFile($myStudentsXls, basename($myStudentsXls));
    $addedAny = true;
}
// If we ever manage to generate student_follow_export PDF reliably, include it
if ($studentFollowPdf && is_file($studentFollowPdf)) {
    $zip->addFile($studentFollowPdf, basename($studentFollowPdf));
    $addedAny = true;
}

$zip->close();

if (!$addedAny) {
    Display::addFlash(Display::return_message(get_lang('NoDataToExport'), 'warning'));
    header('Location: '.api_get_path(WEB_PLUGIN_PATH).'user_profile/view.php?id='.(int) $userId);
    exit;
}

// Send zip for download
DocumentManager::file_send_for_download($zipFile, true, basename($zipFile));
exit;
