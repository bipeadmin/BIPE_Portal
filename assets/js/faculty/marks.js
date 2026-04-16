(() => {
  const filterForm = document.querySelector('.marks-filter-form');
  const templateLink = document.querySelector('[data-template-download]');

  const syncTemplateLink = () => {
    if (!filterForm || !templateLink) {
      return;
    }

    const baseUrl = templateLink.dataset.templateBase || templateLink.href;
    const url = new URL(baseUrl, window.location.href);
    const formData = new FormData(filterForm);

    url.searchParams.set('template', '1');
    ['department_id', 'semester_no', 'subject_id', 'mark_type_id'].forEach((fieldName) => {
      const value = (formData.get(fieldName) || '').toString();
      if (value !== '') {
        url.searchParams.set(fieldName, value);
      } else {
        url.searchParams.delete(fieldName);
      }
    });

    templateLink.href = url.toString();
  };

  if (filterForm) {
    const submitFilters = () => filterForm.requestSubmit();
    filterForm.querySelectorAll('select').forEach((field) => {
      field.addEventListener('change', () => {
        syncTemplateLink();
        submitFilters();
      });
    });
    syncTemplateLink();
  }

  const form = document.querySelector('.marks-manual-form');
  if (!form) {
    return;
  }

  const rows = Array.from(form.querySelectorAll('.marks-row'));
  const csvIssuesPanel = document.querySelector('[data-csv-issues]');
  const csvIssuesWrap = csvIssuesPanel?.querySelector('[data-csv-issues-table-wrap]') || null;
  const csvIssuesEmpty = csvIssuesPanel?.querySelector('[data-csv-issues-empty]') || null;
  const csvIssuesCount = csvIssuesPanel?.querySelector('.marks-csv-issues-count') || null;
  const csvIssueRows = new Map(
    Array.from(document.querySelectorAll('[data-csv-issue-row]')).map((row) => [row.dataset.studentId || '', row])
  );

  const evaluateMarksState = (input, checkbox) => {
    if (!input) {
      return { resolved: false, invalid: false, rawValue: '', numericValue: Number.NaN, maxMarks: Number.NaN };
    }

    if (checkbox?.checked) {
      return { resolved: true, invalid: false, rawValue: '', numericValue: Number.NaN, maxMarks: Number.NaN };
    }

    const rawValue = input.value.trim();
    const maxMarks = Number.parseFloat(input.dataset.maxMarks || '');
    if (rawValue === '') {
      return { resolved: false, invalid: false, rawValue, numericValue: Number.NaN, maxMarks };
    }

    const numericValue = Number.parseFloat(rawValue);
    if (Number.isNaN(numericValue) || numericValue < 0) {
      return { resolved: false, invalid: false, rawValue, numericValue, maxMarks };
    }

    const invalid = !Number.isNaN(maxMarks) && numericValue > maxMarks;
    return {
      resolved: !invalid,
      invalid,
      rawValue,
      numericValue,
      maxMarks,
    };
  };

  const updateLimitState = (input, checkbox, warning) => {
    if (!input || !warning) {
      return evaluateMarksState(input, checkbox);
    }

    warning.textContent = '';
    input.setCustomValidity('');

    const state = evaluateMarksState(input, checkbox);
    if (checkbox?.checked || state.rawValue === '') {
      return state;
    }

    if (Number.isNaN(state.numericValue)) {
      const message = 'Enter a valid number.';
      input.setCustomValidity(message);
      warning.textContent = message;
      return state;
    }

    if (state.numericValue < 0) {
      const message = 'Marks cannot be less than 0.';
      input.setCustomValidity(message);
      warning.textContent = message;
      return state;
    }

    if (state.invalid) {
      const message = `Maximum allowed is ${state.maxMarks}.`;
      input.setCustomValidity(message);
      warning.textContent = message;
    }

    return state;
  };

  const syncCsvIssueSummary = () => {
    if (!csvIssuesPanel || !csvIssuesWrap || !csvIssuesEmpty) {
      return;
    }

    const visibleIssues = Array.from(csvIssueRows.values()).filter((issueRow) => !issueRow.hidden);
    const hasVisibleIssues = visibleIssues.length > 0;

    if (csvIssuesCount) {
      csvIssuesCount.textContent = `${visibleIssues.length} Issue${visibleIssues.length === 1 ? '' : 's'}`;
    }

    csvIssuesWrap.hidden = !hasVisibleIssues;
    csvIssuesEmpty.hidden = hasVisibleIssues;
    csvIssuesPanel.classList.toggle('is-resolved', !hasVisibleIssues);
  };

  const syncCsvIssueState = (studentId, state) => {
    const issueRow = csvIssueRows.get(String(studentId));
    if (!issueRow) {
      return;
    }

    const enteredMarksCell = issueRow.querySelector('[data-csv-entered-marks]');
    if (enteredMarksCell && state.invalid && state.rawValue !== '') {
      enteredMarksCell.textContent = state.rawValue;
    }

    issueRow.hidden = state.resolved;
    syncCsvIssueSummary();
  };

  rows.forEach((row) => {
    const checkbox = row.querySelector('.marks-absent-cell input[type="checkbox"]');
    const input = row.querySelector('.marks-value-cell input');
    const warning = row.querySelector('.marks-inline-warning');
    const isLocked = row.dataset.locked === '1';
    const studentId = row.dataset.studentId || '';

    if (!checkbox || !input) {
      return;
    }

    const syncAbsentState = (syncIssues = false) => {
      const isAbsent = checkbox.checked;
      row.classList.toggle('is-absent', isAbsent);

      if (isAbsent) {
        if (!isLocked && input.value.trim() !== '') {
          input.dataset.lastValue = input.value;
        }
        if (!isLocked) {
          input.value = '';
        }
      } else if (!isLocked && input.value.trim() === '' && input.dataset.lastValue) {
        input.value = input.dataset.lastValue;
      }

      input.disabled = isLocked || isAbsent;
      const state = updateLimitState(input, checkbox, warning);
      if (syncIssues && studentId !== '') {
        syncCsvIssueState(studentId, state);
      }
    };

    checkbox.addEventListener('change', () => syncAbsentState(true));
    input.addEventListener('input', () => {
      const state = updateLimitState(input, checkbox, warning);
      if (studentId !== '') {
        syncCsvIssueState(studentId, state);
      }
    });
    input.addEventListener('invalid', () => updateLimitState(input, checkbox, warning));

    syncAbsentState(false);
  });

  syncCsvIssueSummary();

  form.addEventListener('submit', (event) => {
    if (!form.reportValidity()) {
      event.preventDefault();
    }
  });
})();
