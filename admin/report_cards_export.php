<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/bootstrap.php';
require_role('admin');

$mode = strtolower(trim((string) ($_GET['mode'] ?? 'current')));
$departmentId = (int) ($_GET['department_id'] ?? 0);
$semesterNo = (int) ($_GET['semester_no'] ?? 0);
$markTypeId = (int) ($_GET['mark_type_id'] ?? 0);
$year = current_academic_year();

$cell = static function (mixed $value, string $style = 'Default', int $mergeAcross = 0): array {
    $cell = ['value' => $value, 'style' => $style];
    if ($mergeAcross > 0) {
        $cell['mergeAcross'] = $mergeAcross;
    }

    return $cell;
};

$styledRow = static function (array $values, string $style = 'Default'): array {
    return array_map(
        static fn (mixed $value): array => ['value' => $value, 'style' => $style],
        $values
    );
};

$numberCell = static function (?float $value, string $fallback = '--', string $style = 'Number') use ($cell): array {
    if ($value === null) {
        return $cell($fallback, 'Center');
    }

    return $cell($value, $style);
};

$percentCell = static function (?float $value, string $fallback = '--') use ($cell): array {
    if ($value === null) {
        return $cell($fallback, 'Center');
    }

    return $cell($value / 100, 'Percent');
};

$integerCell = static function (?int $value, string $fallback = '--') use ($cell): array {
    if ($value === null) {
        return $cell($fallback, 'Center');
    }

    return $cell($value, 'Integer');
};

$resultStyle = static function (string $result): string {
    return match (strtolower(trim($result))) {
        'pass' => 'Positive',
        'fail' => 'Negative',
        default => 'Metric',
    };
};

$currentWorksheet = static function (int $departmentId, int $semesterNo, int $markTypeId) use ($year, $styledRow, $cell, $numberCell): array {
    if ($departmentId <= 0 || $semesterNo <= 0 || $markTypeId <= 0) {
        throw new RuntimeException('Choose a department, semester, and assessment before exporting the current report-card view.');
    }

    $department = department_by_id($departmentId);
    $markType = mark_type_by_id($markTypeId);
    if (!$department || !$markType) {
        throw new RuntimeException('Current report-card export could not be prepared because the selected filters are invalid.');
    }

    $matrix = class_report_card_matrix_rows($departmentId, $semesterNo, $markTypeId);
    $subjects = (array) ($matrix['subjects'] ?? []);
    $rows = (array) ($matrix['rows'] ?? []);
    $columnCount = max(3, count($subjects) + 3);
    $columns = array_merge([135, 230], array_fill(0, max(0, count($subjects)), 180), [120]);

    $worksheetRows = [
        [[
            'value' => 'Current Report Card View',
            'style' => 'Title',
            'mergeAcross' => $columnCount - 1,
        ]],
        [[
            'value' => 'Academic Year: ' . (string) ($year['label'] ?? '-') . ' | Department: ' . (string) ($department['name'] ?? '-') . ' | Semester: ' . semester_label($semesterNo) . ' | Assessment: ' . (string) ($markType['label'] ?? '-'),
            'style' => 'Meta',
            'mergeAcross' => $columnCount - 1,
        ]],
        [],
    ];

    $header = ['Enrollment Number', 'Student Name'];
    foreach ($subjects as $subject) {
        $header[] = (string) ($subject['subject_name'] ?? 'Subject');
    }
    $header[] = 'Total Marks';
    $worksheetRows[] = $styledRow($header, 'Header');

    if ($rows === []) {
        $worksheetRows[] = [[
            'value' => 'No students, subjects, or assessment records are available for the selected class.',
            'style' => 'Section',
            'mergeAcross' => $columnCount - 1,
        ]];
    } else {
        foreach ($rows as $row) {
            $student = (array) ($row['student'] ?? []);
            $dataRow = [
                $cell((string) ($student['enrollment_no'] ?? '')),
                $cell((string) ($student['full_name'] ?? '')),
            ];

            foreach ((array) ($row['subject_cells'] ?? []) as $subjectCell) {
                $dataRow[] = ($subjectCell['average_marks'] ?? null) !== null
                    ? $numberCell((float) $subjectCell['average_marks'])
                    : $cell((string) ($subjectCell['display'] ?? '--'), 'Center');
            }

            $dataRow[] = ($row['total_marks'] ?? null) !== null
                ? $numberCell((float) $row['total_marks'])
                : $cell((string) ($row['total_display'] ?? '--'), 'Center');
            $worksheetRows[] = $dataRow;
        }
    }

    return [
        'name' => trim((string) ($department['name'] ?? 'Current Report')) . ' Semester ' . $semesterNo,
        'columns' => $columns,
        'rows' => $worksheetRows,
    ];
};

$departmentWorksheet = static function (array $department) use ($year, $styledRow, $cell, $resultStyle, $numberCell, $percentCell, $integerCell): array {
    $departmentId = (int) ($department['id'] ?? 0);
    $sheetName = (string) ($department['name'] ?? ('Department ' . $departmentId));
    $fixedMetricColumns = 5;
    $semesterSections = [];
    $maxColumnCount = 9;
    $maxAssessmentCells = 0;

    foreach (semester_numbers() as $semesterNo) {
        $section = class_report_card_full_assessment_matrix($departmentId, $semesterNo);
        $subjects = (array) ($section['subjects'] ?? []);
        $markTypes = (array) ($section['mark_types'] ?? []);
        $rows = (array) ($section['rows'] ?? []);
        $assessmentCellCount = count($subjects) * count($markTypes);
        $columnCount = 4 + $assessmentCellCount + $fixedMetricColumns;

        $maxAssessmentCells = max($maxAssessmentCells, $assessmentCellCount);
        $maxColumnCount = max($maxColumnCount, $columnCount);
        $semesterSections[] = [
            'semester_no' => $semesterNo,
            'section' => $section,
            'subjects' => $subjects,
            'mark_types' => $markTypes,
            'rows' => $rows,
            'column_count' => $columnCount,
        ];
    }

    $columns = array_merge([135, 240, 95, 95], array_fill(0, max(0, $maxAssessmentCells), 118), [125, 130, 130, 125, 100]);
    $worksheetRows = [
        [[
            'value' => (string) ($department['name'] ?? 'Department') . ' Report Card Export',
            'style' => 'Title',
            'mergeAcross' => $maxColumnCount - 1,
        ]],
        [[
            'value' => 'Academic Year: ' . (string) ($year['label'] ?? '-') . ' | This worksheet is divided into First Year, Second Year, and Third Year class tables.',
            'style' => 'Meta',
            'mergeAcross' => $maxColumnCount - 1,
        ]],
        [],
    ];

    foreach ($semesterSections as $semesterSection) {
        $semesterNo = (int) ($semesterSection['semester_no'] ?? 0);
        $section = (array) ($semesterSection['section'] ?? []);
        $subjects = (array) ($semesterSection['subjects'] ?? []);
        $markTypes = (array) ($semesterSection['mark_types'] ?? []);
        $rows = (array) ($semesterSection['rows'] ?? []);
        $columnCount = (int) ($semesterSection['column_count'] ?? $maxColumnCount);

        $worksheetRows[] = [$cell(
            year_label((int) ($section['year_level'] ?? max(1, (int) ceil($semesterNo / 2)))) . ' Student Results | ' . semester_label($semesterNo),
            'Section',
            $columnCount - 1
        )];

        if ($subjects === [] || $rows === []) {
            $worksheetRows[] = [$cell('No report-card data available for this class.', 'Meta', $columnCount - 1)];
            $worksheetRows[] = [];
            continue;
        }

        $subjectNames = array_map(
            static fn (array $subject): string => (string) ($subject['subject_name'] ?? 'Subject'),
            $subjects
        );
        $assessmentNames = array_map(
            static fn (array $markType): string => (string) ($markType['label'] ?? 'Assessment') . ' (Maximum ' . format_marks_value((float) ($markType['max_marks'] ?? 0)) . ' marks)',
            $markTypes
        );

        $worksheetRows[] = [$cell('Subjects included: ' . implode(' | ', $subjectNames), 'Meta', $columnCount - 1)];
        $worksheetRows[] = [$cell('Assessments included: ' . implode(' | ', $assessmentNames), 'Meta', $columnCount - 1)];

        $topHeader = [
            $cell('Enrollment Number', 'Header'),
            $cell('Student Name', 'Header'),
            $cell('Academic Year', 'Header'),
            $cell('Semester', 'Header'),
        ];
        foreach ($subjects as $subject) {
            $topHeader[] = $cell((string) ($subject['subject_name'] ?? 'Subject'), 'SubHeader', max(0, count($markTypes) - 1));
        }
        $topHeader[] = $cell('Performance Overview', 'SubHeader', $fixedMetricColumns - 1);
        $worksheetRows[] = $topHeader;

        $detailHeader = [
            $cell('', 'SubHeader'),
            $cell('', 'SubHeader'),
            $cell('', 'SubHeader'),
            $cell('', 'SubHeader'),
        ];
        foreach ($subjects as $subject) {
            foreach ($markTypes as $markType) {
                $detailHeader[] = $cell(
                    (string) ($markType['label'] ?? 'Assessment') . ' (Maximum ' . format_marks_value((float) ($markType['max_marks'] ?? 0)) . ' marks)',
                    'Header'
                );
            }
        }
        $detailHeader[] = $cell('Recorded Assessments', 'Header');
        $detailHeader[] = $cell('Average Marks Across Recorded Assessments', 'Header');
        $detailHeader[] = $cell('Average Percentage Across Recorded Assessments', 'Header');
        $detailHeader[] = $cell('Attendance Percentage', 'Header');
        $detailHeader[] = $cell('Result', 'Header');
        $worksheetRows[] = $detailHeader;

        foreach ($rows as $row) {
            $student = (array) ($row['student'] ?? []);
            $dataRow = [
                $cell((string) ($student['enrollment_no'] ?? '')),
                $cell((string) ($student['full_name'] ?? '')),
                $cell(year_label((int) ($section['year_level'] ?? max(1, (int) ceil($semesterNo / 2))))),
                $cell(semester_label($semesterNo), 'Center'),
            ];

            foreach ((array) ($row['cells'] ?? []) as $scoreCell) {
                $dataRow[] = ($scoreCell['numeric_marks'] ?? null) !== null
                    ? $numberCell((float) $scoreCell['numeric_marks'])
                    : $cell((string) ($scoreCell['display'] ?? '--'), 'Center');
            }

            $dataRow[] = $integerCell((int) ($row['recorded_count'] ?? 0));
            $dataRow[] = ($row['average_marks'] ?? null) !== null
                ? $numberCell((float) $row['average_marks'])
                : $cell((string) ($row['average_display'] ?? '--'), 'Center');
            $dataRow[] = $percentCell(isset($row['average_percentage']) ? (float) $row['average_percentage'] : null, (string) ($row['average_percentage_display'] ?? '--'));
            $dataRow[] = $percentCell(isset($row['attendance']['percentage']) ? (float) $row['attendance']['percentage'] : null, '--');
            $dataRow[] = $cell((string) ($row['result'] ?? 'Pending'), $resultStyle((string) ($row['result'] ?? 'Pending')));
            $worksheetRows[] = $dataRow;
        }

        $worksheetRows[] = [];
    }

    return [
        'name' => $sheetName,
        'columns' => $columns,
        'rows' => $worksheetRows,
    ];
};

$summaryWorksheet = static function () use ($year, $cell, $styledRow, $resultStyle, $numberCell, $percentCell, $integerCell): array {
    $rows = report_card_subject_average_export_rows();
    $columnCount = 9;
    $columns = [140, 240, 260, 140, 145, 135, 125, 110, 120];

    $worksheetRows = [
        [[
            'value' => 'Student Subject Average Summary',
            'style' => 'Title',
            'mergeAcross' => $columnCount - 1,
        ]],
        [[
            'value' => 'Academic Year: ' . (string) ($year['label'] ?? '-') . ' | Each row shows one student, one subject, and the average across all recorded assessments.',
            'style' => 'Meta',
            'mergeAcross' => $columnCount - 1,
        ]],
        [],
    ];

    if ($rows === []) {
        $worksheetRows[] = [[
            'value' => 'No subject-average records are available yet.',
            'style' => 'Section',
            'mergeAcross' => $columnCount - 1,
        ]];
    } else {
        $groupedRows = [];
        foreach ($rows as $row) {
            $groupKey = implode('|', [
                (string) ($row['department_name'] ?? ''),
                (string) ($row['year_level'] ?? ''),
                (string) ($row['semester_no'] ?? ''),
            ]);
            $groupedRows[$groupKey][] = $row;
        }

        foreach ($groupedRows as $groupRows) {
            $sampleRow = (array) ($groupRows[0] ?? []);
            $worksheetRows[] = [$cell(
                'Department: ' . (string) ($sampleRow['department_name'] ?? '')
                . ' | Year: ' . year_label((int) ($sampleRow['year_level'] ?? 1))
                . ' | Semester: ' . semester_label((int) ($sampleRow['semester_no'] ?? 0)),
                'Section',
                $columnCount - 1
            )];
            $worksheetRows[] = [$cell(
                'Each row below lists one subject for one student, with the average across all recorded assessments.',
                'Meta',
                $columnCount - 1
            )];
            $worksheetRows[] = $styledRow([
                'Enrollment Number',
                'Student Name',
                'Subject Name',
                'Assessments Recorded',
                'Average Marks Across Recorded Assessments',
                'Average Percentage',
                'Attendance Percentage',
                'Result',
                'Assessments Marked Absent',
            ], 'Header');

            foreach ($groupRows as $row) {
                $student = (array) ($row['student'] ?? []);
                $worksheetRows[] = [
                    $cell((string) ($student['enrollment_no'] ?? '')),
                    $cell((string) ($student['full_name'] ?? '')),
                    $cell((string) ($row['subject_name'] ?? '')),
                    $integerCell((int) ($row['assessment_count'] ?? 0)),
                    ($row['average_marks'] ?? null) !== null
                        ? $numberCell((float) $row['average_marks'])
                        : $cell((string) ($row['average_display'] ?? '--'), 'Center'),
                    $percentCell(isset($row['average_percentage']) ? (float) $row['average_percentage'] : null, (string) ($row['percentage_display'] ?? '--')),
                    $percentCell(isset($row['attendance_percentage']) ? (float) $row['attendance_percentage'] : null, '--'),
                    $cell((string) ($row['result'] ?? 'Pending'), $resultStyle((string) ($row['result'] ?? 'Pending'))),
                    $integerCell((int) ($row['absent_count'] ?? 0)),
                ];
            }

            $worksheetRows[] = [];
        }
    }

    return [
        'name' => 'Student Subject Averages',
        'columns' => $columns,
        'rows' => $worksheetRows,
    ];
};

$plainCell = static fn (mixed $value): array => ['value' => $value];

$plainMarksValue = static function (?float $value): string|float {
    return $value !== null ? round($value, 2) : '--';
};

$plainPercentValue = static function (?float $value): string {
    return $value !== null ? format_marks_value(round($value, 2)) . '%' : '--';
};

$plainSubjectResult = static function (array $subjectCell, float $maxMarks): string {
    if (($subjectCell['average_marks'] ?? null) !== null && $maxMarks > 0) {
        return pass_fail_from_marks((float) $subjectCell['average_marks'], $maxMarks);
    }

    if (!empty($subjectCell['is_absent'])) {
        return 'Absent';
    }

    return 'Pending';
};

$departmentSimpleWorkbooks = static function (array $department) use ($plainCell, $plainMarksValue, $plainPercentValue, $plainSubjectResult): array {
    $departmentId = (int) ($department['id'] ?? 0);
    $markTypes = mark_type_rows();
    $semesterGroups = [];
    foreach (semester_numbers() as $semesterNo) {
        $yearLevel = max(1, (int) ceil($semesterNo / 2));
        $semesterGroups[$yearLevel][] = $semesterNo;
    }

    $worksheets = [];
    foreach ($markTypes as $markType) {
        $markTypeId = (int) ($markType['id'] ?? 0);
        $assessmentName = (string) ($markType['label'] ?? ('Assessment ' . $markTypeId));
        $assessmentMax = (float) ($markType['max_marks'] ?? 0);
        $rows = [
            [$plainCell('Department'), $plainCell((string) ($department['name'] ?? ('Department ' . $departmentId)))],
            [$plainCell('Assessment'), $plainCell($assessmentName)],
            [$plainCell('Maximum Marks'), $plainCell($assessmentMax > 0 ? $assessmentMax : '--')],
            [],
        ];

        foreach ($semesterGroups as $yearLevel => $semesters) {
            $rows[] = [$plainCell(year_label((int) $yearLevel))];
            $rows[] = array_map($plainCell, [
                'Enrollment Number',
                'Student Name',
                'Semester',
                'Subject Name',
                'Marks',
                'Maximum Marks',
                'Subject Percentage',
                'Subject Result',
                'Student Total Marks',
                'Student Total Maximum Marks',
                'Student Overall Percentage',
                'Student Overall Result',
            ]);

            $yearHasRows = false;
            foreach ($semesters as $semesterNo) {
                $matrix = class_report_card_matrix_rows($departmentId, (int) $semesterNo, $markTypeId);
                $subjects = (array) ($matrix['subjects'] ?? []);
                $subjectCount = count($subjects);
                $studentTotalMax = $assessmentMax > 0 ? $assessmentMax * $subjectCount : 0.0;

                foreach ((array) ($matrix['rows'] ?? []) as $studentRow) {
                    $student = (array) ($studentRow['student'] ?? []);
                    $studentTotalMarks = ($studentRow['total_marks'] ?? null) !== null ? (float) $studentRow['total_marks'] : null;
                    $studentPercent = $studentTotalMarks !== null && $studentTotalMax > 0
                        ? ($studentTotalMarks / $studentTotalMax) * 100
                        : null;
                    $studentResult = $studentPercent !== null ? pass_fail_from_marks($studentPercent, 100.0) : 'Pending';

                    foreach ((array) ($studentRow['subject_cells'] ?? []) as $subjectCell) {
                        $marks = ($subjectCell['average_marks'] ?? null) !== null ? (float) $subjectCell['average_marks'] : null;
                        $subjectPercent = $marks !== null && $assessmentMax > 0 ? ($marks / $assessmentMax) * 100 : null;
                        $rows[] = [
                            $plainCell((string) ($student['enrollment_no'] ?? '')),
                            $plainCell((string) ($student['full_name'] ?? '')),
                            $plainCell(semester_label((int) $semesterNo)),
                            $plainCell((string) ($subjectCell['subject_name'] ?? '')),
                            $plainCell($marks !== null ? $plainMarksValue($marks) : (string) ($subjectCell['display'] ?? '--')),
                            $plainCell($assessmentMax > 0 ? $assessmentMax : '--'),
                            $plainCell($plainPercentValue($subjectPercent)),
                            $plainCell($plainSubjectResult((array) $subjectCell, $assessmentMax)),
                            $plainCell($plainMarksValue($studentTotalMarks)),
                            $plainCell($studentTotalMax > 0 ? round($studentTotalMax, 2) : '--'),
                            $plainCell($plainPercentValue($studentPercent)),
                            $plainCell($studentResult),
                        ];
                        $yearHasRows = true;
                    }
                }
            }

            if (!$yearHasRows) {
                $rows[] = [$plainCell('No data available for ' . year_label((int) $yearLevel) . '.')];
            }

            $rows[] = [];
        }

        $worksheets[] = [
            'name' => $assessmentName,
            'columns' => [135, 220, 110, 260, 95, 115, 125, 120, 130, 170, 155, 135],
            'rows' => $rows,
        ];
    }

    if ($worksheets === []) {
        $worksheets[] = [
            'name' => 'No Assessments',
            'rows' => [
                [$plainCell('No assessment types are configured yet.')],
            ],
        ];
    }

    return $worksheets;
};

if ($mode === 'current') {
    $department = department_by_id($departmentId);
    $markType = mark_type_by_id($markTypeId);
    audit_log(
        'admin',
        (string) (current_user()['username'] ?? 'admin'),
        'REPORT_CARD_EXPORT',
        'Current Excel exported for '
        . (string) ($department['name'] ?? ('Department ' . $departmentId))
        . ' / ' . semester_label($semesterNo)
        . ' / ' . (string) ($markType['label'] ?? ('Assessment ' . $markTypeId))
    );

    download_excel_workbook(
        'report_card_current_' . safe_download_segment((string) ($department['name'] ?? 'department')) . '_semester_' . $semesterNo . '_' . safe_download_segment((string) ($markType['label'] ?? ('assessment_' . $markTypeId))),
        [$currentWorksheet($departmentId, $semesterNo, $markTypeId)]
    );
}

if ($mode === 'full') {
    $zipFiles = [];
    $usedNames = [];
    foreach (departments() as $department) {
        $baseName = safe_download_segment((string) ($department['name'] ?? ('Department ' . (int) ($department['id'] ?? 0))));
        $fileName = $baseName . '.xlsx';
        $suffix = 2;
        while (isset($usedNames[strtolower($fileName)])) {
            $fileName = $baseName . '_' . $suffix . '.xlsx';
            $suffix++;
        }
        $usedNames[strtolower($fileName)] = true;
        $zipFiles[$fileName] = binary_zip_archive(xlsx_package_files($departmentSimpleWorkbooks($department)));
    }

    if ($zipFiles === []) {
        $zipFiles['README.txt'] = "No departments are available for export.\n";
    }

    audit_log(
        'admin',
        (string) (current_user()['username'] ?? 'admin'),
        'REPORT_CARD_EXPORT',
        'Total marks ZIP exported with one Excel workbook per department'
    );

    $filename = 'report_card_total_marks_' . date('Ymd_His') . '.zip';
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: public');
    header('Expires: 0');

    echo binary_zip_archive($zipFiles);
    exit;
}

throw new RuntimeException('Unknown report-card export mode requested.');
