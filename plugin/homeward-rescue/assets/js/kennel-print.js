/* Local QR rendering and paper controls for the standalone staff print view. */
(function () {
  'use strict';
  const root = document.body;
  const controls = document.querySelector('.homeward-print-controls');
  if (!controls) return;
  ['layout', 'paper'].forEach((key) => {
    const select = controls.querySelector('[data-print-' + key + ']');
    select.addEventListener('change', () => {
      root.dataset[key] = select.value;
    });
  });
  const button = controls.querySelector('[data-print-button]');
  const status = controls.querySelector('[data-print-status]');
  let ready = true;
  document.querySelectorAll('[data-print-qr]').forEach((image) => {
    try {
      try {
        image.src = window.homewardQR.qrSource(image.dataset.printQr);
      } catch (_) {
        image.src = window.homewardQR.qrSource(image.dataset.printQrFallback);
      }
      image.hidden = false;
    } catch (_) {
      // Keep the printed page URL when a payload exceeds the encoder capacity.
      ready = false;
      status.textContent = status.dataset.qrError;
    }
  });
  status.hidden = ready;
  const images = Array.from(document.querySelectorAll('.homeward-print-card img:not([hidden])'));
  const loaded = images.map((image) => {
    if (image.complete) return Promise.resolve();
    return new Promise((resolve) => {
      image.addEventListener('load', resolve, { once: true });
      image.addEventListener('error', resolve, { once: true });
    });
  });
  if (document.fonts?.ready) loaded.push(document.fonts.ready);
  Promise.all(loaded).then(() => {
    button.disabled = false;
  });
  button.addEventListener('click', () => window.print());
})();
