(() => {
  const yearSelect = document.getElementById('student-year-admin');
  const semesterSelect = document.getElementById('student-semester-admin');
  if (!yearSelect || !semesterSelect) return;
  const syncSemester = () => {
    const year = Number(yearSelect.value || 1);
    semesterSelect.value = String(year * 2);
  };
  yearSelect.addEventListener('change', syncSemester);
})();
