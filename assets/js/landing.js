/*!
 * YesNet - Landing page animations
 * -----------------------------------------------------------
 * Tanpa library. Bekerja untuk markup index.php Anda
 * (.navbar, .nav-center, .reveal, .package-card, .flow-lane, .scene, ...)
 * maupun versi saya (.nav-links, .paket-card, ...).
 *
 * Isi:
 *  1. Intro "YESNET" (sekali per sesi) + intro hero berurutan
 *  2. Progress bar scroll + navbar aktif + smooth scroll
 *  3. Scroll-reveal bertahap (stagger) + hitung naik angka
 *  4. Hero: jaringan partikel, parallax 3D mengikuti mouse,
 *     sinyal WiFi bergelombang, paket data berjalan di jalur, bintang
 *  5. Tilt 3D kartu, ripple & magnetic tombol, animasi alur layanan
 * Menghormati prefers-reduced-motion.
 */
(function () {
  'use strict';
  if (window.__yesnetLanding) return;
  window.__yesnetLanding = true;

  var w = window, d = document;
  var reduce = !!(w.matchMedia && w.matchMedia('(prefers-reduced-motion: reduce)').matches);
  var fine = !!(w.matchMedia && w.matchMedia('(hover: hover) and (pointer: fine)').matches);
  var HEADER = 76;                 // tinggi navbar untuk offset scroll
  var LOADER_MIN = 1500;           // lama minimal intro (ms)
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

  /* ===================================================== CSS */
  var CSS = [
    /* intro */
    '.yn-loader{position:fixed;inset:0;z-index:9999;display:grid;place-items:center;background:radial-gradient(circle at 50% 38%,#f2f7ff,#d3e3ff 60%,#bcd2ff);transition:opacity .7s ease,visibility .7s}',
    '.yn-loader.out{opacity:0;visibility:hidden}',
    '.yn-loader-inner{text-align:center}',
    '.yn-loader svg{width:78px;height:78px;overflow:visible}',
    '.yn-loader svg path{fill:none;stroke:#1f62ff;stroke-width:4;stroke-linecap:round;opacity:0;animation:ynArc 1.3s ease-in-out infinite}',
    '.yn-loader svg path:nth-child(2){animation-delay:.18s}.yn-loader svg path:nth-child(3){animation-delay:.36s}',
    '.yn-loader svg circle{fill:#1f62ff;transform-box:fill-box;transform-origin:center;animation:ynBeat 1.3s ease-in-out infinite}',
    ".yn-word{display:flex;justify-content:center;margin-top:12px;font:800 clamp(36px,8vw,60px)/1 'Plus Jakarta Sans',system-ui,sans-serif;letter-spacing:.06em;color:#0a2463}",
    '.yn-word span{display:inline-block;opacity:0;transform:translateY(26px) scale(.9);filter:blur(6px);animation:ynLetter .7s cubic-bezier(.2,.9,.2,1) forwards;animation-delay:calc(.25s + var(--i) * .08s)}',
    '.yn-word span:nth-child(n+4){color:#1f62ff}',
    '.yn-tag{margin-top:10px;font:500 13px/1 system-ui,sans-serif;letter-spacing:.18em;text-transform:uppercase;color:#5d6c8f;opacity:0;animation:ynRise .6s 1s ease forwards}',
    '.yn-bar{width:190px;height:3px;border-radius:9px;background:rgba(10,36,99,.12);margin:18px auto 0;overflow:hidden}',
    '.yn-bar i{display:block;height:100%;background:linear-gradient(90deg,#1f62ff,#12b5ff);transform-origin:left;transform:scaleX(0);animation:ynBar 1.4s .2s ease forwards}',

    /* progress + navbar */
    '.yn-progress{position:fixed;top:0;left:0;right:0;height:3px;z-index:9998;pointer-events:none;transform-origin:left;transform:scaleX(0);background:linear-gradient(90deg,#1f62ff,#12b5ff,#a855f7)}',
    '.navbar{transition:box-shadow .3s ease,background-color .3s ease}',
    '.navbar.scrolled{box-shadow:0 8px 28px rgba(10,36,99,.10)}',

    /* hero */
    '.hero,#home{position:relative}',
    '.yn-canvas{position:absolute;inset:0;width:100%;height:100%;pointer-events:none;z-index:0;opacity:.75}',
    '.hero>*:not(.yn-canvas){position:relative;z-index:1}',
    '.yn-hero{animation:ynRise .9s cubic-bezier(.2,.8,.2,1) both}',
    '.hero h1 span{background-size:200% 100%;animation:ynShine 4.5s ease-in-out infinite alternate}',
    '.hero-visual,.hero-art{will-change:transform;transform-style:preserve-3d}',

    /* scene svg */
    '.scene .signal path{animation:ynWave 2.4s ease-in-out infinite}',
    '.scene .signal path:nth-child(2){animation-delay:.25s}.scene .signal path:nth-child(3){animation-delay:.5s}',
    '.scene .pulse{transform-box:fill-box;transform-origin:center;animation:ynBeat 1.6s ease-in-out infinite}',
    '.scene .house{animation:ynFloat 7s ease-in-out infinite}',
    '.scene .trail{stroke-dasharray:12 14;animation:ynFlow 1.4s linear infinite}',
    '.scene .house rect[fill="#ffd27a"],.scene .house rect[fill="#ffc861"]{animation:ynLight 3.2s ease-in-out infinite alternate}',
    '.yn-ring{fill:none;stroke:#22d3ee;stroke-width:2;opacity:0;transform-box:fill-box;transform-origin:center;animation:ynRing 2.4s ease-out infinite}',
    '.yn-star{opacity:.2;animation:ynTwinkle 3.5s ease-in-out infinite}',
    '.yn-packet{filter:drop-shadow(0 0 5px #22d3ee) drop-shadow(0 0 10px #a855f7)}',

    /* reveal */
    '.yn-pre{opacity:0!important;transform:translate3d(0,var(--yy,28px),0) scale(var(--ys,1))!important;filter:blur(6px)}',
    '.yn-in{opacity:1!important;transform:none!important;filter:none;transition:opacity .85s cubic-bezier(.2,.7,.2,1),transform .85s cubic-bezier(.2,.7,.2,1),filter .85s ease}',
    '.yn-done{opacity:1!important}',

    /* tombol */
    '.btn,.package-button,.nav-login{position:relative;overflow:hidden}',
    '.yn-ripple{position:absolute;border-radius:50%;pointer-events:none;transform:scale(0);animation:ynRipple .65s ease-out forwards;background:rgba(31,98,255,.18)}',
    '.yn-ripple.light{background:rgba(255,255,255,.45)}',

    /* keyframes */
    '@keyframes ynArc{0%,100%{opacity:0}40%{opacity:1}}',
    '@keyframes ynLetter{to{opacity:1;transform:none;filter:none}}',
    '@keyframes ynBar{to{transform:scaleX(1)}}',
    '@keyframes ynRise{from{opacity:0;transform:translateY(22px)}to{opacity:1;transform:none}}',
    '@keyframes ynShine{from{background-position:0% 50%}to{background-position:100% 50%}}',
    '@keyframes ynWave{0%,100%{opacity:.25}35%{opacity:1}}',
    '@keyframes ynBeat{50%{transform:scale(1.35)}}',
    '@keyframes ynFloat{50%{transform:translateY(-4px)}}',
    '@keyframes ynFlow{to{stroke-dashoffset:-52}}',
    '@keyframes ynLight{from{opacity:.78}to{opacity:1}}',
    '@keyframes ynRing{0%{transform:scale(1);opacity:.8}100%{transform:scale(7);opacity:0}}',
    '@keyframes ynTwinkle{50%{opacity:.95}}',
    '@keyframes ynRipple{to{transform:scale(1);opacity:0}}',

    '@media (prefers-reduced-motion:reduce){.yn-loader,.yn-canvas,.yn-progress{display:none!important}}'
  ].join('');

  /* ===================================================== 1. INTRO */
  var introDone = false;
  function loader(done) {
    var seen = false;
    try { seen = !!sessionStorage.getItem('yn_seen'); } catch (e) {}
    if (reduce || seen || w.location.hash) { done(); return; }

    var el = d.createElement('div');
    el.className = 'yn-loader';
    el.setAttribute('aria-hidden', 'true');
    el.innerHTML =
      '<div class="yn-loader-inner">' +
      '<svg viewBox="0 0 34 34"><path d="M3 12a20 20 0 0128 0"/><path d="M8 18a13 13 0 0118 0"/><path d="M13 24a6 6 0 018 0"/><circle cx="17" cy="29" r="1.8"/></svg>' +
      '<div class="yn-word">' + 'YESNET'.split('').map(function (c, i) { return '<span style="--i:' + i + '">' + c + '</span>'; }).join('') + '</div>' +
      '<div class="yn-tag">Internet Cepat &amp; Stabil</div>' +
      '<div class="yn-bar"><i></i></div></div>';
    d.body.appendChild(el);

    var prevOverflow = d.body.style.overflow;
    d.body.style.overflow = 'hidden';

    var t0 = Date.now(), loaded = d.readyState === 'complete', fin = false;
    function out() {
      if (fin) return;
      fin = true;
      el.classList.add('out');
      d.body.style.overflow = prevOverflow;
      try { sessionStorage.setItem('yn_seen', '1'); } catch (e) {}
      setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 800);
      done();
    }
    function tryOut() { if (loaded) setTimeout(out, Math.max(0, LOADER_MIN - (Date.now() - t0))); }
    if (!loaded) on(w, 'load', function () { loaded = true; tryOut(); }); else tryOut();
    setTimeout(out, 3800); // batas aman
  }

  function heroIntro() {
    var sel = '.hero .eyebrow,.hero h1,.hero .hero-description,.hero .hero-actions,.hero .hero-stats,.hero .hero-visual';
    $$(sel).forEach(function (el, i) {
      if (el.classList.contains('hero-in')) return; // sudah dianimasikan CSS
      el.style.animationDelay = (i * 0.12) + 's';
      el.classList.add('yn-hero');
    });
  }

  /* ===================================================== 2. NAV */
  function progressBar() {
    var b = d.createElement('div');
    b.className = 'yn-progress';
    b.setAttribute('aria-hidden', 'true');
    d.body.appendChild(b);
    var tick = false;
    function upd() {
      var h = d.documentElement.scrollHeight - w.innerHeight;
      b.style.transform = 'scaleX(' + (h > 0 ? Math.min(1, (w.pageYOffset || 0) / h) : 0) + ')';
      tick = false;
    }
    on(w, 'scroll', function () { if (!tick) { tick = true; requestAnimationFrame(upd); } }, { passive: true });
    on(w, 'resize', upd);
    upd();
  }

  function navbar() {
    var nav = $('.navbar');
    function onScroll() { if (nav) nav.classList.toggle('scrolled', (w.pageYOffset || 0) > 30); }
    on(w, 'scroll', onScroll, { passive: true });
    onScroll();

    var links = $$('.nav-links a[href^="#"],.nav-center a[href^="#"]');
    var map = {}, secs = [];
    links.forEach(function (a) {
      var id = a.getAttribute('href').slice(1), s = id && d.getElementById(id);
      if (s) { map[id] = a; secs.push(s); }
    });
    if (!secs.length || !('IntersectionObserver' in w)) return;
    var so = new IntersectionObserver(function (es) {
      es.forEach(function (e) {
        if (!e.isIntersecting) return;
        links.forEach(function (l) { l.classList.remove('active'); });
        if (map[e.target.id]) map[e.target.id].classList.add('active');
      });
    }, { rootMargin: '-35% 0px -55% 0px' });
    secs.forEach(function (s) { so.observe(s); });
  }

  function smoothAnchors() {
    on(d, 'click', function (e) {
      var a = e.target.closest && e.target.closest('a[href^="#"]');
      if (!a) return;
      var id = a.getAttribute('href').slice(1);
      var t = id && d.getElementById(id);
      if (!t) return;
      e.preventDefault();
      var y = t.getBoundingClientRect().top + (w.pageYOffset || 0) - HEADER + 1;
      w.scrollTo({ top: y, behavior: reduce ? 'auto' : 'smooth' });
      if (w.history && history.replaceState) history.replaceState(null, '', '#' + id);
    });
  }

  /* ===================================================== 3. REVEAL + COUNTER */
  var REVEAL_SEL = '.reveal,.section-heading,.advantage-card,.feature,.package-card,.paket-card,.coverage-box,.coverage,.flow-lane,.proses-list li,.footer-column,.footer-brand,.footer-copy';
  var TILT_SEL = '.package-card,.paket-card,.advantage-card,.feature';
  var revealEls = [], revealStarted = false;

  function prepareReveal() {
    $$(REVEAL_SEL).forEach(function (el) {
      if (revealEls.indexOf(el) > -1 || (el.closest && el.closest('.hero'))) return;
      revealEls.push(el);
      if (reduce) return;
      var idx = Array.prototype.indexOf.call(el.parentNode.children, el);
      el.style.setProperty('--yd', Math.min(idx, 6) * 80 + 'ms');
      if (el.matches('.coverage-box,.coverage')) { el.style.setProperty('--yy', '34px'); el.style.setProperty('--ys', '.96'); }
      el.classList.add('yn-pre');
    });
  }

  function markShown(el) {
    el.classList.remove('yn-pre', 'yn-in');
    el.classList.add('show', 'is-visible', 'yn-done');
  }

  function show(el) {
    var delay = parseInt(el.style.getPropertyValue('--yd'), 10) || 0;
    el.style.transitionDelay = delay + 'ms';
    el.classList.add('yn-in', 'show', 'is-visible');
    setTimeout(function () {
      el.style.transitionDelay = '';
      markShown(el);
      attachTilt(el);
    }, 950 + delay);
  }

  function startReveal() {
    if (revealStarted) return;
    revealStarted = true;
    if (reduce || !('IntersectionObserver' in w)) {
      revealEls.forEach(function (el) { markShown(el); attachTilt(el); });
      return;
    }
    var io = new IntersectionObserver(function (es) {
      es.forEach(function (e) {
        if (!e.isIntersecting) return;
        io.unobserve(e.target);
        show(e.target);
      });
    }, { threshold: 0.12, rootMargin: '0px 0px -6% 0px' });
    revealEls.forEach(function (el) { io.observe(el); });
  }

  // Hitung naik: "99,9%", "Rp 200.000", "20 Mbps"
  var COUNT_SEL = '.hero-stat strong,.hero-stats b,.package-price,.paket-price,.package-speed,.paket-speed';
  var counters = [];
  function fmt(v, dec) {
    var s = v.toFixed(dec).split('.');
    s[0] = s[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');
    return s.join(',');
  }
  function parseNum(str) {
    var m = /\d[\d.,]*/.exec(str);
    if (!m) return null;
    var t = m[0].replace(/[.,]+$/, ''), v, dec = 0;
    if (/^\d{1,3}(\.\d{3})+$/.test(t)) v = parseInt(t.replace(/\./g, ''), 10);
    else if (/^\d+,\d+$/.test(t)) { dec = t.split(',')[1].length; v = parseFloat(t.replace(',', '.')); }
    else if (/^\d+$/.test(t)) v = parseInt(t, 10);
    else return null;
    return { pre: str.slice(0, m.index), post: str.slice(m.index + t.length), v: v, dec: dec };
  }
  function prepareCounters() {
    if (reduce) return;
    $$(COUNT_SEL).forEach(function (el) {
      for (var i = 0; i < el.childNodes.length; i++) {
        var n = el.childNodes[i];
        if (n.nodeType === 3 && /\d/.test(n.nodeValue)) {
          var p = parseNum(n.nodeValue);
          if (p) { n.nodeValue = p.pre + fmt(0, p.dec) + p.post; counters.push({ el: el, node: n, p: p }); }
          break;
        }
      }
    });
  }
  function runCounter(c) {
    var start = null, dur = 1500;
    function step(ts) {
      if (start === null) start = ts;
      var t = Math.min(1, (ts - start) / dur), e = 1 - Math.pow(1 - t, 3);
      c.node.nodeValue = c.p.pre + fmt(c.p.v * e, c.p.dec) + c.p.post;
      if (t < 1) requestAnimationFrame(step);
    }
    requestAnimationFrame(step);
  }
  function startCounters() {
    if (!counters.length) return;
    if (!('IntersectionObserver' in w)) {
      counters.forEach(function (c) { c.node.nodeValue = c.p.pre + fmt(c.p.v, c.p.dec) + c.p.post; });
      return;
    }
    var io = new IntersectionObserver(function (es) {
      es.forEach(function (e) {
        if (!e.isIntersecting) return;
        io.unobserve(e.target);
        counters.forEach(function (c) { if (c.el === e.target) runCounter(c); });
      });
    }, { threshold: 0.6 });
    counters.forEach(function (c) { io.observe(c.el); });
  }

  /* ===================================================== 4. HERO */
  function scene() {
    var svg = $('.scene');
    if (!svg || reduce) return;

    var house = $('.house', svg);
    if (house && house.parentNode) {
      var g = svgEl('g', {});
      for (var i = 0; i < 16; i++) {
        var s = svgEl('circle', {
          cx: (Math.random() * 540 + 10).toFixed(0),
          cy: (Math.random() * 110 + 8).toFixed(0),
          r: (Math.random() * 1.4 + 0.6).toFixed(1),
          fill: '#fff', 'class': 'yn-star'
        });
        s.style.animationDelay = (Math.random() * 4).toFixed(2) + 's';
        g.appendChild(s);
      }
      house.parentNode.insertBefore(g, house);
    }

    var pulse = $('.pulse', svg);
    if (pulse && pulse.parentNode) {
      [0, 1.2].forEach(function (dl) {
        var r = svgEl('circle', { cx: pulse.getAttribute('cx'), cy: pulse.getAttribute('cy'), r: '6', 'class': 'yn-ring' });
        r.style.animationDelay = dl + 's';
        pulse.parentNode.insertBefore(r, pulse);
      });
    }

    var trail = $('.trail', svg);
    if (trail && trail.parentNode) {
      if (!trail.id) trail.id = 'ynTrail';
      [0, 1.7, 3.4].forEach(function (begin) {
        var dur = '5.2s', b = begin + 's';
        var c = svgEl('circle', { r: '4.5', fill: '#fff', opacity: '0', 'class': 'yn-packet' });
        var am = svgEl('animateMotion', { dur: dur, begin: b, repeatCount: 'indefinite' });
        var mp = svgEl('mpath', {});
        mp.setAttribute('href', '#' + trail.id);
        mp.setAttributeNS('http://www.w3.org/1999/xlink', 'href', '#' + trail.id);
        am.appendChild(mp);
        var an = svgEl('animate', { attributeName: 'opacity', values: '0;1;1;0', keyTimes: '0;.08;.92;1', dur: dur, begin: b, repeatCount: 'indefinite' });
        c.appendChild(am);
        c.appendChild(an);
        trail.parentNode.insertBefore(c, trail.nextSibling);
      });
    }
  }

  // Parallax 3D mengikuti mouse + sedikit bergeser saat scroll
  function heroMotion(hero) {
    var vis = $('.hero-visual,.hero-art', hero);
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
        tx = ((e.clientX - r.left) / r.width - 0.5) * 9;
        ty = -((e.clientY - r.top) / r.height - 0.5) * 7;
        kick();
      });
      on(hero, 'mouseleave', function () { tx = 0; ty = 0; kick(); });
    }
  }

  // Jaringan partikel di latar hero
  function network(hero) {
    if (reduce) return;
    var cv = d.createElement('canvas');
    cv.className = 'yn-canvas';
    cv.setAttribute('aria-hidden', 'true');
    hero.insertBefore(cv, hero.firstChild);
    var ctx = cv.getContext('2d');
    if (!ctx) return;

    var W = 0, H = 0, dpr = Math.min(w.devicePixelRatio || 1, 2), pts = [];
    var mouse = { x: -9999, y: -9999 }, raf = 0, visible = true;

    function build() {
      var r = hero.getBoundingClientRect();
      W = r.width; H = r.height;
      cv.width = Math.round(W * dpr); cv.height = Math.round(H * dpr);
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      var n = Math.max(14, Math.min(46, Math.round(W * H / 24000)));
      pts = [];
      for (var i = 0; i < n; i++) {
        pts.push({ x: Math.random() * W, y: Math.random() * H, vx: (Math.random() - 0.5) * 0.35, vy: (Math.random() - 0.5) * 0.35, r: Math.random() * 1.6 + 0.8 });
      }
    }
    function draw() {
      ctx.clearRect(0, 0, W, H);
      var L = 130, i, j, p, q, dx, dy, dist;
      for (i = 0; i < pts.length; i++) {
        p = pts[i];
        p.x += p.vx; p.y += p.vy;
        if (p.x < 0 || p.x > W) p.vx *= -1;
        if (p.y < 0 || p.y > H) p.vy *= -1;
        for (j = i + 1; j < pts.length; j++) {
          q = pts[j]; dx = p.x - q.x; dy = p.y - q.y; dist = Math.sqrt(dx * dx + dy * dy);
          if (dist < L) {
            ctx.strokeStyle = 'rgba(31,98,255,' + (0.22 * (1 - dist / L)).toFixed(3) + ')';
            ctx.lineWidth = 1;
            ctx.beginPath(); ctx.moveTo(p.x, p.y); ctx.lineTo(q.x, q.y); ctx.stroke();
          }
        }
        dx = p.x - mouse.x; dy = p.y - mouse.y; dist = Math.sqrt(dx * dx + dy * dy);
        if (dist < 160) {
          ctx.strokeStyle = 'rgba(18,181,255,' + (0.4 * (1 - dist / 160)).toFixed(3) + ')';
          ctx.beginPath(); ctx.moveTo(p.x, p.y); ctx.lineTo(mouse.x, mouse.y); ctx.stroke();
        }
        ctx.fillStyle = 'rgba(31,98,255,.55)';
        ctx.beginPath(); ctx.arc(p.x, p.y, p.r, 0, 6.2832); ctx.fill();
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
    if ('IntersectionObserver' in w) {
      new IntersectionObserver(function (es) { visible = es[0].isIntersecting; visible ? start() : stop(); }).observe(hero);
    }
    start();
  }

  /* ===================================================== 5. INTERAKSI */
  function attachTilt(el) {
    if (!fine || reduce || el._yt || !el.matches(TILT_SEL)) return;
    el._yt = 1;
    var lift = el.matches('.package-card,.paket-card') ? ' translateY(-6px)' : '';
    on(el, 'mouseenter', function () { el.style.transition = 'transform .15s ease-out'; });
    on(el, 'mousemove', function (e) {
      var r = el.getBoundingClientRect();
      var x = (e.clientX - r.left) / r.width - 0.5, y = (e.clientY - r.top) / r.height - 0.5;
      el.style.transform = 'perspective(800px) rotateX(' + (-y * 6).toFixed(2) + 'deg) rotateY(' + (x * 6).toFixed(2) + 'deg)' + lift;
    });
    on(el, 'mouseleave', function () {
      el.style.transition = 'transform .6s cubic-bezier(.2,.7,.2,1)';
      el.style.transform = '';
    });
  }

  function buttons() {
    on(d, 'click', function (e) {
      var b = e.target.closest && e.target.closest('.btn,.package-button,.nav-login');
      if (!b || reduce) return;
      var r = b.getBoundingClientRect(), s = Math.max(r.width, r.height) * 2;
      var sp = d.createElement('span');
      var light = /btn-primary|nav-login/.test(b.className) || (b.classList.contains('package-button') && b.closest('.popular'));
      sp.className = 'yn-ripple' + (light ? ' light' : '');
      sp.style.cssText = 'width:' + s + 'px;height:' + s + 'px;left:' + (e.clientX - r.left - s / 2) + 'px;top:' + (e.clientY - r.top - s / 2) + 'px';
      b.appendChild(sp);
      on(sp, 'animationend', function () { if (sp.parentNode) sp.parentNode.removeChild(sp); });
    });

    if (!fine || reduce) return;
    $$('.hero-actions .btn,.hero-actions a').forEach(function (b) {
      on(b, 'mousemove', function (e) {
        var r = b.getBoundingClientRect();
        b.style.transform = 'translate(' + ((e.clientX - r.left - r.width / 2) * 0.18).toFixed(1) + 'px,' + ((e.clientY - r.top - r.height / 2) * 0.25).toFixed(1) + 'px)';
      });
      on(b, 'mouseleave', function () { b.style.transform = ''; });
    });
  }

  // Alur layanan: langkah menyala berurutan, lalu mengulang
  function flowLanes() {
    $$('.flow-lane').forEach(function (lane) {
      var track = $('.flow-track', lane);
      if (!track) return;
      var steps = $$('li', track);
      if (reduce || !('IntersectionObserver' in w)) { steps.forEach(function (s) { s.classList.add('on'); }); return; }
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
    var style = d.createElement('style');
    style.id = 'yn-style';
    style.textContent = CSS;
    d.head.appendChild(style);

    var hero = $('.hero') || $('#home');

    safe(progressBar);
    safe(navbar);
    safe(smoothAnchors);
    safe(buttons);
    safe(prepareReveal);
    safe(prepareCounters);
    safe(scene);
    safe(flowLanes);
    if (hero) { safe(function () { network(hero); }); safe(function () { heroMotion(hero); }); }

    function afterIntro() {
      if (introDone) return;
      introDone = true;
      safe(heroIntro);
      safe(startReveal);
      safe(startCounters);
    }
    safe(function () { loader(afterIntro); });
    setTimeout(afterIntro, 5000); // jaga-jaga: konten tidak boleh tetap tersembunyi
  }

  if (d.readyState === 'loading') on(d, 'DOMContentLoaded', init); else init();
})();