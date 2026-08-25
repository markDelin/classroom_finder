/**
 * Classroom Finder — admin add/edit modals.
 *
 * Any element with data-modal-form="#formId" opens that (server-rendered,
 * hidden) form inside a SweetAlert2 modal:
 *   - data-title="Add a classroom"   modal heading
 *   - data-confirm-text="Save"       confirm button label
 *   - data-prefill='{"name":"201"}'  fills named fields before opening (edit)
 *   - without data-prefill           resets the form and restores its
 *                                    data-default-action (add)
 */
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
