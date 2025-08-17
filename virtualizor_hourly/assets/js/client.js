// ===== Virtualizor Cloud — Client UI =====
document.addEventListener('DOMContentLoaded', function () {
  document.querySelectorAll('.vzc-progress-bar').forEach(function (bar) {
    const w = bar.style.width;
    bar.style.width = '0%';
    setTimeout(() => { bar.style.width = w; }, 60);
  });

  const CIRCUMFERENCE = 2 * Math.PI * 16;
  document.querySelectorAll('.vzc-ring').forEach(function (ring) {
    const percent = parseFloat(ring.getAttribute('data-percent') || '0');
    const fg = ring.querySelector('.fg');
    const clamped = Math.max(0, Math.min(100, percent));
    const dash = (clamped / 100) * CIRCUMFERENCE;
    fg.setAttribute('stroke-dasharray', `${dash.toFixed(2)}, ${CIRCUMFERENCE.toFixed(2)}`);
  });
});

(function(){
  const q = document.getElementById('vzc-os-search');
  const sel = document.getElementById('vzc-os-select');
  if (!q || !sel) return;

  const all = Array.from(sel.options).map(o => ({
    val: o.value,
    text: o.text,
    name: (o.getAttribute('data-name')||'').toLowerCase(),
    distro: (o.getAttribute('data-distro')||'').toLowerCase(),
    arch: (o.getAttribute('data-arch')||'').toLowerCase(),
  }));

  function filter(term){
    const t = (term||'').toLowerCase().trim();
    sel.innerHTML = '';
    const filtered = t ? all.filter(o =>
      o.text.toLowerCase().includes(t) ||
      o.name.includes(t) ||
      o.distro.includes(t) ||
      o.arch.includes(t)
    ) : all;
    filtered.forEach(o => {
      const opt = document.createElement('option');
      opt.value = o.val;
      opt.textContent = o.text;
      sel.appendChild(opt);
    });
  }

  q.addEventListener('input', () => filter(q.value));
})();

(function(){
  const cfg = window.__VZC_LIVE__;
  if (!cfg || !cfg.url) return;

  const ringCpu  = document.querySelector('.vzc-gauge:nth-child(1) .vzc-ring .fg');
  const ringRam  = document.querySelector('.vzc-gauge:nth-child(2) .vzc-ring .fg');
  const ringDisk = document.querySelector('.vzc-gauge:nth-child(3) .vzc-ring .fg');
  const txtCpu   = document.querySelector('.vzc-gauge:nth-child(1) .vzc-ring .txt');
  const txtRam   = document.querySelector('.vzc-gauge:nth-child(2) .vzc-ring .txt');
  const txtDisk  = document.querySelector('.vzc-gauge:nth-child(3) .vzc-ring .txt');
  const stateEl  = document.querySelector('.vzc-state');
  const bwBar    = document.querySelector('.vzc-progress-bar');
  const bwMeta   = document.querySelector('.vzc-progress-meta');

  const CIRC = 2 * Math.PI * 16;
  function setRing(el, val){
    if (!el) return;
    const pct = Math.max(0, Math.min(100, val||0));
    const dash = (pct/100)*CIRC;
    el.setAttribute('stroke-dasharray', `${dash.toFixed(2)}, ${CIRC.toFixed(2)}`);
  }
  function setTxt(el, val){ if (el) el.textContent = `${Math.round(val||0)}%`; }
  function setState(on){
    if (!stateEl) return;
    stateEl.textContent = on ? 'روشن' : 'خاموش';
    stateEl.classList.toggle('on', !!on);
    stateEl.classList.toggle('off', !on);
  }
  function setBw(used, limit){
    if (!bwBar || !bwMeta) return;
    let pct = 0; if (limit > 0) pct = Math.max(0, Math.min(100, (used/limit)*100));
    bwBar.style.width = `${pct.toFixed(0)}%`;
    const spans = bwMeta.querySelectorAll('span');
    if (spans[0]) spans[0].innerHTML = `<b>مصرف:</b> ${Number(used||0).toFixed(2)} GB`;
    if (spans[1]) spans[1].innerHTML = `<b>سقف:</b> ${limit>0? (Number(limit).toFixed(0)+' GB') : 'نامحدود'}`;
  }

  let inflight = false;
  async function tick(){
    if (inflight) return; inflight = true;
    try {
      const res = await fetch(cfg.url, { credentials:'same-origin', cache:'no-store' });
      if (!res.ok) throw new Error('HTTP '+res.status);
      const j = await res.json();
      if (!j.ok) throw new Error(j.error || 'Unknown');
      setRing(ringCpu,  j.cpu);  setTxt(txtCpu,  j.cpu);
      setRing(ringRam,  j.ram);  setTxt(txtRam,  j.ram);
      setRing(ringDisk, j.disk); setTxt(txtDisk, j.disk);
      setState(j.power === 1);
      setBw((j.bw && j.bw.used)||0, (j.bw && j.bw.limit)||0);
    } catch(e){ console && console.warn && console.warn('LiveStats failed:', e.message||e); }
    finally { inflight = false; }
  }

  tick();
  const interval = Math.max(3000, cfg.interval||8000);
  const timer = setInterval(tick, interval);
  window.addEventListener('beforeunload', () => clearInterval(timer));
})();
// ===== Usage History (delta GB & amount) =====
(function(){
  const cfg = window.__VZC_HISTORY__;
  if (!cfg || !cfg.url) return;

  const elCanvas = document.getElementById('vzc-history-canvas');
  const elSumGb  = document.getElementById('vzc-h-sum-gb');
  const elSumAmt = document.getElementById('vzc-h-sum-amt');
  const elFrom   = document.getElementById('vzc-h-from');
  const elTo     = document.getElementById('vzc-h-to');
  const btnApply = document.getElementById('vzc-h-apply');

  // پیش‌فرض: امروز و 30 روز قبل
  const now = new Date();
  const toDef = new Date(now.getFullYear(), now.getMonth(), now.getDate());
  const fromDef = new Date(toDef.getTime() - (cfg.days||30)*86400000);
  elFrom.valueAsDate = fromDef;
  elTo.valueAsDate = toDef;

  async function fetchHistory() {
    const params = new URLSearchParams();
    if (elFrom.value) params.set('from', elFrom.value + ' 00:00:00');
    if (elTo.value)   params.set('to',   elTo.value   + ' 23:59:59');
    const url = cfg.url + '&' + params.toString();
    const res = await fetch(url, { credentials:'same-origin', cache:'no-store' });
    if (!res.ok) throw new Error('HTTP '+res.status);
    const j = await res.json();
    if (!j.ok) throw new Error(j.error||'Unknown');
    return j;
  }

  function drawChart(points) {
    const ctx = elCanvas.getContext('2d');
    const w = elCanvas.width = elCanvas.clientWidth || 600;
    const h = elCanvas.height = elCanvas.getAttribute('height') ? parseInt(elCanvas.getAttribute('height')) : 160;
    ctx.clearRect(0,0,w,h);

    if (!points.length) {
      ctx.fillStyle = '#8ea2b8';
      ctx.fillText('داده‌ای برای بازه انتخابی وجود ندارد.', 10, 18);
      return;
    }

    // سری‌ها: delta_gb و debit_amount
    const xs = points.map(p => p.t);
    const ys1= points.map(p => p.gb);
    const ys2= points.map(p => p.amt);

    const minX = Math.min.apply(null, xs), maxX = Math.max.apply(null, xs);
    const maxY1= Math.max(0, Math.max.apply(null, ys1));
    const maxY2= Math.max(0, Math.max.apply(null, ys2));
    const padL=36, padR=12, padT=10, padB=24;
    const plotW = w - padL - padR, plotH = h - padT - padB;

    function xPix(t){ return padL + ((t - minX)/(maxX - minX || 1)) * plotW; }
    function yPix(y,maxY){ return padT + (1 - (y/(maxY || 1))) * plotH; }

    // Grid
    ctx.strokeStyle = 'rgba(255,255,255,0.06)'; ctx.lineWidth = 1;
    for (let i=0;i<=5;i++){
      const y = padT + i*(plotH/5);
      ctx.beginPath(); ctx.moveTo(padL,y); ctx.lineTo(w-padR,y); ctx.stroke();
    }

    // سری 1 (GB) - خط
    ctx.strokeStyle = '#4fa3ff'; ctx.lineWidth = 2;
    ctx.beginPath();
    points.forEach((p,i) => {
      const x=xPix(p.t), y=yPix(p.gb, Math.max(maxY1, 0.0001));
      if (i===0) ctx.moveTo(x,y); else ctx.lineTo(x,y);
    });
    ctx.stroke();

    // سری 2 (Amount) - ستون‌های淡
    const barWidth = Math.max(2, plotW / Math.max(1, points.length*1.5));
    ctx.fillStyle = 'rgba(33,201,122,0.35)';
    points.forEach(p => {
      const x = xPix(p.t) - barWidth/2;
      const y = yPix(p.amt, Math.max(maxY2, 0.0001));
      ctx.fillRect(x, y, barWidth, padT+plotH - y);
    });

    // محور ساده
    ctx.fillStyle = '#8ea2b8'; ctx.font = '12px system-ui, tahoma, sans-serif';
    ctx.fillText('GB', padL, 12);
    ctx.fillText('Amount', w-80, 12);
  }

  async function refresh() {
    try {
      const data = await fetchHistory();
      elSumGb.textContent  = Number(data.total_gb||0).toFixed(3);
      elSumAmt.textContent = Number(data.total_amount||0).toFixed(3);
      drawChart(data.points||[]);
    } catch(e) {
      console && console.warn && console.warn('UsageHistory:', e.message||e);
      drawChart([]);
    }
  }

  btnApply && btnApply.addEventListener('click', refresh);
  refresh();
})();
