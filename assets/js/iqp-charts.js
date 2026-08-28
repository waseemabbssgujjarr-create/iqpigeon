/* ============================================================
   IQPigeon — tiny SVG chart helpers (no dependencies)
   ============================================================ */
(function (global) {
  const NS = 'http://www.w3.org/2000/svg';

  function path(points) {
    return points.map((p, i) => (i === 0 ? 'M' : 'L') + p[0].toFixed(1) + ' ' + p[1].toFixed(1)).join(' ');
  }

  // Smooth-ish line via simple line segments (crisp, matches mockup style with dots)
  function scale(data, w, h, pad) {
    const max = Math.max.apply(null, data) * 1.08 || 1;
    const min = 0;
    const innerW = w - pad.l - pad.r;
    const innerH = h - pad.t - pad.b;
    return data.map((v, i) => [
      pad.l + (data.length === 1 ? innerW / 2 : (i / (data.length - 1)) * innerW),
      pad.t + innerH - ((v - min) / (max - min)) * innerH,
    ]);
  }

  /* Area chart (single green series) */
  global.areaChart = function (el, data, opts) {
    opts = opts || {};
    const w = el.clientWidth || 640, h = opts.height || 240;
    const pad = { t: 10, r: 12, b: 26, l: 46 };
    const pts = scale(data, w, h, pad);
    const line = path(pts);
    const area = line + ` L ${pts[pts.length - 1][0].toFixed(1)} ${h - pad.b} L ${pts[0][0].toFixed(1)} ${h - pad.b} Z`;
    const yLabels = opts.yLabels || [];
    const xLabels = opts.xLabels || [];
    const gy = yLabels.map((lbl, i) => {
      const y = pad.t + (i / (yLabels.length - 1)) * (h - pad.t - pad.b);
      return `<line x1="${pad.l}" y1="${y}" x2="${w - pad.r}" y2="${y}" stroke="#f1f3f6"/>
              <text x="${pad.l - 10}" y="${y + 4}" text-anchor="end" fill="#94a3b8" font-size="11">${lbl}</text>`;
    }).join('');
    const gx = xLabels.map((lbl, i) => {
      const x = pad.l + (i / (xLabels.length - 1)) * (w - pad.l - pad.r);
      return `<text x="${x}" y="${h - 6}" text-anchor="middle" fill="#94a3b8" font-size="11">${lbl}</text>`;
    }).join('');
    const dots = pts.map(p => `<circle cx="${p[0].toFixed(1)}" cy="${p[1].toFixed(1)}" r="3" fill="#1FA855" stroke="#fff" stroke-width="1.5"/>`).join('');
    el.innerHTML = `<svg viewBox="0 0 ${w} ${h}" width="100%" height="${h}" preserveAspectRatio="none">
      <defs><linearGradient id="ga" x1="0" x2="0" y1="0" y2="1">
        <stop offset="0" stop-color="#1FA855" stop-opacity=".22"/>
        <stop offset="1" stop-color="#1FA855" stop-opacity="0"/>
      </linearGradient></defs>
      ${gy}${gx}
      <path d="${area}" fill="url(#ga)"/>
      <path d="${line}" fill="none" stroke="#1FA855" stroke-width="2.5" stroke-linejoin="round"/>
      ${opts.dots === false ? '' : dots}
    </svg>`;
  };

  /* Multi-line chart */
  global.lineChart = function (el, series, opts) {
    opts = opts || {};
    const w = el.clientWidth || 640, h = opts.height || 240;
    const pad = { t: 10, r: 12, b: 26, l: 42 };
    const all = [].concat.apply([], series.map(s => s.data));
    const max = Math.max.apply(null, all) * 1.1 || 1;
    const innerW = w - pad.l - pad.r, innerH = h - pad.t - pad.b;
    const toPts = (data) => data.map((v, i) => [
      pad.l + (i / (data.length - 1)) * innerW,
      pad.t + innerH - (v / max) * innerH,
    ]);
    const yLabels = opts.yLabels || [];
    const xLabels = opts.xLabels || [];
    const gy = yLabels.map((lbl, i) => {
      const y = pad.t + (i / (yLabels.length - 1)) * innerH;
      return `<line x1="${pad.l}" y1="${y}" x2="${w - pad.r}" y2="${y}" stroke="#f1f3f6"/>
              <text x="${pad.l - 8}" y="${y + 4}" text-anchor="end" fill="#94a3b8" font-size="11">${lbl}</text>`;
    }).join('');
    const gx = xLabels.map((lbl, i) => {
      const x = pad.l + (i / (xLabels.length - 1)) * innerW;
      return `<text x="${x}" y="${h - 6}" text-anchor="middle" fill="#94a3b8" font-size="11">${lbl}</text>`;
    }).join('');
    const lines = series.map(s => {
      const pts = toPts(s.data);
      const dots = pts.map(p => `<circle cx="${p[0].toFixed(1)}" cy="${p[1].toFixed(1)}" r="2.6" fill="${s.color}" stroke="#fff" stroke-width="1.3"/>`).join('');
      return `<path d="${path(pts)}" fill="none" stroke="${s.color}" stroke-width="2.2" stroke-linejoin="round"/>${dots}`;
    }).join('');
    el.innerHTML = `<svg viewBox="0 0 ${w} ${h}" width="100%" height="${h}" preserveAspectRatio="none">${gy}${gx}${lines}</svg>`;
  };

  /* Donut chart */
  global.donutChart = function (el, segs, opts) {
    opts = opts || {};
    const size = opts.size || 180, sw = opts.stroke || 22;
    const r = (size - sw) / 2, cx = size / 2, cy = size / 2;
    const C = 2 * Math.PI * r;
    const total = segs.reduce((a, s) => a + s.value, 0) || 1;
    let offset = 0;
    const arcs = segs.map(s => {
      const frac = s.value / total;
      const dash = `${(frac * C).toFixed(2)} ${(C - frac * C).toFixed(2)}`;
      const seg = `<circle cx="${cx}" cy="${cy}" r="${r}" fill="none" stroke="${s.color}" stroke-width="${sw}"
        stroke-dasharray="${dash}" stroke-dashoffset="${(-offset * C).toFixed(2)}"
        transform="rotate(-90 ${cx} ${cy})" stroke-linecap="butt"/>`;
      offset += frac;
      return seg;
    }).join('');
    const center = opts.centerTop || opts.centerBottom ? `
      <text x="${cx}" y="${cy - 2}" text-anchor="middle" font-size="26" font-weight="800" fill="#0f172a">${opts.centerTop || ''}</text>
      <text x="${cx}" y="${cy + 16}" text-anchor="middle" font-size="11" fill="#64748b">${opts.centerBottom || ''}</text>` : '';
    el.innerHTML = `<svg viewBox="0 0 ${size} ${size}" width="${size}" height="${size}">
      <circle cx="${cx}" cy="${cy}" r="${r}" fill="none" stroke="#f1f3f6" stroke-width="${sw}"/>
      ${arcs}${center}</svg>`;
  };
})(window);
