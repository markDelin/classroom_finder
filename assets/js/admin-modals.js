(function () {
  'use strict';

  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-modal-form]');
    if (!btn || !window.cfFormModal) { return; }

    var form = document.querySelector(btn.dataset.modalForm);
    if (!form) { return; }

    var prefill = null;
    if (btn.dataset.prefill) {
      try { prefill = JSON.parse(btn.dataset.prefill); }
      catch (e) { prefill = null; }
    }

    if (prefill) {
      Object.keys(prefill).forEach(function (k) {
        if (form.elements[k]) { form.elements[k].value = prefill[k]; }
      });
    } else {
      form.reset();
      if (form.dataset.defaultAction && form.elements.action) {
        form.elements.action.value = form.dataset.defaultAction;
      }
    }

    window.cfFormModal({
      title: btn.dataset.title || 'Edit',
      confirmText: btn.dataset.confirmText || 'Save',
      form: form
    });
  });
})();
