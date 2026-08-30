/**
 * Classroom Finder — public landing page behaviour.
 * - debounced search + instant filters
 * - polls api/classroom_status.php (JSON incl. pre-rendered card HTML)
 * - pauses polling while the tab is hidden
 */
(function () {
  'use strict';

  var results = document.getElementById('roomResults');
  var grid = document.getElementById('roomGrid');
  var form = document.getElementById('finderForm');
  if (!grid || !form) { return; }
  var currentPage = parseInt(grid.dataset.page, 10) || 1;

  var searchBox   = document.getElementById('searchBox');
  var resultCount = document.getElementById('resultCount');
  var updatedAt   = document.getElementById('updatedAt');
  var refreshSecs = parseInt(grid.dataset.refresh, 10) || 15;

  var chipButtons = form.querySelectorAll('.chip-btn');
  var selects     = form.querySelectorAll('select, input[type="number"]');
  var activeChip  = form.querySelector('.chip-btn.is-active');
  var currentStatus = activeChip ? (activeChip.dataset.status || '') : '';
  var activeRequestId = 0;

  function params() {
    var p = new URLSearchParams();
    if (searchBox.value.trim()) { p.set('q', searchBox.value.trim()); }
    if (currentStatus)         { p.set('status', currentStatus); }
    Array.prototype.forEach.call(selects, function (el) {
      if (el.name && el.value !== '' && el.type !== 'number') { p.set(el.name, el.value); }
      if (el.type === 'number' && el.value !== '')            { p.set(el.name, el.value); }
    });
    p.set('page', String(currentPage));
    return p;
  }

  function humanRel(isoLocal) {
    var diff = Date.parse(isoLocal) - Date.now();
    if (isNaN(diff)) { return null; }
    var m = Math.ceil(diff / 60000);
    if (m <= 0) { return null; }
    if (m < 60) { return 'in ' + m + ' min'; }
    return 'in ' + Math.floor(m / 60) + 'h ' + (m % 60) + 'm';
  }

  function tickCountdowns() {
    var nodes = grid.querySelectorAll('[data-free-at]');
    Array.prototype.forEach.call(nodes, function (n) {
      var rel = humanRel(n.dataset.freeAt.replace(' ', 'T'));
      if (rel) {
        var wasFree = n.textContent.indexOf('Free') === 0;
        n.textContent = (wasFree ? 'Free ' : 'Starts ') + rel;
      }
    });
  }

  function applyStats(stats) {
    if (!stats) { return; }
    var line = form.parentNode.querySelector('.results-line .muted');
    if (line) {
      // mirrors index.php's stat-dots markup
      line.innerHTML = '<span class="dot dot--ok"></span>' + stats.available + ' available · ' +
        '<span class="dot dot--danger"></span>' + stats.occupied + ' occupied · ' +
        '<span class="dot dot--warn"></span>' + stats.reserved + ' reserved · ' +
        '<span class="dot dot--off"></span>' + stats.unavailable + ' unavailable';
    }
  }

  function refresh() {
    var reqId = ++activeRequestId;
    var qs = params();
    var cleanQs = new URLSearchParams(qs);
    qs.set('with_html', '1');

    var baseEndpoint = grid.dataset.endpoint || 'api/classroom_status.php';
    var sep = baseEndpoint.indexOf('?') === -1 ? '?' : '&';
    var fetchUrl = baseEndpoint + sep + qs.toString();

    fetch(fetchUrl, {
      headers: { 'X-Requested-With': 'fetch' }
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (reqId !== activeRequestId) { return; } // ignore stale response from older search
        if (!data.ok || !data.html) { return; }

        if (window.history && window.history.replaceState) {
          var newUrl = window.location.pathname + (cleanQs.toString() ? '?' + cleanQs.toString() : '');
          window.history.replaceState(null, '', newUrl);
        }

        if (results) {
          results.innerHTML = data.html;           // cards + pager
          grid = document.getElementById('roomGrid') || grid;
        } else {
          grid.innerHTML = data.html;
        }
        if (data.page) { currentPage = data.page; } // server clamps out-of-range pages
        resultCount.textContent = data.count;
        applyStats(data.stats);
        // server wall clock — not the device's — so the label matches the
        // session times shown on the cards regardless of timezone
        var wc = window.cfWallClock ? window.cfWallClock(data.server_now, 0) : null;
        if (wc) { updatedAt.textContent = '· updated ' + wc.hm; }
        tickCountdowns();
      })
      .catch(function () { /* transient network hiccup — next tick retries */ });
  }

  // search box: debounce & clear event handling
  var timer = null;
  function triggerSearch() {
    clearTimeout(timer);
    currentPage = 1;
    timer = setTimeout(refresh, 500);
  }
  searchBox.addEventListener('input', triggerSearch);
  searchBox.addEventListener('search', triggerSearch);

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    clearTimeout(timer);
    currentPage = 1;
    refresh();
    var details = form.querySelector('.finder__more');
    if (details) { details.removeAttribute('open'); }
  });

  // chips
  Array.prototype.forEach.call(chipButtons, function (btn) {
    btn.addEventListener('click', function () {
      Array.prototype.forEach.call(chipButtons, function (b) { b.classList.remove('is-active'); });
      btn.classList.add('is-active');
      currentStatus = btn.dataset.status;
      currentPage = 1;
      refresh();
    });
  });

  // selects + capacity
  Array.prototype.forEach.call(selects, function (el) {
    el.addEventListener('change', function () { currentPage = 1; refresh(); });
  });

  // clear button restores defaults then refreshes
  document.getElementById('clearFilters').addEventListener('click', function () {
    window.setTimeout(function () {
      searchBox.value = '';
      Array.prototype.forEach.call(selects, function (el) { el.value = ''; });
      currentStatus = '';
      Array.prototype.forEach.call(chipButtons, function (b) {
        b.classList.toggle('is-active', b.dataset.status === '');
      });
      currentPage = 1;
      refresh();
    }, 0);
  });

  // Prev / Next — delegated because polling re-renders the controls
  (results || grid).addEventListener('click', function (ev) {
    var btn = ev.target.closest('[data-page-go]');
    if (!btn || btn.classList.contains('is-off')) { return; }
    ev.preventDefault();
    var target = parseInt(btn.dataset.pageGo, 10);
    if (!target || target === currentPage) { return; }
    currentPage = target;
    refresh();
    var g = document.getElementById('roomGrid');
    if (g && g.scrollIntoView) { g.scrollIntoView({ behavior: 'smooth', block: 'start' }); }
  });

  // auto-refresh, paused while hidden or while user is focused on search box
  setInterval(function () {
    if (!document.hidden && document.activeElement !== searchBox) {
      refresh();
    }
  }, refreshSecs * 1000);
  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) { refresh(); }
  });

  setInterval(tickCountdowns, 30000);
})();
