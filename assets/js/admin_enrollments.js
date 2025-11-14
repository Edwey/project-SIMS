'use strict';
(function(){
  function ready(fn){ if(document.readyState!=='loading'){ fn(); } else { document.addEventListener('DOMContentLoaded', fn); } }

  function renderItemContent(item, helpers){
    const escape = helpers && typeof helpers.escapeHtml === 'function' ? helpers.escapeHtml : (v)=>String(v ?? '');
    const header = `${escape(item.student_id || '')} · ${escape(item.name || '')}`;
    const email = item.email ? `<div class="small text-muted">${escape(item.email)}</div>` : '';
    const metaPieces = [];
    if (item.level) metaPieces.push(`Level: ${item.level}`);
    if (item.department) metaPieces.push(item.department);
    if (item.status) metaPieces.push(`Status: ${item.status}`);
    const meta = metaPieces.length ? `<div class="small text-muted">${escape(metaPieces.join(' · '))}</div>` : '';
    return `<div class="fw-semibold">${header}</div>${email}${meta}`;
  }

  ready(function(){
    const meta = document.querySelector('meta[name="app-base-url"]');
    const BASE_URL = meta ? meta.getAttribute('content') : '';

    const SEARCH_URL = (BASE_URL || '') + '/admin/ajax/student_search.php';

    function showFormAlert(form, message, type){
      const klass = type === 'error' ? 'alert-danger' : (type === 'success' ? 'alert-success' : 'alert-warning');
      const alert = document.createElement('div');
      alert.className = `alert ${klass} mt-2`; // keep bootstrap styling
      alert.textContent = message;
      form.appendChild(alert);
      setTimeout(()=>{ alert.remove(); }, 4000);
    }

    const autocompleteMap = new WeakMap();

    document.querySelectorAll('form.enroll-add-form').forEach(form => {
      const input = form.querySelector('input.student-autocomplete');
      const list = form.querySelector('.list-group');
      const resolvedField = form.querySelector('.resolved-student-id');
      const triggerBtn = form.querySelector('.student-search-btn');
      if (!input || !list) return;

      const autocomplete = StudentAutocomplete.init(input, {
        fetchUrl: SEARCH_URL,
        listElement: list,
        hiddenInput: resolvedField,
        minChars: 2,
        renderItem: renderItemContent,
        renderEmpty: () => '<div class="list-group-item text-muted">No matches found. Try a different name, email, or code.</div>',
        renderLoading: () => '<div class="list-group-item text-muted">Searching…</div>',
        renderError: () => '<div class="list-group-item list-group-item-danger">Unable to load results. Check your connection or permissions.</div>',
        onSelect: (item) => {
          input.value = `${item.name || ''} (${item.student_id || ''})`.trim();
        }
      });

      autocompleteMap.set(input, autocomplete);

      if (triggerBtn) {
        triggerBtn.addEventListener('click', () => {
          if (autocomplete && typeof autocomplete.triggerSearch === 'function') {
            autocomplete.triggerSearch();
          }
        });
      }

      form.addEventListener('submit', (event) => {
        const studentCode = form.querySelector('input[name="student_code"]').value.trim();
        if ((!resolvedField || !resolvedField.value) && !studentCode) {
          event.preventDefault();
          showFormAlert(form, 'Please select a student from the suggestions or enter an exact Student Code.', 'warning');
        }
      });
    });
  });
})();
