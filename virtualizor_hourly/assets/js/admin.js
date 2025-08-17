// Virtualizor Hourly - Admin helper for loading plan list
// Adds a "Load Plans" button to the product module settings page

document.addEventListener('DOMContentLoaded', function(){
  const btn = document.getElementById('vz-load-plans');
  if (!btn) return;
  btn.addEventListener('click', async function(){
    const input = document.querySelector('input[name="configoption2"]');
    if (!input) return;
    const pid = new URLSearchParams(window.location.search).get('id');
    if (!pid) { alert('Product ID not found in URL'); return; }
    btn.disabled = true;
    btn.textContent = 'Loading...';
    try {
      const url = '../modules/servers/virtualizor_hourly/admin/ajax.php?action=plans&productid=' + encodeURIComponent(pid);
      const res = await fetch(url, {credentials:'same-origin'});
      const j = await res.json();
      if (!j.ok) throw new Error(j.error || 'Error');
      if (!Array.isArray(j.plans) || j.plans.length === 0) {
        alert('No plans retrieved');
      } else {
        const sel = document.createElement('select');
        sel.name = input.name;
        sel.id = input.id;
        sel.className = input.className;
        j.plans.forEach(p => {
          const o = document.createElement('option');
          o.value = p.id;
          o.textContent = p.label;
          if (input.value && String(input.value) === String(p.id)) o.selected = true;
          sel.appendChild(o);
        });
        input.parentNode.replaceChild(sel, input);
      }
    } catch (e){
      alert('Failed to load plans: ' + e.message);
    } finally {
      btn.disabled = false;
      btn.textContent = 'Load Plans';
    }
  });
});
