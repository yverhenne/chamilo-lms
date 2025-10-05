<?php
/* For licensing terms, see /license.txt */
require_once __DIR__.'/config.php';
if (!api_get_configuration_value('plugin_user_profile_enabled')) {
    api_not_allowed(true);
}
require_once __DIR__.'/UserProfilePlugin.php';
require_once api_get_path(LIBRARY_PATH).'message.lib.php';
require_once api_get_path(LIBRARY_PATH).'TicketManager.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $check  = Security::check_token('post');
    $status = 'error';
    $mode = null; // 'message' | 'ticket'
    $action = $_POST['action'] ?? null;
    $debug = [
        'action' => $action,
        'token_check' => (bool) $check,
    ];

    // Enforce CSRF on all actions
    if ($check && isset($_POST['action'])) {
        // Enforce authorization
        // Allow platform admins and session admins, and also teachers/coaches when used from myStudents
        $isAuthorized = (
            api_is_platform_admin() ||
            api_is_session_admin() ||
            api_is_teacher() ||
            api_is_course_admin() ||
            api_is_drh() ||
            api_is_student_boss()
        );
        if (!$isAuthorized) {
            $status = 'not_allowed';
        } else {
            switch ($_POST['action']) {
            case 'toggle':
                $userId  = (int) ($_POST['user_id'] ?? 0);
                $fieldId = (int) ($_POST['field_id'] ?? 0);
                $checked = (int) ($_POST['checked'] ?? 0);

                $tblValue = Database::get_main_table(UserProfilePlugin::TABLE_VALUE);
                $where = ['user_id = ? AND field_id = ?' => [$userId, $fieldId]];
                $exists = Database::select('id', $tblValue, ['where' => $where], 'first');

                if ($exists) {
                    Database::update($tblValue, ['checked' => $checked], $where);
                } else {
                    Database::insert($tblValue, [
                        'user_id'  => $userId,
                        'field_id' => $fieldId,
                        'value'    => '',
                        'checked'  => $checked,
                    ]);
                }
                $status = 'ok';
                break;

            case 'warn':
                $userId   = (int) ($_POST['user_id'] ?? 0);
                $tracking = isset($_POST['tracking']) && (int) $_POST['tracking'] === 0 ? 0 : 1;
                $debug['warn'] = [
                    'user_id' => $userId,
                    'tracking' => $tracking,
                ];

                if ($userId) {
                    $plugin = UserProfilePlugin::create();
                    $teacherIds = $plugin->getUserTeachers($userId);
                    $debug['warn']['teacher_ids'] = array_values(array_map('intval', (array) $teacherIds));
                    if (empty($teacherIds)) {
                        $status = 'no_teacher';
                        $debug['warn']['error'] = 'no_teacher_found';
                        break;
                    }

                    $tblUser = Database::get_main_table(TABLE_MAIN_USER);
                    $userIdInt = (int) $userId;
                    $userInfo = Database::fetch_array(
                        Database::query("SELECT firstname, lastname FROM $tblUser WHERE id = $userIdInt"),
                        'ASSOC'
                    );
                    $userName = $userInfo ? trim((string) ($userInfo['firstname'] ?? '').' '.(string) ($userInfo['lastname'] ?? '')) : '';
                    // For ticket subject, follow "nom et prénom" ordering
                    $userNameLF = $userInfo ? trim($userInfo['lastname'].' '.$userInfo['firstname']) : trim($userName);

                    $tblField = Database::get_main_table(UserProfilePlugin::TABLE_FIELD);
                    $tblValue = Database::get_main_table(UserProfilePlugin::TABLE_VALUE);
                    $tblCat   = Database::get_main_table(UserProfilePlugin::TABLE_CATEGORY);
                    $urlId    = api_get_current_access_url_id();

                    $sql = "SELECT f.name, f.field_type, v.value
                            FROM $tblField f
                            LEFT JOIN $tblValue v ON (f.id = v.field_id AND v.user_id = $userId)
                            LEFT JOIN $tblCat c ON (f.category_id = c.id)
                            WHERE f.access_url_id = $urlId AND c.access_url_id = $urlId
                              AND f.include_tracking = $tracking AND COALESCE(v.checked,0) = 0";
                    $res = Database::query($sql);

                    $lines = [];
                    $now = time();
                    while ($row = Database::fetch_array($res, 'ASSOC')) {
                        $value = $row['value'];
                        if ($row['field_type'] === 'date') {
                            if (empty($value) || strtotime($value) >= $now) {
                                continue;
                            }
                            $value = api_format_date($value, DATE_FORMAT_LONG);
                        }
                        $label = Security::remove_XSS((string) ($row['name'] ?? ''));
                        $valueSan = Security::remove_XSS((string) $value);
                        $lines[] = '- '.$label.' : '.$valueSan;
                    }

                    $intro   = (string) $plugin->get_lang('WarnIntro');
                    $outro   = 'Cordialement';
                    $userUrl = api_get_path(WEB_CODE_PATH).'mySpace/myStudents.php?student='.$userId;
                    $userLine = '<a href="'.Security::remove_XSS($userUrl).'">'.Security::remove_XSS($userName).'</a>';

                    $body    = implode('<br>', $lines);
                    $content = $intro.'<br><br>'.$userLine.'<br><br>'.$body.'<br><br>'.$outro;
                    $subject = 'Avertissement pour '.$userName;

                    // Check plugin configuration for ticket creation
                    $config = UserProfilePlugin::create()->getConfiguration();
                    $projectId = (int) ($config['id_ticket_communication'] ?? 0);
                    $categoryId = (int) ($config['category1'] ?? 0);
                    $debug['warn']['config'] = [
                        'id_ticket_communication' => $projectId,
                        'category1' => $categoryId,
                    ];

                    $allOk = true;
                    if ($projectId > 0 && $categoryId > 0) {
                        // Create one ticket per teacher, assign to each teacher
                        // Subject format: 'Nom Prénom' : une action de votre part est attendue
                        $ticketSubject = "'{$userNameLF}' : une action de votre part est attendue";
                        $createdCount = 0;
                        $debug['warn']['tickets'] = [];
                        foreach ($teacherIds as $teacherId) {
                            $ok = false;
                            $err = null;
                            $payload = [
                                'category_id' => (int) $categoryId,
                                'project_id' => (int) $projectId,
                                'assigned_user_id' => (int) $teacherId,
                                'subject_len' => strlen((string) $ticketSubject),
                                'content_len' => strlen((string) $content),
                            ];
                            try {
                                $ok = TicketManager::add(
                                    $categoryId, // category_id
                                    0,           // course_id
                                    0,           // sessionId
                                    $projectId,  // project_id
                                    '',          // other_area
                                    $ticketSubject, // subject
                                    $content,    // content (same as warn message)
                                    '',          // personalEmail
                                    [],          // attachments
                                    TicketManager::SOURCE_PLATFORM, // source
                                    '', // priority (use default)
                                    TicketManager::STATUS_NEW,      // status
                                    (int) $teacherId                // assigned user
                                );
                            } catch (Exception $e) {
                                $ok = false;
                                $err = $e->getMessage();
                            }
                            $debug['warn']['tickets'][] = [
                                'assigned_user_id' => (int) $teacherId,
                                'ok' => (bool) $ok,
                                'error' => $err,
                                'payload' => $payload,
                            ];
                            if ($ok) { $createdCount++; }
                            $allOk = $allOk && $ok;
                        }
                        if ($allOk || $createdCount > 0) {
                            $status = 'ok';
                            $mode = 'ticket';
                            $debug['warn']['result'] = ['all_ok' => (bool) $allOk, 'created_count' => $createdCount, 'mode' => $mode];
                        } else {
                            // Fallback to messages if ticket creation failed entirely
                            $allSent = true;
                            foreach ($teacherIds as $teacherId) {
                                $sent = MessageManager::send_message_simple($teacherId, $subject, $content, api_get_user_id());
                                $allSent = $allSent && $sent;
                            }
                            $status = $allSent ? 'ok' : 'error';
                            $mode = $allSent ? 'message' : null;
                            $debug['warn']['result'] = ['fallback' => 'message', 'all_sent' => (bool) $allSent, 'mode' => $mode];
                        }
                    } else {
                        // Fallback to simple message if config not complete
                        $allSent = true;
                        foreach ($teacherIds as $teacherId) {
                            $sent = MessageManager::send_message_simple($teacherId, $subject, $content, api_get_user_id());
                            $allSent = $allSent && $sent;
                        }
                        $status = $allSent ? 'ok' : 'error';
                        $mode = $allSent ? 'message' : null;
                        $debug['warn']['result'] = ['fallback' => 'config_incomplete', 'all_sent' => (bool) $allSent, 'mode' => $mode];
                    }
                }
                break;

            case 'remind_agenda':
                $userId = (int) ($_POST['user_id'] ?? 0);
                $debug['remind_agenda'] = [
                    'user_id' => $userId,
                    'sender_id' => (int) api_get_user_id(),
                ];
                if ($userId > 0) {
                    $plugin = UserProfilePlugin::create();
                    $subject = (string) $plugin->get_lang('AgendaReminder');
                    $content = (string) $plugin->get_lang('AgendaReminderBody');
                    if (trim($subject) === '') { $subject = 'Agenda reminder'; }
                    if (trim($content) === '') { $content = 'Hello,\n\nPlease update your training schedule in the calendar.\n\nKind regards'; }
                    $receiverInfo = api_get_user_info($userId);
                    $debug['remind_agenda']['receiver_exists'] = !empty($receiverInfo);
                    $debug['remind_agenda']['subject_len'] = strlen((string) $subject);
                    $debug['remind_agenda']['content_len'] = strlen((string) $content);
                    $sent = MessageManager::send_message_simple(
                        $userId,
                        $subject,
                        $content,
                        api_get_user_id()
                    );
                    $debug['remind_agenda']['sent'] = (bool) $sent;
                    $status = $sent ? 'ok' : 'error';
                    $mode = $sent ? 'message' : null;
                } else {
                    $status = 'error';
                    $debug['remind_agenda']['error'] = 'invalid_user_id';
                }
                break;

                case 'save_teachers':
                $userId = (int) ($_POST['user_id'] ?? 0);
                $teacherIds = $_POST['teachers'] ?? [];
                if ($userId) {
                    $plugin = UserProfilePlugin::create();
                    $plugin->saveUserTeachers($userId, is_array($teacherIds) ? $teacherIds : []);
                    $status = 'ok';
                }
                break;
                
            default:
                $status = 'error';
        }
        }
    }

    Security::clear_token();
    header('Content-Type: application/json');
    echo json_encode([
        'token' => Security::get_token(),
        'status' => $status,
        'mode' => $mode,
        'debug' => isset($debug) ? $debug : [],
    ]);
    exit;






}
