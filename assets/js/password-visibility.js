(() => {
  'use strict';
  if (window.viscarPasswordVisibility) return;
  window.viscarPasswordVisibility = true;
  let sequence = 0;
  function enhance(root) {
    const inputs = [...root.querySelectorAll('input[type="password"]')];
    if (root.matches?.('input[type="password"]')) inputs.unshift(root);
    inputs.forEach(input => {
      if (input.dataset.passwordVisibility) return;
      input.dataset.passwordVisibility = 'ready';
      if (!input.id) {
        do { input.id = 'password-visibility-' + (++sequence); } while (document.querySelectorAll('#' + input.id).length > 1);
      }
      const label = input.labels?.[0]?.textContent.trim() || input.placeholder || input.name.replaceAll('_', ' ') || 'password';
      const wrapper = document.createElement('span');
      wrapper.className = 'password-visibility-field';
      input.before(wrapper);
      wrapper.append(input);
      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'password-visibility-toggle';
      button.setAttribute('aria-controls', input.id);
      const icon = document.getElementById('password-visibility-icon').content.cloneNode(true);
      button.append(icon);
      const slash = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      slash.setAttribute('d', 'M3 3l18 18');
      button.querySelector('svg').append(slash);
      function render() {
        const shown = input.type === 'text';
        button.setAttribute('aria-label', (shown ? 'Hide password: ' : 'Show password: ') + label);
        button.title = shown ? 'Hide password' : 'Show password';
        slash.style.display = shown ? '' : 'none';
        button.disabled = input.disabled;
      }
      button.addEventListener('click', event => {
        event.preventDefault(); // Do not activate an enclosing label or submit a form.
        input.type = input.type === 'password' ? 'text' : 'password';
        render();
      });
      // A form reset or back/forward restoration returns to concealed presentation.
      input.form?.addEventListener('reset', () => { input.type = 'password'; render(); });
      window.addEventListener('pageshow', () => { input.type = 'password'; render(); });
      new MutationObserver(render).observe(input, {attributes: true, attributeFilter: ['disabled']});
      wrapper.append(button);
      render();
    });
  }
  enhance(document);
  new MutationObserver(records => records.forEach(record => record.addedNodes.forEach(node => {
    if (node.nodeType === 1) enhance(node);
  }))).observe(document.body, {childList: true, subtree: true});
})();
