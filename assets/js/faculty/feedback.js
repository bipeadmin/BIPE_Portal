'use strict';

(function () {
  const roleField = document.querySelector('[data-feedback-role-filter]');
  const targetField = document.querySelector('[data-feedback-target]');
  const searchField = document.querySelector('[data-feedback-search]');
  const hint = document.querySelector('[data-feedback-hint]');
  const emptyState = document.querySelector('[data-feedback-empty]');

  if (!roleField || !targetField || !searchField) {
    return;
  }

  const allOptions = Array.from(targetField.querySelectorAll('option[data-role]')).map(function (option) {
    return {
      value: option.value,
      role: option.dataset.role || '',
      label: option.dataset.label || option.textContent || '',
      name: option.dataset.name || '',
      identifier: option.dataset.identifier || '',
    };
  });

  const roleLabels = {
    admin: 'admin',
    teacher: 'faculty',
    student: 'student',
  };

  function buildPlaceholder(role) {
    if (!role) {
      return 'Select a category first';
    }

    const noun = roleLabels[role] || 'recipient';
    return 'Search ' + noun + ' name or ID';
  }

  function buildHint(role, count) {
    if (!role) {
      return 'Select a recipient category to load matching users.';
    }

    const noun = roleLabels[role] || 'recipient';
    if (count === 0) {
      return 'No ' + noun + ' matches this search right now.';
    }

    return count + ' ' + noun + (count === 1 ? '' : 's') + ' available for selection.';
  }

  function syncOptions() {
    const selectedRole = roleField.value;
    const searchText = searchField.value.trim().toLowerCase();
    const previousValue = targetField.value;

    const filteredOptions = allOptions.filter(function (option) {
      if (!selectedRole || option.role !== selectedRole) {
        return false;
      }

      if (!searchText) {
        return true;
      }

      return (
        option.label.toLowerCase().includes(searchText) ||
        option.name.toLowerCase().includes(searchText) ||
        option.identifier.toLowerCase().includes(searchText)
      );
    });

    targetField.innerHTML = '';
    const placeholderOption = document.createElement('option');
    placeholderOption.value = '';
    placeholderOption.textContent = selectedRole ? 'Select recipient' : 'Select a category first';
    targetField.appendChild(placeholderOption);

    filteredOptions.forEach(function (option) {
      const element = document.createElement('option');
      element.value = option.value;
      element.textContent = option.label;
      targetField.appendChild(element);
    });

    targetField.disabled = !selectedRole || filteredOptions.length === 0;
    searchField.disabled = !selectedRole;
    searchField.placeholder = buildPlaceholder(selectedRole);

    if (filteredOptions.some(function (option) { return option.value === previousValue; })) {
      targetField.value = previousValue;
    } else {
      targetField.value = '';
    }

    if (hint) {
      hint.textContent = buildHint(selectedRole, filteredOptions.length);
    }

    if (emptyState) {
      emptyState.hidden = !selectedRole || filteredOptions.length > 0;
    }
  }

  roleField.addEventListener('change', function () {
    searchField.value = '';
    syncOptions();
  });

  searchField.addEventListener('input', syncOptions);

  syncOptions();
}());
