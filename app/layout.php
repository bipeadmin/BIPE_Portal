<?php
declare(strict_types=1);

function render_head(string $title, string $pageCss, string $bodyClass = ''): void
{
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex,nofollow">
    <meta name="csrf-token" content="<?= e(csrf_token()) ?>">
    <title><?= e($title) ?> | <?= e((string) config('app.name')) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cinzel:wght@400;600;700;900&family=EB+Garamond:ital,wght@0,400;0,500;0,600;1,400&family=JetBrains+Mono:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(url('assets/css/common.css')) ?>">
    <link rel="stylesheet" href="<?= e(url('assets/css/' . ltrim($pageCss, '/'))) ?>">
</head>
<body class="<?= e($bodyClass) ?>">
    <?php
}

function render_foot(string $pageJs): void
{
    ?>
    <script src="<?= e(url('assets/js/common.js')) ?>"></script>
    <script src="<?= e(url('assets/js/' . ltrim($pageJs, '/'))) ?>"></script>
</body>
</html>
    <?php
}

function render_flashes(): void
{
    $flashes = consume_flash();
    if (!$flashes) {
        return;
    }

    echo '<div class="flash-stack">';
    foreach ($flashes as $flashItem) {
        echo '<div class="flash flash-' . e($flashItem['type']) . '">' . e($flashItem['message']) . '</div>';
    }
    echo '</div>';
}

function nav_items(string $role): array
{
    return match ($role) {
        'admin' => [
            'dashboard' => ['label' => 'Overview', 'path' => 'admin/dashboard.php'],
            'students' => ['label' => 'Students', 'path' => 'admin/students.php'],
            'faculty' => ['label' => 'Faculty', 'path' => 'admin/faculty.php'],
            'attendance' => ['label' => 'Attendance', 'path' => 'admin/attendance.php'],
            'marks' => ['label' => 'Marks', 'path' => 'admin/marks.php'],
            'subjects' => ['label' => 'Subjects', 'path' => 'admin/subjects.php'],
            'assignments' => ['label' => 'Assignments', 'path' => 'admin/assignments.php'],
            'reports' => ['label' => 'Reports', 'path' => 'admin/reports.php'],
            'report_cards' => ['label' => 'Report Cards', 'path' => 'admin/report_cards.php'],
            'audit_log' => ['label' => 'Audit Log', 'path' => 'admin/audit_log.php'],
            'backup' => ['label' => 'Backup & Restore', 'path' => 'admin/backup.php'],
            'settings' => ['label' => 'Settings', 'path' => 'admin/settings.php'],
        ],
        'teacher' => [
            'dashboard' => ['label' => 'Dashboard', 'path' => 'faculty/dashboard.php'],
            'attendance' => ['label' => 'Mark Attendance', 'path' => 'faculty/attendance.php'],
            'records' => ['label' => 'Attendance Records', 'path' => 'faculty/records.php'],
            'students' => ['label' => 'Students', 'path' => 'faculty/students.php'],
            'marks' => ['label' => 'Upload Marks', 'path' => 'faculty/marks.php'],
            'view_marks' => ['label' => 'View Marks', 'path' => 'faculty/view_marks.php'],
            'assignments' => ['label' => 'Assignments', 'path' => 'faculty/assignments.php'],
            'reports' => ['label' => 'Reports', 'path' => 'faculty/reports.php'],
            'profile' => ['label' => 'My Profile', 'path' => 'faculty/profile.php'],
        ],
        'student' => [
            'dashboard' => ['label' => 'Profile', 'path' => 'student/dashboard.php'],
            'attendance' => ['label' => 'Attendance', 'path' => 'student/attendance.php'],
            'marks' => ['label' => 'Marks', 'path' => 'student/marks.php'],
            'assignments' => ['label' => 'Assignments', 'path' => 'student/assignments.php'],
        ],
        default => [],
    };
}

function render_dashboard_layout(string $title, string $role, string $activeKey, string $pageCss, string $pageJs, callable $content): void
{
    render_head($title, $pageCss, 'dashboard-body role-' . $role);
    $user = current_user();
    ?>
    <div class="sidebar-backdrop" data-sidebar-close></div>
    <div class="app-shell">
        <aside class="sidebar" data-sidebar>
            <div class="brand-block">
                <div class="brand-mark">
                    <img src="<?= e(url('assets/images/bipe-logo.jpeg')) ?>" alt="BIPE logo">
                </div>
                <div class="brand-text">
                    <h1><?= e((string) config('app.name')) ?></h1>
                    <p><?= e(strtoupper($role)) ?> PORTAL</p>
                </div>
            </div>
            <nav class="sidebar-nav">
                <?php foreach (nav_items($role) as $key => $item): ?>
                    <a class="sidebar-link <?= $key === $activeKey ? 'is-active' : '' ?>" href="<?= e(url($item['path'])) ?>">
                        <?= e($item['label']) ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <form method="post" action="<?= e(url('logout.php')) ?>" class="logout-form">
                <button class="logout-link" type="submit" data-confirm="Do you want to logout from the portal?">Logout</button>
            </form>
        </aside>
        <main class="main-shell">
            <header class="page-header">
                <div class="header-title-group">
                    <button class="menu-toggle" type="button" data-sidebar-toggle aria-label="Toggle menu">Menu</button>
                    <div>
                        <p class="eyebrow">Current AY <?= e(current_academic_year()['label'] ?? '-') ?></p>
                        <h2><?= e($title) ?></h2>
                    </div>
                </div>
                <div class="user-chip">
                    <span><?= e($user['name'] ?? 'User') ?></span>
                </div>
            </header>
            <?php render_flashes(); ?>
            <section class="page-content">
                <?php $content(); ?>
            </section>
            <footer class="page-footer-note">
                <p>&copy; <?= date('Y') ?> BIPE. Managed by BIPE. Designed by BIPE.</p>
            </footer>
        </main>
    </div>
    <?php
    render_foot($pageJs);
}

function render_auth_layout(string $title, string $subtitle, string $pageCss, string $pageJs, callable $content): void
{
    render_head($title, $pageCss, 'auth-body');
    ?>
    <main class="auth-shell">
        <section class="auth-panel">
            <div class="auth-copy">
                <div class="auth-logo">
                    <img src="<?= e(url('assets/images/bipe-logo.jpeg')) ?>" alt="BIPE logo">
                </div>
                <p class="eyebrow">BIPE Academic Management System</p>
                <h1><?= e($title) ?></h1>
                <p><?= e($subtitle) ?></p>
                <div class="auth-links">
                    <a href="<?= e(url('')) ?>">Home</a>
                    <a href="<?= e(url('admin/login.php')) ?>">Admin</a>
                    <a href="<?= e(url('faculty/login.php')) ?>">Faculty</a>
                    <a href="<?= e(url('student/login.php')) ?>">Student</a>
                </div>
            </div>
            <div class="auth-form-card">
                <?php render_flashes(); ?>
                <?php $content(); ?>
            </div>
        </section>
        <footer class="auth-footer-note">
            <p>&copy; <?= date('Y') ?> BIPE. Managed by BIPE. Designed by BIPE.</p>
        </footer>
    </main>
    <?php
    render_foot($pageJs);
}






