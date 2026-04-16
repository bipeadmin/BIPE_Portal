(() => {
  const filterForm = document.querySelector('.view-marks-filters');
  if (!filterForm) {
    return;
  }

  filterForm.querySelectorAll('select').forEach((field) => {
    field.addEventListener('change', () => filterForm.requestSubmit());
  });
})();
