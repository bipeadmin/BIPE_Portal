(() => {
  const form = document.querySelector('.marks-manual-form');
  if (!form) {
    return;
  }

  const rows = Array.from(form.querySelectorAll('.marks-row'));

  const updateLimitState = (input, checkbox, warning) => {
    if (!input || !warning) {
      return;
    }

    warning.textContent = '';
    input.setCustomValidity('');

    if (checkbox?.checked) {
      return;
    }

    const rawValue = input.value.trim();
    if (rawValue === '') {
      return;
    }

    const maxMarks = Number.parseFloat(input.dataset.maxMarks || '');
    const numericValue = Number.parseFloat(rawValue);
    if (Number.isNaN(numericValue)) {
      const message = 'Enter a valid number.';
      input.setCustomValidity(message);
      warning.textContent = message;
      return;
    }

    if (numericValue < 0) {
      const message = 'Marks cannot be less than 0.';
      input.setCustomValidity(message);
      warning.textContent = message;
      return;
    }

    if (!Number.isNaN(maxMarks) && numericValue > maxMarks) {
      const message = `Maximum allowed is ${maxMarks}.`;
      input.setCustomValidity(message);
      warning.textContent = message;
    }
  };

  rows.forEach((row) => {
    const checkbox = row.querySelector('.marks-absent-cell input[type="checkbox"]');
    const input = row.querySelector('.marks-value-cell input');
    const warning = row.querySelector('.marks-inline-warning');
    const isLocked = row.dataset.locked === '1';

    if (!checkbox || !input) {
      return;
    }

    const syncAbsentState = () => {
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
      updateLimitState(input, checkbox, warning);
    };

    checkbox.addEventListener('change', syncAbsentState);
    input.addEventListener('input', () => updateLimitState(input, checkbox, warning));
    input.addEventListener('invalid', () => updateLimitState(input, checkbox, warning));

    syncAbsentState();
  });

  form.addEventListener('submit', (event) => {
    if (!form.reportValidity()) {
      event.preventDefault();
    }
  });
})();
