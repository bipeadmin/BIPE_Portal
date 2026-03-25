(() => {
  const scopeType = document.getElementById('scope-type');
  const scopeDepartment = document.getElementById('scope-department');
  if (!scopeType || !scopeDepartment) return;
  const sync = () => {
    scopeDepartment.disabled = scopeType.value !== 'department';
  };
  scopeType.addEventListener('change', sync);
  sync();
})();
