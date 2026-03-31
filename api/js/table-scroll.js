// table-scroll.js — horní/dolní scrollbar pro tabulky + auto-wrap .table-container
(function () {
  function makeWrapper(tc) {
    // už je obaleno?
    if (tc.closest('.table-scroll')) return;

    // vytvořit wrapper a horní lištu
    const wrap = document.createElement('div');
    wrap.className = 'table-scroll';
    const top = document.createElement('div');
    top.className = 'table-scroll-top';
    const spacer = document.createElement('div');
    spacer.className = 'spacer';
    top.appendChild(spacer);

    // vložit před .table-container a přesunout ji dovnitř wrapperu
    tc.parentNode.insertBefore(wrap, tc);
    wrap.appendChild(top);
    wrap.appendChild(tc);

    // sync šířky spaceru s tabulkou
    function syncWidth() {
      spacer.style.width = tc.scrollWidth + 'px';
    }
    syncWidth();
    window.addEventListener('resize', syncWidth);

    // sync scroll mezi horní lištou a table-container
    let lock = false;
    top.addEventListener('scroll', () => {
      if (lock) return;
      lock = true;
      tc.scrollLeft = top.scrollLeft;
      requestAnimationFrame(() => {
        lock = false;
      });
    });
    tc.addEventListener('scroll', () => {
      if (lock) return;
      lock = true;
      top.scrollLeft = tc.scrollLeft;
      requestAnimationFrame(() => {
        lock = false;
      });
    });
  }

  function init() {
    document.querySelectorAll('.table-container').forEach(makeWrapper);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();

// === Automatické zaokrouhlení na dvě desetinná místa pro vstupy s .round2 ===
(function () {
  function roundTo2(el) {
    const v = (el.value || '').replace(',', '.');
    if (v === '') return;
    const n = Number(v);
    if (!Number.isNaN(n)) el.value = n.toFixed(2);
  }
  function initRounders() {
    document.querySelectorAll('input.round2').forEach(inp => {
      inp.addEventListener('blur', () => roundTo2(inp));
      inp.addEventListener('change', () => roundTo2(inp));
    });
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initRounders);
  } else {
    initRounders();
  }
})();
