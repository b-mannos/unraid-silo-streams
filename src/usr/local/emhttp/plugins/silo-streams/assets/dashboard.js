'use strict';
(() => {
  const start = () => document.querySelectorAll('[data-silo-streams]').forEach(root => {
    if (!root.dataset.mounted && window.SiloStreams) {
      window.SiloStreams.mount(root, { endpoint: '/plugins/silo-streams/api.php', pollInterval: Number(root.dataset.pollInterval) || 5 });
      root.dataset.mounted = 'true';
    }
  });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start, { once: true });
  else start();
})();
