<?php
/* For licensing terms, see /license.txt */
require_once __DIR__.'/config.php';
require_once __DIR__.'/UserProfilePlugin.php';

api_block_anonymous_users();

if (!api_get_configuration_value('plugin_user_profile_enabled')) {
    api_not_allowed(true);
}

$studentId = isset($_GET['student']) ? (int) $_GET['student'] : 0;
if (empty($studentId)) {
    api_not_allowed(true);
}

// Pagination params
$perPage = isset($_GET['per_page']) ? (int) $_GET['per_page'] : 1;
if (!in_array($perPage, [1, 5, 10], true)) {
    $perPage = 1;
}
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;

$accessUrlId = (int) api_get_current_access_url_id();
$tblRelation = Database::get_main_table(UserProfilePlugin::TABLE_RELATION_STUDENT_TICKET);
$tblTicket = Database::get_main_table(TABLE_TICKET_TICKET);

// Count total tickets
$sqlCount = "SELECT COUNT(*) AS total
             FROM $tblRelation r
             INNER JOIN $tblTicket t ON t.id = r.ticket_id
             WHERE r.user_id = $studentId AND r.access_url_id = $accessUrlId";
$res = Database::query($sqlCount);
$row = Database::fetch_array($res);
$total = (int) ($row['total'] ?? 0);

$offset = ($page - 1) * $perPage;

// Fetch tickets page
$sql = "SELECT t.*
        FROM $tblRelation r
        INNER JOIN $tblTicket t ON t.id = r.ticket_id
        WHERE r.user_id = $studentId AND r.access_url_id = $accessUrlId
        ORDER BY t.id DESC
        LIMIT $offset, $perPage";
$rs = Database::query($sql);
$tickets = Database::store_result($rs);

$student = api_get_user_info($studentId);
Display::display_header(UserProfilePlugin::create()->get_lang('TrackingSynthesis'));

// Back button to myStudents
$backParam = isset($_GET['back']) ? (string) $_GET['back'] : '';
if ($backParam !== '') {
    $backUrl = Security::remove_XSS($backParam);
} else {
    $backUrl = api_get_path(WEB_CODE_PATH).'mySpace/myStudents.php?student='.(int) $studentId;
}
echo '<div class="actions">'
    .Display::url(
        Display::return_icon('back.png', get_lang('Back'), '', ICON_SIZE_MEDIUM),
        $backUrl
    )
    .'</div>';

echo '<div class="container">';
// Header row: user complete name + PDF export icon aligned right
$pdfUrl = api_get_path(WEB_PLUGIN_PATH).'user_profile/resume_tracking_pdf.php?student='.(int) $studentId;
echo '<div class="d-flex align-items-center justify-content-between mb-1">';
echo '<div>'.Display::page_subheader2(Security::remove_XSS($student['complete_name'] ?? '')).'</div>';
echo '<div class="ml-auto">'
    .'<a href="'.Security::remove_XSS($pdfUrl).'" target="_blank" rel="noopener" title="'.get_lang('ExportToPdf').'">'
    .Display::return_icon('icons/32/export_pdf.png', get_lang('ExportToPdf')).
    '</a>'
    .'</div>';
echo '</div>';

// Per-page selector
$self = api_get_self();
$baseParams = [
    'student' => $studentId,
    'page' => 1,
];
echo '<form method="get" class="form-inline mb-2">';
echo '<input type="hidden" name="student" value="'.(int) $studentId.'">';
echo '<select name="per_page" class="form-control" onchange="this.form.submit()">';
foreach ([1, 5, 10] as $n) {
    $sel = $perPage === $n ? ' selected' : '';
    echo '<option value="'.$n.'"'.$sel.'>'.$n.'</option>';
}
echo '</select>';
echo '</form>';
// Extra space below the dropdown
echo '<div class="h-10"></div>';

if ($total === 0) {
    echo Display::return_message(UserProfilePlugin::create()->get_lang('NoResultsFound'), 'warning');
} else {
    foreach ($tickets as $t) {
        $title = Security::remove_XSS((string) $t['subject']);
        $date = !empty($t['start_date']) ? api_convert_and_format_date($t['start_date'], DATE_TIME_FORMAT_LONG) : '';
        echo '<div class="card mb-3">';
        echo '<div class="card-header p-10"><strong>'.$title.'</strong>'; 
        if ($date) { echo ' <span class="text-muted">'.$date.'</span>'; }
        echo '</div>';
echo '<div class="card-body p-10">';
        // Ticket initial message can include HTML stored in DB.
        // Sanitize to a safe subset of tags and strip dangerous attributes.
        // Tidy change-tracking wrappers, then purify like ticket view
        if (!function_exists('user_profile_tidy_message')) {
            function user_profile_tidy_message(string $html): string {
                // Unwrap insertions, drop deletions
                $html = preg_replace('/<ins\b[^>]*>(.*?)<\/ins>/is', '$1', $html);
                $html = preg_replace('/<del\b[^>]*>.*?<\/del>/is', '', $html);
                // Unwrap spans that look like insert markers
                $html = preg_replace('/<span[^>]*class=[^>]*(insert|ins)[^>]*>(.*?)<\/span>/is', '$2', $html);
                // Remove spans that look like delete markers
                $html = preg_replace('/<span[^>]*class=[^>]*(delete|del)[^>]*>.*?<\/span>/is', '', $html);
                // Hide stray French labels that sometimes appear
                $html = preg_replace('/(^|[\s>(])(?:ajout|suppessionr|suppression)\s*:?(?=\s|[)<])/iu', '$1', $html);
                return $html;
            }
        }
        $msg = user_profile_tidy_message((string) ($t['message'] ?? ''));
        echo Security::remove_XSS($msg);
        echo '</div>';
        echo '</div>';
    }

    // Pagination controls
    $totalPages = (int) ceil($total / $perPage);
    if ($totalPages > 1) {
        echo '<nav aria-label="Page navigation"><ul class="pagination">';
        $prevPage = max(1, $page - 1);
        $nextPage = min($totalPages, $page + 1);
        $queryBase = http_build_query(['student' => $studentId, 'per_page' => $perPage]);
        echo '<li class="page-item'.($page <= 1 ? ' disabled' : '').'">'
            .'<a class="page-link" href="'.$self.'?'.$queryBase.'&page='.$prevPage.'">&laquo;</a></li>';
        // Show current page indicator only (simple)
        echo '<li class="page-item active"><span class="page-link">'.(int) $page.'</span></li>';
        echo '<li class="page-item'.($page >= $totalPages ? ' disabled' : '').'">'
            .'<a class="page-link" href="'.$self.'?'.$queryBase.'&page='.$nextPage.'">&raquo;</a></li>';
        echo '</ul></nav>';
    }
}

echo '</div>';

Display::display_footer();


