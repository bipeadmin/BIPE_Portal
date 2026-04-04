(() => {
  const shell = document.querySelector('[data-request-mailto]');
  const mailtoLink = shell ? shell.getAttribute('data-request-mailto') || '' : '';

  if (mailtoLink) {
    window.setTimeout(() => {
      window.location.href = mailtoLink;
    }, 180);
  }
})();
