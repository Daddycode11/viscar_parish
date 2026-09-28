(function () {
  if (window.appNavigationLoaded) return;
  window.appNavigationLoaded = true;
  // Some Admin pages use the shared modal markup without defining its controls.
  window.openModal = window.openModal || function (id) {
    const modal = document.getElementById(id);
    if (modal) modal.classList.add('open');
  };
  window.closeModal = window.closeModal || function (id) {
    const modal = document.getElementById(id);
    if (modal) modal.classList.remove('open');
  };
  function safePrevious(value) {
    if (typeof value !== 'string' || !value) return false;
    try {
      const url = new URL(value, location.href);
      return url.origin === location.origin && url.href !== location.href &&
        !/\/(?:login|logout|signup|register|verify_otp|verify_password|reset_password|forgot_password)\.php$/.test(url.pathname);
    } catch (_) { return false; }
  }
  function hasPreviousPage() {
    if (window.navigation && typeof navigation.entries === 'function') {
      const entry = navigation.currentEntry;
      const previous = entry && navigation.entries().find(item => item.index === entry.index - 1);
      return !!previous && safePrevious(previous.url);
    }
    return history.length > 1 && !!document.referrer && safePrevious(document.referrer);
  }
  document.addEventListener('click', function (event) {
    const link = event.target.closest('[data-app-back]');
    if (!link || event.button !== 0 || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
    event.preventDefault();
    if (!document.dispatchEvent(new CustomEvent('app:back', {cancelable:true}))) return;
    if (hasPreviousPage()) { history.back(); return; }
    if (window.opener && history.length <= 1) {
      window.close();
      setTimeout(() => location.assign(link.href), 100);
      return;
    }
    location.assign(link.href);
  });
  let lastTrigger = null;
  document.addEventListener('click', () => { lastTrigger = document.activeElement; }, true);
  const dialogs = [];
  document.querySelectorAll('.modal-wrap').forEach(wrapper => {
    const modal = wrapper.querySelector('.modal');
    if (!modal) return;
    const existingClose = modal.querySelector('button[onclick*="closeModal"],button[onclick*="closeView"]');
    const close = existingClose || document.createElement('button');
    if (!existingClose) {
    close.type = 'button'; close.className = 'dialog-return'; close.textContent = 'Close';
    close.setAttribute('aria-label', 'Close dialog');
    modal.prepend(close);
    } else if (close.textContent.trim() === '×') {
      close.setAttribute('aria-label', 'Close dialog');
    }
    let trigger = null, wasOpen = false;
    function dismiss() {
      wrapper.classList.remove('open');
      if (wrapper.style.display && wrapper.style.display !== 'none') wrapper.style.display = 'none';
    }
    close.addEventListener('click', dismiss);
    new MutationObserver(() => {
      if (!existingClose) {
        close.hidden = !!modal.querySelector('button[onclick*="closeModal"],button[onclick*="closeView"]');
      }
      const opened = getComputedStyle(wrapper).display !== 'none';
      if (opened && !wasOpen) {
        trigger = lastTrigger;
        const focusClose = close.hidden ? modal.querySelector('button[onclick*="closeModal"],button[onclick*="closeView"]') : close;
        if (focusClose) focusClose.focus();
      } else if (!opened && wasOpen && trigger && trigger.isConnected) trigger.focus();
      wasOpen = opened;
    }).observe(wrapper, {attributes:true, attributeFilter:['class','style'],childList:true,subtree:true});
    dialogs.push({wrapper, dismiss});
  });
  document.addEventListener('keydown', event => {
    if (event.key !== 'Escape') return;
    const open = dialogs.filter(item => getComputedStyle(item.wrapper).display !== 'none').pop();
    if (open) { event.preventDefault(); open.dismiss(); }
  });
})();
