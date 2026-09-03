/**
 * Classroom Finder — lecturer QR scanner.
 *
 * Flow: camera decode (html5-qrcode) -> POST token to api/scan_qr.php
 *       -> SweetAlert2 confirmation with duration picker -> native form
 *       submit to occupy.php (which re-validates everything server-side).
 */
(function () {
  'use strict';

  var CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  var readerEl = document.getElementById('reader');
  if (!readerEl || !window.Swal) { return; }

  /* .reader-wrap — gets .is-live while the camera runs so the scan beam
   * only sweeps over a real feed, not the idle placeholder. */
  var wrapEl = readerEl.closest('.reader-wrap');

  var startBtn = document.getElementById('startBtn');
  var stopBtn  = document.getElementById('stopBtn');
  var torchBox = document.getElementById('torchToggle');
  var torchLbl = document.querySelector('.torch-toggle');
  var statusEl = document.getElementById('scanStatus');

  var occupyForm = document.getElementById('occupyForm');
  var fToken     = document.getElementById('fToken');
  var fMinutes   = document.getElementById('fMinutes');
  var manualForm = document.getElementById('manualForm');

  var scanner     = null;
  var limits      = { min: 15, max: 480, step: 30 };

  /* Sound effects for scanner feedback */
  var successAudio = document.getElementById('scanSuccessSound') || new Audio('../assets/sound/success.mp3');
  var errorAudio   = document.getElementById('scanErrorSound')   || new Audio('../assets/sound/error.mp3');

  function playSound(audio) {
    if (!audio) { return; }
    try {
      audio.currentTime = 0;
      var p = audio.play();
      if (p && typeof p.catch === 'function') {
        p.catch(function () { /* autoplay restriction fallback */ });
      }
    } catch (e) {
      /* ignore audio errors */
    }
  }

  function playSuccess() {
    playSound(successAudio);
  }

  function playError() {
    playSound(errorAudio);
  }

  function unlockAudio() {
    [successAudio, errorAudio].forEach(function (sound) {
      if (sound && typeof sound.load === 'function') {
        sound.load();
      }
    });
  }

  document.addEventListener('click', unlockAudio, { once: true });
  document.addEventListener('touchstart', unlockAudio, { once: true });

  /* scan_qr.php reports {min_minutes, max_minutes, step}; accept that shape
   * (or plain {min,max}) and always fall back to sane numbers — an undefined
   * limit here used to turn every duration into NaN. */
  /**
   * Normalizes duration boundary options with fallback values.
   * @param {Object} raw Raw limits object from scan endpoint
   * @returns {{min: number, max: number, step: number}} Sanitized limits object
   */
  function readLimits(raw) {
    raw = raw || {};
    function num(v, fallback) {
      v = parseInt(v, 10);
      return isFinite(v) && v > 0 ? v : fallback;
    }
    return {
      min:  num(raw.min !== undefined ? raw.min : raw.min_minutes, 15),
      max:  num(raw.max !== undefined ? raw.max : raw.max_minutes, 480),
      step: num(raw.step, 30)
    };
  }
  var minutes     = 60;
  var serverNowIso = '';          // server wall clock at scan time (ISO + offset)
  var lastToken   = null;
  var lastAt      = 0;
  var busy        = false;

  /**
   * Updates scanner status text and error state.
   * @param {string} msg Message to display
   * @param {boolean} [isErr=false] Whether this status represents an error
   */
  function say(msg, isErr) {
    statusEl.textContent = msg;
    statusEl.classList.toggle('scan-status--err', !!isErr);
    if (isErr) {
      readerEl.classList.add('reader--err');
      playError();
    }
  }

  /**
   * Formats raw minutes into a human-readable duration string (e.g. "1h 30m").
   * @param {number} m Number of minutes
   * @returns {string} Formatted duration string
   */
  function humanMins(m) {
    var h = Math.floor(m / 60), r = m % 60;
    if (h && r) { return h + 'h ' + r + 'm'; }
    if (h) { return h + ' hour' + (h > 1 ? 's' : ''); }
    return m + ' minutes';
  }

  /* --- duration picker state, rendered inside the SWAL popup --- */

  function setMinutes(m) {
    if (!isFinite(m)) { m = limits.min; }   // never let NaN into the picker
    minutes = Math.max(limits.min, Math.min(limits.max, m));
    var lbl = document.getElementById('minsLabel');
    if (!lbl) { return; }
    lbl.textContent = humanMins(minutes);
    document.querySelectorAll('#presetRow [data-mins]').forEach(function (b) {
      b.classList.toggle('is-active', parseInt(b.dataset.mins, 10) === minutes);
    });
    previewTimes();
  }

  /* Start/end previews are computed from the SERVER's wall clock
   * (ui.js reads the digits straight out of server_now's ISO string), so the
   * times shown here always match what occupy.php will store — even when the
   * lecturer's device is set to another timezone. */
  function previewTimes() {
    var s = document.getElementById('startTime');
    var e = document.getElementById('endTime');
    if (!s || !e || !serverNowIso || !window.cfWallClock) { return; }
    s.textContent = window.cfWallClock(serverNowIso, 0).time;
    e.textContent = window.cfWallClock(serverNowIso, minutes).time;
  }

  /* --- "scheduled class isn't meeting" -> instant force-open --- */

  function requestForceOpen(data) {
    var num = data.room.room_number;
    var opts = [
      ['', '— choose a reason —'],
      ['lecturer_absent', 'Lecturer is absent'],
      ['emergency', 'Emergency / class suspended'],
      ['ended_early', 'Class ended early'],
      ['other', 'Other reason']
    ].map(function (r) {
      return '<option value="' + r[0] + '">' + r[1] + '</option>';
    }).join('');

    window.Swal.fire({
      title: 'Open room ' + num,
      html:
        '<p class="swal-meta muted">If the scheduled class is not meeting, you can open '
        + 'this room for other lecturers right away.</p>'
        + '<label class="swal-field"><span>Reason</span><select id="foReason">' + opts + '</select></label>'
        + '<label class="swal-field"><span>Details (optional)</span>'
        + '<input id="foDetails" maxlength="160" autocomplete="off" placeholder="e.g. Professor cancelled today"></label>',
      showCancelButton: true,
      confirmButtonText: 'Open room',
      cancelButtonText: 'Cancel',
      reverseButtons: true,
      focusConfirm: false,
      allowOutsideClick: function () { return !window.Swal.isLoading(); },
      preConfirm: function () {
        var reason = document.getElementById('foReason').value;
        if (!reason) {
          window.Swal.showValidationMessage('Please pick a reason.');
          return false;
        }
        return { reason: reason, details: document.getElementById('foDetails').value.trim() };
      }
    }).then(function (res) {
      if (!res.isConfirmed || !res.value) { return; }
      submitForceOpen(data, res.value.reason, res.value.details);
    });
  }

  async function submitForceOpen(data, reason, details) {
    try {
      var resp = await fetch('../api/force_open.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify({ token: data.room.token, reason: reason, details: details })
      });
      var out = await resp.json();
      if (!resp.ok || !out.ok) {
        window.cfToast && window.cfToast('error', out.error || 'Could not open the room.');
        playError();
        return;
      }
      window.cfToast && window.cfToast('success', out.message || 'Room opened.');
      playSuccess();
    } catch (e) {
      window.cfToast && window.cfToast('error', 'Network error while opening the room.');
      playError();
    } finally {
      lastToken = null;   // next scan/manual entry sees the room as available
    }
  }

  function openDialog(data) {
    limits = readLimits(data.limits);
    serverNowIso = data.server_now || '';
    var num = data.room.room_number;

    if (!data.available) {
      playError();
      if (data.fixed_class) {
        // blocked by a fixed weekly class — offer to report it as not meeting
        window.Swal.fire({
          icon: 'warning',
          title: 'Room ' + num + ' has a class scheduled',
          text: data.reason || 'A fixed schedule slot is active in this room.',
          showDenyButton: true,
          confirmButtonText: 'OK',
          denyButtonText: "Class isn't happening",
          allowOutsideClick: false
        }).then(function (r) {
          if (r.isDenied) { requestForceOpen(data); return; }
          lastToken = null;
        });
        return;
      }
      // Display detailed rejection reason when room cannot be occupied
      window.Swal.fire({
        icon: 'warning',
        title: 'Room ' + num + ' is unavailable',
        text: data.reason || 'Please pick another room.',
        confirmButtonText: 'OK'
      }).then(function () { lastToken = null; });
      return;
    }

    minutes = Math.min(60, limits.max);

    var presets = (data.suggestions || []).map(function (s) {
      return '<button type="button" class="btn btn--ghost btn--sm" data-mins="' + parseInt(s.minutes, 10) + '">'
           + String(s.label).replace(/&/g, '&amp;').replace(/</g, '&lt;') + '</button>';
    }).join('');

    var html =
      '<div class="swal-meta">' +
        '<span>' + String(data.room.building).replace(/</g, '&lt;') + '</span>' +
        '<span class="dot-sep">·</span><span>Floor ' + parseInt(data.room.floor, 10) + '</span>' +
        '<span class="dot-sep">·</span><span>' + String(data.room.room_type).replace(/</g, '&lt;') + '</span>' +
        '<span class="dot-sep">·</span><span>' + parseInt(data.room.capacity, 10) + '&nbsp;seats</span>' +
      '</div>' +
      '<div class="availability availability--ok">' +
        '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" width="14" height="14" style="vertical-align:-2px;margin-right:4px;"><circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/></svg>' +
        'Classroom is available.' +
      '</div>' +
      '<p class="question"><strong>How long will you use this classroom?</strong></p>' +
      '<div class="duration-presets" id="presetRow">' + presets + '</div>' +
      '<div class="stepper">' +
        '<button type="button" class="stepper__btn" id="minusBtn" aria-label="Less">−</button>' +
        '<span class="stepper__value" id="minsLabel"></span>' +
        '<button type="button" class="stepper__btn" id="plusBtn" aria-label="More">+</button>' +
      '</div>' +
      '<div class="when">' +
        '<div><small>Starting</small><span id="startTime">—</span></div>' +
        '<div><small>Ending</small><span id="endTime">—</span></div>' +
      '</div>';

    window.Swal.fire({
      title: 'ROOM ' + num,
      html: html,
      showCancelButton: true,
      confirmButtonText: 'Occupy room',
      cancelButtonText: 'Cancel',
      reverseButtons: true,
      focusConfirm: true,
      allowOutsideClick: function () { return !window.Swal.isLoading(); },
      didOpen: function () {
        document.querySelectorAll('#presetRow [data-mins]').forEach(function (b) {
          b.addEventListener('click', function () { setMinutes(parseInt(b.dataset.mins, 10)); });
        });
        document.getElementById('plusBtn').addEventListener('click', function () { setMinutes(minutes + limits.step); });
        document.getElementById('minusBtn').addEventListener('click', function () { setMinutes(minutes - limits.step); });
        setMinutes(minutes);          // paints label + time preview
      },
      preConfirm: function () {
        if (minutes < limits.min || minutes > limits.max) {
          window.Swal.showValidationMessage(
            'Duration must be between ' + limits.min + ' and ' + limits.max + ' minutes.');
          return false;
        }
        return minutes;
      }
    }).then(function (res) {
      if (!res.isConfirmed) { lastToken = null; return; }
      fToken.value = data.room.token;
      fMinutes.value = String(res.value);
      occupyForm.submit();            // occupy.php re-validates and redirects
    });
  }

  async function handlePayload(text) {
    if (busy) { return; }
    busy = true;
    try {
      var match = String(text).match(/[0-9a-f]{32}/i);
      if (!match) {
        say('That is not a Classroom Finder QR code.', true);
        return;
      }
      var token = match[0].toLowerCase();
      var now = Date.now();
      if (token === lastToken && now - lastAt < 3000) { return; }   // same poster re-read
      lastToken = token; lastAt = now;

      say('Checking room…');
      var res = await fetch('../api/scan_qr.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
        body: JSON.stringify({ token: token })
      });
      var data = await res.json();
      if (!res.ok || !data.ok) {
        say(data.error || 'Could not validate this QR code.', true);
        window.cfToast && window.cfToast('error', data.error || 'Could not validate this QR code.');
        return;
      }
      if (data.available) {
        playSuccess();
      }
      say('Room found: ' + data.room.building + ' ' + data.room.room_number);
      openDialog(data);
    } catch (e) {
      say('Network error while validating the QR code.', true);
    } finally {
      busy = false;
    }
  }

  /* Translate getUserMedia failures into guidance a lecturer can act on
   * instead of surfacing raw browser error objects. */
  function cameraErrorMessage(err) {
    var name = (err && err.name) || '';
    if (name === 'NotAllowedError' || name === 'PermissionDeniedError') {
      window.cfToast && window.cfToast('warn', 'Camera permission blocked. Enable camera permissions in your browser address bar.');
      return 'Camera access is blocked. Allow camera permission for this site '
           + '(tap the padlock in the address bar), then press Start again.';
    }
    if (name === 'NotFoundError' || name === 'DevicesNotFoundError') {
      return 'No camera was found on this device. Type the token under the QR poster instead.';
    }
    if (name === 'NotReadableError' || name === 'TrackStartError') {
      return 'The camera seems busy in another app or tab. Close it, then press Start again.';
    }
    if (name === 'OverconstrainedError') {
      return 'This device’s camera isn’t compatible with the scanner. Type the token instead.';
    }
    if (!window.isSecureContext) {
      window.cfToast && window.cfToast('warn', 'Camera requires HTTPS or localhost.');
      return 'Cameras need a secure (https) connection. Open the page over https or type the token instead.';
    }
    return 'Could not start the camera. Check permission and try again, or type the token instead.';
  }

  async function startCamera() {
    try {
      scanner = new Html5Qrcode('reader', { verbose: false });
      await scanner.start(
        { facingMode: 'environment' },
        { fps: 10, qrbox: function (w, h) { var s = Math.min(w, h) * 0.7; return { width: s, height: s }; } },
        function (decodedText) { handlePayload(decodedText); },
        function () { /* per-frame decode misses — ignore */ }
      );
      startBtn.hidden = true;
      stopBtn.hidden = false;
      wrapEl.classList.add('is-live');
      say('Camera on — point it at the QR poster.');
      // torch support?
      try {
        var caps = scanner.getRunningTrackCapabilities();
        if (caps && caps.torch) { torchLbl.hidden = false; }
      } catch (e) { /* not supported */ }
    } catch (err) {
      say(cameraErrorMessage(err), true);
    }
  }

  async function stopCamera() {
    if (!scanner) { return; }
    try { await scanner.stop(); scanner.clear(); } catch (e) { /* already stopped */ }
    scanner = null;
    startBtn.hidden = false;
    stopBtn.hidden = true;
    wrapEl.classList.remove('is-live');
    torchLbl.hidden = true;
    torchBox.checked = false;
    say('Camera stopped.');
  }

  startBtn.addEventListener('click', startCamera);
  stopBtn.addEventListener('click', stopCamera);

  torchBox.addEventListener('change', async function () {
    if (!scanner) { return; }
    try {
      await scanner.applyVideoConstraints({ advanced: [{ torch: torchBox.checked }] });
    } catch (e) { say('Torch is not available on this camera.', true); }
  });

  manualForm.addEventListener('submit', function (ev) {
    ev.preventDefault();
    handlePayload(manualForm.token.value.trim());
    manualForm.token.value = '';
  });
})();
