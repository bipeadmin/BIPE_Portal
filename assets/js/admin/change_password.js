(() => {
  const copyButtons = document.querySelectorAll('[data-copy-value]');
  if (copyButtons.length === 0) {
    return;
  }

  const copyText = async (value) => {
    if (navigator.clipboard && window.isSecureContext) {
      await navigator.clipboard.writeText(value);
      return true;
    }

    const helper = document.createElement('textarea');
    helper.value = value;
    helper.setAttribute('readonly', '');
    helper.style.position = 'fixed';
    helper.style.opacity = '0';
    document.body.appendChild(helper);
    helper.select();
    const success = document.execCommand('copy');
    document.body.removeChild(helper);
    return success;
  };

  copyButtons.forEach((button) => {
    const defaultLabel = button.textContent.trim() || 'Copy';
    button.addEventListener('click', async () => {
      const value = button.getAttribute('data-copy-value') || '';
      if (!value) {
        return;
      }

      try {
        const copied = await copyText(value);
        button.textContent = copied ? 'Copied' : 'Copy Failed';
      } catch (error) {
        button.textContent = 'Copy Failed';
      }

      window.setTimeout(() => {
        button.textContent = defaultLabel;
      }, 1800);
    });
  });
})();
