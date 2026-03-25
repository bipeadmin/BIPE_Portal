<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('admin');

if (is_post()) {
    $action = (string) post('action');

    if ($action === 'download_backup') {
        $payload = export_backup_payload();
        audit_log('admin', (string) (current_user()['username'] ?? 'admin'), 'BACKUP_DOWNLOAD', 'Full portal backup exported');
        header('Content-Type: application/json');
        header('Content-Disposition: attachment; filename="bipe-portal-backup-' . date('Y-m-d-His') . '.json"');
        echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }

    if ($action === 'restore_backup') {
        try {
            if (trim((string) post('confirm_phrase')) !== 'RESTORE') {
                throw new RuntimeException('Type RESTORE exactly before uploading a backup file.');
            }
            $payload = read_uploaded_json_file((array) ($_FILES['backup_file'] ?? []), (int) config('uploads.max_backup_bytes', 5242880));
            restore_backup_payload($payload);
            audit_log('admin', (string) (current_user()['username'] ?? 'admin'), 'DATA_RESTORE', 'Portal restored from uploaded backup');
            flash('success', 'Backup restored successfully.');
        } catch (Throwable $exception) {
            flash_exception($exception, 'Backup restore failed. Please verify the file and try again.');
        }

        redirect_to('admin/backup.php');
    }
}

$dataSummary = [];
foreach (backup_table_names() as $table) {
    $dataSummary[] = ['table' => $table, 'count' => (int) query_value('SELECT COUNT(*) FROM `' . $table . '`')];
}

render_dashboard_layout('Backup & Restore', 'admin', 'backup', 'admin/backup.css', 'admin/backup.js', function () use ($dataSummary): void {
    ?>
    <section class="grid-2">
        <article class="data-card">
            <div class="card-head">
                <div>
                    <p class="eyebrow">Download Backup</p>
                    <h3 class="card-title">Export the current MySQL data as JSON</h3>
                </div>
            </div>
            <div class="stack">
                <div class="notice-box">Use this before major resets or academic-year transitions. The exported file includes portal settings, users, marks, attendance, assignments, and audit logs.</div>
                <form method="post">
                    <input type="hidden" name="action" value="download_backup">
                    <button class="btn-primary" type="submit">Download Backup</button>
                </form>
            </div>
        </article>

        <article class="data-card">
            <div class="card-head">
                <div>
                    <p class="eyebrow">Restore Backup</p>
                    <h3 class="card-title">Replace the current portal data</h3>
                </div>
            </div>
            <form method="post" enctype="multipart/form-data" class="form-grid">
                <input type="hidden" name="action" value="restore_backup">
                <div class="form-group">
                    <label class="form-label" for="backup-file">Backup JSON</label>
                    <input class="form-input" id="backup-file" type="file" name="backup_file" accept=".json" data-file-input data-file-target="#backup-file-name" required>
                    <div class="file-hint" id="backup-file-name">No file selected</div>
                </div>
                <div class="form-group">
                    <label class="form-label" for="confirm-restore">Type RESTORE to continue</label>
                    <input class="form-input mono" id="confirm-restore" name="confirm_phrase" placeholder="RESTORE" required>
                </div>
                <div class="notice-box danger">Restoring a backup overwrites current students, faculty, marks, attendance, assignments, OTP records, and audit data.</div>
                <button class="btn-danger" type="submit" data-confirm="Restore this backup and replace the current portal data?">Restore Backup</button>
            </form>
        </article>
    </section>

    <article class="data-card">
        <div class="card-head">
            <div>
                <p class="eyebrow">Data Summary</p>
                <h3 class="card-title">Current records by table</h3>
            </div>
        </div>
        <div class="table-wrap">
            <table>
                <thead><tr><th>Table</th><th>Rows</th></tr></thead>
                <tbody>
                <?php foreach ($dataSummary as $row): ?>
                    <tr>
                        <td class="mono"><?= e($row['table']) ?></td>
                        <td><?= e((string) $row['count']) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </article>
    <?php
});
