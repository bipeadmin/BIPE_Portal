(() => {
  document.querySelectorAll('[data-mark-all]').forEach((button) => {
    button.addEventListener('click', () => {
      const value = button.getAttribute('data-mark-all');
      document.querySelectorAll('.attendance-select').forEach((select) => {
        select.value = value;
      });
    });
  });
})();
