(function () {
  function closeNav() {
    document.body.classList.remove('iqp-nav-open');
    var side = document.getElementById('mobileSidebar') || document.getElementById('adminSidebar');
    var overlay = document.getElementById('sidebarOverlay');
    if (side) side.classList.add('-translate-x-full');
    if (overlay) overlay.classList.add('hidden');
  }
  function openNav() {
    document.body.classList.add('iqp-nav-open');
    var side = document.getElementById('mobileSidebar') || document.getElementById('adminSidebar');
    var overlay = document.getElementById('sidebarOverlay');
    if (side) side.classList.remove('-translate-x-full');
    if (overlay) overlay.classList.remove('hidden');
  }
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!(t instanceof Element)) return;
    if (t.closest('[data-iqp-open]')) { e.preventDefault(); openNav(); }
    if (t.closest('[data-iqp-close]') || t.id === 'sidebarOverlay') { closeNav(); }
  });

  // Topbar avatar/account dropdown (logout menu)
  document.querySelectorAll('[data-iqp-usermenu]').forEach(function (pill) {
    pill.addEventListener('click', function (e) {
      var t = e.target;
      if (t instanceof Element && t.closest('[data-usermenu-panel]')) return;
      e.stopPropagation();
      var btn = pill.querySelector('.iqp-topbar-profile');
      var wasOpen = pill.classList.contains('is-open');
      document.querySelectorAll('[data-iqp-usermenu].is-open').forEach(function (p) {
        p.classList.remove('is-open');
        var b = p.querySelector('.iqp-topbar-profile');
        if (b) b.setAttribute('aria-expanded', 'false');
      });
      if (!wasOpen) {
        pill.classList.add('is-open');
        if (btn) btn.setAttribute('aria-expanded', 'true');
      }
    });
  });
  document.addEventListener('click', function (e) {
    var t = e.target;
    if (t instanceof Element && t.closest('[data-iqp-usermenu]')) return;
    document.querySelectorAll('[data-iqp-usermenu].is-open').forEach(function (p) {
      p.classList.remove('is-open');
      var b = p.querySelector('.iqp-topbar-profile');
      if (b) b.setAttribute('aria-expanded', 'false');
    });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') {
      document.querySelectorAll('[data-iqp-usermenu].is-open').forEach(function (p) {
        p.classList.remove('is-open');
        var b = p.querySelector('.iqp-topbar-profile');
        if (b) b.setAttribute('aria-expanded', 'false');
      });
    }
  });

  document.querySelectorAll('.iqp-tab-nav__select').forEach(function (sel) {
    sel.addEventListener('change', function () {
      var url = sel.value;
      if (url && url !== '#') window.location.href = url;
    });
  });

  /** Inject mobile dropdown for dense admin tab / section navs (4+ items). */
  function initAdminMobileNavSelects() {
    var mq = window.matchMedia('(max-width: 1023px)');

    function buildSelect(labelText, options, activeValue, onChange) {
      var wrap = document.createElement('label');
      wrap.className = 'iqp-tab-nav__select-wrap';

      var label = document.createElement('span');
      label.className = 'iqp-tab-nav__select-label';
      label.textContent = labelText;

      var field = document.createElement('div');
      field.className = 'iqp-tab-nav__select-field';

      var sel = document.createElement('select');
      sel.className = 'iqp-tab-nav__select';
      sel.setAttribute('aria-label', labelText);

      options.forEach(function (opt) {
        var o = document.createElement('option');
        o.value = opt.value;
        o.textContent = opt.label;
        if (opt.value === activeValue) o.selected = true;
        sel.appendChild(o);
      });

      var chev = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
      chev.setAttribute('class', 'iqp-tab-nav__select-chevron');
      chev.setAttribute('width', '16');
      chev.setAttribute('height', '16');
      chev.setAttribute('viewBox', '0 0 24 24');
      chev.setAttribute('fill', 'none');
      chev.setAttribute('stroke', 'currentColor');
      chev.setAttribute('stroke-width', '2');
      var path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
      path.setAttribute('d', 'm6 9 6 6 6-6');
      chev.appendChild(path);

      field.appendChild(sel);
      field.appendChild(chev);

      var active = document.createElement('span');
      active.className = 'iqp-tab-nav__select-active';
      active.style.display = 'none';
      var activeOpt = options.find(function (o) { return o.value === activeValue; });
      active.textContent = activeOpt ? activeOpt.label : '';

      sel.addEventListener('change', function () {
        onChange(sel.value, sel);
      });

      wrap.appendChild(label);
      wrap.appendChild(field);
      return wrap;
    }

    function apply() {
      var isMobile = mq.matches;

      document.querySelectorAll('nav.iqp-tab-nav').forEach(function (nav) {
        if (nav.querySelector('.iqp-tab-nav__select-wrap:not([data-js-mobile])')) return;

        var existing = nav.querySelector('[data-js-mobile="tabs"]');
        var grid = nav.querySelector('[data-tabset]');
        if (!grid) {
          if (existing) existing.remove();
          nav.classList.remove('iqp-tab-nav--has-mobile-select');
          return;
        }

        var tabs = Array.prototype.slice.call(grid.querySelectorAll('[data-tab]'));
        if (!isMobile || tabs.length < 4) {
          if (existing) existing.remove();
          nav.classList.remove('iqp-tab-nav--has-mobile-select');
          return;
        }

        if (existing) return;

        var activeTab = grid.querySelector('[data-tab].is-active') || tabs[0];
        var activeId = activeTab ? activeTab.getAttribute('data-tab') : '';
        var aria = nav.getAttribute('aria-label') || 'Section';
        var options = tabs.map(function (tab) {
          return {
            value: tab.getAttribute('data-tab') || '',
            label: (tab.textContent || '').trim()
          };
        });

        var selectWrap = buildSelect(aria, options, activeId, function (value) {
          tabs.forEach(function (tab) {
            if (tab.getAttribute('data-tab') === value) tab.click();
          });
        });
        selectWrap.setAttribute('data-js-mobile', 'tabs');
        nav.classList.add('iqp-tab-nav--has-mobile-select');
        nav.insertBefore(selectWrap, grid);
      });

      document.querySelectorAll('[data-innernav].iqp-section-nav__grid').forEach(function (nav) {
        var host = nav.closest('.card') || nav.parentElement;
        if (!host) return;

        var existing = host.querySelector('[data-js-mobile="inner"]');
        var links = Array.prototype.slice.call(nav.querySelectorAll('[data-inner]'));
        if (!isMobile || links.length < 6) {
          if (existing) existing.remove();
          host.classList.remove('iqp-tab-nav--has-inner-select');
          return;
        }

        if (existing) return;

        var activeLink = nav.querySelector('[data-inner].is-active') || links[0];
        var activeId = activeLink ? activeLink.getAttribute('data-inner') : '';
        var options = links.map(function (link) {
          return {
            value: link.getAttribute('data-inner') || '',
            label: (link.textContent || '').trim()
          };
        });

        var selectWrap = buildSelect('Section', options, activeId, function (value) {
          links.forEach(function (link) {
            if (link.getAttribute('data-inner') === value) link.click();
          });
        });
        selectWrap.setAttribute('data-js-mobile', 'inner');

        var wrapper = document.createElement('div');
        wrapper.className = 'iqp-tab-nav iqp-tab-nav--admin';
        wrapper.style.marginBottom = '12px';
        wrapper.appendChild(selectWrap);
        host.classList.add('iqp-tab-nav--has-inner-select');
        host.insertBefore(wrapper, nav.closest('.card__body') || nav);
      });

      document.querySelectorAll('nav.iqp-section-nav__grid:not([data-innernav])').forEach(function (nav) {
        var host = nav.closest('.card') || nav.parentElement;
        if (!host) return;

        var existing = host.querySelector('[data-js-mobile="section"]');
        var links = Array.prototype.slice.call(nav.querySelectorAll('a[href]'));
        if (!isMobile || links.length < 6) {
          if (existing) existing.remove();
          host.classList.remove('iqp-tab-nav--has-inner-select');
          return;
        }

        if (existing) return;

        var activeLink = nav.querySelector('a.is-active') || links[0];
        var activeHref = activeLink ? activeLink.getAttribute('href') : '';
        var options = links.map(function (link) {
          return {
            value: link.getAttribute('href') || '',
            label: (link.textContent || '').trim()
          };
        });

        var selectWrap = buildSelect('Section', options, activeHref, function (value) {
          if (value && value !== '#') window.location.href = value;
        });
        selectWrap.setAttribute('data-js-mobile', 'section');

        var wrapper = document.createElement('div');
        wrapper.className = 'iqp-tab-nav iqp-tab-nav--admin';
        wrapper.style.marginBottom = '12px';
        wrapper.appendChild(selectWrap);
        host.classList.add('iqp-tab-nav--has-inner-select');
        host.insertBefore(wrapper, nav.closest('.card__body') || nav);
      });
    }

    apply();
    if (mq.addEventListener) mq.addEventListener('change', apply);
    else mq.addListener(apply);
  }

  initAdminMobileNavSelects();
  initAdminMobileTableCards();
  initAdminBulkSelection();

  /** Checkbox bulk selection bar (businesses table). */
  function initAdminBulkSelection() {
    var wrap = document.querySelector('[data-bulk-select="businesses"]');
    if (!wrap) return;

    var bar = document.getElementById('bizBulkBar');
    var countEl = document.getElementById('bizBulkCount');
    var form = document.getElementById('bizBulkForm');
    var actionInput = document.getElementById('bizBulkAction');
    var idsWrap = document.getElementById('bizBulkIds');
    var checkAll = wrap.querySelector('.iqp-row-check-all');
    var clearBtn = document.getElementById('bizBulkClear');
    if (!bar || !countEl || !form || !actionInput || !idsWrap) return;

    function rowChecks() {
      return Array.prototype.slice.call(wrap.querySelectorAll('.iqp-row-check'));
    }

    function selectedChecks() {
      return rowChecks().filter(function (cb) { return cb.checked; });
    }

    function syncBar() {
      var selected = selectedChecks();
      var n = selected.length;
      countEl.textContent = String(n);
      bar.hidden = n === 0;
      if (checkAll) {
        var total = rowChecks().length;
        checkAll.checked = total > 0 && n === total;
        checkAll.indeterminate = n > 0 && n < total;
      }
    }

    function clearSelection() {
      rowChecks().forEach(function (cb) { cb.checked = false; });
      if (checkAll) {
        checkAll.checked = false;
        checkAll.indeterminate = false;
      }
      syncBar();
    }

    function submitBulk(action) {
      var selected = selectedChecks();
      if (!selected.length) return;
      var labels = { activate: 'activate', suspend: 'suspend', delete: 'permanently delete' };
      var verb = labels[action] || action;
      var msg = action === 'delete'
        ? 'Permanently delete ' + selected.length + ' business' + (selected.length === 1 ? '' : 'es') + '? This cannot be undone.'
        : 'Are you sure you want to ' + verb + ' ' + selected.length + ' business' + (selected.length === 1 ? '' : 'es') + '?';
      if (!window.confirm(msg)) return;

      idsWrap.innerHTML = '';
      selected.forEach(function (cb) {
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = 'biz_ids[]';
        input.value = cb.value;
        idsWrap.appendChild(input);
      });
      actionInput.value = action;
      form.submit();
    }

    wrap.addEventListener('change', function (e) {
      var t = e.target;
      if (!(t instanceof HTMLInputElement)) return;
      if (t.classList.contains('iqp-row-check-all')) {
        rowChecks().forEach(function (cb) { cb.checked = t.checked; });
        t.indeterminate = false;
      }
      if (t.classList.contains('iqp-row-check') || t.classList.contains('iqp-row-check-all')) {
        syncBar();
      }
    });

    document.querySelectorAll('[data-biz-bulk]').forEach(function (btn) {
      btn.addEventListener('click', function () {
        submitBulk(btn.getAttribute('data-biz-bulk') || '');
      });
    });

    if (clearBtn) clearBtn.addEventListener('click', clearSelection);
    syncBar();
  }

  /** Convert admin data tables to 2-column mobile cards. */
  function initAdminMobileTableCards() {
    var mq = window.matchMedia('(max-width: 767px)');
    var hideCol = /billing cycle|next billing|mrr|amount|payment method|joined on|whatsapp|usage|items|created|date added|added by|channels|audience|priority|last message|gateway|placed|transaction|invoice|dir|from\/to|message|time|member|actions|industry|email|phone|website|activity|bot|score|handled|source|country|city|zip|address|notes|description|updated|expires|trial|renewal|limit|percent|growth|rev|revenue|orders|leads|bots|messages sent|last login|2fa|permissions|dir/i;

    function apply() {
      var isMobile = mq.matches;
      document.querySelectorAll('.page .table-wrap').forEach(function (wrap) {
        var table = wrap.querySelector('table.tbl');
        if (!table || wrap.classList.contains('tbl-perm-matrix') || wrap.classList.contains('tbl-no-mobile-cards')) {
          return;
        }

        var headers = [];
        table.querySelectorAll('thead th').forEach(function (th, i) {
          headers[i] = (th.textContent || '').replace(/\s+/g, ' ').trim();
        });

        table.querySelectorAll('tbody tr').forEach(function (tr) {
          Array.prototype.forEach.call(tr.cells, function (td, i) {
            td.classList.remove('tbl-col-mobile-hide');
            if (headers[i]) td.setAttribute('data-label', headers[i]);
            var isCheckbox = td.querySelector(':scope > input[type="checkbox"]') && !td.querySelector('.cell-media');
            var isPrimary = td.querySelector('.cell-media') || i === 0;
            var isActions = i === tr.cells.length - 1 && (td.querySelector('.row-menu-wrap') || td.querySelector('.row-actions') || td.querySelector('.icon-btn'));
            var isBadgeCol = td.querySelector('.badge') && !td.querySelector('.cell-media');
            if (isMobile && !isCheckbox && !isPrimary && !isActions && !(isBadgeCol && i <= 3)) {
              if (hideCol.test(headers[i] || '') || i >= 4) {
                td.classList.add('tbl-col-mobile-hide');
              }
            }
          });
        });

        if (isMobile) wrap.classList.add('tbl-mobile-cards');
        else wrap.classList.remove('tbl-mobile-cards');
      });

      document.querySelectorAll('.table-wrap.tbl-perm-matrix .tbl tbody tr').forEach(function (tr) {
        var headers = [];
        var table = tr.closest('table');
        if (!table) return;
        table.querySelectorAll('thead th').forEach(function (th, i) {
          headers[i] = (th.textContent || '').trim();
        });
        Array.prototype.forEach.call(tr.cells, function (td, i) {
          if (i > 0 && headers[i]) td.setAttribute('data-label', headers[i]);
        });
      });
    }

    apply();
    if (mq.addEventListener) mq.addEventListener('change', apply);
    else mq.addListener(apply);
  }

  window.iqpToggleRowMenu = function (btn) {
    var menu = btn && btn.nextElementSibling;
    if (!menu || !menu.classList.contains('row-menu')) return;
    document.querySelectorAll('.row-menu.visible').forEach(function (m) {
      if (m !== menu) m.classList.remove('visible');
    });
    menu.classList.toggle('visible');
  };

  document.addEventListener('click', function (e) {
    var t = e.target;
    if (!(t instanceof Element)) return;
    if (t.closest('[data-row-menu-toggle]') || t.closest('.row-menu')) return;
    document.querySelectorAll('.row-menu.visible').forEach(function (m) {
      m.classList.remove('visible');
    });
  });

  // Tabs — works for both .tabs[data-tabset] and .pill-tabs[data-tabset] wrappers
  document.querySelectorAll('[data-tabset]').forEach(function (set) {
    set.querySelectorAll('[data-tab]').forEach(function (tab) {
      tab.addEventListener('click', function (e) {
        e.preventDefault();
        var target = tab.getAttribute('data-tab');
        var scope  = set.getAttribute('data-tabset');
        // deactivate all tabs in this tabset
        set.querySelectorAll('[data-tab]').forEach(function (x) { x.classList.remove('is-active'); });
        tab.classList.add('is-active');
        // activate matching panel — look in the closest [data-panelset=scope]
        var panelWrap = document.querySelector('[data-panelset="' + scope + '"]');
        if (panelWrap) {
          panelWrap.querySelectorAll('.tab-panel').forEach(function (p) {
            p.classList.toggle('is-active', p.getAttribute('data-panel') === target);
          });
        } else {
          // fallback: breadth-first scan
          document.querySelectorAll('[data-panelset="' + scope + '"] .tab-panel').forEach(function (p) {
            p.classList.toggle('is-active', p.getAttribute('data-panel') === target);
          });
        }
      });
    });
  });

  document.querySelectorAll('.inner-nav').forEach(function (navEl) {
    navEl.querySelectorAll('[data-inner]').forEach(function (link) {
      link.addEventListener('click', function (e) {
        e.preventDefault();
        var panel = link.getAttribute('data-inner');
        navEl.querySelectorAll('[data-inner]').forEach(function (x) { x.classList.remove('is-active'); });
        link.classList.add('is-active');
        var scope = navEl.getAttribute('data-innernav');
        document.querySelectorAll('[data-innerpanels="' + scope + '"] > .tab-panel').forEach(function (p) {
          p.classList.toggle('is-active', p.getAttribute('data-panel') === panel);
        });
      });
    });
  });

  document.querySelectorAll('.seg').forEach(function (seg) {
    var set = seg.getAttribute('data-segset');
    seg.querySelectorAll('button').forEach(function (b) {
      b.addEventListener('click', function () {
        seg.querySelectorAll('button').forEach(function (x) { x.classList.remove('is-active'); });
        b.classList.add('is-active');
        var value = b.getAttribute('data-seg') || (b.textContent || '').trim();
        if (set) {
          document.querySelectorAll('[data-segpanels="' + set + '"] > .tab-panel').forEach(function (p) {
            p.classList.toggle('is-active', p.getAttribute('data-panel') === value);
          });
        }
        document.dispatchEvent(new CustomEvent('iqp:seg', { detail: { set: set, value: value, button: b } }));
      });
    });
  });
})();
