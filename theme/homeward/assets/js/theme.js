/* Progressive controls only. Core WordPress owns navigation and its focus handling. */
(() => {
  'use strict';
  const root = document.documentElement;
  const reduced = window.matchMedia('(prefers-reduced-motion: reduce)');
  const controls = [...document.querySelectorAll('[data-homeward-motion]')];
  const videos = [...document.querySelectorAll('.homeward-hero video[data-homeward-autoplay]')];
  const hasMotion = videos.length > 0 || Boolean(document.querySelector('.homeward-logo-slot img'));
  let userPaused = false;
  const syncMotion = () => {
    const paused = reduced.matches || userPaused;
    root.classList.toggle('homeward-motion-paused', paused);
    videos.forEach((video) => {
      if (paused) video.pause();
      else {
        const playback = video.play();
        if (playback && playback.catch) playback.catch(() => {});
      }
    });
    controls.forEach((button) => {
      button.hidden = !hasMotion;
      button.disabled = reduced.matches;
      button.setAttribute('aria-pressed', String(paused));
      button.textContent = reduced.matches
        ? button.dataset.reducedLabel
        : paused
          ? button.dataset.resumeLabel
          : button.dataset.pauseLabel;
    });
  };
  controls.forEach((button) =>
    button.addEventListener('click', () => {
      userPaused = !userPaused;
      syncMotion();
    })
  );
  reduced.addEventListener('change', syncMotion);
  syncMotion();
  root.classList.add('homeward-motion-ready');

  document.querySelectorAll('[data-homeward-copy]').forEach((button) => {
    const panel = button.closest('[data-homeward-copy-panel]');
    const status = panel && panel.querySelector('[role="status"]');
    if (!status) return;
    button.hidden = false;
    let clearStatus;
    button.addEventListener('click', async () => {
      window.clearTimeout(clearStatus);
      status.textContent = '';
      try {
        if (!window.isSecureContext || !navigator.clipboard) throw new Error('Clipboard unavailable');
        await navigator.clipboard.writeText(button.dataset.homewardCopy);
        status.textContent = button.dataset.success;
      } catch (_) {
        status.textContent = button.dataset.failure;
      }
      // Keep feedback long enough to hear; focus remains on the button.
      clearStatus = window.setTimeout(() => {
        status.textContent = '';
      }, 10000);
    });
  });
})();
