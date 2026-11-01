(() => {
  'use strict';
  const root = document.querySelector('.homeward-setup');
  if (!root || typeof homewardWizard === 'undefined') return;
  const config = homewardWizard;
  const field = (key) => root.querySelector('#homeward-' + key);
  const posthogKey = field('posthog_key');
  if (posthogKey) {
    const validateKey = () =>
      posthogKey.setCustomValidity(
        !posthogKey.value || /^phc_[A-Za-z0-9_-]{20,}$/.test(posthogKey.value) ? '' : config.posthogKeyError
      );
    posthogKey.addEventListener('input', validateKey);
    validateKey();
  }
  const preview = root.querySelector('.homeward-setup-preview');
  const colors = ['primary', 'accent', 'ink', 'background', 'surface'];
  const updatePreview = () => {
    if (!preview) return;
    colors.forEach((key) => {
      const input = field('color_' + key);
      if (input && /^#[a-f0-9]{6}$/i.test(input.value))
        preview.style.setProperty('--setup-' + key, input.value);
    });
    ['heading', 'body'].forEach((role) => {
      const input = field('font_' + role);
      if (input && config.fonts[input.value])
        preview.style.setProperty('--setup-' + role, config.fonts[input.value].stack);
    });
  };
  const request = async (action, values, signal) => {
    const body = new URLSearchParams({ action, nonce: config.nonce, ...values });
    const response = await fetch(config.url, { method: 'POST', credentials: 'same-origin', body, signal });
    const result = await response.json();
    if (!response.ok || !result.success) throw new Error(result.data?.message || config.failed);
    return result.data;
  };
  // Each checker owns its timer and request so stale responses never replace newer input.
  const checker = (run) => {
    let timer;
    let controller;
    let generation = 0;
    return (delay = 450) => {
      clearTimeout(timer);
      if (controller) controller.abort();
      const current = ++generation;
      timer = setTimeout(() => {
        controller = new AbortController();
        run(controller.signal, () => current === generation);
      }, delay);
    };
  };
  const sourceButton = root.querySelector('[data-homeward-source-check]');
  const sourceResult = root.querySelector('[data-homeward-source-result]');
  if (sourceButton && sourceResult) {
    const checkSource = checker(async (signal, current) => {
      sourceResult.textContent = config.checking;
      sourceResult.setAttribute('aria-busy', 'true');
      try {
        const result = await request(
          'homeward_check_source',
          { shelter_id: field('shelter_id').value },
          signal
        );
        if (current()) sourceResult.textContent = result.message;
      } catch (error) {
        if (current() && error.name !== 'AbortError')
          sourceResult.textContent = error.message || config.failed;
      } finally {
        if (current()) sourceResult.setAttribute('aria-busy', 'false');
      }
    });
    sourceButton.addEventListener('click', () => checkSource(0));
    ['shelter_id'].forEach((key) =>
      field(key).addEventListener('input', () => {
        sourceResult.textContent = config.checking;
        checkSource();
      })
    );
    if (Number(field('shelter_id').value) >= 0) checkSource(0);
  }
  const contrast = root.querySelector('[data-homeward-contrast]');
  if (contrast) {
    const checkColors = checker(async (signal, current) => {
      const values = {};
      colors.forEach((key) => {
        values['colors[color_' + key + ']'] = field('color_' + key).value;
      });
      contrast.setAttribute('aria-busy', 'true');
      try {
        const result = await request('homeward_check_colors', values, signal);
        if (!current()) return;
        contrast.replaceChildren();
        result.checks.forEach((check) => {
          const line = document.createElement('p');
          line.textContent = check.message;
          if (!check.passes) line.className = 'homeward-contrast-warning';
          contrast.appendChild(line);
        });
      } catch (error) {
        if (current() && error.name !== 'AbortError') contrast.textContent = error.message || config.failed;
      } finally {
        if (current()) contrast.setAttribute('aria-busy', 'false');
      }
    });
    colors.forEach((key) =>
      field('color_' + key).addEventListener('input', () => {
        updatePreview();
        checkColors();
      })
    );
  }
  // Pairing cards set both selects; changing a select picks the matching card, or "Use my own mix".
  const radios = root.querySelectorAll
    ? [...root.querySelectorAll('input[name="fields[font_pairing]"]')]
    : [];
  const cards = radios.filter((radio) => radio.dataset && radio.dataset.heading);
  const custom = radios.find((radio) => radio.value === 'custom');
  const syncRadios = () => {
    const heading = field('font_heading'),
      body = field('font_body');
    if (!heading || !body) return;
    const match = cards.find(
      (radio) => radio.dataset.heading === heading.value && radio.dataset.body === body.value
    );
    const target = match || custom;
    if (target) target.checked = true;
  };
  cards.forEach((radio) =>
    radio.addEventListener('change', () => {
      if (!radio.checked) return;
      const heading = field('font_heading'),
        body = field('font_body');
      if (heading) heading.value = radio.dataset.heading;
      if (body) body.value = radio.dataset.body;
      updatePreview();
    })
  );
  ['heading', 'body'].forEach((role) =>
    field('font_' + role)?.addEventListener('change', () => {
      syncRadios();
      updatePreview();
    })
  );
  updatePreview();
  const logo = root.querySelector('[data-homeward-logo]');
  const logoPreview = root.querySelector('[data-homeward-logo-preview]');
  if (logo && logoPreview) {
    logo.addEventListener('click', () => {
      if (!window.wp?.media) return;
      const picker = window.wp.media({
        title: config.chooseLogo,
        button: { text: config.useLogo },
        library: { type: 'image' },
        multiple: false,
      });
      picker.on('select', () => {
        const image = picker.state().get('selection').first().toJSON();
        field('logo_id').value = image.id;
        logoPreview.src = image.sizes?.thumbnail?.url || image.url;
        logoPreview.hidden = false;
      });
      picker.open();
    });
    root.querySelector('[data-homeward-logo-remove]').addEventListener('click', () => {
      field('logo_id').value = '0';
      logoPreview.hidden = true;
      logoPreview.removeAttribute('src');
    });
  }
})();
