(() => {
  const body = document.body;
  const toggle = document.querySelector('[data-sidebar-toggle]');
  const close = document.querySelector('[data-sidebar-close]');
  const csrfMeta = document.querySelector('meta[name="csrf-token"]');
  const csrfToken = csrfMeta ? csrfMeta.getAttribute('content') : '';

  if (toggle) {
    toggle.addEventListener('click', () => {
      body.classList.toggle('sidebar-open');
    });
  }

  if (close) {
    close.addEventListener('click', () => body.classList.remove('sidebar-open'));
  }

  const attachCsrfField = (form) => {
    if (!form || !csrfToken || String(form.method || '').toUpperCase() !== 'POST') {
      return;
    }

    let field = form.querySelector('input[name="_csrf"]');
    if (!field) {
      field = document.createElement('input');
      field.type = 'hidden';
      field.name = '_csrf';
      form.appendChild(field);
    }

    field.value = csrfToken;
  };

  document.querySelectorAll('form').forEach((form) => {
    attachCsrfField(form);
    form.addEventListener('submit', () => attachCsrfField(form));
  });

  const ensureConfirmModal = () => {
    let modal = document.querySelector('[data-confirm-modal]');
    if (modal) {
      return modal;
    }

    modal = document.createElement('div');
    modal.className = 'confirm-modal-backdrop';
    modal.setAttribute('data-confirm-modal', '');
    modal.hidden = true;
    modal.innerHTML = `
      <div class="confirm-modal" role="dialog" aria-modal="true" aria-labelledby="confirm-modal-title">
        <p class="eyebrow">Confirmation</p>
        <h3 id="confirm-modal-title">Please Confirm</h3>
        <p class="confirm-modal-message">Are you sure?</p>
        <div class="confirm-modal-actions">
          <button class="btn-secondary" type="button" data-confirm-cancel>Cancel</button>
          <button class="btn-danger" type="button" data-confirm-accept>Confirm</button>
        </div>
      </div>
    `;
    document.body.appendChild(modal);
    return modal;
  };

  const confirmModal = ensureConfirmModal();
  const confirmMessage = confirmModal.querySelector('.confirm-modal-message');
  const cancelButton = confirmModal.querySelector('[data-confirm-cancel]');
  const acceptButton = confirmModal.querySelector('[data-confirm-accept]');
  let pendingAction = null;

  const closeConfirmModal = () => {
    confirmModal.hidden = true;
    document.body.classList.remove('confirm-open');
    pendingAction = null;
  };

  const runConfirmedAction = (element) => {
    if (element.tagName === 'A' && element.href) {
      window.location.href = element.href;
      return;
    }

    if (element.form) {
      attachCsrfField(element.form);
      if (typeof element.form.requestSubmit === 'function') {
        element.form.requestSubmit(element);
      } else {
        element.form.submit();
      }
      return;
    }

    element.click();
  };

  document.addEventListener('click', (event) => {
    const trigger = event.target.closest('[data-confirm]');
    if (!trigger) {
      return;
    }

    event.preventDefault();
    pendingAction = trigger;
    confirmMessage.textContent = trigger.getAttribute('data-confirm') || 'Are you sure you want to continue?';
    confirmModal.hidden = false;
    document.body.classList.add('confirm-open');
  });

  cancelButton.addEventListener('click', closeConfirmModal);
  confirmModal.addEventListener('click', (event) => {
    if (event.target === confirmModal) {
      closeConfirmModal();
    }
  });
  acceptButton.addEventListener('click', () => {
    if (!pendingAction) {
      closeConfirmModal();
      return;
    }

    const element = pendingAction;
    closeConfirmModal();
    runConfirmedAction(element);
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !confirmModal.hidden) {
      closeConfirmModal();
    }
  });

  document.querySelectorAll('[data-file-input]').forEach((input) => {
    input.addEventListener('change', () => {
      const targetSelector = input.getAttribute('data-file-target');
      if (!targetSelector) {
        return;
      }
      const target = document.querySelector(targetSelector);
      if (!target) {
        return;
      }
      target.textContent = input.files && input.files[0] ? input.files[0].name : 'No file selected';
    });
  });

  const flashes = document.querySelectorAll('.flash');
  if (flashes.length > 0) {
    window.setTimeout(() => {
      flashes.forEach((flash) => {
        flash.style.transition = 'opacity 0.25s ease, transform 0.25s ease';
        flash.style.opacity = '0';
        flash.style.transform = 'translateY(-4px)';
      });
    }, 6000);
  }
})();
