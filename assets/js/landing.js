// Landing Finder: live room grid controller handling search filtering, pagination, countdowns, and background polling.
(function () {
  'use strict';

  var results = document.getElementById('roomResults');
  var grid = document.getElementById('roomGrid');
  var form = document.getElementById('finderForm');
  if (!grid || !form) { return; }
  var currentPage = parseInt(grid.dataset.page, 10) || 1;
  var isLoadingMore = false;

  var searchBox   = document.getElementById('searchBox');
  var resultCount = document.getElementById('resultCount');
  var updatedAt   = document.getElementById('updatedAt');
  var refreshSecs = parseInt(grid.dataset.refresh, 10) || 15;

  var chipButtons = form.querySelectorAll('.chip-btn');
  var selects     = form.querySelectorAll('select, input[type="number"]');
  var activeChip  = form.querySelector('.chip-btn.is-active');
  var currentStatus = activeChip ? (activeChip.dataset.status || '') : '';
  var activeRequestId = 0;

  function params(pageOverride, cumulative) {
    var p = new URLSearchParams();
    if (searchBox.value.trim()) { p.set('q', searchBox.value.trim()); }
    if (currentStatus)         { p.set('status', currentStatus); }
    Array.prototype.forEach.call(selects, function (el) {
      if (el.name && el.value !== '' && el.type !== 'number') { p.set(el.name, el.value); }
      if (el.type === 'number' && el.value !== '')            { p.set(el.name, el.value); }
    });
    p.set('page', String(pageOverride || currentPage));
    if (cumulative) { p.set('cumulative', '1'); }
    return p;
  }

  function humanRel(isoLocal) {
    if (!isoLocal) { return null; }
    var parsed = Date.parse(isoLocal);
    if (isNaN(parsed)) {
      parsed = Date.parse(isoLocal.replace(' ', 'T'));
    }
    if (isNaN(parsed)) { return null; }
    var diff = parsed - Date.now();
    var m = Math.ceil(diff / 60000);
    if (m <= 0) { return null; }
    if (m < 60) { return 'in ' + m + ' min'; }
    return 'in ' + Math.floor(m / 60) + 'h ' + (m % 60) + 'm';
  }

  function tickCountdowns() {
    var g = document.getElementById('roomGrid') || grid;
    if (!g) { return; }
    var nodes = g.querySelectorAll('[data-free-at]');
    Array.prototype.forEach.call(nodes, function (n) {
      var rel = humanRel(n.dataset.freeAt);
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
      line.innerHTML = '<span class="dot dot--ok"></span>' + stats.available + ' available · ' +
        '<span class="dot dot--danger"></span>' + stats.occupied + ' occupied · ' +
        '<span class="dot dot--off"></span>' + stats.unavailable + ' unavailable';
    }
  }

  function refresh(isBackgroundPoll) {
    var reqId = ++activeRequestId;
    var qs = params(currentPage, isBackgroundPoll ? true : false);
    var cleanQs = new URLSearchParams(qs);
    cleanQs.delete('cumulative');
    qs.set('with_html', '1');

    var baseEndpoint = grid.dataset.endpoint || 'api/classroom_status.php';
    var sep = baseEndpoint.indexOf('?') === -1 ? '?' : '&';
    var fetchUrl = baseEndpoint + sep + qs.toString();

    fetch(fetchUrl, {
      headers: { 'X-Requested-With': 'fetch' }
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        if (reqId !== activeRequestId) { return; }
        if (!data.ok || !data.html) { return; }

        if (window.history && window.history.replaceState) {
          var newUrl = window.location.pathname + (cleanQs.toString() ? '?' + cleanQs.toString() : '');
          window.history.replaceState(null, '', newUrl);
        }

        if (results) {
          results.innerHTML = data.html;
          grid = document.getElementById('roomGrid') || grid;
        } else {
          grid.innerHTML = data.html;
        }
        if (data.page) { currentPage = data.page; }
        resultCount.textContent = data.count;
        applyStats(data.stats);
        var wc = window.cfWallClock ? window.cfWallClock(data.server_now, 0) : null;
        if (wc) { updatedAt.textContent = '· updated ' + wc.hm; }
        tickCountdowns();
      })
      .catch(function () {});
  }

  function loadMore() {
    if (isLoadingMore) { return; }
    var btn = document.getElementById('loadMoreBtn');
    if (!btn) { return; }
    var nextPage = parseInt(btn.dataset.nextPage, 10);
    if (!nextPage || nextPage <= currentPage) { return; }

    isLoadingMore = true;
    btn.classList.add('is-loading');
    var btnText = btn.querySelector('.load-more-text');
    var originalText = btnText ? btnText.textContent : '';
    if (btnText) { btnText.textContent = 'Loading…'; }

    var qs = params(nextPage, false);
    qs.set('with_html', '1');

    var baseEndpoint = grid.dataset.endpoint || 'api/classroom_status.php';
    var sep = baseEndpoint.indexOf('?') === -1 ? '?' : '&';
    var fetchUrl = baseEndpoint + sep + qs.toString();

    fetch(fetchUrl, {
      headers: { 'X-Requested-With': 'fetch' }
    })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        isLoadingMore = false;
        var b = document.getElementById('loadMoreBtn');
        if (b) {
          b.classList.remove('is-loading');
          var bt = b.querySelector('.load-more-text');
          if (bt) { bt.textContent = originalText; }
        }

        if (!data.ok) { return; }

        currentPage = data.page;
        var curGrid = document.getElementById('roomGrid') || grid;
        if (curGrid) {
          curGrid.dataset.page = String(data.page);
          if (data.cards_html) {
            curGrid.insertAdjacentHTML('beforeend', data.cards_html);
          }
        }

        var pagerWrap = document.getElementById('roomPager') || (results ? results.querySelector('.load-more-wrap') : null);
        if (pagerWrap) {
          pagerWrap.outerHTML = data.pager_html || '';
        } else if (results) {
          results.insertAdjacentHTML('beforeend', data.pager_html || '');
        }

        resultCount.textContent = data.count;
        applyStats(data.stats);
        tickCountdowns();
      })
      .catch(function () {
        isLoadingMore = false;
        var b = document.getElementById('loadMoreBtn');
        if (b) {
          b.classList.remove('is-loading');
          var bt = b.querySelector('.load-more-text');
          if (bt) { bt.textContent = originalText; }
        }
      });
  }

  var timer = null;
  function triggerSearch() {
    clearTimeout(timer);
    currentPage = 1;
    timer = setTimeout(function () { refresh(false); }, 500);
  }
  searchBox.addEventListener('input', triggerSearch);
  searchBox.addEventListener('search', triggerSearch);

  form.addEventListener('submit', function (ev) {
    ev.preventDefault();
    clearTimeout(timer);
    currentPage = 1;
    refresh(false);
    var details = form.querySelector('.finder__more');
    if (details) { details.removeAttribute('open'); }
  });

  Array.prototype.forEach.call(chipButtons, function (btn) {
    btn.addEventListener('click', function () {
      Array.prototype.forEach.call(chipButtons, function (b) { b.classList.remove('is-active'); });
      btn.classList.add('is-active');
      currentStatus = btn.dataset.status;
      currentPage = 1;
      refresh(false);
    });
  });

  Array.prototype.forEach.call(selects, function (el) {
    el.addEventListener('change', function () { currentPage = 1; refresh(false); });
  });

  document.getElementById('clearFilters').addEventListener('click', function () {
    window.setTimeout(function () {
      searchBox.value = '';
      Array.prototype.forEach.call(selects, function (el) { el.value = ''; });
      currentStatus = '';
      Array.prototype.forEach.call(chipButtons, function (b) {
        b.classList.toggle('is-active', b.dataset.status === '');
      });
      currentPage = 1;
      refresh(false);
    }, 0);
  });

  document.addEventListener('click', function (ev) {
    var loadBtn = ev.target.closest('#loadMoreBtn');
    if (loadBtn) {
      ev.preventDefault();
      loadMore();
      return;
    }

    var pageGo = ev.target.closest('[data-page-go]');
    if (pageGo && !pageGo.classList.contains('is-off')) {
      ev.preventDefault();
      var target = parseInt(pageGo.dataset.pageGo, 10);
      if (target) {
        currentPage = target;
        refresh(false);
      }
    }
  });

  setInterval(function () {
    if (!document.hidden && document.activeElement !== searchBox && !isLoadingMore) {
      refresh(true);
    }
  }, refreshSecs * 1000);

  document.addEventListener('visibilitychange', function () {
    if (!document.hidden) { refresh(true); }
  });

  setInterval(tickCountdowns, 30000);
})();
