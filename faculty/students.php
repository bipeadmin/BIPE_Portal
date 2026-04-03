<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('teacher');

$departments = departments();
$departmentId = (int) ($_GET['department_id'] ?? 0);
$yearLevel = (int) ($_GET['year_level'] ?? 0);
$semesterNo = (int) ($_GET['semester_no'] ?? 0);
$search = trim((string) ($_GET['search'] ?? ''));
$students = student_directory_rows($departmentId > 0 ? $departmentId : null, $yearLevel > 0 ? $yearLevel : null, $semesterNo > 0 ? $semesterNo : null, $search);
$registeredCount = count(array_filter($students, static fn (array $row): bool => !empty($row['password_hash'])));
$mobileCount = count(array_filter($students, static fn (array $row): bool => trim((string) ($row['phone_number'] ?? '')) !== ''));

render_dashboard_layout('Student Directory', 'teacher', 'students', 'faculty/students.css', 'faculty/students.js', function () use ($departments, $departmentId, $yearLevel, $semesterNo, $search, $students, $registeredCount, $mobileCount): void {
    ?>
    <section class="stats-grid">
        <article class="stat-card"><p class="eyebrow">Filtered Students</p><h3 class="stat-value"><?= e((string) count($students)) ?></h3><p class="stat-label">Students matching the current filters</p></article>
        <article class="stat-card"><p class="eyebrow">Registered</p><h3 class="stat-value"><?= e((string) $registeredCount) ?></h3><p class="stat-label">Activated student accounts in the filtered set</p></article>
        <article class="stat-card"><p class="eyebrow">Mobile Added</p><h3 class="stat-value"><?= e((string) $mobileCount) ?></h3><p class="stat-label">Students with mobile numbers on record</p></article>
    </section>

    <article class="data-card student-directory-card">
        <div class="card-head">
            <div>
                <p class="eyebrow">Student Directory</p>
                <h3 class="card-title">View-only access to all student records</h3>
                <p class="card-subtitle">Faculty can review student details across every department and filter combination, but cannot edit or delete records from this page.</p>
            </div>
        </div>
        <form method="get" class="filters student-directory-filters">
            <div class="form-group">
                <label class="form-label" for="faculty-filter-department">Department</label>
                <select class="form-select" id="faculty-filter-department" name="department_id">
                    <option value="0">All departments</option>
                    <?php foreach ($departments as $department): ?>
                        <option value="<?= e((string) $department['id']) ?>" <?= $departmentId === (int) $department['id'] ? 'selected' : '' ?>><?= e($department['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="faculty-filter-year">Year</label>
                <select class="form-select" id="faculty-filter-year" name="year_level">
                    <option value="0">All years</option>
                    <option value="1" <?= $yearLevel === 1 ? 'selected' : '' ?>>1st Year</option>
                    <option value="2" <?= $yearLevel === 2 ? 'selected' : '' ?>>2nd Year</option>
                    <option value="3" <?= $yearLevel === 3 ? 'selected' : '' ?>>3rd Year</option>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="faculty-filter-semester">Semester</label>
                <select class="form-select" id="faculty-filter-semester" name="semester_no">
                    <option value="0">All semesters</option>
                    <?php foreach (semester_numbers() as $semester): ?>
                        <option value="<?= e((string) $semester) ?>" <?= $semesterNo === $semester ? 'selected' : '' ?>><?= e(semester_label($semester)) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label class="form-label" for="faculty-filter-search">Search</label>
                <input class="search-input" id="faculty-filter-search" name="search" value="<?= e($search) ?>" placeholder="Name, enrollment, email, or mobile">
            </div>
            <button class="btn-primary" type="submit">Apply Filters</button>
        </form>

        <?php if ($students): ?>
            <div class="table-wrap student-directory-wrap">
                <table class="student-directory-table">
                    <thead>
                    <tr>
                        <th>Enrollment</th>
                        <th>Student</th>
                        <th>Department</th>
                        <th>Year</th>
                        <th>Semester</th>
                        <th>Email</th>
                        <th>Mobile</th>
                        <th>Account</th>
                    </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($students as $student):

                        ?>
                        <tr>
                            <td class="mono" data-label="Enrollment"><?= e($student['enrollment_no']) ?></td>
                            <td class="student-name-cell" data-label="Student">
                                <strong><?= e($student['full_name']) ?></strong>
                            </td>
                            <td data-label="Department"><?= e($student['department_name']) ?></td>
                            <td data-label="Year"><?= e(year_label((int) $student['year_level'])) ?></td>
                            <td data-label="Semester"><?= e(semester_label((int) $student['semester_no'])) ?></td>
                            <td data-label="Email"><?= e((string) ($student['email'] ?: '-')) ?></td>
                            <td data-label="Mobile"><?= e((string) ($student['phone_number'] ?: '-')) ?></td>
                            <td data-label="Account">
                                <?php if (!empty($student['password_hash'])): ?>
                                    <span class="badge success">Registered</span>
                                <?php else: ?>
                                    <span class="badge warning">Pending activation</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-state">No students match the selected filters.</div>
        <?php endif; ?>
    </article>
    <?php
});



