(() => {
  const filterForm = document.querySelector('.admin-reports-filter-form');
  if (!filterForm) {
    return;
  }

  const dataNode = filterForm.querySelector('[data-report-filter-data]');
  const viewSelect = filterForm.querySelector('[data-report-filter="view"]');
  const departmentSelect = filterForm.querySelector('[data-report-filter="department"]');
  const semesterSelect = filterForm.querySelector('[data-report-filter="semester"]');
  const subjectSelect = filterForm.querySelector('[data-report-filter="subject"]');
  const assignmentSelect = filterForm.querySelector('[data-report-filter="assignment"]');
  const assignmentWrap = filterForm.querySelector('[data-report-filter-wrap="assignment"]');

  if (!dataNode || !viewSelect || !departmentSelect || !semesterSelect || !subjectSelect || !assignmentSelect) {
    return;
  }

  let filterData = {};
  try {
    filterData = JSON.parse(dataNode.textContent || '{}');
  } catch (error) {
    return;
  }

  const buildOptions = (select, options, selectedValue) => {
    const normalizedSelected = String(selectedValue ?? '');
    select.innerHTML = '';

    let hasSelected = false;
    options.forEach((optionData) => {
      const option = document.createElement('option');
      option.value = String(optionData.value ?? '');
      option.textContent = String(optionData.label ?? option.value);
      if (option.value === normalizedSelected) {
        option.selected = true;
        hasSelected = true;
      }
      select.appendChild(option);
    });

    if (!hasSelected && select.options.length > 0) {
      select.selectedIndex = 0;
    }
  };

  const syncFilters = () => {
    const departmentValue = String(departmentSelect.value || '0');
    const semesterValueBefore = String(semesterSelect.value || '0');
    const subjectValueBefore = String(subjectSelect.value || '0');
    const assignmentValueBefore = String(assignmentSelect.value || '');
    const reportView = String(viewSelect.value || 'overview');

    const semesterOptions = departmentValue !== '0'
      ? (filterData.semestersByDepartment?.[departmentValue] ?? [])
      : (filterData.allSemesters ?? []);
    buildOptions(
      semesterSelect,
      [{ value: '0', label: 'All Semesters' }, ...semesterOptions],
      semesterValueBefore
    );

    const semesterValue = String(semesterSelect.value || '0');
    const classKey = `${departmentValue}:${semesterValue}`;
    const subjectOptions = departmentValue !== '0' && semesterValue !== '0'
      ? (filterData.subjectsByClass?.[classKey] ?? [{ value: '0', label: 'All Subjects' }])
      : [{ value: '0', label: 'All Subjects' }];
    buildOptions(subjectSelect, subjectOptions, subjectValueBefore);

    const subjectValue = String(subjectSelect.value || '0');
    const assignmentKey = `${departmentValue}:${semesterValue}:${subjectValue}`;
    const assignmentOptions = departmentValue !== '0' && semesterValue !== '0'
      ? (filterData.assignmentsByClassSubject?.[assignmentKey] ?? [{ value: '', label: 'All Assignments' }])
      : [{ value: '', label: 'All Assignments' }];
    buildOptions(assignmentSelect, assignmentOptions, assignmentValueBefore);

    const classSelected = departmentValue !== '0' && semesterValue !== '0';
    subjectSelect.disabled = !classSelected;
    assignmentSelect.disabled = !classSelected || reportView !== 'assignment';

    if (assignmentWrap) {
      assignmentWrap.hidden = reportView !== 'assignment';
    }

    if (reportView !== 'assignment') {
      assignmentSelect.value = '';
    }
  };

  [viewSelect, departmentSelect, semesterSelect, subjectSelect, assignmentSelect].forEach((field) => {
    field.addEventListener('change', () => {
      syncFilters();
      filterForm.requestSubmit();
    });
  });

  syncFilters();
})();
