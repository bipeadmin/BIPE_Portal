(() => {
  const filterForm = document.querySelector('.admin-marks-filter-form');
  if (!filterForm) {
    return;
  }

  filterForm.querySelectorAll('select').forEach((field) => {
    field.addEventListener('change', () => filterForm.requestSubmit());
  });
})();
