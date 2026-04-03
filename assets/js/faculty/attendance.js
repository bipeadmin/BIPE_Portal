(() => {
  const selects = Array.from(document.querySelectorAll('.attendance-select'));
  const totalNode = document.querySelector('[data-attendance-total]');
  const presentNode = document.querySelector('[data-attendance-present]');
  const absentNode = document.querySelector('[data-attendance-absent]');

  const updateSummary = () => {
    if (!selects.length) {
      return;
    }

    const total = selects.length;
    let present = 0;
    let absent = 0;

    selects.forEach((select) => {
      if (select.value === 'A') {
        absent += 1;
      } else {
        present += 1;
      }
    });

    if (totalNode) {
      totalNode.textContent = String(total);
    }
    if (presentNode) {
      presentNode.textContent = String(present);
    }
    if (absentNode) {
      absentNode.textContent = String(absent);
    }
  };

  document.querySelectorAll('[data-mark-all]').forEach((button) => {
    button.addEventListener('click', () => {
      const value = button.getAttribute('data-mark-all');
      selects.forEach((select) => {
        select.value = value;
      });
      updateSummary();
    });
  });

  selects.forEach((select) => {
    select.addEventListener('change', updateSummary);
  });

  updateSummary();
})();
