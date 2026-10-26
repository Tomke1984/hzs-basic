(function () {
  // Publiek menu: op smalle schermen inklapbaar achter een knop (zelfde gedrag als omnit.be/HZS)
  var menuBtn = document.querySelector('.menu-btn'), siteNav = document.getElementById('site-nav');
  if (menuBtn && siteNav && window.matchMedia) {
    var mq = window.matchMedia('(max-width: 1180px)');
    var apply = function () {
      menuBtn.hidden = !mq.matches;
      if (mq.matches) { siteNav.classList.add('is-collapsed'); menuBtn.setAttribute('aria-expanded', 'false'); }
      else { siteNav.classList.remove('is-collapsed'); }
    };
    menuBtn.addEventListener('click', function () {
      var open = siteNav.classList.toggle('is-collapsed') === false;
      menuBtn.setAttribute('aria-expanded', open ? 'true' : 'false');
    });
    if (mq.addEventListener) { mq.addEventListener('change', apply); }
    apply();
  }
  // Brede tabellen horizontaal scrollbaar maken
  document.querySelectorAll('.prose table').forEach(function (t) {
    if (t.parentElement.classList.contains('table-wrap')) return;
    var w = document.createElement('div'); w.className = 'table-wrap';
    t.parentNode.insertBefore(w, t); w.appendChild(t);
  });
})();
