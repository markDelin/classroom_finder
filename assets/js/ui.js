/**
 * Classroom Finder — shared UI layer (all roles).
 *
 * SweetAlert2 drives every modal, confirmation and alert in the app:
 *   - any form marked data-confirm="…" gets an SWAL confirm before submitting
 *   - server-side flash messages are converted into SWAL modal alerts on load
 *
 * Exposes window.cfToast(type, message) for ad-hoc client-side feedback (uses SweetAlert2 modal alerts).
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

  /* ---------- toast notification factory (Toastify JS) ---------- */
  window.cfToast = function (type, message) {
    if (!message) { return Promise.resolve(); }

    if (window.Toastify) {
      var t = type === 'warn' ? 'warning' : (type || 'info');
      var cls = 'cf-toast cf-toast--' + t;
      var iconSvg = '';
      if (t === 'success') {
        iconSvg = '<svg class="cf-toast__icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="M20 6L9 17l-5-5"/></svg>';
      } else if (t === 'error') {
        iconSvg = '<svg class="cf-toast__icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><path d="m15 9-6 6"/><path d="m9 9 6 6"/></svg>';
      } else if (t === 'warning') {
        iconSvg = '<svg class="cf-toast__icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3Z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>';
      } else {
        iconSvg = '<svg class="cf-toast__icon" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>';
      }

      var div = document.createElement('div');
      div.className = 'cf-toast__content';
      div.style.display = 'inline-flex';
      div.style.alignItems = 'center';
      div.style.gap = '8px';
      div.innerHTML = iconSvg + '<span class="cf-toast__body">' + String(message).replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</span>';

      Toastify({
        node: div,
        duration: 2000,
        gravity: 'top',
        position: 'right',
        className: cls,
        stopOnFocus: true
      }).showToast();

      return Promise.resolve();
    }

    if (window.Swal) {
      return window.Swal.fire({
        icon: type === 'warn' ? 'warning' : (type || 'info'),
        title: message,
        showConfirmButton: false,
        timer: 2000
      });
    }

    alert(message);
    return Promise.resolve();
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
    if (!window.Swal) { return; }
    var f = opts.form;
    var placeholder = document.createElement('div');
    f.parentNode.insertBefore(placeholder, f);

    return window.Swal.fire({
      title: opts.title,
      html: '<div class="swal-form-slot swal2-form-slot"></div>',
      customClass: { popup: 'swal2-form-modal' },
      showCancelButton: true,
      confirmButtonText: opts.confirmText || 'Save',
      cancelButtonText: 'Cancel',
      reverseButtons: true,
      focusConfirm: false,
      allowOutsideClick: function () { return !window.Swal.isLoading(); },
      didOpen: function () {
        var slot = document.querySelector('.swal2-form-slot, .swal-form-slot');
        if (slot) { slot.appendChild(f); }
        f.hidden = false;
        f.style.display = '';
        var onFormSubmit = function (e) {
          e.preventDefault();
          window.Swal.clickConfirm();
        };
        f._cfModalSubmit = onFormSubmit;
        f.addEventListener('submit', onFormSubmit);
        var first = f.querySelector('input:not([type=hidden]):not([type=submit]), select');
        if (first) { first.focus(); }
      },
      preConfirm: function () {
        if (!f.reportValidity()) { return false; }   // native HTML5 validation
        return true;
      },
      willClose: function () {
        if (f._cfModalSubmit) {
          f.removeEventListener('submit', f._cfModalSubmit);
          delete f._cfModalSubmit;
        }
        f.hidden = true;
        f.style.display = 'none';
        if (placeholder.parentNode) {
          placeholder.parentNode.insertBefore(f, placeholder);
          placeholder.remove();
        }
      }
    }).then(function (res) {
      if (res.isConfirmed) {
        f.submit();                                   // full POST as before
        return;
      }
      f.hidden = true;
      f.style.display = 'none';
      if (placeholder.parentNode) {
        placeholder.parentNode.insertBefore(f, placeholder);
        placeholder.remove();
      }
    });
  };

  /* ---------- destructive-action confirmation ---------- */
  document.addEventListener('submit', function (ev) {
    var f = ev.target;
    if (!(f instanceof HTMLFormElement) || !f.dataset.confirm || !window.Swal) { return; }
    ev.preventDefault();

    var msg = f.getAttribute('data-confirm');
    window.Swal.fire({
      icon: 'warning',
      title: 'Are you sure?',
      text: msg,
      showCancelButton: true,
      confirmButtonText: 'Yes, continue',
      cancelButtonText: 'Cancel',
      reverseButtons: true,
      focusCancel: true            // destructive: safest button gets focus
    }).then(function (res) {
      if (!res.isConfirmed) { return; }
      // retire the guard, then let the original submit proceed unchanged
      f.removeAttribute('data-confirm');
      if (f.requestSubmit) { f.requestSubmit(); } else { f.submit(); }
    });
  }, true);

  /* ---------- logout confirmation modal ---------- */
  document.addEventListener('submit', function (ev) {
    var f = ev.target;
    if (!(f instanceof HTMLFormElement)) { return; }
    var action = f.getAttribute('action') || '';
    if (action.indexOf('logout.php') !== -1 || f.hasAttribute('data-logout-confirm')) {
      ev.preventDefault();
      window.Swal.fire({
        icon: 'question',
        title: 'Log Out',
        text: 'Are you sure you want to log out?',
        showCancelButton: true,
        confirmButtonText: 'Log Out',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        focusCancel: true
      }).then(function (res) {
        if (res.isConfirmed) {
          f.submit();
        }
      });
    }
  }, true);

  document.addEventListener('click', function (ev) {
    var a = ev.target.closest('a');
    if (!a) { return; }
    var href = a.getAttribute('href') || '';
    if (href.indexOf('logout.php') !== -1) {
      ev.preventDefault();
      window.Swal.fire({
        icon: 'question',
        title: 'Log Out',
        text: 'Are you sure you want to log out?',
        showCancelButton: true,
        confirmButtonText: 'Log Out',
        cancelButtonText: 'Cancel',
        reverseButtons: true,
        focusCancel: true
      }).then(function (res) {
        if (res.isConfirmed) {
          window.location.href = a.href;
        }
      });
    }
  });

  /* ---------- search & filter row enhancements ---------- */
  var searchTimer = null;
  document.addEventListener('search', function (ev) {
    var inp = ev.target;
    if (inp instanceof HTMLInputElement && inp.type === 'search' && inp.form && inp.form.method.toLowerCase() === 'get' && inp.form.id !== 'finderForm') {
      inp.form.submit();
    }
  });

  document.addEventListener('input', function (ev) {
    var inp = ev.target;
    if (inp instanceof HTMLInputElement && inp.type === 'search' && inp.form && inp.form.method.toLowerCase() === 'get' && inp.form.id !== 'finderForm') {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(function () {
        inp.form.submit();
      }, 500);
    }
  });

  /* ---------- password visibility toggle ---------- */
  /* ---------- exclusive popover & click-outside for details menus ---------- */
  document.addEventListener('click', function (ev) {
    var openDetails = document.querySelectorAll('details.mini-details[open]');
    if (!openDetails.length) return;
    openDetails.forEach(function (det) {
      if (!det.contains(ev.target)) {
        det.removeAttribute('open');
        var tr = det.closest('tr');
        if (tr) tr.classList.remove('has-open-details');
        var td = det.closest('td');
        if (td) td.classList.remove('has-open-details');
        var tw = det.closest('.table-wrap');
        if (tw) tw.classList.remove('has-open-details');
        var ac = det.closest('.actions-cell');
        if (ac) ac.classList.remove('has-open-details');
      }
    });
  });

  document.addEventListener('toggle', function (ev) {
    var det = ev.target;
    if (det && det.matches && det.matches('details.mini-details')) {
      var tr = det.closest('tr');
      var td = det.closest('td');
      var tw = det.closest('.table-wrap');
      var ac = det.closest('.actions-cell');

      if (det.open) {
        document.querySelectorAll('details.mini-details[open]').forEach(function (other) {
          if (other !== det) {
            other.removeAttribute('open');
            var otr = other.closest('tr');
            if (otr) otr.classList.remove('has-open-details');
            var otd = other.closest('td');
            if (otd) otd.classList.remove('has-open-details');
            var otw = other.closest('.table-wrap');
            if (otw) otw.classList.remove('has-open-details');
            var oac = other.closest('.actions-cell');
            if (oac) oac.classList.remove('has-open-details');
          }
        });
        det.classList.add('is-open');
        if (tr) tr.classList.add('has-open-details');
        if (td) td.classList.add('has-open-details');
        if (tw) tw.classList.add('has-open-details');
        if (ac) ac.classList.add('has-open-details');
      } else {
        det.classList.remove('is-open');
        if (tr) tr.classList.remove('has-open-details');
        if (td) td.classList.remove('has-open-details');
        if (tw) tw.classList.remove('has-open-details');
        if (ac) ac.classList.remove('has-open-details');
      }
    }
  }, true);

  document.addEventListener('click', function (ev) {
    var btn = ev.target.closest('.password-toggle-btn');
    if (!btn) return;

    var wrapper = btn.closest('.password-toggle-wrapper') || btn.parentElement;
    var input = wrapper ? wrapper.querySelector('input') : null;
    if (!input) return;

    ev.preventDefault();
    if (input.type === 'password') {
      input.type = 'text';
      btn.classList.add('is-visible');
      btn.setAttribute('aria-label', 'Hide password');
      btn.setAttribute('title', 'Hide password');
    } else {
      input.type = 'password';
      btn.classList.remove('is-visible');
      btn.setAttribute('aria-label', 'Show password');
      btn.setAttribute('title', 'Show password');
    }
  });

  /* ---------- server flashes become SweetAlert2 modal alerts ---------- */
  var wrap = document.querySelector('.flashes');
  if (!wrap) { return; }
  var map = { success: 'success', error: 'error', warn: 'warning', info: 'info' };
  var items = [];
  wrap.querySelectorAll('.flash').forEach(function (el) {
    var icon = 'info';
    Object.keys(map).forEach(function (c) {
      if (el.classList.contains('flash--' + c)) { icon = map[c]; }
    });
    var text = el.textContent.trim();
    if (text) {
      items.push({ type: icon, text: text });
    }
  });
  if (items.length) {
    wrap.hidden = true;
    var chain = Promise.resolve();
    items.forEach(function (it) {
      chain = chain.then(function () {
        return window.cfToast(it.type, it.text);
      });
    });
  }

  /* ---------- PWA Service Worker Registration ---------- */
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      var prefix = document.body.dataset.prefix || '';
      navigator.serviceWorker.register(prefix + 'sw.js').catch(function () {});
    });
  }
})();
