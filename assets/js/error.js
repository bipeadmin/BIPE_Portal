(() => {
  const retryButton = document.querySelector('[data-error-reload]');
  if (!retryButton) {
    return;
  }

  retryButton.addEventListener('click', () => {
    window.location.reload();
  });
})();
