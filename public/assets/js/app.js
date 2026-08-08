/*
 * Notices, and nothing else.
 *
 * This file used to carry 43 modules for the web interface this application
 * served itself - table filters, tree editors, drop zones, a cropper, a barcode
 * reader. That interface is gone, and 40 of the 43 attached to markup no longer
 * present: they found nothing and returned, which is harmless and 83 KB of
 * harmless.
 *
 * What is left is what the two remaining pages actually use. Setup and 404 raise
 * flashes, and a flash is a toast.
 */

/* Notices.
 *
 * One place that knows what a notice looks like, so the server and the page raise the
 * same thing. A flash rendered into the container on page load and a toast raised by
 * script after a save that never reloads are the same object with the same behaviour.
 *
 *   RetroVault.notify('Order saved.')            - a success, fades on its own
 *   RetroVault.notify('That did not save', 'error')  - stays until dismissed
 *
 * Errors do not auto-dismiss: missing "Saved." costs nothing, missing "That did not
 * save" costs the work. Everything is dismissable by hand either way, and with no
 * script at all the server-rendered ones are still readable - they simply do not fade.
 */
(function () {
  var HOLD = 4500;

  function box() {
    var el = document.querySelector('[data-toasts]');
    if (!el) {
      el = document.createElement('div');
      el.className = 'toasts';
      el.setAttribute('data-toasts', '');
      el.setAttribute('role', 'status');
      el.setAttribute('aria-live', 'polite');
      document.body.appendChild(el);
    }
    return el;
  }

  function dismiss(toast) {
    if (!toast || toast.classList.contains('is-going')) { return; }
    toast.classList.add('is-going');
    // Remove after the animation, but on a timer rather than animationend: a browser
    // with animations disabled never fires that event, and the node would stay forever.
    setTimeout(function () { toast.remove(); }, 260);
  }

  function arm(toast) {
    if (toast.hasAttribute('data-toast-sticky')) { return; }
    var timer = setTimeout(function () { dismiss(toast); }, HOLD);
    // Reading it should not race the clock: hovering or focusing holds it open.
    ['mouseenter', 'focusin'].forEach(function (ev) {
      toast.addEventListener(ev, function () { clearTimeout(timer); });
    });
    ['mouseleave', 'focusout'].forEach(function (ev) {
      toast.addEventListener(ev, function () {
        clearTimeout(timer);
        timer = setTimeout(function () { dismiss(toast); }, HOLD);
      });
    });
  }

  function notify(message, type) {
    var toast = document.createElement('div');
    toast.className = 'toast toast--' + (type === 'error' ? 'error' : 'ok');
    if (type === 'error') { toast.setAttribute('data-toast-sticky', ''); }

    var text = document.createElement('span');
    text.className = 'toast__text';
    text.textContent = String(message);          // textContent, so a message cannot inject
    toast.appendChild(text);

    var close = document.createElement('button');
    close.type = 'button';
    close.className = 'toast__close';
    close.setAttribute('data-toast-close', '');
    close.setAttribute('aria-label', 'Dismiss this notice');
    close.innerHTML = '&times;';
    toast.appendChild(close);

    box().appendChild(toast);
    arm(toast);
    return toast;
  }

  // The ones the server rendered into the page get the same treatment.
  document.querySelectorAll('[data-toasts] .toast').forEach(arm);

  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest ? ev.target.closest('[data-toast-close]') : null;
    if (btn) { dismiss(btn.closest('.toast')); }
  });

  window.RetroVault = window.RetroVault || {};
  window.RetroVault.notify = notify;
})();
