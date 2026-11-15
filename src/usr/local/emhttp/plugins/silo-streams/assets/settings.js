'use strict';
(() => {
  const form = document.getElementById('silo-streams-settings');
  if (!form || form.dataset.mounted) return;
  form.dataset.mounted = 'true';
  form.addEventListener('submit', async (event) => {
    event.preventDefault();
    const result = document.getElementById('ss-settings-result');
    const data = new URLSearchParams(new FormData(form));
    data.set('silo_csrf_token', data.get('csrf_token') || '');
    const action = event.submitter?.value || 'save';
    data.set('action', action);
    form.querySelectorAll('[aria-invalid]').forEach(el => el.removeAttribute('aria-invalid'));
    const buttons = [...form.querySelectorAll('button')];
    buttons.forEach(button => { button.disabled = true; });
    result.textContent = action === 'test' ? 'Connecting to Silo...' : 'Saving settings...';
    const controller = new AbortController();
    const timer = setTimeout(() => controller.abort(), 20000);
    try {
      // The submit buttons named "action" shadow form.action in the DOM.
      const response = await fetch(form.getAttribute('action'), { method: 'POST', body: data, signal: controller.signal, credentials: 'same-origin', cache: 'no-store' });
      const payload = await response.json();
      if (!response.ok || !payload.ok) {
        Object.entries(payload.fields || {}).forEach(([field]) => form.elements.namedItem(field)?.setAttribute('aria-invalid', 'true'));
        const details = Object.values(payload.fields || {}).join(' ');
        result.textContent = details || payload.error?.message || 'The request failed. Refresh and try again.';
      } else {
        result.textContent = payload.message;
        if (action === 'save') {
          form.elements.namedItem('api_key').value = '';
          document.getElementById('ss-key-help').textContent = 'A key is saved. Leave blank to keep it.';
        }
      }
    } catch {
      result.textContent = 'The request could not be completed. Refresh and try again.';
    } finally {
      clearTimeout(timer);
      buttons.forEach(button => { button.disabled = false; });
    }
  });
})();
