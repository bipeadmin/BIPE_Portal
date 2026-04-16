(() => {
  const filterForm = document.querySelector('.assignment-filter-form');
  if (!filterForm) {
    return;
  }

  filterForm.querySelectorAll('select').forEach((field) => {
    field.addEventListener('change', () => filterForm.requestSubmit());
  });
})();
