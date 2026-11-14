document.addEventListener('DOMContentLoaded', () => {
  const dataEl = document.getElementById('ocData');
  const DATA = dataEl ? JSON.parse(dataEl.textContent || '[]') : [];

  const byId = new Map(DATA.map(d => [d.id, d]));
  const kids = new Map();
  DATA.forEach(d => {
    if (d.mgr !== null) {
      if (!kids.has(d.mgr)) kids.set(d.mgr, []);
      kids.get(d.mgr).push(d.id);
    }
  });

  const $ = id => document.getElementById(id);
  const root = $('oc'),
    viewport = $('ocViewport'),
    canvas = $('ocCanvas');
  if (!root || !viewport || !canvas) return;

  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;'
  }[c]));

  const liMap = new Map(),
    nodeMap = new Map();
  canvas.querySelectorAll('li[data-id]').forEach(l => liMap.set(l.dataset.id, l));
  canvas.querySelectorAll('.oc-node[data-id]').forEach(n => nodeMap.set(n.dataset.id, n));
  const li = id => liMap.get(String(id));
  const nodeEl = id => nodeMap.get(String(id));
  const allLi = () => canvas.querySelectorAll('li');

  /* ---------- ซูม ---------- */
  let zoom = 1;
  const setZoom = z => {
    zoom = Math.min(1.6, Math.max(0.3, Math.round(z * 100) / 100));
    canvas.style.zoom = zoom;
    $('ocZoomLabel').textContent = Math.round(zoom * 100) + '%';
  };
  $('ocZoomIn').onclick = () => setZoom(zoom + 0.1);
  $('ocZoomOut').onclick = () => setZoom(zoom - 0.1);
  $('ocZoomLabel').onclick = () => setZoom(1);
  $('ocFit').onclick = () => {
    setZoom(1);
    const need = canvas.scrollWidth,
      have = viewport.clientWidth - 56;
    setZoom(Math.min(1, have / need));
    viewport.scrollLeft = (viewport.scrollWidth - viewport.clientWidth) / 2;
  };
  viewport.addEventListener('wheel', e => {
    if (!e.ctrlKey) return;
    e.preventDefault();
    setZoom(zoom + (e.deltaY < 0 ? 0.08 : -0.08));
  }, { passive: false });

  /* ---------- ลากเพื่อเลื่อน ---------- */
  let drag = null;
  viewport.addEventListener('pointerdown', e => {
    if (e.button !== 0 || e.target.closest('.oc-node')) return;
    drag = {
      x: e.clientX,
      y: e.clientY,
      l: viewport.scrollLeft,
      t: viewport.scrollTop
    };
    viewport.classList.add('dragging');
    viewport.setPointerCapture(e.pointerId);
  });
  viewport.addEventListener('pointermove', e => {
    if (!drag) return;
    viewport.scrollLeft = drag.l - (e.clientX - drag.x);
    viewport.scrollTop = drag.t - (e.clientY - drag.y);
  });
  const endDrag = () => {
    drag = null;
    viewport.classList.remove('dragging');
  };
  viewport.addEventListener('pointerup', endDrag);
  viewport.addEventListener('pointercancel', endDrag);

  /* ---------- ย่อ / ขยาย ---------- */
  const setCollapsed = (item, collapsed) => {
    item.classList.toggle('collapsed', collapsed);
    const t = item.querySelector(':scope > .oc-node .oc-toggle');
    if (t) t.setAttribute('aria-expanded', String(!collapsed));
  };
  $('ocExpandAll').onclick = () => allLi().forEach(l => setCollapsed(l, false));
  $('ocCollapse2').onclick = () => allLi().forEach(l => setCollapsed(l, Number(l.dataset.depth) >= 1));

  const revealAncestors = id => {
    let p = byId.get(id)?.mgr;
    while (p != null) {
      const l = li(p);
      if (l) setCollapsed(l, false);
      p = byId.get(p)?.mgr;
    }
  };
  const focusNode = id => {
    revealAncestors(id);
    const el = nodeEl(id);
    if (!el) return;
    el.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'center' });
    el.classList.remove('flash');
    void el.offsetWidth;
    el.classList.add('flash');
  };

  canvas.addEventListener('click', e => {
    const t = e.target.closest('.oc-toggle');
    if (t) {
      e.stopPropagation();
      const item = t.closest('li');
      setCollapsed(item, !item.classList.contains('collapsed'));
      return;
    }
    const n = e.target.closest('.oc-node');
    if (n) openPanel(n.dataset.id);
  });
  canvas.addEventListener('keydown', e => {
    if ((e.key === 'Enter' || e.key === ' ') && e.target.classList.contains('oc-node')) {
      e.preventDefault();
      openPanel(e.target.dataset.id);
    }
  });

  /* ---------- ค้นหา + กรองแผนก ---------- */
  let activeDept = '';
  const searchInput = $('ocSearch'),
    clearBtn = $('ocClearSearch'),
    resetFilterBtn = $('ocResetFilter');
  const norm = s => (s || '').toString().toLowerCase().trim();

  const applyFilter = () => {
    const q = norm(searchInput.value);
    clearBtn.classList.toggle('show', searchInput.value !== '');
    const filtering = q !== '' || activeDept !== '';
    root.classList.toggle('filtering', filtering);
    resetFilterBtn.classList.toggle('show', filtering);

    const matches = [];
    canvas.querySelectorAll('.oc-node').forEach(n => {
      const d = byId.get(n.dataset.id);
      const okQ = !q || norm(d.name + ' ' + d.pos + ' ' + d.dept).includes(q);
      const okD = !activeDept || d.dept === activeDept;
      const hit = filtering && okQ && okD;
      n.classList.toggle('match', hit);
      if (hit) matches.push(d.id);
    });

    const res = $('ocResult');
    if (!filtering) {
      res.textContent = '';
      return;
    }
    res.textContent = matches.length ? 'พบ ' + matches.length + ' คน' : 'ไม่พบข้อมูลที่ตรงกัน';
    matches.forEach(revealAncestors);
    if (matches.length) {
      setTimeout(() => nodeEl(matches[0])?.scrollIntoView({
        behavior: 'smooth',
        block: 'center',
        inline: 'center'
      }), 60);
    }
  };

  let timer;
  searchInput.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(applyFilter, 180);
  });
  clearBtn.onclick = () => {
    searchInput.value = '';
    applyFilter();
    searchInput.focus();
  };
  resetFilterBtn.onclick = () => {
    searchInput.value = '';
    activeDept = '';
    $('ocLegend')?.querySelectorAll('.oc-chip').forEach(x => x.classList.remove('active'));
    applyFilter();
  };
  $('ocLegend')?.addEventListener('click', e => {
    const c = e.target.closest('.oc-chip');
    if (!c) return;
    const same = c.classList.contains('active');
    $('ocLegend').querySelectorAll('.oc-chip').forEach(x => x.classList.remove('active'));
    activeDept = same ? '' : c.dataset.dept;
    if (!same) c.classList.add('active');
    applyFilter();
  });

  /* ---------- แผงรายละเอียด ---------- */
  const panel = $('ocPanel'),
    body = $('ocPanelBody');

  const linkRow = d => {
    const mini = d.img
      ? `<img src="${esc(d.img)}" alt="">`
      : esc(d.name.charAt(0));
    return `<button type="button" class="oc-link" data-go="${d.id}" style="--c:${esc(d.color)}">
            <span class="oc-mini-avatar">${mini}</span>
            <span class="oc-link-info">
                <span class="oc-link-name d-block">${esc(d.name)}</span>
                <span class="oc-link-pos d-block">${esc(d.pos || '-')}</span>
            </span>
            <i class="bi bi-chevron-right"></i>
        </button>`;
  };

  function openPanel(id) {
    const d = byId.get(id);
    if (!d) return;
    const chain = [];
    for (let p = d.mgr; p != null; p = byId.get(p)?.mgr) chain.unshift(byId.get(p));
    const subs = (kids.get(id) || []).map(k => byId.get(k));
    const avatar = d.img ? `<img class="oc-avatar-img" src="${esc(d.img)}" alt="">` : esc(d.name.charAt(0));

    body.innerHTML = `
            <div style="--dept:${esc(d.color)}">
                <div class="oc-panel-banner"></div>
                <div class="oc-panel-content">
                    <div class="text-center oc-panel-avatar-wrap">
                        <div class="oc-avatar">${avatar}</div>
                        <div class="fw-bold fs-6 mt-2">${esc(d.name)}</div>
                        <div class="text-muted small mb-2">${esc(d.pos || '-')}</div>
                        <span class="oc-dept">${esc(d.dept || 'ไม่ระบุแผนก')}</span>
                    </div>
                    <div class="oc-metrics">
                        <div><b>${d.direct}</b><span>ลูกทีมโดยตรง</span></div>
                        <div><b>${d.team}</b><span>ลูกทีมทั้งหมด</span></div>
                    </div>
                    ${chain.length ? `<h6><i class="bi bi-diagram-2"></i>สายการบังคับบัญชา</h6>${chain.map(linkRow).join('')}` : ''}
                    ${subs.length ? `<h6><i class="bi bi-people"></i>ลูกทีมโดยตรง (${subs.length})</h6>${subs.map(linkRow).join('')}` : ''}
                </div>
            </div>`;
    panel.classList.add('open');
  }

  body.addEventListener('click', e => {
    const g = e.target.closest('[data-go]');
    if (!g) return;
    const id = g.dataset.go;
    focusNode(id);
    openPanel(id);
  });
  $('ocPanelClose').onclick = () => panel.classList.remove('open');
  document.addEventListener('keydown', e => {
    if (e.key === 'Escape') panel.classList.remove('open');
  });

  $('ocPrint').onclick = () => {
    $('ocExpandAll').click();
    setTimeout(() => window.print(), 100);
  };

  // เริ่มต้น: ถ้าผังกว้างเกินจอ ให้ย่อให้พอดี
  requestAnimationFrame(() => {
    if (canvas.scrollWidth > viewport.clientWidth) $('ocFit').click();
  });
});