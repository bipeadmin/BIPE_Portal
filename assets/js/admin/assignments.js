(() => {
  const filterForm = document.querySelector('.assignment-filters');
  if (!filterForm) {
    return;
  }

  filterForm.querySelectorAll('select').forEach((field) => {
    field.addEventListener('change', () => filterForm.requestSubmit());
  });
})();
