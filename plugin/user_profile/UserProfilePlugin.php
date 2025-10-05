<?php
/* For licensing terms, see /license.txt */
require_once __DIR__.'/src/HookUserProfile.php';

class UserProfilePlugin extends Plugin implements HookPluginInterface
{
    public const TABLE_FIELD = 'plugin_user_profile_field';
    public const TABLE_VALUE = 'plugin_user_profile_value';
    public const TABLE_CATEGORY = 'plugin_user_profile_category';
    public const TABLE_TEACHERS = 'plugin_user_profile_teachers';
    public const TABLE_USER_TEACHER = 'plugin_user_profile_user_teacher';
    public const TABLE_COMMENT = 'plugin_user_profile_comment';
    public const TABLE_ENTREPRISE = 'plugin_user_profile_entreprise';
    public const TABLE_CONFIG = 'plugin_user_profile_configuration';
    public const TABLE_USER_COMPANY = 'plugin_user_profile_user_company';
    public const TABLE_RELATION_STUDENT_TICKET = 'relation_student_ticket';
    public const TABLE_MONTHLY_EVALUATION = 'user_profile_monthly_evaluation';

    public function get_name(): string
    {
        return 'user_profile';
    }

    public static function getCategoryLabel(array $category): string
    {
        $name = $category['name'];
        $label = self::create()->get_lang($name);

        // get_lang() returns the key in brackets when missing; fallback to raw
        if (preg_match('/^\[[=]?' . preg_quote($name, '/') . '[=]?\]$/', $label)) {
            return $name;
        }

        return $label;
    }

    protected function __construct()
    {
        parent::__construct('1.0', 'Yannick VERHENNE');
    }

    public static function create(): UserProfilePlugin
    {
        static $instance = null;

        return $instance ?: $instance = new self();
    }

    /**
     * Install plugin database schema and hooks for the current portal.
     */
    public function install()
    {
        $tblField = Database::get_main_table(self::TABLE_FIELD);
        $tblValue = Database::get_main_table(self::TABLE_VALUE);
        $tblCat = Database::get_main_table(self::TABLE_CATEGORY);
        $tblTeachers = Database::get_main_table(self::TABLE_TEACHERS);
        $tblUserTeacher = Database::get_main_table(self::TABLE_USER_TEACHER);
        $tblComment = Database::get_main_table(self::TABLE_COMMENT);
        $tblEntreprise = Database::get_main_table(self::TABLE_ENTREPRISE);
        $tblConfig = Database::get_main_table(self::TABLE_CONFIG);
        $tblUserCompany = Database::get_main_table(self::TABLE_USER_COMPANY);
        $tblRelation = Database::get_main_table(self::TABLE_RELATION_STUDENT_TICKET);
        $tblMonthlyEval = Database::get_main_table(self::TABLE_MONTHLY_EVALUATION);
        $tblUser = Database::get_main_table(TABLE_MAIN_USER);
        $urlId = (int) api_get_current_access_url_id();

        $sql = "CREATE TABLE IF NOT EXISTS $tblCat (
            id INT AUTO_INCREMENT PRIMARY KEY,
            access_url_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            cat_order INT NOT NULL DEFAULT 0,
            INDEX (access_url_id)
        )";
        Database::query($sql);

        $sql = "CREATE TABLE IF NOT EXISTS $tblField (
            id INT AUTO_INCREMENT PRIMARY KEY,
            access_url_id INT NOT NULL,
            name VARCHAR(255) NOT NULL,
            field_type VARCHAR(10) NOT NULL,
            category_id INT NOT NULL,
            field_order INT NOT NULL DEFAULT 0,
            include_tracking TINYINT(1) NOT NULL DEFAULT 0,
            FOREIGN KEY (category_id) REFERENCES $tblCat(id) ON DELETE CASCADE,
            INDEX (access_url_id)
        )";
        Database::query($sql);
        // Ensure include_tracking exists for legacy installations
        $res = Database::query("SHOW COLUMNS FROM $tblField LIKE 'include_tracking'");
        if (0 === Database::num_rows($res)) {
            Database::query("ALTER TABLE $tblField ADD include_tracking TINYINT(1) NOT NULL DEFAULT 0");
        }

        $sql = "CREATE TABLE IF NOT EXISTS $tblValue (
            id INT AUTO_INCREMENT PRIMARY KEY,
            user_id INT NOT NULL,
            field_id INT NOT NULL,
            value TEXT,
            checked TINYINT(1) NOT NULL DEFAULT 0,
            UNIQUE KEY uniq_user_field(user_id, field_id)
        )";
        Database::query($sql);
        // Ensure checked column exists for legacy installations
        $res = Database::query("SHOW COLUMNS FROM $tblValue LIKE 'checked'");
        if (0 === Database::num_rows($res)) {
            Database::query("ALTER TABLE $tblValue ADD checked TINYINT(1) NOT NULL DEFAULT 0");
        }

        $sql = "CREATE TABLE IF NOT EXISTS $tblTeachers (
            user_id INT NOT NULL PRIMARY KEY,
            teacher_ids TEXT
        )";
        Database::query($sql);

        // Normalized teacher relation table for native cascades
        $sql = "CREATE TABLE IF NOT EXISTS $tblUserTeacher (
            user_id INT NOT NULL,
            teacher_id INT NOT NULL,
            PRIMARY KEY (user_id, teacher_id),
            INDEX (teacher_id)
        )";
        Database::query($sql);

        $sql = "CREATE TABLE IF NOT EXISTS $tblComment (
            id INT AUTO_INCREMENT PRIMARY KEY,
            author_id INT NOT NULL,
            user_id INT NOT NULL,
            comment_date DATETIME NOT NULL,
            content TEXT NOT NULL,
            is_public TINYINT(1) NOT NULL DEFAULT 0,
            INDEX (user_id)
        )";
        Database::query($sql);

        // Entreprise information table
        $sql = "CREATE TABLE IF NOT EXISTS $tblEntreprise (
            id INT AUTO_INCREMENT PRIMARY KEY,
            access_url_id INT NOT NULL,
            trade_name VARCHAR(255) DEFAULT NULL,
            legal_name VARCHAR(255) DEFAULT NULL,
            address TEXT DEFAULT NULL,
            tutor_last_name VARCHAR(255) DEFAULT NULL,
            tutor_first_name VARCHAR(255) DEFAULT NULL,
            tutor_email VARCHAR(255) DEFAULT NULL,
            tutor_phone VARCHAR(50) DEFAULT NULL,
            director_last_name VARCHAR(255) DEFAULT NULL,
            director_first_name VARCHAR(255) DEFAULT NULL,
            director_email VARCHAR(255) DEFAULT NULL,
            director_phone VARCHAR(50) DEFAULT NULL,
            other_contact_last_name VARCHAR(255) DEFAULT NULL,
            other_contact_first_name VARCHAR(255) DEFAULT NULL,
            other_contact_email VARCHAR(255) DEFAULT NULL,
            other_contact_phone VARCHAR(50) DEFAULT NULL,
            INDEX (access_url_id)
        )";
        Database::query($sql);

        // User -> company link (one company per user)
        $sql = "CREATE TABLE IF NOT EXISTS $tblUserCompany (
            user_id INT NOT NULL PRIMARY KEY,
            company_id INT NOT NULL,
            INDEX (company_id)
        )";
        Database::query($sql);

        // Ensure indexes to support search and allow multiple companies per URL
        $this->ensureEntrepriseSchema();

        // Configuration table: one row per access_url_id
        $sql = "CREATE TABLE IF NOT EXISTS $tblConfig (
            access_url_id INT NOT NULL PRIMARY KEY,
            email VARCHAR(255) DEFAULT NULL,
            id_ticket_communication INT DEFAULT NULL,
            id_ticket_learner_tracking INT DEFAULT NULL,
            category1 INT DEFAULT NULL,
            category2 INT DEFAULT NULL
        )";
        Database::query($sql);

        // Monthly evaluation table
        $sql = "CREATE TABLE IF NOT EXISTS $tblMonthlyEval (
            id INT AUTO_INCREMENT PRIMARY KEY,
            id_student INT NOT NULL,
            access_url_id INT NOT NULL,
            comment TEXT,
            month INT NOT NULL,
            year INT NOT NULL,
            author_id INT NOT NULL,
            validation TINYINT(1) NOT NULL DEFAULT 0,
            INDEX (access_url_id),
            INDEX (id_student)
        )";
        Database::query($sql);
        // Ensure access_url_id exists for legacy installations and is filled
        try {
            $res = Database::query("SHOW COLUMNS FROM $tblMonthlyEval LIKE 'access_url_id'");
            if (0 === Database::num_rows($res)) {
                Database::query("ALTER TABLE $tblMonthlyEval ADD access_url_id INT NULL");
                $urlId = (int) $urlId;
                Database::query("UPDATE $tblMonthlyEval SET access_url_id = $urlId WHERE access_url_id IS NULL");
                Database::query("ALTER TABLE $tblMonthlyEval MODIFY access_url_id INT NOT NULL");
            }
        } catch (Exception $e) {
        }
        // Ensure author_id exists and position it right before validation
        try {
            $res = Database::query("SHOW COLUMNS FROM $tblMonthlyEval LIKE 'author_id'");
            if (0 === Database::num_rows($res)) {
                Database::query("ALTER TABLE $tblMonthlyEval ADD author_id INT NOT NULL DEFAULT 0");
            }
            // Ensure column order: author_id just before validation
            Database::query("ALTER TABLE $tblMonthlyEval MODIFY author_id INT NOT NULL AFTER year");
        } catch (Exception $e) {
        }
        // Ensure an auto-increment primary key exists (id)
        try {
            $res = Database::query("SHOW COLUMNS FROM $tblMonthlyEval LIKE 'id'");
            if (0 === Database::num_rows($res)) {
                Database::query("ALTER TABLE $tblMonthlyEval ADD id INT NOT NULL FIRST");
                Database::query("ALTER TABLE $tblMonthlyEval ADD PRIMARY KEY (id)");
                Database::query("ALTER TABLE $tblMonthlyEval MODIFY id INT NOT NULL AUTO_INCREMENT");
            }
        } catch (Exception $e) {
        }

        // Relation table between students (users) and tickets
        $sql = "CREATE TABLE IF NOT EXISTS $tblRelation (
            user_id INT NOT NULL,
            ticket_id INT NOT NULL,
            access_url_id INT NOT NULL,
            PRIMARY KEY (user_id, ticket_id),
            INDEX (ticket_id)
        )";
        Database::query($sql);

        // Ensure access_url_id exists for legacy installations and is filled
        try {
            $res = Database::query("SHOW COLUMNS FROM $tblRelation LIKE 'access_url_id'");
            if (0 === Database::num_rows($res)) {
                // Add as NULLable first to allow backfilling
                Database::query("ALTER TABLE $tblRelation ADD access_url_id INT NULL");
                // Backfill with current URL id
                $urlId = (int) $urlId;
                Database::query("UPDATE $tblRelation SET access_url_id = $urlId WHERE access_url_id IS NULL");
                // Make NOT NULL afterwards
                Database::query("ALTER TABLE $tblRelation MODIFY access_url_id INT NOT NULL");
            }
        } catch (Exception $e) {
        }

        // Ensure index on access_url_id exists (deduplicated)
        try {
            $res = Database::query("SHOW INDEX FROM $tblRelation WHERE Key_name = 'idx_relation_access_url'");
            if (0 === Database::num_rows($res)) {
                Database::query("CREATE INDEX idx_relation_access_url ON $tblRelation (access_url_id)");
            }
        } catch (Exception $e) {
        }

        // Minimal intra-plugin foreign keys (idempotent via try/catch)
        try { Database::query("CREATE INDEX idx_value_field_id ON $tblValue (field_id)"); } catch (Exception $e) {}
        try { Database::query("ALTER TABLE $tblValue ADD CONSTRAINT fk_user_profile_value_field FOREIGN KEY (field_id) REFERENCES $tblField(id) ON DELETE CASCADE"); } catch (Exception $e) {}
        try { Database::query("CREATE INDEX idx_uc_company_id ON $tblUserCompany (company_id)"); } catch (Exception $e) {}
        try { Database::query("ALTER TABLE $tblUserCompany ADD CONSTRAINT fk_user_profile_uc_company FOREIGN KEY (company_id) REFERENCES $tblEntreprise(id) ON DELETE CASCADE"); } catch (Exception $e) {}

        // Add FKs to main user table for normalized teacher links (best-effort)
        try { Database::query("ALTER TABLE $tblUserTeacher ADD CONSTRAINT fk_user_profile_ut_user FOREIGN KEY (user_id) REFERENCES $tblUser(id) ON DELETE CASCADE"); } catch (Exception $e) {}
        try { Database::query("ALTER TABLE $tblUserTeacher ADD CONSTRAINT fk_user_profile_ut_teacher FOREIGN KEY (teacher_id) REFERENCES $tblUser(id) ON DELETE CASCADE"); } catch (Exception $e) {}

        // One-time migration from CSV teacher_ids to normalized relation
        try {
            if (Database::tableExists($tblTeachers) && Database::tableExists($tblUserTeacher)) {
                $res = Database::query("SELECT user_id, teacher_ids FROM $tblTeachers WHERE teacher_ids IS NOT NULL AND teacher_ids <> ''");
                while ($row = Database::fetch_array($res)) {
                    $uid = (int) $row['user_id'];
                    $csv = trim((string) $row['teacher_ids']);
                    if ($csv === '') { continue; }
                    $ids = array_unique(array_filter(array_map('intval', explode(',', $csv))));
                    foreach ($ids as $tid) {
                        if ($tid <= 0) { continue; }
                        try { Database::query("INSERT IGNORE INTO $tblUserTeacher (user_id, teacher_id) VALUES ($uid, $tid)"); } catch (Exception $e) {}
                    }
                }
            }
        } catch (Exception $e) {}

        // Extra FKs to ensure cleanup on platform user deletion (best-effort)
        try { Database::query("CREATE INDEX idx_value_user_id ON $tblValue (user_id)"); } catch (Exception $e) {}
        try { Database::query("ALTER TABLE $tblValue ADD CONSTRAINT fk_user_profile_value_user FOREIGN KEY (user_id) REFERENCES $tblUser(id) ON DELETE CASCADE"); } catch (Exception $e) {}
        try { Database::query("CREATE INDEX idx_teachers_user_id ON $tblTeachers (user_id)"); } catch (Exception $e) {}
        try { Database::query("ALTER TABLE $tblTeachers ADD CONSTRAINT fk_user_profile_teachers_user FOREIGN KEY (user_id) REFERENCES $tblUser(id) ON DELETE CASCADE"); } catch (Exception $e) {}
        try { Database::query("CREATE INDEX idx_comment_user ON $tblComment (user_id)"); } catch (Exception $e) {}
        try { Database::query("ALTER TABLE $tblComment ADD CONSTRAINT fk_user_profile_comment_user FOREIGN KEY (user_id) REFERENCES $tblUser(id) ON DELETE CASCADE"); } catch (Exception $e) {}
        try { Database::query("CREATE INDEX idx_comment_author ON $tblComment (author_id)"); } catch (Exception $e) {}
        try { Database::query("ALTER TABLE $tblComment ADD CONSTRAINT fk_user_profile_comment_author FOREIGN KEY (author_id) REFERENCES $tblUser(id) ON DELETE CASCADE"); } catch (Exception $e) {}
        try { Database::query("CREATE INDEX idx_uc_user_id ON $tblUserCompany (user_id)"); } catch (Exception $e) {}
        try { Database::query("ALTER TABLE $tblUserCompany ADD CONSTRAINT fk_user_profile_uc_user FOREIGN KEY (user_id) REFERENCES $tblUser(id) ON DELETE CASCADE"); } catch (Exception $e) {}
        try { Database::query("CREATE INDEX idx_relation_user ON $tblRelation (user_id)"); } catch (Exception $e) {}
        try { Database::query("ALTER TABLE $tblRelation ADD CONSTRAINT fk_user_profile_relation_user FOREIGN KEY (user_id) REFERENCES $tblUser(id) ON DELETE CASCADE"); } catch (Exception $e) {}
        try { Database::query("CREATE INDEX idx_monthlyeval_student ON $tblMonthlyEval (id_student)"); } catch (Exception $e) {}
        try { Database::query("ALTER TABLE $tblMonthlyEval ADD CONSTRAINT fk_user_profile_monthlyeval_student FOREIGN KEY (id_student) REFERENCES $tblUser(id) ON DELETE CASCADE"); } catch (Exception $e) {}
        try { Database::query("CREATE INDEX idx_monthlyeval_author ON $tblMonthlyEval (author_id)"); } catch (Exception $e) {}
        try { Database::query("ALTER TABLE $tblMonthlyEval ADD CONSTRAINT fk_user_profile_monthlyeval_author FOREIGN KEY (author_id) REFERENCES $tblUser(id) ON DELETE CASCADE"); } catch (Exception $e) {}

        $this->installHook();
    }

    /**
     * Ensure entreprise table schema allows multiple companies per URL and has search indexes.
     * Adds a user-company link table if missing.
     */
    public function ensureEntrepriseSchema(): void
    {
        $tblEntreprise = Database::get_main_table(self::TABLE_ENTREPRISE);
        $tblUserCompany = Database::get_main_table(self::TABLE_USER_COMPANY);
        if (!Database::tableExists($tblEntreprise)) {
            return;
        }

        try {
            $res = Database::query("SHOW INDEX FROM $tblEntreprise WHERE Key_name = 'uniq_access'");
            if (Database::num_rows($res) > 0) {
                Database::query("ALTER TABLE $tblEntreprise DROP INDEX uniq_access");
            }
        } catch (Exception $e) {
        }

        try {
            $res = Database::query("SHOW INDEX FROM $tblEntreprise WHERE Key_name = 'idx_trade_name'");
            if (Database::num_rows($res) === 0) {
                Database::query("CREATE INDEX idx_trade_name ON $tblEntreprise (trade_name)");
            }
        } catch (Exception $e) {
        }

        try {
            $res = Database::query("SHOW INDEX FROM $tblEntreprise WHERE Key_name = 'idx_legal_name'");
            if (Database::num_rows($res) === 0) {
                Database::query("CREATE INDEX idx_legal_name ON $tblEntreprise (legal_name)");
            }
        } catch (Exception $e) {
        }

        // Ensure user-company link table exists
        if (!Database::tableExists($tblUserCompany)) {
            Database::query("CREATE TABLE IF NOT EXISTS $tblUserCompany (user_id INT NOT NULL PRIMARY KEY, company_id INT NOT NULL, INDEX (company_id))");
        }
    }

    /**
     * Uninstall plugin database schema and detach hooks.
     */
    public function uninstall()
    {
        $tables = [
            self::TABLE_FIELD,
            self::TABLE_VALUE,
            self::TABLE_CATEGORY,
            self::TABLE_TEACHERS,
            self::TABLE_USER_TEACHER,
            self::TABLE_COMMENT,
            self::TABLE_USER_COMPANY,
            self::TABLE_ENTREPRISE,
            self::TABLE_CONFIG,
            self::TABLE_RELATION_STUDENT_TICKET,
            self::TABLE_MONTHLY_EVALUATION,
        ];
        foreach ($tables as $table) {
            $tableName = Database::get_main_table($table);
            $sql = "DROP TABLE IF EXISTS $tableName";
            Database::query($sql);
        }

        $this->uninstallHook();
    }

    /**
     * Attach required observers to core hooks.
     */
    public function installHook()
    {
        $observer = HookUserProfile::create();
        HookCreateUser::create()->attach($observer);
        HookUpdateUser::create()->attach($observer);
        HookAdminBlock::create()->attach($observer);
        // Try to attach user delete hook if available
        try { HookDeleteUser::create()->attach($observer); } catch (Throwable $e) {}

        return 1;
    }

    /**
     * Detach observers from core hooks.
     */
    public function uninstallHook()
    {
        $observer = HookUserProfile::create();
        HookCreateUser::create()->detach($observer);
        HookUpdateUser::create()->detach($observer);
        HookAdminBlock::create()->detach($observer);
        try { HookDeleteUser::create()->detach($observer); } catch (Throwable $e) {}

        return 1;
    }

    /**
     * Get the admin management URL for the plugin.
     */
    public function getAdminUrl()
    {
        return api_get_path(WEB_PLUGIN_PATH).$this->get_name().'/admin.php';
    }

    /**
     * Get the user view URL for a given user id.
     *
     * @param int  $userId     Target user identifier
     * @param bool $fromSearch Whether the link originates from a search result
     */
    public function getViewUrl(int $userId, bool $fromSearch = false): string
    {
        $url = api_get_path(WEB_PLUGIN_PATH).$this->get_name().'/view.php?id='.$userId;
        if ($fromSearch) {
            $url .= '&from_search=1';
        }
        return $url;
    }

    public function getTrackingUrl(): string
    {
        return api_get_path(WEB_PLUGIN_PATH).$this->get_name().'/tracking.php';
    }

    public function getTeacherManagementUrl(): string
    {
        return api_get_path(WEB_PLUGIN_PATH).$this->get_name().'/teachers.php';
    }

    /**
     * Render the top navigation menu used across plugin pages, with a consistent order:
     * Pedagogical | Administrative | Company | Teacher assignment | Monthly evaluation (admins only).
     */
    public function renderTopMenu(): string
    {
        $current = basename($_SERVER['SCRIPT_NAME'] ?? '');
        $links = [];

        // Pedagogical tracking
        if ($current === 'tracking.php') {
            $links[] = api_utf8_encode($this->get_lang('PedagogicalTracking'));
        } else {
            $links[] = '<a href="tracking.php">'.api_utf8_encode($this->get_lang('PedagogicalTracking')).'</a>';
        }

        // Administrative tracking
        if ($current === 'tracking_untracked.php') {
            $links[] = api_utf8_encode($this->get_lang('AdministrativeTracking'));
        } else {
            $links[] = '<a href="tracking_untracked.php">'.api_utf8_encode($this->get_lang('AdministrativeTracking')).'</a>';
        }

        // Company list
        if ($current === 'company_list.php') {
            $links[] = api_utf8_encode(get_plugin_lang('FicheEntreprise', 'UserProfilePlugin'));
        } else {
            $links[] = '<a href="company_list.php">'.api_utf8_encode(get_plugin_lang('FicheEntreprise', 'UserProfilePlugin')).'</a>';
        }

        // Teachers assignment
        if ($current === 'teachers.php') {
            $links[] = api_utf8_encode($this->get_lang('TeacherAssignment'));
        } else {
            $links[] = '<a href="teachers.php">'.api_utf8_encode($this->get_lang('TeacherAssignment')).'</a>';
        }

        // Monthly evaluation (admins and session admins)
        if (api_is_platform_admin() || api_is_session_admin()) {
            if ($current === 'admin_monthly_evaluation.php') {
                $links[] = api_utf8_encode($this->get_lang('MonthlyEvaluation'));
            } else {
                $links[] = '<a href="admin_monthly_evaluation.php">'.api_utf8_encode($this->get_lang('MonthlyEvaluation')).'</a>';
            }
        }

        $links = array_map(static function ($s) { return Security::remove_XSS($s); }, $links);
        return '<div class="mb-3">'.implode(' | ', $links).'</div>';
    }

    public function getCategories(): array
    {
        $table = Database::get_main_table(self::TABLE_CATEGORY);
        $urlId = (int) api_get_current_access_url_id();
        $res = Database::query("SELECT * FROM $table WHERE access_url_id = $urlId ORDER BY cat_order, id");
        return Database::store_result($res);
    }

    public function getCategoryOptions(): array
    {
        $options = [];
        foreach ($this->getCategories() as $cat) {
            $options[$cat['id']] = self::getCategoryLabel($cat);
        }
        return $options;
    }

    public function getFields(): array
    {
        $table = Database::get_main_table(self::TABLE_FIELD);
        $urlId = (int) api_get_current_access_url_id();
        $res = Database::query("SELECT * FROM $table WHERE access_url_id = $urlId ORDER BY field_order, id");
        return Database::store_result($res);
    }

    public function addFieldsToForm(FormValidator $form, ?int $userId = null)
    {
        $values = [];
        if ($userId) {
            $values = $this->getUserValues($userId);
        }

        $fields = $this->getFields();
        $byCat = [];
        foreach ($fields as $field) {
            $byCat[$field['category_id']][] = $field;
        }

        foreach ($this->getCategories() as $cat) {
            $form->addHtml('<h5 style="text-align: center;"><strong>'.Security::remove_XSS($cat['name']).'</strong></h5></br>');
            if (empty($byCat[$cat['id']])) {
                continue;
            }
            foreach ($byCat[$cat['id']] as $field) {
                $name = 'profile_'.$field['id'];
                if ($field['field_type'] === 'date') {
                    $form->addDatePicker($name, $field['name']);
                } else {
                    $form->addText($name, $field['name'], false);
                }
                if (isset($values[$field['id']])) {
                    $form->setDefaults([
                        $name => $values[$field['id']]['value'],
                    ]);
                }
            }
        }
    }

    public function saveUserValues(int $userId, array $formValues)
    {
        $table = Database::get_main_table(self::TABLE_VALUE);
        foreach ($this->getFields() as $field) {
            $key = 'profile_'.$field['id'];
            if (!array_key_exists($key, $formValues)) {
                continue;
            }
            $value = trim((string) $formValues[$key]);
            $where = ['user_id = ? AND field_id = ?' => [$userId, $field['id']]];
            $existing = Database::select('id', $table, ['where' => $where], 'first');
            if ($existing) {
                Database::update($table, ['value' => $value], $where);
            } elseif ($value !== '') {
                Database::insert($table, [
                    'user_id' => $userId,
                    'field_id' => $field['id'],
                    'value' => $value,
                    'checked' => 0,
                ]);
            }
        }
    }

    public function getUserValues(int $userId): array
    {
        $table = Database::get_main_table(self::TABLE_VALUE);
        $rows = Database::select('*', $table, [
            'where' => ['user_id = ?' => $userId],
        ]);
        $values = [];
        foreach ($rows as $row) {
            $values[$row['field_id']] = [
                'value' => $row['value'],
                'checked' => (int) $row['checked'],
            ];
        }

        return $values;
    }

    public function getTeacherOptions(): array
    {
        $tblUser = Database::get_main_table(TABLE_MAIN_USER);
        $res = Database::query("SELECT id, firstname, lastname FROM $tblUser WHERE status = ".COURSEMANAGER." ORDER BY lastname, firstname");
        $options = [];
        while ($row = Database::fetch_array($res)) {
            $options[$row['id']] = $row['firstname'].' '.$row['lastname'];
        }
        return $options;
    }

    public function saveUserTeachers(int $userId, array $teacherIds): void
    {
        $legacy = Database::get_main_table(self::TABLE_TEACHERS);
        $rel = Database::get_main_table(self::TABLE_USER_TEACHER);
        $teacherIds = array_values(array_unique(array_filter(array_map('intval', $teacherIds))));
        if (Database::tableExists($rel)) {
            Database::delete($rel, ['user_id = ?' => $userId]);
            foreach ($teacherIds as $tid) {
                Database::insert($rel, ['user_id' => $userId, 'teacher_id' => $tid]);
            }
        }
        $data = ['teacher_ids' => implode(',', $teacherIds)];
        $exists = Database::select('user_id', $legacy, ['where' => ['user_id = ?' => $userId]], 'first');
        if ($exists) {
            Database::update($legacy, $data, ['user_id = ?' => $userId]);
        } else {
            $data['user_id'] = $userId;
            Database::insert($legacy, $data);
        }
    }

    public function getUserTeachers(int $userId): array
    {
        $legacy = Database::get_main_table(self::TABLE_TEACHERS);
        $rel = Database::get_main_table(self::TABLE_USER_TEACHER);
        if (Database::tableExists($rel)) {
            $rows = Database::select('teacher_id', $rel, ['where' => ['user_id = ?' => $userId]]);
            $ids = array_map(static function ($r) { return (int) $r['teacher_id']; }, $rows ?: []);
            if (!empty($ids)) {
                return array_values(array_unique($ids));
            }
            $row = Database::select('teacher_ids', $legacy, ['where' => ['user_id = ?' => $userId]], 'first');
            if (!$row || empty($row['teacher_ids'])) { return []; }
            $ids = array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $row['teacher_ids'])))));
            foreach ($ids as $tid) { try { Database::insert($rel, ['user_id' => $userId, 'teacher_id' => $tid]); } catch (Exception $e) {} }
            return $ids;
        }
        $row = Database::select('teacher_ids', $legacy, ['where' => ['user_id = ?' => $userId]], 'first');
        if (!$row || empty($row['teacher_ids'])) { return []; }
        return array_values(array_unique(array_filter(array_map('intval', explode(',', (string) $row['teacher_ids'])))));
    }

    /**
     * Best-effort cleanup of all user-related rows in this plugin.
     */
    public function deleteUserData(int $userId): void
    {
        $tblValue = Database::get_main_table(self::TABLE_VALUE);
        Database::delete($tblValue, ['user_id = ?' => $userId]);

        $tblTeachers = Database::get_main_table(self::TABLE_TEACHERS);
        Database::delete($tblTeachers, ['user_id = ?' => $userId]);
        try {
            $tblUserTeacher = Database::get_main_table(self::TABLE_USER_TEACHER);
            if (Database::tableExists($tblUserTeacher)) {
                Database::delete($tblUserTeacher, ['user_id = ? OR teacher_id = ?' => [$userId, $userId]]);
            }
        } catch (Exception $e) {}

        $tblComment = Database::get_main_table(self::TABLE_COMMENT);
        Database::delete($tblComment, ['user_id = ? OR author_id = ?' => [$userId, $userId]]);

        $tblUserCompany = Database::get_main_table(self::TABLE_USER_COMPANY);
        Database::delete($tblUserCompany, ['user_id = ?' => $userId]);

        $tblRelation = Database::get_main_table(self::TABLE_RELATION_STUDENT_TICKET);
        Database::delete($tblRelation, ['user_id = ?' => $userId]);

        $tblMonthlyEval = Database::get_main_table(self::TABLE_MONTHLY_EVALUATION);
        Database::delete($tblMonthlyEval, ['id_student = ? OR author_id = ?' => [$userId, $userId]]);
    }

    /**
     * Called by AppPlugin when deleting an item of type 'user'.
     */
    public function doWhenDeletingUser($userId): void
    {
        if (!api_get_configuration_value('plugin_user_profile_enabled')) {
            return;
        }
        $userId = (int) $userId;
        if ($userId > 0) {
            $this->deleteUserData($userId);
        }
    }

    public function getTeacherNamesForUser(int $userId): string
    {
        $ids = $this->getUserTeachers($userId);
        if (empty($ids)) {
            return '';
        }
        $tblUser = Database::get_main_table(TABLE_MAIN_USER);
        $idList = implode(',', array_map('intval', $ids));
        $res = Database::query("SELECT firstname, lastname FROM $tblUser WHERE id IN ($idList) ORDER BY lastname, firstname");
        $names = [];
        while ($row = Database::fetch_array($res)) {
            $names[] = $row['firstname'].' '.$row['lastname'];
        }
        return implode(', ', $names);
    }

    /** Ensure configuration table/columns exist. */
    public function ensureConfigurationSchema(): void
    {
        $tblConfig = Database::get_main_table(self::TABLE_CONFIG);
        $sql = "CREATE TABLE IF NOT EXISTS $tblConfig (
            access_url_id INT NOT NULL PRIMARY KEY,
            email VARCHAR(255) DEFAULT NULL,
            id_ticket_communication INT DEFAULT NULL,
            id_ticket_learner_tracking INT DEFAULT NULL,
            category1 INT DEFAULT NULL,
            category2 INT DEFAULT NULL
        )";
        Database::query($sql);
        try {
            $cols = [];
            foreach (Database::listTableColumns($tblConfig) as $col) {
                $cols[strtolower($col->getName())] = true;
            }
            if (!isset($cols['email'])) {
                Database::query("ALTER TABLE $tblConfig ADD email VARCHAR(255) DEFAULT NULL");
            }
            if (!isset($cols['id_ticket_communication'])) {
                Database::query("ALTER TABLE $tblConfig ADD id_ticket_communication INT DEFAULT NULL");
            }
            if (!isset($cols['id_ticket_learner_tracking'])) {
                Database::query("ALTER TABLE $tblConfig ADD id_ticket_learner_tracking INT DEFAULT NULL");
            }
            if (!isset($cols['category1'])) {
                Database::query("ALTER TABLE $tblConfig ADD category1 INT DEFAULT NULL");
            }
            if (!isset($cols['category2'])) {
                Database::query("ALTER TABLE $tblConfig ADD category2 INT DEFAULT NULL");
            }
        } catch (Exception $e) {
            // ignore
        }
    }

    /**
     * After saving settings in configure_plugin, sync to configuration table.
     */
    public function performActionsAfterConfigure()
    {
        $this->ensureConfigurationSchema();
        $email = $this->get('email');
        $comm = $this->get('id_ticket_communication');
        $learn = $this->get('id_ticket_learner_tracking');
        $cat1 = $this->get('category1');
        $cat2 = $this->get('category2');
        $this->saveConfiguration([
            'email' => $email !== '' ? $email : null,
            'id_ticket_communication' => $comm !== '' ? (int) $comm : null,
            'id_ticket_learner_tracking' => $learn !== '' ? (int) $learn : null,
            'category1' => $cat1 !== '' ? (int) $cat1 : null,
            'category2' => $cat2 !== '' ? (int) $cat2 : null,
        ]);

        // Remove duplicates from core settings table so values live only in our config table
        $accessUrlId = (int) api_get_current_access_url_id();
        $conditionsBase = [
            'category = ? AND access_url = ? AND subkey = ? AND type = ? AND variable = ?' => [
                'Plugins',
                $accessUrlId,
                $this->get_name(),
                'setting',
                '', // placeholder; replaced per key
            ],
        ];
        foreach ([
            $this->get_name().'_email',
            $this->get_name().'_id_ticket_communication',
            $this->get_name().'_id_ticket_learner_tracking',
            $this->get_name().'_category1',
            $this->get_name().'_category2',
        ] as $var) {
            $conditions = $conditionsBase;
            $conditions['category = ? AND access_url = ? AND subkey = ? AND type = ? AND variable = ?'][4] = $var;
            api_delete_settings_params($conditions);
        }
    }

    /* Entreprise helpers */
    public function getEntreprise(): array
    {
        $table = Database::get_main_table(self::TABLE_ENTREPRISE);
        $urlId = (int) api_get_current_access_url_id();
        $row = Database::select('*', $table, [
            'where' => ['access_url_id = ?' => $urlId],
        ], 'first');
        return $row ?: [];
    }


    public function renderMonthlyEvaluationBody(int $studentId, int $evaluationId, bool $includeScripts = false, bool $forPdf = false): ?string
    {
        require_once api_get_path(LIBRARY_PATH).'sessionmanager.lib.php';
        require_once api_get_path(LIBRARY_PATH).'tracking.lib.php';
        require_once api_get_path(LIBRARY_PATH).'course.lib.php';

        $studentId = (int) $studentId;
        $evaluationId = (int) $evaluationId;

        if ($studentId <= 0 || $evaluationId <= 0) {
            return null;
        }

        $userInfo = api_get_user_info($studentId);
        if (empty($userInfo)) {
            return null;
        }

        $urlId = (int) api_get_current_access_url_id();
        $tblMonthly = Database::get_main_table(self::TABLE_MONTHLY_EVALUATION);
        $evaluation = Database::select(
            'id, id_student, month, year, comment, validation',
            $tblMonthly,
            ['where' => ['id = ? AND id_student = ? AND access_url_id = ?' => [$evaluationId, $studentId, $urlId]]],
            'first'
        );

        if (!$evaluation) {
            return null;
        }

        $wrapperClasses = 'monthly-evaluation';
        if ($forPdf) {
            $wrapperClasses .= ' monthly-evaluation--pdf';
        }

        $content = sprintf('<div class="%s">', $wrapperClasses);

        $builtFields = [
            get_lang('FirstName') => $userInfo['firstname'] ?? '',
            get_lang('LastName') => $userInfo['lastname'] ?? '',
        ];
        $teacherNames = $this->getTeacherNamesForUser($studentId);
        $builtFields[get_lang('Teachers')] = $teacherNames !== '' ? $teacherNames : '-';

        $identityColumns = [];
        $cardBaseClass = 'card user-profile mb-3';
        $cardAttr = '';
        $cardTitleAttr = '';
        $listAttr = '';
        $itemBaseStyle = '';
        $cardBodyAttr = '';
        $commentBodyAttr = '';
        if ($forPdf) {
            $cardAttr = ' style="background-color:#fff;border:1px solid #dbe1e6;border-radius:6px;margin:0 0 16px;"';
            $cardTitleAttr = ' style="font-weight:600;text-align:center;background:#e1f0f5;margin:0;padding:10px 12px;"';
            $listAttr = ' style="list-style:none;margin:0;padding:0;"';
            $itemBaseStyle = 'border:0;font-size:13px;padding:6px 0;';
            $cardBodyAttr = ' style="padding:14px;"';
            $commentBodyAttr = ' style="white-space:pre-wrap;line-height:1.45;"';
        }


        if (!$forPdf) {
            $userCard = '<div class="'.$cardBaseClass.'"'.$cardAttr.'>';
            $userCard .= '<div class="card-title"'.$cardTitleAttr.'><strong>'.Security::remove_XSS($this->get_lang('PlatformFields')).'</strong></div>';
            $userCard .= '<ul class="list-group list-group-flush"'.$listAttr.'>';
            foreach ($builtFields as $label => $value) {
                $userCard .= '<li class="list-group-item"><strong>'.Security::remove_XSS($label).':</strong> '.Security::remove_XSS((string) $value).'</li>';
            }
            $userCard .= '</ul></div>';
            $identityColumns[] = $userCard;
        }
        $entreprise = $this->getEntreprise();
        if (!empty($entreprise)) {
            $legacyMap = [
                'trade_name' => 'nom_commercial',
                'tutor_last_name' => 'nom_tuteur',
                'tutor_first_name' => 'prenom_tuteur',
            ];

            $companyCard = '<div class="'.$cardBaseClass.'"'.$cardAttr.'>';
            $companyCard .= '<div class="card-title"'.$cardTitleAttr.'><strong>'.Security::remove_XSS(get_plugin_lang('FicheEntreprise', 'UserProfilePlugin')).'</strong></div>';
            $companyCard .= '<ul class="list-group list-group-flush"'.$listAttr.'>';
            $companyFields = [
                'trade_name' => get_plugin_lang('TradeName', 'UserProfilePlugin'),
                'tutor_last_name' => get_plugin_lang('TutorLastName', 'UserProfilePlugin'),
                'tutor_first_name' => get_plugin_lang('TutorFirstName', 'UserProfilePlugin'),
            ];
            $companyItems = [];
            foreach ($companyFields as $key => $label) {
                $value = '';
                if (!empty($entreprise[$key])) {
                    $value = (string) $entreprise[$key];
                } elseif (isset($legacyMap[$key]) && !empty($entreprise[$legacyMap[$key]])) {
                    $value = (string) $entreprise[$legacyMap[$key]];
                }
                $companyItems[$label] = $value;
            }
            $totalCompanyItems = count($companyItems);
            $companyIndex = 0;
            foreach ($companyItems as $label => $value) {
                $itemAttr = '';
                if ($forPdf) {
                    $borderStyle = $companyIndex === $totalCompanyItems - 1 ? 'border-bottom:0;' : 'border-bottom:1px solid #e5edf2;';
                    $itemAttr = ' style="'.$itemBaseStyle.$borderStyle.'"';
                }
                $companyCard .= '<li class="list-group-item"'.$itemAttr.'><strong>'.Security::remove_XSS($label).':</strong> '.Security::remove_XSS((string) $value).'</li>';
                $companyIndex++;
            }
            $companyCard .= '</ul></div>';
            $identityColumns[] = $companyCard;
        }

        if (!empty($identityColumns)) {
            if ($forPdf) {
                foreach ($identityColumns as $columnContent) {
                    $content .= $columnContent;
                }
            } else {
                $content .= '<div class="monthly-evaluation__identity">';
                foreach ($identityColumns as $columnContent) {
                    $content .= '<div class="monthly-evaluation__identity-column">'.$columnContent.'</div>';
                }
                $content .= '</div>';
            }
        }
        $orderCondition = null;
        if (api_get_configuration_value('session_list_order')) {
            $orderCondition = ' ORDER BY s.position ASC';
        }

        $sessions = SessionManager::getSessionsFollowedByUser(
            $studentId,
            null,
            null,
            null,
            false,
            false,
            false,
            $orderCondition
        );

        $sessionProgressList = [];
        $totalSessionsProgress = 0.0;

        foreach ($sessions as $sessionItem) {
            $courses = SessionManager::get_course_list_by_session_id($sessionItem['id']);
            $courseProgressSum = 0.0;
            $courseCount = 0;

            foreach ($courses as $courseItem) {
                $courseInfoItem = api_get_course_info_by_id($courseItem['real_id']);
                if (empty($courseInfoItem)) {
                    continue;
                }

                $courseCodeItem = $courseInfoItem['code'];
                if (!CourseManager::is_user_subscribed_in_course($studentId, $courseCodeItem, true, $sessionItem['id'])) {
                    continue;
                }

                $progressValue = Tracking::get_avg_student_progress($studentId, $courseCodeItem, [], $sessionItem['id']);
                if (is_numeric($progressValue)) {
                    $courseProgressSum += (float) $progressValue;
                }
                $courseCount++;
            }

            $progress = $courseCount > 0 ? round($courseProgressSum / $courseCount, 2) : 0.0;
            $sessionProgressList[] = [
                'name' => $sessionItem['name'],
                'progress' => $progress,
            ];
            $totalSessionsProgress += $progress;
        }

        $avgSessionsProgress = !empty($sessionProgressList)
            ? round($totalSessionsProgress / count($sessionProgressList), 2)
            : 0.0;

        $avgDisplay = number_format($avgSessionsProgress, 2);
        if ($forPdf) {
            $avgProgressContent = '<div class="avg-progress-pdf">';
            $avgProgressContent .= '<div class="avg-progress-value">'.$avgDisplay.'%</div>';
            $avgProgressContent .= '</div>';
        } else {
            $avgProgressContent = '<div class="text-center">';
            $avgProgressContent .= '<div id="avg-sessions-progress" class="easypiechart" data-percent="'.$avgDisplay.'">';
            $avgProgressContent .= '<span class="percent">'.$avgDisplay.'%</span>';
            $avgProgressContent .= '</div>';
            $avgProgressContent .= '</div>';

            if ($includeScripts) {
                $avgProgressContent .= "<script>$(function(){ $('#avg-sessions-progress').easyPieChart({ scaleColor: false, lineWidth: 8, barColor: '#3ba557', trackColor: '#f2f2f2'});});</script>";
            }
        }

        $content .= Display::panel(
            $avgProgressContent,
            get_lang('AverageProgressInSessions')
        );

        $sessionBars = '';
        if ($forPdf) {
            if (!empty($sessionProgressList)) {
                $sessionBars .= '<table class="session-progress-table">';
                foreach ($sessionProgressList as $item) {
                    $name = Security::remove_XSS((string) $item['name']);
                    $progressValue = max(0.0, min(100.0, (float) $item['progress']));
                    $progressDisplay = number_format($progressValue, 2);
                    $sessionBars .= '<tr>';
                    $sessionBars .= '<td class="session-progress-table__label">'.$name.'</td>';
                    $sessionBars .= '<td class="session-progress-table__value">'.$progressDisplay.'%</td>';
                    $sessionBars .= '</tr>';
                }
                $sessionBars .= '</table>';
            }
        } else {
            foreach ($sessionProgressList as $item) {
                $name = Security::remove_XSS((string) $item['name']);
                $progressValue = max(0.0, min(100.0, (float) $item['progress']));
                $progressDisplay = number_format($progressValue, 2);
                $sessionBars .= '<p>'.$name.'</p>';
                $sessionBars .= '<div class="progress">';
                $sessionBars .= '<div class="progress-bar progress-bar-success" role="progressbar" style="width: '.$progressDisplay.'%">'.$progressDisplay.'%</div>';
                $sessionBars .= '</div>';
            }
        }

        $content .= Display::panel(
            $sessionBars,
            get_lang('ProgressionInSessions')
        );

        $months = api_get_months_long();
        $monthIndex = (int) ($evaluation['month'] ?? 0);
        $monthName = $monthIndex > 0 && isset($months[$monthIndex - 1]) ? $months[$monthIndex - 1] : (string) $monthIndex;
        $label = trim($monthName.' '.(int) ($evaluation['year'] ?? 0));

        $commentCardAttr = $forPdf ? $cardAttr : '';
        $content .= '<div class="'.$cardBaseClass.'"'.$commentCardAttr.'>';
        $content .= '<div class="card-title"'.$cardTitleAttr.'><strong>'.Security::remove_XSS($this->get_lang('MonthlyEvaluation')).' - '.Security::remove_XSS($label).'</strong></div>';
        $content .= '<div class="card-body"'.$cardBodyAttr.'>';
        $content .= '<div class="comment-body"'.$commentBodyAttr.'>'.nl2br(Security::remove_XSS((string) ($evaluation['comment'] ?? ''))).'</div>';
        $content .= '</div></div>';
        $content .= '</div>';

        return $content;
    }

    public function getTutorEmailForUser(int $userId): string
    {
        $userId = (int) $userId;
        if ($userId <= 0) {
            return '';
        }

        $urlId = (int) api_get_current_access_url_id();
        $tblUserCompany = Database::get_main_table(self::TABLE_USER_COMPANY);
        $relation = Database::select(
            'company_id',
            $tblUserCompany,
            ['where' => ['user_id = ?' => $userId]],
            'first'
        );

        if ($relation && !empty($relation['company_id'])) {
            $companyId = (int) $relation['company_id'];
            if ($companyId > 0) {
                $tblEntreprise = Database::get_main_table(self::TABLE_ENTREPRISE);
                $company = Database::select(
                    '*',
                    $tblEntreprise,
                    ['where' => ['id = ? AND access_url_id = ?' => [$companyId, $urlId]]],
                    'first'
                );
                $email = $this->extractTutorEmail($company ?: []);
                if ($email !== '') {
                    return $email;
                }
            }
        }

        return $this->extractTutorEmail($this->getEntreprise());
    }

    private function extractTutorEmail(?array $row): string
    {
        if (empty($row)) {
            return '';
        }

        foreach (['tutor_email', 'mail_tuteur'] as $key) {
            if (!empty($row[$key])) {
                return trim((string) $row[$key]);
            }
        }

        return '';
    }
    public function saveEntreprise(array $data): void
    {
        $table = Database::get_main_table(self::TABLE_ENTREPRISE);
        $urlId = (int) api_get_current_access_url_id();
        $exists = Database::select('id', $table, ['where' => ['access_url_id = ?' => $urlId]], 'first');
        $payload = [
            'access_url_id' => $urlId,
            'trade_name' => $data['trade_name'] ?? null,
            'legal_name' => $data['legal_name'] ?? null,
            'address' => $data['address'] ?? null,
            'tutor_last_name' => $data['tutor_last_name'] ?? null,
            'tutor_first_name' => $data['tutor_first_name'] ?? null,
            'tutor_email' => $data['tutor_email'] ?? null,
            'tutor_phone' => $data['tutor_phone'] ?? null,
            'director_last_name' => $data['director_last_name'] ?? null,
            'director_first_name' => $data['director_first_name'] ?? null,
            'director_email' => $data['director_email'] ?? null,
            'director_phone' => $data['director_phone'] ?? null,
            'other_contact_last_name' => $data['other_contact_last_name'] ?? null,
            'other_contact_first_name' => $data['other_contact_first_name'] ?? null,
            'other_contact_email' => $data['other_contact_email'] ?? null,
            'other_contact_phone' => $data['other_contact_phone'] ?? null,
        ];
        // Backward-compat: if table still has legacy French column names, map keys
        $cols = [];
        foreach (Database::listTableColumns($table) as $col) {
            $cols[strtolower($col->getName())] = true;
        }
        if (!isset($cols['trade_name']) && isset($cols['nom_commercial'])) {
            $payload = [
                'access_url_id' => $urlId,
                'nom_commercial' => $data['trade_name'] ?? null,
                'raison_sociale' => $data['legal_name'] ?? null,
                'adresse_complete' => $data['address'] ?? null,
                'nom_tuteur' => $data['tutor_last_name'] ?? null,
                'prenom_tuteur' => $data['tutor_first_name'] ?? null,
                'mail_tuteur' => $data['tutor_email'] ?? null,
                'telephone_tuteur' => $data['tutor_phone'] ?? null,
                'nom_directeur' => $data['director_last_name'] ?? null,
                'prenom_directeur' => $data['director_first_name'] ?? null,
                'mail_directeur' => $data['director_email'] ?? null,
                'telephone_directeur' => $data['director_phone'] ?? null,
                'nom_autre_contact' => $data['other_contact_last_name'] ?? null,
                'prenom_autre_contact' => $data['other_contact_first_name'] ?? null,
                'mail_autre_contact' => $data['other_contact_email'] ?? null,
                'tel_autre_contact' => $data['other_contact_phone'] ?? null,
            ];
        }
        if ($exists) {
            Database::update($table, $payload, ['access_url_id = ?' => $urlId]);
        } else {
            Database::insert($table, $payload);
        }
    }

    /**
     * Return company options list as [id => label].
     */
    public function getCompanyOptions(): array
    {
        $table = Database::get_main_table(self::TABLE_ENTREPRISE);
        $urlId = (int) api_get_current_access_url_id();
        $res = Database::query("SELECT id, trade_name, legal_name FROM $table WHERE access_url_id = ".$urlId." ORDER BY trade_name, legal_name, id DESC");
        $options = [];
        while ($row = Database::fetch_array($res)) {
            $name = trim($row['trade_name']);
            $legal = trim((string) $row['legal_name']);
            $label = $name !== '' ? $name : ('#'.$row['id']);
            if ($legal !== '') {
                $label .= ' ('.$legal.')';
            }
            $options[(int) $row['id']] = $label;
        }
        return $options;
    }

    /**
     * Save or clear a user-company association.
     */
    public function saveUserCompany(int $userId, ?int $companyId): void
    {
        $table = Database::get_main_table(self::TABLE_USER_COMPANY);
        $exists = Database::select('user_id', $table, ['where' => ['user_id = ?' => $userId]], 'first');
        if (empty($companyId)) {
            if ($exists) {
                Database::delete($table, ['user_id = ?' => $userId]);
            }
            return;
        }
        $data = [
            'user_id' => $userId,
            'company_id' => (int) $companyId,
        ];
        if ($exists) {
            Database::update($table, ['company_id' => (int) $companyId], ['user_id = ?' => $userId]);
        } else {
            Database::insert($table, $data);
        }
    }

    /**
     * Get the company id for a given user (if any).
     */
    public function getUserCompanyId(int $userId): ?int
    {
        $table = Database::get_main_table(self::TABLE_USER_COMPANY);
        $row = Database::select('company_id', $table, ['where' => ['user_id = ?' => $userId]], 'first');
        return $row ? (int) $row['company_id'] : null;
    }

    /* Configuration helpers */
    /**
     * Get plugin configuration for the current access_url.
     */
    public function getConfiguration(): array
    {
        $table = Database::get_main_table(self::TABLE_CONFIG);
        $urlId = (int) api_get_current_access_url_id();
        $row = Database::select('*', $table, ['where' => ['access_url_id = ?' => $urlId]], 'first');
        if (!$row) {
            return [
                'email' => null,
                'id_ticket_communication' => null,
                'id_ticket_learner_tracking' => null,
                'category1' => null,
                'category2' => null,
            ];
        }
        return $row;
    }

    /**
     * Persist plugin configuration for the current access_url.
     */
    public function saveConfiguration(array $data): void
    {
        $table = Database::get_main_table(self::TABLE_CONFIG);
        $urlId = (int) api_get_current_access_url_id();
        $exists = Database::select('access_url_id', $table, ['where' => ['access_url_id = ?' => $urlId]], 'first');
        $payload = [
            'access_url_id' => $urlId,
            'email' => $data['email'] ?? null,
            'id_ticket_communication' => isset($data['id_ticket_communication']) && $data['id_ticket_communication'] !== '' ? (int) $data['id_ticket_communication'] : null,
            'id_ticket_learner_tracking' => isset($data['id_ticket_learner_tracking']) && $data['id_ticket_learner_tracking'] !== '' ? (int) $data['id_ticket_learner_tracking'] : null,
            'category1' => isset($data['category1']) && $data['category1'] !== '' ? (int) $data['category1'] : null,
            'category2' => isset($data['category2']) && $data['category2'] !== '' ? (int) $data['category2'] : null,
        ];
        if ($exists) {
            Database::update($table, $payload, ['access_url_id = ?' => $urlId]);
        } else {
            Database::insert($table, $payload);
        }
    }
}




