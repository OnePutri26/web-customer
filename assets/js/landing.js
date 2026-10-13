/*!
 * YesNet - Landing page v3
 * Tanpa library. Semua gaya ada di landing.css, file ini hanya
 * mengatur perilaku: intro, reveal, menu mobile, pemilih paket,
 * cek coverage, serta efek hero (partikel, aurora, parallax 3D).
 * Menghormati prefers-reduced-motion.
 */
(function () {
  'use strict';
  if (window.__yesnet) return;
  window.__yesnet = true;

  var w = window, d = document, root = d.documentElement;
  var reduce = !!(w.matchMedia && w.matchMedia('(prefers-reduced-motion: reduce)').matches);
  var fine = !!(w.matchMedia && w.matchMedia('(hover: hover) and (pointer: fine)').matches);
  var HAS_IO = 'IntersectionObserver' in w;
  var LOADER_MIN = 1000;
  var SVGNS = 'http://www.w3.org/2000/svg';

  function $(s, r) { return (r || d).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || d).querySelectorAll(s)); }
  function on(el, ev, fn, opt) { el.addEventListener(ev, fn, opt || false); }
  function safe(fn) { try { fn(); } catch (e) { if (w.console) console.warn('[yesnet]', e); } }
  function svgEl(name, attrs) {
    var el = d.createElementNS(SVGNS, name);
    for (var k in attrs) { if (Object.prototype.hasOwnProperty.call(attrs, k)) el.setAttribute(k, attrs[k]); }
    return el;
  }

  root.classList.add('js');

  /* ===================================================== INTRO */
  var introDone = false;
  function afterIntro() {
    if (introDone) return;
    introDone = true;
    root.classList.add('ready');
    safe(startReveal);
    safe(startSplit);
    safe(startCounters);
  }

  function loader(done) {
    var el = $('#loader');
    if (!el || !root.classList.contains('loading') || reduce) {
      if (el && el.parentNode) el.parentNode.removeChild(el);
      root.classList.remove('loading');
      done();
      return;
    }
    var bar = $('.ld-bar i', el), pct = $('.ld-pct', el);
    var t0 = Date.now(), loaded = d.readyState === 'complete', fin = false, prog = 0, timer;

    function out() {
      if (fin) return;
      fin = true;
      clearInterval(timer);
      bar.style.transform = 'scaleX(1)';
      pct.textContent = '100%';
      setTimeout(function () {
        el.classList.add('out');
        done();
        try { sessionStorage.setItem('yn_seen', '1'); } catch (e) {}
        setTimeout(function () {
          root.classList.remove('loading');
          if (el.parentNode) el.parentNode.removeChild(el);
        }, 1000);
      }, 160);
    }
    timer = setInterval(function () {
      var target = Math.min(1, (Date.now() - t0) / LOADER_MIN);
      if (!loaded) target = Math.min(target, 0.92);
      prog += (target - prog) * 0.22;
      bar.style.transform = 'scaleX(' + prog.toFixed(3) + ')';
      pct.textContent = Math.round(prog * 100) + '%';
      if (loaded && target >= 1 && prog > 0.985) out();
    }, 40);
    if (!loaded) on(w, 'load', function () { loaded = true; });
    setTimeout(out, 3500);
  }

  /* ===================================================== NAVIGASI */
  var scrollSubs = [];
  function onScrollRaf(fn) { scrollSubs.push(fn); }
  function initScrollHub() {
    var tick = false;
    function run() { tick = false; for (var i = 0; i < scrollSubs.length; i++) scrollSubs[i](); }
    on(w, 'scroll', function () { if (!tick) { tick = true; requestAnimationFrame(run); } }, { passive: true });
    on(w, 'resize', function () { requestAnimationFrame(run); });
    run();
  }

  function progressBar() {
    var b = d.createElement('div');
    b.className = 'yn-progress';
    b.setAttribute('aria-hidden', 'true');
    d.body.appendChild(b);
    onScrollRaf(function () {
      var h = root.scrollHeight - w.innerHeight;
      b.style.transform = 'scaleX(' + (h > 0 ? Math.min(1, (w.pageYOffset || 0) / h) : 0) + ')';
    });
  }

  function navbar() {
    var nav = $('.navbar'), toggle = $('.nav-toggle'), menu = $('#menu');
    onScrollRaf(function () { if (nav) nav.classList.toggle('scrolled', (w.pageYOffset || 0) > 30); });

    function setMenu(open) {
      root.classList.toggle('menu-open', open);
      if (toggle) {
        toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        toggle.setAttribute('aria-label', open ? 'Tutup menu' : 'Buka menu');
      }
    }
    if (toggle) {
      on(toggle, 'click', function () { setMenu(!root.classList.contains('menu-open')); });
      on(d, 'keydown', function (e) { if (e.key === 'Escape') setMenu(false); });
      on(w, 'resize', function () { if (w.innerWidth > 960) setMenu(false); });
      if (menu) on(menu, 'click', function (e) { if (e.target.closest && e.target.closest('a')) setMenu(false); });
      on(d, 'click', function (e) {
        if (root.classList.contains('menu-open') && !(e.target.closest && e.target.closest('.navbar'))) setMenu(false);
      });
    }

    var links = $$('.nav-center a[href^="#"]');
    var map = {}, secs = [];
    links.forEach(function (a) {
      var id = a.getAttribute('href').slice(1), s = id && d.getElementById(id);
      if (s) { map[id] = a; secs.push(s); }
    });
    if (!secs.length || !HAS_IO) return;
    var so = new IntersectionObserver(function (es) {
      es.forEach(function (e) {
        if (!e.isIntersecting) return;
        links.forEach(function (l) { l.classList.remove('active'); });
        if (map[e.target.id]) map[e.target.id].classList.add('active');
      });
    }, { rootMargin: '-35% 0px -55% 0px' });
    secs.forEach(function (s) { so.observe(s); });
  }

  // Bar CTA mobile muncul setelah hero
  function mobileBar() {
    var bar = $('#mbar');
    if (!bar) return;
    onScrollRaf(function () { bar.classList.toggle('show', (w.pageYOffset || 0) > 520); });
  }

  /* ===================================================== REVEAL + SPLIT + COUNTER */
  function attachTilt(el) {
    if (!fine || reduce || el._yt || !el.classList.contains('tilt')) return;
    el._yt = 1;
    if (w.getComputedStyle(el).position === 'static') el.style.position = 'relative';
    var glow = d.createElement('span');
    glow.className = 'yn-glow';
    glow.setAttribute('aria-hidden', 'true');
    el.appendChild(glow);
    on(el, 'mouseenter', function () { el.classList.add('yn-hov'); });
    on(el, 'mousemove', function (e) {
      var r = el.getBoundingClientRect();
      var x = (e.clientX - r.left) / r.width - 0.5, y = (e.clientY - r.top) / r.height - 0.5;
      el.style.setProperty('--mx', (x + 0.5) * 100 + '%');
      el.style.setProperty('--my', (y + 0.5) * 100 + '%');
      el.style.setProperty('--rx', (-y * 7).toFixed(2) + 'deg');
      el.style.setProperty('--ry', (x * 7).toFixed(2) + 'deg');
    });
    on(el, 'mouseleave', function () {
      el.classList.remove('yn-hov');
      el.style.setProperty('--rx', '0deg');
      el.style.setProperty('--ry', '0deg');
    });
  }

  function showEl(el) {
    if (el.classList.contains('is-visible')) return;
    el.classList.add('is-visible');
    var delay = el._d || 0;
    setTimeout(function () { el.classList.add('done'); attachTilt(el); }, 1000 + delay);
  }

  function startReveal() {
    var els = $$('.reveal');
    els.forEach(function (el) {
      var sibs = Array.prototype.filter.call(el.parentNode.children, function (c) { return c.classList.contains('reveal'); });
      el._d = Math.min(sibs.indexOf(el), 5) * 90;
      el.style.setProperty('--d', el._d + 'ms');
    });
    if (reduce || !HAS_IO) { els.forEach(showEl); return; }
    var io = new IntersectionObserver(function (es) {
      es.forEach(function (e) {
        if (!e.isIntersecting) return;
        io.unobserve(e.target);
        showEl(e.target);
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -6% 0px' });
    els.forEach(function (el) { io.observe(el); });
  }

  // Judul h2 muncul kata demi kata
  var splitTargets = [];
  function splitNode(node, c) {
    Array.prototype.slice.call(node.childNodes).forEach(function (n) {
      if (n.nodeType === 3) {
        var frag = d.createDocumentFragment();
        n.nodeValue.split(/(\s+)/).forEach(function (p) {
          if (!p) return;
          if (/^\s+$/.test(p)) { frag.appendChild(d.createTextNode(' ')); return; }
          var s = d.createElement('span');
          s.className = 'yn-w';
          s.style.setProperty('--wi', c.n++);
          s.setAttribute('aria-hidden', 'true');
          s.textContent = p;
          frag.appendChild(s);
        });
        node.replaceChild(frag, n);
      } else if (n.nodeType === 1 && n.nodeName !== 'BR') {
        if (n.classList.contains('grad')) { n.classList.add('yn-w'); n.style.setProperty('--wi', c.n++); n.style.display = 'inline-block'; }
        else splitNode(n, c);
      }
    });
  }
  function prepareSplit() {
    if (reduce) return;
    $$('.section-heading h2').forEach(function (h) {
      var label = h.textContent.replace(/\s+/g, ' ').trim();
      if (!label || label.length > 120) return;
      h.setAttribute('aria-label', label);
      splitNode(h, { n: 0 });
      h.classList.add('yn-split');
      splitTargets.push(h);
    });
  }
  function startSplit() {
    if (!splitTargets.length) return;
    if (!HAS_IO) { splitTargets.forEach(function (h) { h.classList.add('yn-split-in'); }); return; }
    var io = new IntersectionObserver(function (es) {
      es.forEach(function (e) {
        if (!e.isIntersecting) return;
        io.unobserve(e.target);
        e.target.classList.add('yn-split-in');
      });
    }, { threshold: 0.35 });
    splitTargets.forEach(function (h) { io.observe(h); });
  }

  // Hitung naik: elemen dengan data-count / data-dec
  function fmtNum(v, dec) {
    var s = v.toFixed(dec).split('.');
    s[0] = s[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return s.join(',');
  }
  var counters = [];
  function prepareCounters() {
    if (reduce) return;
    $$('[data-count]').forEach(function (el) {
      var dec = parseInt(el.getAttribute('data-dec') || '0', 10);
      counters.push(el);
      el.textContent = fmtNum(0, dec);
    });
  }
  function runCounter(el) {
    var to = parseFloat(el.getAttribute('data-count')), dec = parseInt(el.getAttribute('data-dec') || '0', 10), t0 = null, dur = 1700;
    function step(ts) {
      if (t0 === null) t0 = ts;
      var t = Math.min(1, (ts - t0) / dur), e = t === 1 ? 1 : 1 - Math.pow(2, -10 * t);
      el.textContent = fmtNum(to * e, dec);
      if (t < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }
  function startCounters() {
    if (!counters.length) return;
    function finalValue(el) { el.textContent = fmtNum(parseFloat(el.getAttribute('data-count')), parseInt(el.getAttribute('data-dec') || '0', 10)); }
    if (!HAS_IO) { counters.forEach(finalValue); return; }
    var io = new IntersectionObserver(function (es) {
      es.forEach(function (e) {
        if (!e.isIntersecting) return;
        io.unobserve(e.target);
        runCounter(e.target);
      });
    }, { threshold: 0.6 });
    counters.forEach(function (el) { io.observe(el); });
  }

  /* ===================================================== HERO */
  function scene() {
    var svg = $('.scene');
    if (!svg || reduce) return;

    var house = $('.house', svg), i;
    if (house && house.parentNode) {
      var g = svgEl('g', {});
      for (i = 0; i < 18; i++) {
        var s = svgEl('circle', {
          cx: (Math.random() * 540 + 10).toFixed(0), cy: (Math.random() * 110 + 8).toFixed(0),
          r: (Math.random() * 1.4 + 0.6).toFixed(1), fill: '#fff', 'class': 'yn-star'
        });
        s.style.animationDelay = (Math.random() * 4).toFixed(2) + 's';
        g.appendChild(s);
      }
      for (i = 0; i < 2; i++) {
        var wrap = svgEl('g', { transform: 'translate(' + (80 + i * 260 + Math.random() * 60).toFixed(0) + ',' + (10 + Math.random() * 30).toFixed(0) + ')' });
        var ln = svgEl('line', { x1: '0', y1: '0', x2: '-44', y2: '-19', 'class': 'yn-shoot' });
        ln.style.animationDelay = (i * 3.6 + 1.2) + 's';
        wrap.appendChild(ln);
        g.appendChild(wrap);
      }
      house.parentNode.insertBefore(g, house);
    }

    var pulse = $('.pulse', svg);
    if (pulse && pulse.parentNode) {
      [0, 0.8, 1.6].forEach(function (dl) {
        var r = svgEl('circle', { cx: pulse.getAttribute('cx'), cy: pulse.getAttribute('cy'), r: '6', 'class': 'yn-ring' });
        r.style.animationDelay = dl + 's';
        pulse.parentNode.insertBefore(r, pulse);
      });
    }

    var trail = $('.trail', svg);
    if (trail && trail.parentNode) {
      if (!trail.id) trail.id = 'ynTrail';
      [0, 1.3, 2.6, 3.9].forEach(function (begin) {
        var dur = '5.2s', b = begin + 's';
        var c = svgEl('circle', { r: '4.5', fill: '#fff', opacity: '0', 'class': 'yn-packet' });
        var am = svgEl('animateMotion', { dur: dur, begin: b, repeatCount: 'indefinite' });
        var mp = svgEl('mpath', {});
        mp.setAttribute('href', '#' + trail.id);
        mp.setAttributeNS('http://www.w3.org/1999/xlink', 'href', '#' + trail.id);
        am.appendChild(mp);
        c.appendChild(am);
        c.appendChild(svgEl('animate', { attributeName: 'opacity', values: '0;1;1;0', keyTimes: '0;.08;.92;1', dur: dur, begin: b, repeatCount: 'indefinite' }));
        trail.parentNode.insertBefore(c, trail.nextSibling);
      });
    }
  }

  function aurora(hero) {
    if (reduce) return;
    ['b1', 'b2', 'b3'].forEach(function (c) {
      var b = d.createElement('div');
      b.className = 'yn-blob ' + c;
      b.setAttribute('aria-hidden', 'true');
      hero.insertBefore(b, hero.firstChild);
    });
  }

  // Parallax 3D mengikuti mouse + bergeser halus saat scroll
  function heroMotion(hero) {
    var vis = $('.hero-visual', hero);
    if (!vis || reduce) return;
    var tx = 0, ty = 0, cx = 0, cy = 0, raf = 0;
    function frame() {
      cx += (tx - cx) * 0.08;
      cy += (ty - cy) * 0.08;
      var py = Math.min(60, (w.pageYOffset || 0) * 0.05);
      vis.style.transform = 'translate3d(0,' + py.toFixed(1) + 'px,0) perspective(1100px) rotateY(' + cx.toFixed(2) + 'deg) rotateX(' + cy.toFixed(2) + 'deg)';
      raf = (Math.abs(tx - cx) > 0.02 || Math.abs(ty - cy) > 0.02) ? requestAnimationFrame(frame) : 0;
    }
    function kick() { if (!raf) raf = requestAnimationFrame(frame); }
    on(w, 'scroll', kick, { passive: true });
    if (fine) {
      on(hero, 'mousemove', function (e) {
        var r = hero.getBoundingClientRect();
        tx = ((e.clientX - r.left) / r.width - 0.5) * 10;
        ty = -((e.clientY - r.top) / r.height - 0.5) * 7;
        kick();
      });
      on(hero, 'mouseleave', function () { tx = 0; ty = 0; kick(); });
    }
  }

  // Jaringan partikel + paket data berjalan
  function network(hero) {
    if (reduce) return;
    var cv = d.createElement('canvas');
    cv.className = 'yn-canvas';
    cv.setAttribute('aria-hidden', 'true');
    hero.insertBefore(cv, hero.firstChild);
    var ctx = cv.getContext('2d');
    if (!ctx) return;

    var W = 0, H = 0, dpr = Math.min(w.devicePixelRatio || 1, 2), pts = [], packets = [];
    var mouse = { x: -9999, y: -9999 }, raf = 0, visible = true;
    var COLORS = ['31,98,255', '18,181,255', '168,85,247'];
    var small = w.innerWidth < 700;

    function build() {
      var r = hero.getBoundingClientRect();
      W = r.width; H = r.height;
      cv.width = Math.round(W * dpr); cv.height = Math.round(H * dpr);
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      var n = Math.max(14, Math.min(small ? 24 : 50, Math.round(W * H / 22000)));
      pts = []; packets = [];
      for (var i = 0; i < n; i++) {
        pts.push({ x: Math.random() * W, y: Math.random() * H, vx: (Math.random() - 0.5) * 0.4, vy: (Math.random() - 0.5) * 0.4,
          r: Math.random() * 1.7 + 0.8, c: COLORS[Math.floor(Math.random() * 3)], ph: Math.random() * 6.28 });
      }
    }
    function draw(ts) {
      ctx.clearRect(0, 0, W, H);
      var L = 135, i, j, p, q, dx, dy, dist, links = [];
      for (i = 0; i < pts.length; i++) {
        p = pts[i];
        dx = p.x - mouse.x; dy = p.y - mouse.y; dist = Math.sqrt(dx * dx + dy * dy);
        if (dist < 120 && dist > 0.1) { var f = (1 - dist / 120) * 0.9; p.x += dx / dist * f; p.y += dy / dist * f; }
        p.x += p.vx; p.y += p.vy;
        if (p.x < 0 || p.x > W) p.vx *= -1;
        if (p.y < 0 || p.y > H) p.vy *= -1;
        for (j = i + 1; j < pts.length; j++) {
          q = pts[j]; dx = p.x - q.x; dy = p.y - q.y; dist = Math.sqrt(dx * dx + dy * dy);
          if (dist < L) {
            ctx.strokeStyle = 'rgba(31,98,255,' + (0.24 * (1 - dist / L)).toFixed(3) + ')';
            ctx.lineWidth = 1;
            ctx.beginPath(); ctx.moveTo(p.x, p.y); ctx.lineTo(q.x, q.y); ctx.stroke();
            links.push([p, q]);
          }
        }
        dx = p.x - mouse.x; dy = p.y - mouse.y; dist = Math.sqrt(dx * dx + dy * dy);
        if (dist < 170) {
          ctx.strokeStyle = 'rgba(18,181,255,' + (0.45 * (1 - dist / 170)).toFixed(3) + ')';
          ctx.beginPath(); ctx.moveTo(p.x, p.y); ctx.lineTo(mouse.x, mouse.y); ctx.stroke();
        }
        ctx.fillStyle = 'rgba(' + p.c + ',' + (0.45 + 0.25 * Math.sin(ts * 0.002 + p.ph)).toFixed(2) + ')';
        ctx.beginPath(); ctx.arc(p.x, p.y, p.r, 0, 6.2832); ctx.fill();
      }
      if (links.length && packets.length < 7 && Math.random() < 0.05) {
        var l = links[Math.floor(Math.random() * links.length)];
        packets.push({ a: l[0], b: l[1], t: 0, s: 0.012 + Math.random() * 0.012 });
      }
      for (i = packets.length - 1; i >= 0; i--) {
        var k = packets[i];
        k.t += k.s;
        if (k.t >= 1) { packets.splice(i, 1); continue; }
        var px = k.a.x + (k.b.x - k.a.x) * k.t, py = k.a.y + (k.b.y - k.a.y) * k.t;
        var grd = ctx.createRadialGradient(px, py, 0, px, py, 11);
        grd.addColorStop(0, 'rgba(34,211,238,.95)');
        grd.addColorStop(1, 'rgba(34,211,238,0)');
        ctx.fillStyle = grd;
        ctx.beginPath(); ctx.arc(px, py, 11, 0, 6.2832); ctx.fill();
        ctx.fillStyle = '#fff';
        ctx.beginPath(); ctx.arc(px, py, 1.8, 0, 6.2832); ctx.fill();
      }
      raf = requestAnimationFrame(draw);
    }
    function start() { if (!raf && visible && !d.hidden) raf = requestAnimationFrame(draw); }
    function stop() { if (raf) cancelAnimationFrame(raf); raf = 0; }

    build();
    var rt;
    on(w, 'resize', function () { clearTimeout(rt); rt = setTimeout(build, 200); });
    on(d, 'visibilitychange', function () { d.hidden ? stop() : start(); });
    if (fine) {
      on(hero, 'mousemove', function (e) { var r = hero.getBoundingClientRect(); mouse.x = e.clientX - r.left; mouse.y = e.clientY - r.top; });
      on(hero, 'mouseleave', function () { mouse.x = mouse.y = -9999; });
    }
    if (HAS_IO) new IntersectionObserver(function (es) { visible = es[0].isIntersecting; visible ? start() : stop(); }).observe(hero);
    start();
  }

  /* ===================================================== FUNGSI UTAMA: PAKET & COVERAGE */
  // Pemilih kebutuhan -> menyorot paket yang disarankan
  function picker() {
    var chips = $$('.chip[data-pick]'), out = $('#pick-result');
    if (!chips.length || !out) return;
    chips.forEach(function (ch) {
      on(ch, 'click', function () {
        var id = ch.getAttribute('data-pick');
        var card = $('.package-card[data-id="' + id + '"]');
        if (!card) return;
        chips.forEach(function (c) {
          var act = c === ch;
          c.classList.toggle('active', act);
          c.setAttribute('aria-pressed', act ? 'true' : 'false');
        });
        $$('.package-card.recommended').forEach(function (c) { c.classList.remove('recommended'); });
        card.classList.add('recommended');

        out.textContent = '';
        out.appendChild(d.createTextNode('Kami sarankan paket '));
        var b = d.createElement('strong');
        b.textContent = card.getAttribute('data-name') + ' (' + card.getAttribute('data-speed') + ' Mbps, Rp ' + card.getAttribute('data-price') + '/bulan)';
        out.appendChild(b);
        var btn = $('.package-button', card);
        if (btn) {
          var a = d.createElement('a');
          a.className = 'picker-go';
          a.href = btn.getAttribute('href');
          a.textContent = 'Pilih paket ini →';
          out.appendChild(a);
        }
        out.classList.add('show');

        var r = card.getBoundingClientRect();
        if (r.top < 90 || r.bottom > w.innerHeight - 20) card.scrollIntoView({ block: 'center', behavior: reduce ? 'auto' : 'smooth' });
      });
    });
  }

  // Form coverage: validasi ringan + indikator loading agar tidak klik dua kali
  function coverageForms() {
    $$('form[data-coverage]').forEach(function (form) {
      var input = $('input[name="alamat"]', form), btn = $('button[type="submit"]', form);
      on(form, 'submit', function (e) {
        var v = input ? input.value.replace(/\s+/g, ' ').trim() : '';
        if (v.length < 5) {
          e.preventDefault();
          form.classList.remove('invalid');
          void form.offsetWidth;
          form.classList.add('invalid');
          if (input) input.focus();
          return;
        }
        if (input) input.value = v;
        if (btn) btn.classList.add('loading');
      });
    });
    on(w, 'pageshow', function () { $$('.btn.loading').forEach(function (b) { b.classList.remove('loading'); }); });
  }

  /* ===================================================== INTERAKSI TOMBOL */
  function buttons() {
    on(d, 'click', function (e) {
      var b = e.target.closest && e.target.closest('.btn,.package-button,.nav-login');
      if (!b || reduce) return;
      var r = b.getBoundingClientRect(), s = Math.max(r.width, r.height) * 2;
      var sp = d.createElement('span');
      var light = /btn-primary|btn-wa/.test(b.className) || (b.classList.contains('package-button') && b.closest('.popular, .recommended'));
      sp.className = 'yn-ripple' + (light ? ' light' : '');
      sp.style.cssText = 'width:' + s + 'px;height:' + s + 'px;left:' + (e.clientX - r.left - s / 2) + 'px;top:' + (e.clientY - r.top - s / 2) + 'px';
      b.appendChild(sp);
      on(sp, 'animationend', function () { if (sp.parentNode) sp.parentNode.removeChild(sp); });
    });

    if (!fine || reduce) return;
    $$('.hero .btn-primary,.hero .btn-outline,.cta-actions .btn').forEach(function (b) {
      on(b, 'mousemove', function (e) {
        var r = b.getBoundingClientRect();
        b.style.setProperty('--bx', ((e.clientX - r.left - r.width / 2) * 0.16).toFixed(1) + 'px');
        b.style.setProperty('--by', ((e.clientY - r.top - r.height / 2) * 0.22).toFixed(1) + 'px');
      });
      on(b, 'mouseleave', function () { b.style.setProperty('--bx', '0px'); b.style.setProperty('--by', '0px'); });
    });
  }

  // Alur layanan: langkah menyala berurutan (aktif saat panel detail terbuka)
  function flowLanes() {
    $$('.flow-lane').forEach(function (lane) {
      var steps = $$('.flow-track li', lane);
      if (!steps.length) return;
      if (reduce || !HAS_IO) { steps.forEach(function (s) { s.classList.add('on'); }); return; }
      var i = 0, active = false;
      new IntersectionObserver(function (es) { active = es[0].isIntersecting; }, { threshold: 0.25 }).observe(lane);
      setInterval(function () {
        if (!active) return;
        if (i >= steps.length + 2) { steps.forEach(function (s) { s.classList.remove('on'); }); i = 0; }
        if (steps[i]) steps[i].classList.add('on');
        i++;
      }, 650);
    });
  }

  /* ===================================================== INIT */
  function init() {
    var hero = $('.hero');
    safe(progressBar);
    safe(navbar);
    safe(mobileBar);
    safe(buttons);
    safe(picker);
    safe(coverageForms);
    safe(prepareSplit);
    safe(prepareCounters);
    safe(scene);
    safe(flowLanes);
    if (hero) {
      safe(function () { aurora(hero); });
      safe(function () { network(hero); });
      safe(function () { heroMotion(hero); });
    }
    safe(initScrollHub);
    safe(function () { loader(afterIntro); });
    setTimeout(afterIntro, 5000); // jaga-jaga: konten tidak boleh tetap tersembunyi
  }

  if (d.readyState === 'loading') on(d, 'DOMContentLoaded', init); else init();
})();