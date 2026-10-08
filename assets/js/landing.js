/* Animasi scroll-reveal landing page */
(function () {
  var els = document.querySelectorAll('.reveal, .landing .paket-card');
  els.forEach(function (el) { el.classList.add('reveal'); });
  if (!('IntersectionObserver' in window)) {
    els.forEach(function (el) { el.classList.add('show'); });
    return;
  }
  var io = new IntersectionObserver(function (entries) {
    entries.forEach(function (en) {
      if (en.isIntersecting) { en.target.classList.add('show'); io.unobserve(en.target); }
    });
  }, { threshold: 0.15 });
  els.forEach(function (el, i) { el.style.setProperty('--d', ((i % 5) * 0.07) + 's'); io.observe(el); });

  // Menu aktif saat scroll
  var links = document.querySelectorAll('.nav-links a[href^="#"]');
  var map = {};
  links.forEach(function (a) { map[a.getAttribute('href')] = a; });
  var secs = ['home', 'keunggulan', 'paket', 'kontak'].map(function (id) { return document.getElementById(id); }).filter(Boolean);
  window.addEventListener('scroll', function () {
    var y = window.scrollY + 140, cur = secs[0];
    secs.forEach(function (s) { if (s.offsetTop <= y) cur = s; });
    links.forEach(function (a) { a.classList.remove('active'); });
    if (cur && map['#' + cur.id]) map['#' + cur.id].classList.add('active');
  }, { passive: true });
})();
