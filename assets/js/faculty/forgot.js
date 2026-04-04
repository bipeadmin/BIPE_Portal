(() => {
  const form = document.querySelector('[data-faculty-forgot-form]');
  if (!form) {
    return;
  }

  const requestType = form.querySelector('[data-forgot-request-type]');
  const passwordGroup = form.querySelector('[data-forgot-password-group]');
  const passwordInput = form.querySelector('#faculty-new-password');

  if (!requestType || !passwordGroup || !passwordInput) {
    return;
  }

  const syncView = () => {
    const showPassword = requestType.value === 'forgot_password';
    passwordGroup.classList.toggle('is-hidden', !showPassword);
    passwordInput.required = showPassword;
    if (!showPassword) {
      passwordInput.value = '';
    }
  };

  requestType.addEventListener('change', syncView);
  syncView();
})();
