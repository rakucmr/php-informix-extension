(function () {
  'use strict';

  function $(selector, root) {
    return (root || document).querySelector(selector);
  }

  function $all(selector, root) {
    return Array.prototype.slice.call((root || document).querySelectorAll(selector));
  }

  // Mobile navigation -----------------------------------------------------
  var menuButton = $('[data-menu-toggle]');
  var nav = $('[data-nav]');
  if (menuButton && nav) {
    menuButton.addEventListener('click', function () {
      var open = nav.classList.toggle('open');
      menuButton.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    $all('a', nav).forEach(function (link) {
      link.addEventListener('click', function () {
        nav.classList.remove('open');
        menuButton.setAttribute('aria-expanded', 'false');
      });
    });
  }

  // Copy buttons ----------------------------------------------------------
  function fallbackCopy(text) {
    var area = document.createElement('textarea');
    area.value = text;
    area.setAttribute('readonly', 'readonly');
    area.style.position = 'fixed';
    area.style.opacity = '0';
    document.body.appendChild(area);
    area.select();
    try {
      document.execCommand('copy');
    } catch (err) {
      /* ignore */
    }
    document.body.removeChild(area);
  }

  $all('[data-copy]').forEach(function (button) {
    button.addEventListener('click', function () {
      var box = button.closest('.code');
      var code = box ? $('pre code', box) : null;
      if (!code) return;
      var text = code.textContent;
      var done = function () {
        var original = button.textContent;
        button.textContent = button.getAttribute('data-copied') || 'Copied';
        window.setTimeout(function () { button.textContent = original; }, 1400);
      };
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(done, function () {
          fallbackCopy(text);
          done();
        });
      } else {
        fallbackCopy(text);
        done();
      }
    });
  });

  // Code tabs -------------------------------------------------------------
  $all('[data-code-tabs]').forEach(function (box) {
    var buttons = $all('[data-tab]', box);
    var panels = $all('[data-panel]', box);
    buttons.forEach(function (button) {
      button.addEventListener('click', function () {
        var id = button.getAttribute('data-tab');
        buttons.forEach(function (b) {
          var active = b === button;
          b.classList.toggle('active', active);
          b.setAttribute('aria-selected', active ? 'true' : 'false');
        });
        panels.forEach(function (panel) {
          panel.classList.toggle('active', panel.getAttribute('data-panel') === id);
        });
      });
    });
  });

  // Documentation-side filter --------------------------------------------
  var sideFilter = $('[data-doc-filter]');
  if (sideFilter) {
    var sideLinks = $all('.docs-side nav:not(.module-switch) a');
    sideFilter.addEventListener('input', function () {
      var q = sideFilter.value.trim().toLowerCase();
      sideLinks.forEach(function (link) {
        link.style.display = !q || link.textContent.toLowerCase().indexOf(q) !== -1 ? '' : 'none';
      });
    });
  }

  // Search: filter sections on documentation pages -----------------------
  var sections = $all('.docs-main section');
  var searchForm = $('[data-search-form]');
  var searchInput = searchForm ? $('input[name="q"]', searchForm) : null;

  function filterSections(query) {
    if (!sections.length) return;
    var q = (query || '').trim().toLowerCase();
    var visible = 0;
    sections.forEach(function (section) {
      var match = !q || section.textContent.toLowerCase().indexOf(q) !== -1;
      section.style.display = match ? '' : 'none';
      if (match) visible += 1;
    });
    $all('.docs-side nav a').forEach(function (link) {
      var id = (link.getAttribute('href') || '').replace('#', '');
      var section = id ? document.getElementById(id) : null;
      link.style.display = !q || (section && section.style.display !== 'none') ? '' : 'none';
    });
    var notice = $('[data-search-empty]');
    if (!visible && q) {
      if (!notice) {
        notice = document.createElement('p');
        notice.setAttribute('data-search-empty', '');
        notice.className = 'notice info';
        notice.textContent = 'No section matches this search. Try a shorter term.';
        var main = $('.docs-main');
        if (main) main.insertBefore(notice, main.firstChild);
      }
      notice.style.display = '';
    } else if (notice) {
      notice.style.display = 'none';
    }
  }

  if (searchForm && searchInput) {
    var params = new URLSearchParams(window.location.search);
    if (params.get('q')) {
      searchInput.value = params.get('q');
      if (sections.length) filterSections(params.get('q'));
    }
    searchForm.addEventListener('submit', function (event) {
      if (!sections.length) return; // let the browser navigate to the docs page
      event.preventDefault();
      var q = searchInput.value.trim();
      filterSections(q);
      var url = new URL(window.location.href);
      if (q) url.searchParams.set('q', q); else url.searchParams.delete('q');
      window.history.replaceState({}, '', url.toString());
    });
    if (sections.length) {
      var clear = document.createElement('button');
      clear.type = 'button';
      clear.className = 'search-clear';
      clear.textContent = 'Clear';
      clear.style.display = params.get('q') ? '' : 'none';
      clear.addEventListener('click', function () {
        searchInput.value = '';
        filterSections('');
        clear.style.display = 'none';
        var url = new URL(window.location.href);
        url.searchParams.delete('q');
        window.history.replaceState({}, '', url.toString());
      });
      searchForm.appendChild(clear);
      searchInput.addEventListener('input', function () {
        clear.style.display = searchInput.value ? '' : 'none';
      });
    }
  }

  // Scroll spy ------------------------------------------------------------
  var sideNavLinks = $all('.docs-side nav a');
  if (sideNavLinks.length && 'IntersectionObserver' in window) {
    var byId = {};
    sideNavLinks.forEach(function (link) {
      byId[(link.getAttribute('href') || '').replace('#', '')] = link;
    });
    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;
        sideNavLinks.forEach(function (link) { link.classList.remove('active'); });
        var link = byId[entry.target.id];
        if (link) link.classList.add('active');
      });
    }, { rootMargin: '-90px 0px -70% 0px', threshold: 0 });
    sections.forEach(function (section) { observer.observe(section); });
  }
})();
