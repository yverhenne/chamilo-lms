<?php
/* For licensing terms, see /license.txt */
use Chamilo\CoreBundle\Component\Utils\ChamiloApi;

require_once __DIR__.'/config.php';
require_once __DIR__.'/UserProfilePlugin.php';

api_block_anonymous_users();

if (!api_get_configuration_value('plugin_user_profile_enabled')) {
    api_not_allowed(true);
}

$studentId = isset($_GET['student']) ? (int) $_GET['student'] : 0;
if ($studentId <= 0) {
    api_not_allowed(true);
}

$student = api_get_user_info($studentId);
if (empty($student)) {
    api_not_allowed(true);
}

$accessUrlId = (int) api_get_current_access_url_id();
$tblRelation = Database::get_main_table(UserProfilePlugin::TABLE_RELATION_STUDENT_TICKET);
$tblTicket   = Database::get_main_table(TABLE_TICKET_TICKET);

// Fetch all tickets linked to the student for current access URL
$sql = "SELECT t.*
        FROM $tblRelation r
        INNER JOIN $tblTicket t ON t.id = r.ticket_id
        WHERE r.user_id = $studentId AND r.access_url_id = $accessUrlId
        ORDER BY t.id DESC";
$rs = Database::query($sql);
$tickets = Database::store_result($rs);

ob_start();
?>
<h2 style="text-align:center;font-weight:bold; margin: 0 0 12px;">
    <?php echo Security::remove_XSS(api_utf8_encode(UserProfilePlugin::create()->get_lang('TrackingSynthesis'))); ?>
    <div style="font-size:13px; font-weight:normal; color:#54697a; margin-top:6px;">
        <?php
            $nameParts = array_filter([
                $student['firstname'] ?? '',
                $student['lastname'] ?? '',
            ]);
            echo Security::remove_XSS(trim(implode(' ', $nameParts)));
        ?>
    </div>
</h2>

<?php if (empty($tickets)): ?>
    <div style="padding:8px; border:1px solid #ccd9e6; border-radius:6px; background:#fff;">
        <em><?php echo api_utf8_encode(get_lang('NoResultsFound')); ?></em>
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
                <?php // Ticket message may contain allowed HTML stored in DB ?>
                <?php echo (string) $t['message']; ?>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>
<?php
$html = ob_get_clean();

$logo = ChamiloApi::getPlatformLogoPath('', true);
$header = '<div style="text-align:right;"><img src="'.$logo.'" height="50" alt="logo"></div>';
$date = api_format_date(api_get_local_time(), DATE_TIME_FORMAT_LONG);
$footer = '<table width="100%"><tr><td>'.$date.'</td><td style="text-align:right">{PAGENO}/{nb}</td></tr></table>';

$tpl = new Template('', false, false, false, false, true, false);
$tpl->assign('pdf_header', $header);
$tpl->assign('pdf_footer', $footer);

$pdf = new PDF('A4', 'P', [], $tpl);
$identifier = !empty($student['username']) ? $student['username'] : (string) $studentId;
$pdf->params['filename'] = 'user_tracking_'.$identifier;
$pdf->html_to_pdf_with_template($html, false, false, true);
