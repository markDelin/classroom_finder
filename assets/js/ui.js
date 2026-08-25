/**
 * Classroom Finder — shared UI layer (all roles).
 *
 * SweetAlert2 drives every modal, confirmation and toast in the app:
 *   - any form marked data-confirm="…" gets an SWAL confirm before submitting
 *   - server-side flash messages are converted into SWAL toasts on load
 *
 * Exposes window.cfToast(type, message) for ad-hoc client-side feedback.
 */
(function () {
  'use strict';

  /* ---------- burger navigation (mobile drawer) ----------
   * Slides in from the left under the sticky top bar. Runs before the Swal
   * guard so the menu never depends on the modal library. */
  var navToggle = document.querySelector('.nav-toggle');
  if (navToggle) {
    var topbarEl = document.querySelector('.topbar');
    var backdrop = null;

    var syncTop = function () {
      if (topbarEl) {
        document.body.style.setProperty('--cf-nav-top', topbarEl.offsetHeight + 'px');
      }
    };

    var setNav = function (open) {
      document.body.classList.toggle('nav-open', open);
      navToggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open && !backdrop) {
        backdrop = document.createElement('div');
        backdrop.className = 'nav-backdrop';
        backdrop.addEventListener('click', function () { setNav(false); });
        document.body.appendChild(backdrop);
      }
      if (open) { syncTop(); }
    };

    navToggle.addEventListener('click', function () {
      setNav(!document.body.classList.contains('nav-open'));
    });
    // Escape and scrim both close it
    document.addEventListener('keydown', function (ev) {
      if (ev.key === 'Escape') { setNav(false); }
    });
    // choosing a page closes the drawer
    document.querySelectorAll('#cfSidebar a').forEach(function (a) {
      a.addEventListener('click', function () { setNav(false); });
    });
    // keep the drawer below the top bar as things shift
    window.addEventListener('resize', function () {
      syncTop();
      if (window.innerWidth >= 900) { setNav(false); }
    });
    syncTop();
  }

  if (!window.Swal) { return; }

  /* ---------- toast factory ---------- */
  var Toast = window.Swal.mixin({
    toast: true,
    position: 'top-end',
    showConfirmButton: false,
    timer: 4000,
    timerProgressBar: true,
    didOpen: function (t) {
      t.addEventListener('mouseenter', window.Swal.stopTimer);
      t.addEventListener('mouseleave', window.Swal.resumeTimer);
    }
  });

  window.cfToast = function (type, message) {
    Toast.fire({ icon: type, title: message });
  };

  /* ---------- server wall-clock formatting (timezone-safe) ----------
   * Server datetimes are stored in the campus timezone. Parsing them with
   * the browser's local Date shifts the display for off-campus devices, so
   * we read the wall-clock digits straight out of the ISO string instead.
   * cfWallClock('2026-08-24T16:40:56+08:00', 45).time -> '5:25 PM'        */
  window.cfWallClock = function (iso, addMinutes) {
    var m = /^(\d{4})-(\d{2})-(\d{2})[T ](\d{2}):(\d{2}):(\d{2})/.exec(String(iso || ''));
    if (!m) { return null; }
    var d = new Date(Date.UTC(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +m[6]) + (addMinutes || 0) * 60000);
    var p = function (n) { return n < 10 ? '0' + n : String(n); };
    var h = d.getUTCHours(), min = d.getUTCMinutes();
    var ap = h >= 12 ? 'PM' : 'AM';
    h = h % 12; if (h === 0) { h = 12; }
    return {
      time: h + ':' + p(min) + ' ' + ap,
      hm: h + ':' + p(min),
      date: m[1] + '-' + m[2] + '-' + m[3]
    };
  };

  /* ---------- any existing <form> shown inside a SweetAlert2 modal ----------
   * The real form keeps posting to its normal action with CSRF intact;
   * SWAL is only the shell. Used by classroom/user/reservation add+edit.  */
  window.cfFormModal = function (opts) {
    var f = opts.form;
    var placeholder = document.createElement('div');
    f.parentNode.insertBefore(placeholder, f);

    return window.Swal.fire({
      title: opts.title,
      html: '<div class="swal-form-slot"></div>',
      customClass: { popup: 'swal2-form-modal' },
      showCancelButton: true,
      confirmButtonText: opts.confirmText || 'Save',
      cancelButtonText: 'Cancel',
      focusConfirm: false,
      allowOutsideClick: function () { return !window.Swal.isLoading(); },
      didOpen: function () {
        document.querySelector('.swal-form-slot').appendChild(f);
        f.hidden = false;
        var first = f.querySelector('input:not([type=hidden]):not([type=submit]), select');
        if (first) { first.focus(); }
      },
      preConfirm: function () {
        if (!f.reportValidity()) { return false; }   // native HTML5 validation
        return true;
      }
    }).then(function (res) {
      if (res.isConfirmed) {
        f.submit();                                   // full POST as before
        return;
      }
      // put the form back where it came from
      placeholder.parentNode.insertBefore(f, placeholder);
      placeholder.remove();
    });
  };

  /* ---------- destructive-action confirmation ---------- */
  document.addEventListener('submit', function (ev) {
    var f = ev.target;
    if (!(f instanceof HTMLFormElement) || !f.dataset.confirm) { return; }
    ev.preventDefault();

    var msg = f.getAttribute('data-confirm');
    window.Swal.fire({
      icon: 'warning',
      title: 'Are you sure?',
      text: msg,
      showCancelButton: true,
      confirmButtonText: 'Yes, continue',
      cancelButtonText: 'Cancel',
      focusCancel: true            // destructive: safest button gets focus
    }).then(function (res) {
      if (!res.isConfirmed) { return; }
      // retire the guard, then let the original submit proceed unchanged
      f.removeAttribute('data-confirm');
      if (f.requestSubmit) { f.requestSubmit(); } else { f.submit(); }
    });
  }, true);

  /* ---------- server flashes become toasts ---------- */
  var wrap = document.querySelector('.flashes');
  if (!wrap) { return; }
  var map = { success: 'success', error: 'error', warn: 'warning', info: 'info' };
  var items = [];
  wrap.querySelectorAll('.flash').forEach(function (el, i) {
    var icon = 'info';
    Object.keys(map).forEach(function (c) {
      if (el.classList.contains('flash--' + c)) { icon = map[c]; }
    });
    items.push([icon, el.textContent.trim(), i]);
  });
  if (items.length) {
    wrap.hidden = true;
    items.forEach(function (it) {
      window.setTimeout(function () { window.cfToast(it[0], it[1]); }, it[2] * 350);
    });
  }
})();
