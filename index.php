<?php
declare(strict_types=1);

require_once __DIR__ . '/app/bootstrap.php';

if (current_role() === 'admin') {
    redirect_to('admin/dashboard.php');
}
if (current_role() === 'teacher') {
    redirect_to('faculty/reports.php');
}
if (current_role() === 'student') {
    redirect_to('student/dashboard.php');
}

$footerLinks = [
    [
        'label' => 'Official Website',
        'value' => 'bipevns.org',
        'href' => 'https://bipevns.org/contact-us',
    ],
    [
        'label' => 'Facebook',
        'value' => '@bipevns',
        'href' => 'https://www.facebook.com/bipevns/',
    ],
    [
        'label' => 'Email',
        'value' => 'info@bipevns.org',
        'href' => 'mailto:info@bipevns.org',
    ],
    [
        'label' => 'Call',
        'value' => '+91 9198646464',
        'href' => 'tel:+919198646464',
    ],
];

render_head('Home', 'index.css', 'landing-body');
?>
<main class="landing-shell">
    <section class="landing-hero">
        <div class="landing-intro">
            <div class="hero-mark">
                <img src="<?= e(url('assets/images/bipe-logo.jpeg')) ?>" alt="BIPE logo">
            </div>
            <p class="eyebrow">Standalone Academic Portal</p>
            <h1 class="hero-title">BIPE <span>Academic Management Portal</span></h1>
        </div>
        <div class="portal-grid">
            <a class="portal-card admin" href="<?= e(url('admin/login.php')) ?>">
                <div class="portal-icon">🛡️</div>
                <h2 class="portal-role">Administrator</h2>
                <p class="portal-desc">Manage academic years, student rosters, faculty approvals, holidays, attendance analytics, and portal-wide settings.</p>
                <span class="portal-link">Open Admin Portal</span>
            </a>
            <a class="portal-card faculty" href="<?= e(url('faculty/login.php')) ?>">
                <div class="portal-icon">🎓</div>
                <h2 class="portal-role">Faculty</h2>
                <p class="portal-desc">Register, mark attendance, upload marks, track assignment submissions, and review department-level academic activity.</p>
                <span class="portal-link">Open Faculty Portal</span>
            </a>
            <a class="portal-card student" href="<?= e(url('student/login.php')) ?>">
                <div class="portal-icon">👩‍🎓</div>
                <h2 class="portal-role">Student</h2>
                <p class="portal-desc">Register using your enrollment number and view your attendance, marks, profile details, and assignment status.</p>
                <span class="portal-link">Open Student Portal</span>
            </a>
        </div>
    </section>
</main>
<footer class="landing-footer">
    <div class="footer-inner">
        <div class="footer-brand">
            <div class="footer-logo">
                <img src="<?= e(url('assets/images/bipe-logo.jpeg')) ?>" alt="BIPE logo">
            </div>
            <div class="footer-copy-block">
                <p class="eyebrow">Stay Connected</p>
                <h2 class="footer-title">Banaras Institute of Polytechnic &amp; Engineering</h2>
                <p class="footer-text">Gajokhar, Parsara, Phoolpur, Varanasi, Uttar Pradesh 221206</p>
                <p class="footer-text">College updates, contact information, and official announcements in one place.</p>
            </div>
        </div>
        <div class="footer-social-grid">
            <?php foreach ($footerLinks as $link): ?>
                <?php $isExternal = str_starts_with($link['href'], 'http'); ?>
                <a class="footer-social-card" href="<?= e($link['href']) ?>"<?= $isExternal ? ' target="_blank" rel="noreferrer"' : '' ?>>
                    <span class="footer-social-label"><?= e($link['label']) ?></span>
                    <span class="footer-social-value"><?= e($link['value']) ?></span>
                </a>
            <?php endforeach; ?>
        </div>
    </div>
    <p class="footer-copyright">&copy; <?= date('Y') ?> BIPE. Managed by BIPE. Designed by BIPE.</p>
</footer>
<?php render_foot('index.js');




