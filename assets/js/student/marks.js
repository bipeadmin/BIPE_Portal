(() => {
  const filterForm = document.querySelector('.student-marks-filter-form');
  if (!filterForm) {
    return;
  }

  const select = filterForm.querySelector('select[name="mark_type_id"]');
  if (!select) {
    return;
  }

  select.addEventListener('change', () => filterForm.requestSubmit());
})();
