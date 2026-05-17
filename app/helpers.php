<?php
declare(strict_types=1);

function config(?string $path = null, mixed $default = null): mixed
{
    $config = $GLOBALS['bipe_v2_config'] ?? [];

    if ($path === null) {
        return $config;
    }

    $value = $config;
    foreach (explode('.', $path) as $segment) {
        if (!is_array($value) || !array_key_exists($segment, $value)) {
            return $default;
        }
        $value = $value[$segment];
    }

    return $value;
}

function url(string $path = ''): string
{
    $base = rtrim((string) config('app.base_url', ''), '/');

    if ($path === '') {
        return $base !== '' ? $base : '/';
    }

    return $base . '/' . ltrim($path, '/');
}

function redirect_to(string $path): never
{
    header('Location: ' . url($path));
    exit;
}

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function is_post(): bool
{
    return strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';
}

function post(string $key, mixed $default = null): mixed
{
    return $_POST[$key] ?? $default;
}

function query_all(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchAll();
}

function query_one(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();

    return $row ?: null;
}

function query_value(string $sql, array $params = []): mixed
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return $stmt->fetchColumn();
}

function execute_sql(string $sql, array $params = []): bool
{
    $stmt = db()->prepare($sql);

    return $stmt->execute($params);
}

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function consume_flash(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);

    return is_array($messages) ? $messages : [];
}

function active_session_login_path(string $role): string
{
    return match (strtolower(trim($role))) {
        'admin' => 'admin/login.php',
        'teacher' => 'faculty/login.php',
        'student' => 'student/login.php',
        default => '',
    };
}

function active_session_supported_role(?string $role): ?string
{
    $role = strtolower(trim((string) $role));

    return in_array($role, ['admin', 'teacher', 'student'], true) ? $role : null;
}

function active_session_timeout_window(): int
{
    $timeout = (int) config('security.session_idle_timeout', 7200);

    return $timeout > 0 ? $timeout : 43200;
}

function active_session_key(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return '';
    }

    $sessionId = session_id();

    return $sessionId !== '' ? hash('sha256', $sessionId) : '';
}

function active_session_identity(?array $user = null): ?array
{
    $user ??= current_user();
    if (!is_array($user)) {
        return null;
    }

    $role = active_session_supported_role($user['role'] ?? null);
    $userId = (int) ($user['id'] ?? 0);

    if ($role === null || $userId <= 0) {
        return null;
    }

    return ['role' => $role, 'user_id' => $userId];
}

function active_session_row(string $role, int $userId): ?array
{
    $role = active_session_supported_role($role);
    if ($role === null || $userId <= 0) {
        return null;
    }

    return query_one(
        'SELECT * FROM active_user_sessions WHERE role_name = :role_name AND user_id = :user_id LIMIT 1',
        ['role_name' => $role, 'user_id' => $userId]
    );
}

function active_session_rows_map(string $role, array $userIds): array
{
    $role = active_session_supported_role($role);
    if ($role === null) {
        return [];
    }

    $userIds = array_values(array_unique(array_filter(array_map(
        static fn (mixed $value): int => (int) $value,
        $userIds
    ), static fn (int $value): bool => $value > 0)));

    if ($userIds === []) {
        return [];
    }

    $placeholders = [];
    $params = ['role_name' => $role];
    foreach ($userIds as $index => $userId) {
        $key = 'user_id_' . $index;
        $placeholders[] = ':' . $key;
        $params[$key] = $userId;
    }

    $rows = query_all(
        'SELECT * FROM active_user_sessions WHERE role_name = :role_name AND user_id IN (' . implode(', ', $placeholders) . ')',
        $params
    );

    $map = [];
    foreach ($rows as $row) {
        $map[(int) $row['user_id']] = $row;
    }

    return $map;
}

function reset_active_session(string $role, int $userId): bool
{
    $role = active_session_supported_role($role);
    if ($role === null || $userId <= 0) {
        return false;
    }

    $existing = active_session_row($role, $userId);
    if ($existing === null) {
        return false;
    }

    execute_sql(
        'DELETE FROM active_user_sessions WHERE role_name = :role_name AND user_id = :user_id',
        ['role_name' => $role, 'user_id' => $userId]
    );

    return true;
}

function store_login_takeover_request(array $user): void
{
    $role = active_session_supported_role($user['role'] ?? null);
    $userId = (int) ($user['id'] ?? 0);
    if ($role === null || $userId <= 0) {
        return;
    }

    $_SESSION['login_takeover'][$role] = [
        'role' => $role,
        'user_id' => $userId,
        'display_name' => (string) ($user['name'] ?? 'User'),
        'reference' => (string) ($user['username'] ?? $user['teacher_code'] ?? $user['enrollment_no'] ?? ''),
        'issued_at' => time(),
        'expires_at' => time() + 300,
    ];
}

function clear_login_takeover_request(?string $role = null): void
{
    if ($role === null) {
        unset($_SESSION['login_takeover']);
        return;
    }

    $role = active_session_supported_role($role);
    if ($role === null) {
        return;
    }

    unset($_SESSION['login_takeover'][$role]);
    if (empty($_SESSION['login_takeover'])) {
        unset($_SESSION['login_takeover']);
    }
}

function login_takeover_request(string $role): ?array
{
    $role = active_session_supported_role($role);
    if ($role === null) {
        return null;
    }

    $request = $_SESSION['login_takeover'][$role] ?? null;
    if (!is_array($request)) {
        return null;
    }

    if ((int) ($request['expires_at'] ?? 0) < time()) {
        clear_login_takeover_request($role);
        return null;
    }

    return $request;
}

function purge_expired_active_sessions(): void
{
    static $lastPurgedAt = 0;

    $now = time();
    if (($now - $lastPurgedAt) < 30) {
        return;
    }

    $lastPurgedAt = $now;
    $cutoff = date('Y-m-d H:i:s', $now - active_session_timeout_window());

    execute_sql('DELETE FROM active_user_sessions WHERE last_seen_at < :cutoff', ['cutoff' => $cutoff]);
}

function acquire_active_session_slot(string $role, int $userId, bool $forceTakeover = false): bool
{
    $role = active_session_supported_role($role);
    if ($role === null || $userId <= 0) {
        return true;
    }

    $sessionKey = active_session_key();
    if ($sessionKey === '') {
        return false;
    }

    purge_expired_active_sessions();

    $connection = db();
    $startedTransaction = !$connection->inTransaction();
    $now = date('Y-m-d H:i:s');
    $params = [
        'role_name' => $role,
        'user_id' => $userId,
        'session_key' => $sessionKey,
        'login_ip' => client_ip(),
        'user_agent' => audit_trimmed_value((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 1000),
        'created_at' => $now,
        'last_seen_at' => $now,
    ];

    if ($startedTransaction) {
        $connection->beginTransaction();
    }

    try {
        $existing = query_one(
            'SELECT id, session_key
             FROM active_user_sessions
             WHERE role_name = :role_name AND user_id = :user_id
             LIMIT 1
             FOR UPDATE',
            ['role_name' => $role, 'user_id' => $userId]
        );

        if ($existing && (string) ($existing['session_key'] ?? '') !== $sessionKey && !$forceTakeover) {
            if ($startedTransaction && $connection->inTransaction()) {
                $connection->rollBack();
            }

            return false;
        }

        if ($existing) {
            execute_sql(
                'UPDATE active_user_sessions
                 SET session_key = :session_key,
                     login_ip = :login_ip,
                     user_agent = :user_agent,
                     last_seen_at = :last_seen_at
                 WHERE id = :id',
                [
                    'id' => (int) $existing['id'],
                    'session_key' => $params['session_key'],
                    'login_ip' => $params['login_ip'],
                    'user_agent' => $params['user_agent'],
                    'last_seen_at' => $params['last_seen_at'],
                ]
            );
        } else {
            execute_sql(
                'INSERT INTO active_user_sessions (role_name, user_id, session_key, login_ip, user_agent, created_at, last_seen_at)
                 VALUES (:role_name, :user_id, :session_key, :login_ip, :user_agent, :created_at, :last_seen_at)',
                $params
            );
        }

        if ($startedTransaction && $connection->inTransaction()) {
            $connection->commit();
        }

        return true;
    } catch (Throwable $exception) {
        if ($startedTransaction && $connection->inTransaction()) {
            $connection->rollBack();
        }

        if ($exception instanceof PDOException && $exception->getCode() === '23000') {
            return false;
        }

        throw $exception;
    }
}

function refresh_active_session_heartbeat(): void
{
    $identity = active_session_identity();
    if ($identity === null) {
        return;
    }

    $sessionKey = active_session_key();
    if ($sessionKey === '') {
        return;
    }

    $row = active_session_row($identity['role'], $identity['user_id']);
    if ($row === null) {
        return;
    }

    if ((string) ($row['session_key'] ?? '') !== $sessionKey) {
        return;
    }

    execute_sql(
        'UPDATE active_user_sessions
         SET login_ip = :login_ip,
             user_agent = :user_agent,
             last_seen_at = :last_seen_at
         WHERE id = :id',
        [
            'id' => (int) $row['id'],
            'login_ip' => client_ip(),
            'user_agent' => audit_trimmed_value((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 1000),
            'last_seen_at' => date('Y-m-d H:i:s'),
        ]
    );
}

function enforce_single_session_integrity(): void
{
    $identity = active_session_identity();
    if ($identity === null) {
        return;
    }

    purge_expired_active_sessions();

    $row = active_session_row($identity['role'], $identity['user_id']);
    $sessionKey = active_session_key();

    if ($row !== null && (string) ($row['session_key'] ?? '') === $sessionKey) {
        refresh_active_session_heartbeat();
        return;
    }

    $role = $identity['role'];
    logout_session();
    flash('info', 'This session was closed from another login or by an administrator. Please login again.');
    redirect_to(active_session_login_path($role));
}

function login_session(array $user, bool $forceTakeover = false): bool
{
    session_regenerate_id(true);
    $_SESSION['auth'] = $user;
    $_SESSION['last_activity_at'] = time();
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));

    if (!acquire_active_session_slot((string) ($user['role'] ?? ''), (int) ($user['id'] ?? 0), $forceTakeover)) {
        unset($_SESSION['auth'], $_SESSION['last_activity_at'], $_SESSION['_csrf']);
        session_regenerate_id(true);

        return false;
    }

    return true;
}

function release_active_session(?array $user = null): void
{
    $identity = active_session_identity($user);
    if ($identity === null) {
        return;
    }

    $params = [
        'role_name' => $identity['role'],
        'user_id' => $identity['user_id'],
    ];
    $sql = 'DELETE FROM active_user_sessions WHERE role_name = :role_name AND user_id = :user_id';
    $sessionKey = active_session_key();

    if ($sessionKey !== '') {
        $sql .= ' AND session_key = :session_key';
        $params['session_key'] = $sessionKey;
    }

    execute_sql($sql, $params);
}

function logout_session(): void
{
    $user = current_user();
    release_active_session($user);
    unset($_SESSION['auth'], $_SESSION['last_activity_at'], $_SESSION['_csrf']);
    session_regenerate_id(true);
}

function current_user(): ?array
{
    $user = $_SESSION['auth'] ?? null;

    return is_array($user) ? $user : null;
}

function update_current_user_session(array $updates): void
{
    if (!isset($_SESSION['auth']) || !is_array($_SESSION['auth'])) {
        return;
    }

    $_SESSION['auth'] = array_merge($_SESSION['auth'], $updates);
}

function current_role(): ?string
{
    return current_user()['role'] ?? null;
}

function require_role(string $role): void
{
    if (current_role() !== $role) {
        flash('error', 'Please login first.');
        redirect_to('');
    }
}

function year_label(int $level): string
{
    return match ($level) {
        1 => '1st Year',
        2 => '2nd Year',
        3 => '3rd Year',
        default => $level . 'th Year',
    };
}

function semester_label(int $semester): string
{
    return 'Semester ' . $semester;
}

function subject_short_label(array $subject): string
{
    $shortName = strtoupper(trim((string) ($subject['subject_short_name'] ?? '')));
    if ($shortName !== '') {
        return $shortName;
    }

    $code = strtoupper(trim((string) ($subject['subject_code'] ?? '')));
    $name = trim((string) ($subject['subject_name'] ?? ''));
    if ($name === '') {
        return $code !== '' ? $code : 'SUB';
    }

    $tokens = preg_split('/[^A-Za-z0-9]+/', $name, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if ($tokens === []) {
        return $code !== '' ? $code : strtoupper(substr($name, 0, 10));
    }

    if (count($tokens) === 1) {
        return strtoupper(substr($tokens[0], 0, 10));
    }

    $ignored = ['and', 'of', 'the', 'for', 'to', 'in'];
    $label = '';
    foreach ($tokens as $token) {
        if (in_array(strtolower($token), $ignored, true)) {
            continue;
        }

        $label .= strtoupper(substr($token, 0, 1));
    }

    if ($label === '') {
        $label = $code !== '' ? $code : strtoupper(substr(implode('', $tokens), 0, 10));
    }

    return substr($label, 0, 10);
}

function backfill_subject_short_names(): void
{
    if (!schema_column_exists('subjects', 'subject_short_name')) {
        return;
    }

    $subjects = query_all(
        'SELECT id, subject_code, subject_name, subject_short_name
         FROM subjects
         WHERE subject_short_name IS NULL OR TRIM(subject_short_name) = ""'
    );

    foreach ($subjects as $subject) {
        execute_sql(
            'UPDATE subjects SET subject_short_name = :subject_short_name WHERE id = :id',
            [
                'id' => (int) $subject['id'],
                'subject_short_name' => subject_short_label($subject),
            ]
        );
    }
}

function safe_download_segment(string $value): string
{
    $value = preg_replace('/[^A-Za-z0-9._ -]+/', '', $value) ?? '';
    $value = trim((string) preg_replace('/\s+/', '_', $value));

    return $value !== '' ? $value : 'download';
}

function excel_xml_escape(string $value): string
{
    return htmlspecialchars(str_replace("\r", '', $value), ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function excel_normalize_sheet_name(string $name, array &$usedNames): string
{
    $name = trim(preg_replace('/[:\\\\\\/\\?\\*\\[\\]]+/', ' ', $name) ?? '');
    if ($name === '') {
        $name = 'Sheet';
    }

    $name = substr($name, 0, 31);
    $base = $name;
    $suffix = 1;

    while (in_array(strtolower($name), $usedNames, true)) {
        $suffixLabel = ' ' . $suffix;
        $name = substr($base, 0, max(1, 31 - strlen($suffixLabel))) . $suffixLabel;
        $suffix++;
    }

    $usedNames[] = strtolower($name);

    return $name;
}

function excel_workbook_xml(array $worksheets): string
{
    if ($worksheets === []) {
        $worksheets = [['name' => 'Sheet1', 'rows' => [[['value' => 'No data available.', 'style' => 'Section']]]]];
    }

    $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    $xml .= '<?mso-application progid="Excel.Sheet"?>' . "\n";
    $xml .= '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet"';
    $xml .= ' xmlns:o="urn:schemas-microsoft-com:office:office"';
    $xml .= ' xmlns:x="urn:schemas-microsoft-com:office:excel"';
    $xml .= ' xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet"';
    $xml .= ' xmlns:html="http://www.w3.org/TR/REC-html40">' . "\n";
    $xml .= '  <Styles>' . "\n";
    $xml .= '    <Style ss:ID="Default" ss:Name="Normal">' . "\n";
    $xml .= '      <Alignment ss:Vertical="Center" ss:WrapText="1"/>' . "\n";
    $xml .= '      <Borders>' . "\n";
    $xml .= '        <Border ss:Position="Bottom" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D6DEEE"/>' . "\n";
    $xml .= '        <Border ss:Position="Left" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D6DEEE"/>' . "\n";
    $xml .= '        <Border ss:Position="Right" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D6DEEE"/>' . "\n";
    $xml .= '        <Border ss:Position="Top" ss:LineStyle="Continuous" ss:Weight="1" ss:Color="#D6DEEE"/>' . "\n";
    $xml .= '      </Borders>' . "\n";
    $xml .= '      <Font ss:FontName="Calibri" ss:Size="11" ss:Color="#1F2937"/>' . "\n";
    $xml .= '    </Style>' . "\n";
    $xml .= '    <Style ss:ID="Title">' . "\n";
    $xml .= '      <Font ss:FontName="Calibri" ss:Size="15" ss:Bold="1" ss:Color="#0F172A"/>' . "\n";
    $xml .= '      <Alignment ss:Vertical="Center" ss:WrapText="1"/>' . "\n";
    $xml .= '      <Interior ss:Color="#E8F0FF" ss:Pattern="Solid"/>' . "\n";
    $xml .= '    </Style>' . "\n";
    $xml .= '    <Style ss:ID="Section">' . "\n";
    $xml .= '      <Font ss:FontName="Calibri" ss:Size="12" ss:Bold="1" ss:Color="#1D4ED8"/>' . "\n";
    $xml .= '      <Interior ss:Color="#EEF4FF" ss:Pattern="Solid"/>' . "\n";
    $xml .= '    </Style>' . "\n";
    $xml .= '    <Style ss:ID="Header">' . "\n";
    $xml .= '      <Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1" ss:Color="#FFFFFF"/>' . "\n";
    $xml .= '      <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>' . "\n";
    $xml .= '      <Interior ss:Color="#1D4ED8" ss:Pattern="Solid"/>' . "\n";
    $xml .= '    </Style>' . "\n";
    $xml .= '    <Style ss:ID="SubHeader">' . "\n";
    $xml .= '      <Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1" ss:Color="#1E3A8A"/>' . "\n";
    $xml .= '      <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>' . "\n";
    $xml .= '      <Interior ss:Color="#DBEAFE" ss:Pattern="Solid"/>' . "\n";
    $xml .= '    </Style>' . "\n";
    $xml .= '    <Style ss:ID="Meta">' . "\n";
    $xml .= '      <Font ss:FontName="Calibri" ss:Size="10" ss:Italic="1" ss:Color="#475569"/>' . "\n";
    $xml .= '      <Interior ss:Color="#F8FAFC" ss:Pattern="Solid"/>' . "\n";
    $xml .= '    </Style>' . "\n";
    $xml .= '    <Style ss:ID="Center">' . "\n";
    $xml .= '      <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>' . "\n";
    $xml .= '    </Style>' . "\n";
    $xml .= '    <Style ss:ID="Metric">' . "\n";
    $xml .= '      <Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1" ss:Color="#0F172A"/>' . "\n";
    $xml .= '      <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>' . "\n";
    $xml .= '      <Interior ss:Color="#F8FAFC" ss:Pattern="Solid"/>' . "\n";
    $xml .= '    </Style>' . "\n";
    $xml .= '    <Style ss:ID="Positive">' . "\n";
    $xml .= '      <Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1" ss:Color="#166534"/>' . "\n";
    $xml .= '      <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>' . "\n";
    $xml .= '      <Interior ss:Color="#DCFCE7" ss:Pattern="Solid"/>' . "\n";
    $xml .= '    </Style>' . "\n";
    $xml .= '    <Style ss:ID="Negative">' . "\n";
    $xml .= '      <Font ss:FontName="Calibri" ss:Size="11" ss:Bold="1" ss:Color="#B91C1C"/>' . "\n";
    $xml .= '      <Alignment ss:Horizontal="Center" ss:Vertical="Center" ss:WrapText="1"/>' . "\n";
    $xml .= '      <Interior ss:Color="#FEE2E2" ss:Pattern="Solid"/>' . "\n";
    $xml .= '    </Style>' . "\n";
    $xml .= '  </Styles>' . "\n";

    $usedSheetNames = [];
    foreach ($worksheets as $worksheetIndex => $worksheet) {
        $sheetName = excel_normalize_sheet_name((string) ($worksheet['name'] ?? 'Sheet'), $usedSheetNames);
        $rows = is_array($worksheet['rows'] ?? null) ? $worksheet['rows'] : [];
        $columns = is_array($worksheet['columns'] ?? null) ? $worksheet['columns'] : [];

        $xml .= '  <Worksheet ss:Name="' . excel_xml_escape($sheetName) . '">' . "\n";
        $xml .= '    <Table>' . "\n";
        foreach ($columns as $width) {
            $numericWidth = max(40, (float) $width);
            $xml .= '      <Column ss:AutoFitWidth="0" ss:Width="' . $numericWidth . '"/>' . "\n";
        }

        foreach ($rows as $row) {
            $xml .= '      <Row>' . "\n";
            foreach ((array) $row as $cell) {
                if (!is_array($cell) || !array_key_exists('value', $cell)) {
                    $cell = ['value' => $cell];
                }

                $value = $cell['value'] ?? '';
                $styleId = (string) ($cell['style'] ?? 'Default');
                $mergeAcross = max(0, (int) ($cell['mergeAcross'] ?? 0));
                $type = (string) ($cell['type'] ?? ((is_int($value) || is_float($value)) ? 'Number' : 'String'));

                $attributes = ' ss:StyleID="' . excel_xml_escape($styleId) . '"';
                if ($mergeAcross > 0) {
                    $attributes .= ' ss:MergeAcross="' . $mergeAcross . '"';
                }

                $xml .= '        <Cell' . $attributes . '><Data ss:Type="' . excel_xml_escape($type) . '">'
                    . excel_xml_escape((string) $value)
                    . '</Data></Cell>' . "\n";
            }
            $xml .= '      </Row>' . "\n";
        }

        $xml .= '    </Table>' . "\n";
        $xml .= '    <WorksheetOptions xmlns="urn:schemas-microsoft-com:office:excel">' . "\n";
        if ($worksheetIndex === 0) {
            $xml .= '      <Selected/>' . "\n";
        }
        $xml .= '      <ProtectObjects>False</ProtectObjects>' . "\n";
        $xml .= '      <ProtectScenarios>False</ProtectScenarios>' . "\n";
        $xml .= '    </WorksheetOptions>' . "\n";
        $xml .= '  </Worksheet>' . "\n";
    }

    $xml .= '</Workbook>';

    return $xml;
}

function xlsx_column_name(int $index): string
{
    $label = '';
    while ($index > 0) {
        $index--;
        $label = chr(65 + ($index % 26)) . $label;
        $index = intdiv($index, 26);
    }

    return $label !== '' ? $label : 'A';
}

function xlsx_style_index(string $style): int
{
    return match ($style) {
        'Title' => 1,
        'Section' => 2,
        'Header' => 3,
        'SubHeader' => 4,
        'Meta' => 5,
        'Center' => 6,
        'Metric' => 7,
        'Positive' => 8,
        'Negative' => 9,
        'Number' => 10,
        'Percent' => 11,
        'Integer' => 12,
        default => 0,
    };
}

function xlsx_styles_xml(): string
{
    return <<<'XML'
<?xml version="1.0" encoding="UTF-8" standalone="yes"?>
<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">
  <numFmts count="2">
    <numFmt numFmtId="164" formatCode="0.##"/>
    <numFmt numFmtId="165" formatCode="0.##%"/>
  </numFmts>
  <fonts count="9">
    <font><sz val="11"/><name val="Calibri"/><color rgb="FF1F2937"/></font>
    <font><b/><sz val="15"/><name val="Calibri"/><color rgb="FF0F172A"/></font>
    <font><b/><sz val="12"/><name val="Calibri"/><color rgb="FF1D4ED8"/></font>
    <font><b/><sz val="11"/><name val="Calibri"/><color rgb="FFFFFFFF"/></font>
    <font><b/><sz val="11"/><name val="Calibri"/><color rgb="FF1E3A8A"/></font>
    <font><i/><sz val="10"/><name val="Calibri"/><color rgb="FF475569"/></font>
    <font><b/><sz val="11"/><name val="Calibri"/><color rgb="FF0F172A"/></font>
    <font><b/><sz val="11"/><name val="Calibri"/><color rgb="FF166534"/></font>
    <font><b/><sz val="11"/><name val="Calibri"/><color rgb="FFB91C1C"/></font>
  </fonts>
  <fills count="9">
    <fill><patternFill patternType="none"/></fill>
    <fill><patternFill patternType="gray125"/></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFE8F0FF"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFEEF4FF"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FF1D4ED8"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFDBEAFE"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFF8FAFC"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFDCFCE7"/><bgColor indexed="64"/></patternFill></fill>
    <fill><patternFill patternType="solid"><fgColor rgb="FFFEE2E2"/><bgColor indexed="64"/></patternFill></fill>
  </fills>
  <borders count="2">
    <border><left/><right/><top/><bottom/><diagonal/></border>
    <border>
      <left style="thin"><color rgb="FFD6DEEE"/></left>
      <right style="thin"><color rgb="FFD6DEEE"/></right>
      <top style="thin"><color rgb="FFD6DEEE"/></top>
      <bottom style="thin"><color rgb="FFD6DEEE"/></bottom>
      <diagonal/>
    </border>
  </borders>
  <cellStyleXfs count="1">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>
  </cellStyleXfs>
  <cellXfs count="13">
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="2" fillId="3" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="3" fillId="4" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="4" fillId="5" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="5" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="0" fillId="0" borderId="1" xfId="0" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="6" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="7" fillId="7" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="0" fontId="8" fillId="8" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="164" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="165" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
    <xf numFmtId="1" fontId="0" fillId="0" borderId="1" xfId="0" applyNumberFormat="1" applyBorder="1" applyAlignment="1"><alignment horizontal="center" vertical="center" wrapText="1"/></xf>
  </cellXfs>
  <cellStyles count="1">
    <cellStyle name="Normal" xfId="0" builtinId="0"/>
  </cellStyles>
</styleSheet>
XML;
}

function xlsx_numeric_value(mixed $value): string
{
    if (is_int($value)) {
        return (string) $value;
    }

    $numeric = (float) $value;

    return rtrim(rtrim(sprintf('%.12F', $numeric), '0'), '.');
}

function xlsx_sheet_xml(array $worksheet): string
{
    $rows = is_array($worksheet['rows'] ?? null) ? $worksheet['rows'] : [];
    $columns = is_array($worksheet['columns'] ?? null) ? $worksheet['columns'] : [];
    $rowIndex = 1;
    $maxColumnIndex = 1;
    $mergeRefs = [];
    $sheetDataXml = '';

    foreach ($rows as $row) {
        $sheetDataXml .= '<row r="' . $rowIndex . '">';
        $columnIndex = 1;

        foreach ((array) $row as $cell) {
            if (!is_array($cell) || !array_key_exists('value', $cell)) {
                $cell = ['value' => $cell];
            }

            $value = $cell['value'] ?? '';
            $styleIndex = xlsx_style_index((string) ($cell['style'] ?? 'Default'));
            $mergeAcross = max(0, (int) ($cell['mergeAcross'] ?? 0));
            $reference = xlsx_column_name($columnIndex) . $rowIndex;
            $type = $cell['type'] ?? null;

            if ($type === null) {
                $type = match (true) {
                    is_int($value), is_float($value) => 'number',
                    is_bool($value) => 'boolean',
                    $value === null => 'blank',
                    default => 'string',
                };
            }

            if ($type === 'blank') {
                $sheetDataXml .= '<c r="' . $reference . '" s="' . $styleIndex . '"/>';
            } elseif ($type === 'number' && is_numeric($value)) {
                $sheetDataXml .= '<c r="' . $reference . '" s="' . $styleIndex . '"><v>'
                    . xlsx_numeric_value($value)
                    . '</v></c>';
            } elseif ($type === 'boolean') {
                $sheetDataXml .= '<c r="' . $reference . '" s="' . $styleIndex . '" t="b"><v>'
                    . ((bool) $value ? '1' : '0')
                    . '</v></c>';
            } else {
                $sheetDataXml .= '<c r="' . $reference . '" s="' . $styleIndex . '" t="inlineStr"><is><t xml:space="preserve">'
                    . excel_xml_escape((string) $value)
                    . '</t></is></c>';
            }

            if ($mergeAcross > 0) {
                $mergeRefs[] = $reference . ':' . xlsx_column_name($columnIndex + $mergeAcross) . $rowIndex;
            }

            $columnIndex += $mergeAcross + 1;
        }

        $maxColumnIndex = max($maxColumnIndex, $columnIndex - 1);
        $sheetDataXml .= '</row>';
        $rowIndex++;
    }

    $dimensionRef = 'A1:' . xlsx_column_name($maxColumnIndex) . max(1, $rowIndex - 1);
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    $xml .= '<dimension ref="' . $dimensionRef . '"/>';
    $xml .= '<sheetViews><sheetView workbookViewId="0"/></sheetViews>';

    if ($columns !== []) {
        $xml .= '<cols>';
        foreach (array_values($columns) as $index => $width) {
            $columnWidth = round(max(8.43, ((float) $width) / 7.2), 2);
            $xml .= '<col min="' . ($index + 1) . '" max="' . ($index + 1) . '" width="' . $columnWidth . '" customWidth="1"/>';
        }
        $xml .= '</cols>';
    }

    $xml .= '<sheetData>' . $sheetDataXml . '</sheetData>';

    if ($mergeRefs !== []) {
        $xml .= '<mergeCells count="' . count($mergeRefs) . '">';
        foreach ($mergeRefs as $mergeRef) {
            $xml .= '<mergeCell ref="' . $mergeRef . '"/>';
        }
        $xml .= '</mergeCells>';
    }

    $xml .= '<pageMargins left="0.35" right="0.35" top="0.75" bottom="0.75" header="0.3" footer="0.3"/>';
    $xml .= '<ignoredErrors><ignoredError sqref="' . $dimensionRef . '" numberStoredAsText="1"/></ignoredErrors>';
    $xml .= '</worksheet>';

    return $xml;
}

function xlsx_package_files(array $worksheets): array
{
    if ($worksheets === []) {
        $worksheets = [['name' => 'Sheet1', 'rows' => [[['value' => 'No data available.', 'style' => 'Section']]]]];
    }

    $usedSheetNames = [];
    $normalizedWorksheets = [];
    foreach ($worksheets as $worksheet) {
        $worksheet['name'] = excel_normalize_sheet_name((string) ($worksheet['name'] ?? 'Sheet'), $usedSheetNames);
        $normalizedWorksheets[] = $worksheet;
    }

    $sheetOverrides = '';
    $sheetEntries = '';
    $sheetRelationships = '';
    $sheetAppTitles = '';
    $worksheetFiles = [];

    foreach ($normalizedWorksheets as $index => $worksheet) {
        $sheetNumber = $index + 1;
        $worksheetFiles['xl/worksheets/sheet' . $sheetNumber . '.xml'] = xlsx_sheet_xml($worksheet);
        $sheetOverrides .= '<Override PartName="/xl/worksheets/sheet' . $sheetNumber . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        $sheetEntries .= '<sheet name="' . excel_xml_escape((string) $worksheet['name']) . '" sheetId="' . $sheetNumber . '" r:id="rId' . $sheetNumber . '"/>';
        $sheetRelationships .= '<Relationship Id="rId' . $sheetNumber . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $sheetNumber . '.xml"/>';
        $sheetAppTitles .= '<vt:lpstr>' . excel_xml_escape((string) $worksheet['name']) . '</vt:lpstr>';
    }

    $timestamp = gmdate('Y-m-d\TH:i:s\Z');

    return array_merge([
        '[Content_Types].xml' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . $sheetOverrides
            . '</Types>',
        '_rels/.rels' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>',
        'docProps/app.xml' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>BIPE Academic Portal</Application>'
            . '<HeadingPairs><vt:vector size="2" baseType="variant"><vt:variant><vt:lpstr>Worksheets</vt:lpstr></vt:variant><vt:variant><vt:i4>' . count($normalizedWorksheets) . '</vt:i4></vt:variant></vt:vector></HeadingPairs>'
            . '<TitlesOfParts><vt:vector size="' . count($normalizedWorksheets) . '" baseType="lpstr">' . $sheetAppTitles . '</vt:vector></TitlesOfParts>'
            . '</Properties>',
        'docProps/core.xml' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>BIPE Academic Portal Export</dc:title>'
            . '<dc:creator>OpenAI Codex</dc:creator>'
            . '<cp:lastModifiedBy>OpenAI Codex</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">' . $timestamp . '</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">' . $timestamp . '</dcterms:modified>'
            . '</cp:coreProperties>',
        'xl/workbook.xml' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<bookViews><workbookView xWindow="0" yWindow="0" windowWidth="24000" windowHeight="12000"/></bookViews>'
            . '<sheets>' . $sheetEntries . '</sheets>'
            . '</workbook>',
        'xl/_rels/workbook.xml.rels' =>
            '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . $sheetRelationships
            . '<Relationship Id="rId' . (count($normalizedWorksheets) + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>',
        'xl/styles.xml' => xlsx_styles_xml(),
    ], $worksheetFiles);
}

function binary_zip_archive(array $files): string
{
    $archive = '';
    $centralDirectory = '';
    $offset = 0;
    $timestamp = time();
    $dosTime = ((int) date('H', $timestamp) << 11) | ((int) date('i', $timestamp) << 5) | ((int) date('s', $timestamp) >> 1);
    $dosDate = (((int) date('Y', $timestamp) - 1980) << 9) | ((int) date('n', $timestamp) << 5) | (int) date('j', $timestamp);

    foreach ($files as $name => $contents) {
        $name = str_replace('\\', '/', (string) $name);
        $contents = (string) $contents;
        $crc = (int) sprintf('%u', crc32($contents));
        $size = strlen($contents);
        $nameLength = strlen($name);

        $localHeader = pack(
            'VvvvvvVVVvv',
            0x04034b50,
            20,
            0,
            0,
            $dosTime,
            $dosDate,
            $crc,
            $size,
            $size,
            $nameLength,
            0
        );

        $archive .= $localHeader . $name . $contents;

        $centralHeader = pack(
            'VvvvvvvVVVvvvvvVV',
            0x02014b50,
            20,
            20,
            0,
            0,
            $dosTime,
            $dosDate,
            $crc,
            $size,
            $size,
            $nameLength,
            0,
            0,
            0,
            0,
            0,
            $offset
        );

        $centralDirectory .= $centralHeader . $name;
        $offset = strlen($archive);
    }

    $endOfCentralDirectory = pack(
        'VvvvvVVv',
        0x06054b50,
        0,
        0,
        count($files),
        count($files),
        strlen($centralDirectory),
        $offset,
        0
    );

    return $archive . $centralDirectory . $endOfCentralDirectory;
}

function download_excel_workbook(string $filename, array $worksheets): never
{
    $filename = safe_download_segment($filename);
    if (!str_ends_with(strtolower($filename), '.xlsx')) {
        $filename .= '.xlsx';
    }

    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: public');
    header('Expires: 0');

    echo binary_zip_archive(xlsx_package_files($worksheets));
    exit;
}

function assignment_labels(): array
{
    return ['Assignment-I', 'Assignment-II', 'Assignment-III', 'Assignment-IV', 'Assignment-V'];
}

function current_academic_year(): ?array
{
    return query_one('SELECT * FROM academic_years WHERE is_current = 1 ORDER BY id DESC LIMIT 1');
}

function departments(): array
{
    return query_all('SELECT * FROM departments ORDER BY name');
}

function department_map(): array
{
    $map = [];
    foreach (departments() as $department) {
        $map[(int) $department['id']] = $department;
    }

    return $map;
}

function subjects_for(int $departmentId, int $semesterNo): array
{
    return query_all(
        'SELECT * FROM subjects WHERE department_id = :department_id AND semester_no = :semester_no ORDER BY subject_name',
        ['department_id' => $departmentId, 'semester_no' => $semesterNo]
    );
}

function attendance_percent_for_department(int $departmentId): int
{
    $percent = query_value(
        'SELECT ROUND(COALESCE(AVG(CASE WHEN ar.status = "P" THEN 100 ELSE 0 END), 0))
         FROM attendance_records ar
         INNER JOIN attendance_sessions ats ON ats.id = ar.attendance_session_id
         WHERE ats.department_id = :department_id',
        ['department_id' => $departmentId]
    );

    return (int) $percent;
}

function attendance_summary_for_student(int $studentId): array
{
    $row = query_one(
        'SELECT
            SUM(CASE WHEN ar.status = "P" THEN 1 ELSE 0 END) AS present_count,
            SUM(CASE WHEN ar.status = "A" THEN 1 ELSE 0 END) AS absent_count,
            COUNT(ar.id) AS total_count
         FROM attendance_records ar
         WHERE ar.student_id = :student_id',
        ['student_id' => $studentId]
    ) ?? ['present_count' => 0, 'absent_count' => 0, 'total_count' => 0];

    $present = (int) ($row['present_count'] ?? 0);
    $absent = (int) ($row['absent_count'] ?? 0);
    $total = (int) ($row['total_count'] ?? 0);

    return [
        'present' => $present,
        'absent' => $absent,
        'total' => $total,
        'percentage' => $total > 0 ? (int) round(($present / $total) * 100) : 0,
    ];
}

function marks_rows_for_student(int $studentId): array
{
    return query_all(
        'SELECT
            s.subject_name,
            mu.exam_type,
            mu.max_marks,
            mr.marks_obtained
         FROM mark_records mr
         INNER JOIN mark_uploads mu ON mu.id = mr.mark_upload_id
         INNER JOIN subjects s ON s.id = mu.subject_id
         WHERE mr.student_id = :student_id
         ORDER BY s.subject_name, mu.exam_type',
        ['student_id' => $studentId]
    );
}

function assignment_rows_for_student(int $studentId): array
{
    return query_all(
        'SELECT
            sb.subject_name,
            a.assignment_label,
            COALESCE(asb.submission_status, "pending") AS submission_status
         FROM assignments a
         INNER JOIN subjects sb ON sb.id = a.subject_id
         LEFT JOIN assignment_submissions asb
            ON asb.assignment_id = a.id AND asb.student_id = :student_id
         WHERE a.academic_year_id = (SELECT id FROM academic_years WHERE is_current = 1 ORDER BY id DESC LIMIT 1)
         ORDER BY sb.subject_name, a.assignment_label',
        ['student_id' => $studentId]
    );
}

function grade_from_marks(float $marks, float $maxMarks): string
{
    if ($maxMarks <= 0) {
        return '-';
    }

    $percent = ($marks / $maxMarks) * 100;

    return match (true) {
        $percent >= 90 => 'O',
        $percent >= 80 => 'A+',
        $percent >= 70 => 'A',
        $percent >= 60 => 'B+',
        $percent >= 50 => 'B',
        $percent >= 40 => 'C',
        default => 'F',
    };
}

function pass_fail_from_marks(float $marks, float $maxMarks): string
{
    if ($maxMarks <= 0) {
        return 'Pending';
    }

    return grade_from_marks($marks, $maxMarks) === 'F' ? 'Fail' : 'Pass';
}
function portal_stats(): array
{
    return [
        'students_total' => (int) query_value('SELECT COUNT(*) FROM students'),
        'registered_students' => (int) query_value('SELECT COUNT(*) FROM students WHERE password_hash IS NOT NULL'),
        'teachers_active' => (int) query_value('SELECT COUNT(*) FROM teachers WHERE status = "approved"'),
        'teachers_pending' => (int) query_value('SELECT COUNT(*) FROM teachers WHERE status = "pending"'),
    ];
}

function teacher_department_students(int $departmentId, ?int $yearLevel = null): array
{
    $sql = 'SELECT * FROM students WHERE department_id = :department_id';
    $params = ['department_id' => $departmentId];

    if ($yearLevel !== null) {
        $sql .= ' AND year_level = :year_level';
        $params['year_level'] = $yearLevel;
    }

    $sql .= ' ORDER BY year_level DESC, full_name ASC';

    return query_all($sql, $params);
}

function teacher_record_summary(int $teacherId): array
{
    $teacher = query_one('SELECT * FROM teachers WHERE id = :id', ['id' => $teacherId]);

    if (!$teacher) {
        return ['student_count' => 0, 'attendance_percent' => 0, 'assignment_count' => 0, 'marks_uploads' => 0];
    }

    $departmentId = (int) $teacher['department_id'];

    return [
        'student_count' => (int) query_value('SELECT COUNT(*) FROM students WHERE department_id = :department_id', ['department_id' => $departmentId]),
        'attendance_percent' => attendance_percent_for_department($departmentId),
        'assignment_count' => (int) query_value('SELECT COUNT(*) FROM assignments WHERE department_id = :department_id', ['department_id' => $departmentId]),
        'marks_uploads' => (int) query_value('SELECT COUNT(*) FROM mark_uploads WHERE department_id = :department_id', ['department_id' => $departmentId]),
    ];
}




function app_env(): string
{
    return strtolower((string) config('app.env', 'production'));
}

function app_debug(): bool
{
    return (bool) config('app.debug', false);
}

function is_production(): bool
{
    return app_env() === 'production';
}

function show_seed_hints(): bool
{
    return (bool) config('security.show_seed_hints', false);
}

function expose_otp_in_flash(): bool
{
    return (bool) config('security.expose_otp_in_flash', false);
}

function request_is_https(): bool
{
    if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
        return true;
    }

    $forwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));

    return $forwardedProto === 'https';
}

function client_ip(): string
{
    $forwarded = trim((string) ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? ''));
    if ($forwarded !== '') {
        $parts = explode(',', $forwarded);
        $candidate = trim((string) ($parts[0] ?? ''));
        if ($candidate !== '') {
            return $candidate;
        }
    }

    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function ensure_directory(string $path): void
{
    if (is_dir($path)) {
        return;
    }

    if (!mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('Unable to create application directory.');
    }
}

function schema_column_exists(string $table, string $column): bool
{
    return (bool) query_value(
        'SELECT COUNT(*)
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name',
        ['table_name' => $table, 'column_name' => $column]
    );
}

function schema_column_type(string $table, string $column): ?string
{
    $value = query_value(
        'SELECT COLUMN_TYPE
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name
         LIMIT 1',
        ['table_name' => $table, 'column_name' => $column]
    );

    return $value !== null ? (string) $value : null;
}

function ensure_runtime_schema_support(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;

    $columnDefinitions = [
        ['admins', 'phone_number', 'ALTER TABLE admins ADD COLUMN phone_number VARCHAR(20) DEFAULT NULL AFTER email'],
        ['admins', 'profile_image_path', 'ALTER TABLE admins ADD COLUMN profile_image_path VARCHAR(255) DEFAULT NULL AFTER phone_number'],
        ['teachers', 'phone_number', 'ALTER TABLE teachers ADD COLUMN phone_number VARCHAR(20) DEFAULT NULL AFTER email'],
        ['teachers', 'profile_image_path', 'ALTER TABLE teachers ADD COLUMN profile_image_path VARCHAR(255) DEFAULT NULL AFTER phone_number'],
        ['teachers', 'archived_at', 'ALTER TABLE teachers ADD COLUMN archived_at DATETIME DEFAULT NULL AFTER rejected_at'],
        ['students', 'phone_number', 'ALTER TABLE students ADD COLUMN phone_number VARCHAR(20) DEFAULT NULL AFTER email'],
        ['students', 'profile_image_path', 'ALTER TABLE students ADD COLUMN profile_image_path VARCHAR(255) DEFAULT NULL AFTER phone_number'],
        ['subjects', 'subject_short_name', 'ALTER TABLE subjects ADD COLUMN subject_short_name VARCHAR(80) DEFAULT NULL AFTER subject_code'],
        ['audit_logs', 'actor_id', 'ALTER TABLE audit_logs ADD COLUMN actor_id INT UNSIGNED DEFAULT NULL AFTER user_identifier'],
        ['audit_logs', 'actor_name', 'ALTER TABLE audit_logs ADD COLUMN actor_name VARCHAR(190) DEFAULT NULL AFTER actor_id'],
        ['audit_logs', 'actor_reference', 'ALTER TABLE audit_logs ADD COLUMN actor_reference VARCHAR(190) DEFAULT NULL AFTER actor_name'],
        ['audit_logs', 'user_agent', 'ALTER TABLE audit_logs ADD COLUMN user_agent TEXT DEFAULT NULL AFTER details'],
        ['audit_logs', 'request_method', 'ALTER TABLE audit_logs ADD COLUMN request_method VARCHAR(10) DEFAULT NULL AFTER user_agent'],
        ['audit_logs', 'request_path', 'ALTER TABLE audit_logs ADD COLUMN request_path VARCHAR(255) DEFAULT NULL AFTER request_method'],
        ['audit_logs', 'referer', 'ALTER TABLE audit_logs ADD COLUMN referer VARCHAR(255) DEFAULT NULL AFTER request_path'],
        ['audit_logs', 'forwarded_for', 'ALTER TABLE audit_logs ADD COLUMN forwarded_for VARCHAR(255) DEFAULT NULL AFTER referer'],
        ['audit_logs', 'accept_language', 'ALTER TABLE audit_logs ADD COLUMN accept_language VARCHAR(120) DEFAULT NULL AFTER forwarded_for'],
        ['audit_logs', 'session_fingerprint', 'ALTER TABLE audit_logs ADD COLUMN session_fingerprint CHAR(64) DEFAULT NULL AFTER accept_language'],
    ];

    foreach ($columnDefinitions as [$table, $column, $sql]) {
        if (!schema_column_exists($table, $column)) {
            execute_sql($sql);
        }
    }

    $teacherStatusType = strtolower((string) (schema_column_type('teachers', 'status') ?? ''));
    if ($teacherStatusType !== '' && strpos($teacherStatusType, "'archived'") === false) {
        execute_sql(
            'ALTER TABLE teachers
             MODIFY COLUMN status ENUM("pending", "approved", "rejected", "archived") NOT NULL DEFAULT "pending"'
        );
    }

    $auditDetailsType = strtolower((string) (schema_column_type('audit_logs', 'details') ?? ''));
    if ($auditDetailsType !== '' && strpos($auditDetailsType, 'text') === false) {
        execute_sql('ALTER TABLE audit_logs MODIFY COLUMN details TEXT DEFAULT NULL');
    }

    execute_sql(
        'CREATE TABLE IF NOT EXISTS active_user_sessions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            role_name ENUM("admin", "teacher", "student") NOT NULL,
            user_id INT UNSIGNED NOT NULL,
            session_key CHAR(64) NOT NULL,
            login_ip VARCHAR(45) DEFAULT NULL,
            user_agent VARCHAR(1000) DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_active_user_slot (role_name, user_id),
            UNIQUE KEY uq_active_session_key (session_key),
            INDEX idx_active_session_last_seen (last_seen_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    execute_sql(
        'CREATE TABLE IF NOT EXISTS support_requests (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            category ENUM("request", "feedback", "issue") NOT NULL DEFAULT "request",
            request_type VARCHAR(80) DEFAULT NULL,
            requester_role ENUM("teacher", "student", "admin", "guest") NOT NULL DEFAULT "teacher",
            requester_id INT UNSIGNED DEFAULT NULL,
            requester_name VARCHAR(190) DEFAULT NULL,
            requester_identifier VARCHAR(80) DEFAULT NULL,
            requester_email VARCHAR(190) DEFAULT NULL,
            target_role ENUM("admin", "teacher", "student") DEFAULT NULL,
            target_id INT UNSIGNED DEFAULT NULL,
            target_name VARCHAR(190) DEFAULT NULL,
            target_identifier VARCHAR(80) DEFAULT NULL,
            subject_line VARCHAR(190) DEFAULT NULL,
            message_body TEXT DEFAULT NULL,
            requested_password_hash VARCHAR(255) DEFAULT NULL,
            admin_response TEXT DEFAULT NULL,
            status ENUM("pending", "approved", "rejected") NOT NULL DEFAULT "pending",
            reviewed_by_admin_id INT UNSIGNED DEFAULT NULL,
            reviewed_at DATETIME DEFAULT NULL,
            requester_seen_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_support_requests_category_status (category, status),
            INDEX idx_support_requests_requester (requester_role, requester_identifier),
            INDEX idx_support_requests_created_at (created_at),
            CONSTRAINT fk_support_requests_admin FOREIGN KEY (reviewed_by_admin_id) REFERENCES admins(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );

    $supportColumnDefinitions = [
        ['target_role', 'ALTER TABLE support_requests ADD COLUMN target_role ENUM("admin", "teacher", "student") DEFAULT NULL AFTER requester_email'],
        ['target_id', 'ALTER TABLE support_requests ADD COLUMN target_id INT UNSIGNED DEFAULT NULL AFTER target_role'],
        ['target_name', 'ALTER TABLE support_requests ADD COLUMN target_name VARCHAR(190) DEFAULT NULL AFTER target_id'],
        ['target_identifier', 'ALTER TABLE support_requests ADD COLUMN target_identifier VARCHAR(80) DEFAULT NULL AFTER target_name'],
        ['admin_response', 'ALTER TABLE support_requests ADD COLUMN admin_response TEXT DEFAULT NULL AFTER requested_password_hash'],
        ['requester_seen_at', 'ALTER TABLE support_requests ADD COLUMN requester_seen_at DATETIME DEFAULT NULL AFTER reviewed_at'],
    ];

    foreach ($supportColumnDefinitions as [$column, $sql]) {
        if (!schema_column_exists('support_requests', $column)) {
            execute_sql($sql);
        }
    }

    backfill_subject_short_names();

    foreach (['admins', 'teachers', 'students'] as $role) {
        profile_image_storage_directory($role);
    }
}
function app_log(string $level, string $message, array $context = []): void
{
    $logPath = (string) config('logging.path');
    if ($logPath === '') {
        return;
    }

    $directory = dirname($logPath);
    if (!is_dir($directory)) {
        mkdir($directory, 0775, true);
    }

    $entry = [
        'timestamp' => date('c'),
        'level' => strtoupper($level),
        'message' => $message,
        'context' => $context,
    ];

    file_put_contents($logPath, json_encode($entry, JSON_UNESCAPED_SLASHES) . PHP_EOL, FILE_APPEND | LOCK_EX);
}

function apply_security_headers(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
    header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; script-src 'self' 'unsafe-inline'; connect-src 'self'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
}

function safe_exception_message(Throwable $exception, string $fallback): string
{
    $message = trim($exception->getMessage());
    if ($message === '') {
        return $fallback;
    }

    foreach (['SQLSTATE', 'PDOException', 'Stack trace', 'Undefined', 'Call to', 'Failed opening', 'syntax error'] as $needle) {
        if (stripos($message, $needle) !== false) {
            return $fallback;
        }
    }

    return $message;
}

function flash_exception(Throwable $exception, string $fallback = 'We could not complete your request. Please review the submitted data and try again.'): void
{
    app_log('error', $exception->getMessage(), [
        'type' => get_class($exception),
        'file' => $exception->getFile(),
        'line' => $exception->getLine(),
        'uri' => $_SERVER['REQUEST_URI'] ?? null,
    ]);

    flash('error', app_debug() ? $exception->getMessage() : safe_exception_message($exception, $fallback));
}

function csrf_token(): string
{
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['_csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function verify_csrf_request(): void
{
    $submitted = (string) ($_POST['_csrf'] ?? '');
    $sessionToken = (string) ($_SESSION['_csrf'] ?? '');

    if ($submitted !== '' && $sessionToken !== '' && hash_equals($sessionToken, $submitted)) {
        return;
    }

    app_log('warning', 'CSRF validation failed', [
        'uri' => $_SERVER['REQUEST_URI'] ?? null,
        'ip' => client_ip(),
    ]);

    render_error_response(419, 'Security Validation Error', 'Your session expired or the request token is invalid. Please go back and submit the form again.');
}

function enforce_session_timeout(): void
{
    $user = current_user();
    if (!$user) {
        return;
    }

    $timeout = (int) config('security.session_idle_timeout', 7200);
    if ($timeout <= 0) {
        $_SESSION['last_activity_at'] = time();
        return;
    }

    $lastActivity = (int) ($_SESSION['last_activity_at'] ?? time());
    if ((time() - $lastActivity) > $timeout) {
        $identifier = (string) ($user['username'] ?? $user['teacher_code'] ?? $user['enrollment_no'] ?? $user['name'] ?? 'user');
        app_log('warning', 'Session timed out', ['user' => $identifier, 'role' => $user['role'] ?? null]);
        logout_session();
        flash('info', 'Your session expired. Please login again.');
        redirect_to('');
    }

    $_SESSION['last_activity_at'] = time();
}

function validate_email_address(string $email): string
{
    $normalized = strtolower(trim($email));
    if ($normalized === '' || !filter_var($normalized, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Enter a valid email address.');
    }

    return $normalized;
}

function validate_password_strength(string $password): string
{
    if (strlen($password) < 8) {
        throw new RuntimeException('Password must be at least 8 characters long.');
    }
    if (!preg_match('/[A-Z]/', $password)) {
        throw new RuntimeException('Password must include at least one uppercase letter.');
    }
    if (!preg_match('/[a-z]/', $password)) {
        throw new RuntimeException('Password must include at least one lowercase letter.');
    }
    if (!preg_match('/\d/', $password)) {
        throw new RuntimeException('Password must include at least one number.');
    }
    if (!preg_match('/[^A-Za-z\d]/', $password)) {
        throw new RuntimeException('Password must include at least one special character.');
    }

    return $password;
}

function assert_uploaded_file(array $file, array $allowedExtensions, int $maxBytes): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed. Please choose the file again.');
    }

    if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
        throw new RuntimeException('Uploaded file could not be verified.');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        throw new RuntimeException('The uploaded file is empty.');
    }
    if ($size > $maxBytes) {
        throw new RuntimeException('The uploaded file is too large.');
    }

    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    if ($extension === '' || !in_array($extension, $allowedExtensions, true)) {
        throw new RuntimeException('Invalid file type uploaded.');
    }

    return (string) $file['tmp_name'];
}

function normalize_phone_number(?string $value): ?string
{
    $value = trim((string) $value);
    if ($value === '') {
        return null;
    }

    $normalized = preg_replace('/[^\d+]/', '', $value) ?? '';
    $hasPlus = str_starts_with($normalized, '+');
    $digits = $hasPlus ? substr($normalized, 1) : $normalized;

    if ($digits === '' || preg_match('/\D/', $digits)) {
        throw new RuntimeException('Phone number must contain digits only.');
    }

    $length = strlen($digits);
    if ($length < 10 || $length > 15) {
        throw new RuntimeException('Phone number must be between 10 and 15 digits.');
    }

    return ($hasPlus ? '+' : '') . $digits;
}

function profile_image_initial(string $name): string
{
    $initial = strtoupper(substr(trim($name), 0, 1));

    return $initial !== '' ? $initial : 'U';
}

function profile_image_url(?string $path): ?string
{
    $path = trim((string) $path);
    if ($path === '') {
        return null;
    }

    return url(str_replace('\\', '/', $path));
}

function profile_image_storage_directory(string $role): string
{
    $role = preg_replace('/[^a-z0-9_-]+/i', '', strtolower($role)) ?: 'users';
    $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'profiles' . DIRECTORY_SEPARATOR . $role;
    ensure_directory($directory);

    return $directory;
}

function profile_image_absolute_path(string $relativePath): string
{
    $relativePath = str_replace('/', DIRECTORY_SEPARATOR, ltrim($relativePath, '/\\'));

    return dirname(__DIR__) . DIRECTORY_SEPARATOR . $relativePath;
}

function delete_profile_image(?string $relativePath): void
{
    $relativePath = str_replace('\\', '/', trim((string) $relativePath));
    if ($relativePath === '' || !str_starts_with($relativePath, 'assets/uploads/profiles/')) {
        return;
    }

    $absolutePath = profile_image_absolute_path($relativePath);
    if (is_file($absolutePath)) {
        @unlink($absolutePath);
    }
}

function store_profile_image_upload(array $file, string $role, ?string $currentPath = null): ?string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return $currentPath;
    }

    $tmpName = assert_uploaded_file($file, ['jpg', 'jpeg', 'png', 'webp'], (int) config('uploads.max_image_bytes', 2097152));
    if (@getimagesize($tmpName) === false) {
        throw new RuntimeException('Please upload a valid JPG, PNG, or WEBP image.');
    }

    $role = preg_replace('/[^a-z0-9_-]+/i', '', strtolower($role)) ?: 'users';
    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
    $filename = $role . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    $relativePath = 'assets/uploads/profiles/' . $role . '/' . $filename;
    $absolutePath = profile_image_absolute_path($relativePath);
    profile_image_storage_directory($role);

    if (!move_uploaded_file($tmpName, $absolutePath)) {
        throw new RuntimeException('Profile image could not be saved. Please try again.');
    }

    if ($currentPath !== null && trim($currentPath) !== '' && $currentPath !== $relativePath) {
        delete_profile_image($currentPath);
    }

    return $relativePath;
}

function read_uploaded_json_file(array $file, int $maxBytes): array
{
    $tmpName = assert_uploaded_file($file, ['json'], $maxBytes);
    $content = file_get_contents($tmpName);
    if ($content === false) {
        throw new RuntimeException('Unable to read the uploaded JSON file.');
    }

    return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
}

function rate_limit_storage_path(string $bucket): string
{
    $cacheDir = (string) config('paths.cache');
    if ($cacheDir === '') {
        $cacheDir = dirname((string) config('logging.path')) . DIRECTORY_SEPARATOR . 'cache';
    }
    if (!is_dir($cacheDir)) {
        mkdir($cacheDir, 0775, true);
    }

    return $cacheDir . DIRECTORY_SEPARATOR . 'rate_' . hash('sha256', $bucket . '|' . client_ip()) . '.json';
}

function rate_limit_or_fail(string $bucket, int $maxAttempts, int $windowSeconds): void
{
    if ($maxAttempts <= 0 || $windowSeconds <= 0) {
        return;
    }

    $path = rate_limit_storage_path($bucket);
    $now = time();
    $attempts = [];

    if (is_file($path)) {
        $decoded = json_decode((string) file_get_contents($path), true);
        if (is_array($decoded)) {
            $attempts = array_values(array_filter($decoded, static fn ($timestamp): bool => is_int($timestamp) && $timestamp >= ($now - $windowSeconds)));
        }
    }

    if (count($attempts) >= $maxAttempts) {
        throw new RuntimeException('Too many requests. Please wait a few minutes and try again.');
    }

    $attempts[] = $now;
    file_put_contents($path, json_encode($attempts), LOCK_EX);
}

function clear_rate_limit(string $bucket): void
{
    $path = rate_limit_storage_path($bucket);
    if (is_file($path)) {
        unlink($path);
    }
}

function mail_delivery_enabled(): bool
{
    return (bool) config('mail.enabled', false);
}

function send_portal_mail(string $to, string $subject, string $message): bool
{
    if (!mail_delivery_enabled()) {
        return false;
    }

    $fromAddress = (string) config('mail.from_address', 'noreply@example.com');
    $fromName = (string) config('mail.from_name', (string) config('app.name', 'BIPE Academic Portal'));
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . $fromName . ' <' . $fromAddress . '>',
    ];

    return @mail($to, $subject, $message, implode("\r\n", $headers));
}

function dispatch_password_reset_otp(string $email, string $recipientName, string $otp, string $roleLabel): ?string
{
    $subject = (string) config('app.name', 'BIPE Academic Portal') . ' password reset code';
    $message = "Hello {$recipientName},\n\nYour {$roleLabel} password reset OTP is: {$otp}\n\nThis code expires in 10 minutes. If you did not request it, please contact the administrator immediately.\n";

    if (mail_delivery_enabled()) {
        if (!send_portal_mail($email, $subject, $message)) {
            throw new RuntimeException('Password reset delivery is temporarily unavailable.');
        }

        return null;
    }

    if (expose_otp_in_flash()) {
        return $otp;
    }

    if (is_production()) {
        throw new RuntimeException('Password reset delivery is not configured.');
    }

    app_log('info', 'OTP generated without mail delivery', ['email' => $email, 'role' => $roleLabel]);
    return null;
}

function password_reset_request_success_message(?string $otpPreview = null): string
{
    $message = 'If the account exists, a password reset code has been sent to the registered email address.';
    if ($otpPreview !== null && expose_otp_in_flash()) {
        $message .= ' Development OTP: ' . $otpPreview;
    }

    return $message;
}












