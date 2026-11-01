(function () {
  'use strict';
  document.querySelectorAll('[data-homeward-copy]').forEach(function (button) {
    button.classList.remove('homeward-js-only');
    button.addEventListener('click', async function () {
      var status = button.parentElement.querySelector('[role="status"]');
      try {
        await navigator.clipboard.writeText(button.dataset.homewardCopy);
        status.textContent = (window.homewardDonateL10n || {}).copied || 'Email copied.';
      } catch (_) {
        status.textContent =
          ((window.homewardDonateL10n || {}).manual || 'Select and copy this email: ') +
          button.dataset.homewardCopy;
      }
    });
  });
})();
