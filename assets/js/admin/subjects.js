(() => {
  const bindClassPair = (yearId, semesterId, allowZero) => {
    const yearSelect = document.getElementById(yearId);
    const semesterSelect = document.getElementById(semesterId);
    if (!yearSelect || !semesterSelect) {
      return;
    }

    const syncSemesterFromYear = () => {
      const yearValue = Number(yearSelect.value || 0);
      if (yearValue > 0) {
        semesterSelect.value = String(yearValue * 2);
      } else if (allowZero) {
        semesterSelect.value = '0';
      }
    };

    const syncYearFromSemester = () => {
      const semesterValue = Number(semesterSelect.value || 0);
      if (semesterValue > 0) {
        yearSelect.value = String(Math.max(1, Math.floor(semesterValue / 2)));
      } else if (allowZero) {
        yearSelect.value = '0';
      }
    };

    yearSelect.addEventListener('change', syncSemesterFromYear);
    semesterSelect.addEventListener('change', syncYearFromSemester);
  };

  const uploadModeSelect = document.querySelector('[data-subject-upload-mode]');
  const uploadPanels = Array.from(document.querySelectorAll('[data-subject-upload-panel]'));

  const syncUploadMode = () => {
    if (!uploadModeSelect || uploadPanels.length === 0) {
      return;
    }

    const activeMode = uploadModeSelect.value === 'single' ? 'single' : 'bulk';
    uploadPanels.forEach((panel) => {
      panel.hidden = panel.dataset.subjectUploadPanel !== activeMode;
    });
  };

  if (uploadModeSelect) {
    uploadModeSelect.addEventListener('change', syncUploadMode);
    syncUploadMode();
  }

  bindClassPair('subject-single-upload-year', 'subject-single-upload-semester', false);
  bindClassPair('subject-bulk-upload-year', 'subject-bulk-upload-semester', false);
  bindClassPair('subject-filter-year', 'subject-filter-semester', true);
})();
