/*!
 * YesNet - Landing page animations v2
 * -----------------------------------------------------------
 * Tanpa library. Cocok untuk markup index.php Anda
 * (.navbar, .nav-center, .reveal, .package-card, .flow-lane, .scene, ...)
 *
 * Baru di v2:
 *  - Intro: persen loading + transisi "iris wipe" melingkar
 *  - Hero: aurora blob, jaringan partikel dengan paket data berjalan,
 *    partikel menjauhi kursor, parallax 3D, bintang jatuh di scene
 *  - Judul h2 muncul kata demi kata (efek 3D)
 *  - Kartu: tilt 3D + spotlight cahaya mengikuti kursor
 *  - Kursor kustom (titik + cincin) yang membesar di atas elemen interaktif
 *  - Tombol magnetik + ripple, progress bar scroll bercahaya
 *  - Atribut opsional: data-parallax="0.15" untuk elemen apa pun
 * Menghormati prefers-reduced-motion.
 */
(function () {
  'use strict';
  if (window.__yesnetLanding) return;
  window.__yesnetLanding = true;

  var w = window, d = document;
  var reduce = !!(w.matchMedia && w.matchMedia('(prefers-reduced-motion: reduce)').matches);
  var fine = !!(w.matchMedia && w.matchMedia('(hover: hover) and (pointer: fine)').matches);
  var HEADER = 76;
  var LOADER_MIN = 1700;
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
    /* ---------- intro ---------- */
    '.yn-loader{position:fixed;inset:0;z-index:9999;display:grid;place-items:center;background:radial-gradient(circle at 50% 38%,#f2f7ff,#d3e3ff 60%,#bcd2ff);clip-path:circle(150% at 50% 45%);transition:clip-path .95s cubic-bezier(.77,0,.18,1),visibility 0s .95s}',
    '.yn-loader.out{clip-path:circle(0% at 50% 45%);visibility:hidden}',
    '.yn-loader-inner{text-align:center;transition:opacity .35s ease,transform .6s ease}',
    '.yn-loader.out .yn-loader-inner{opacity:0;transform:scale(.9)}',
    '.yn-loader::before{content:"";position:absolute;width:520px;height:520px;max-width:90vw;max-height:90vw;border-radius:50%;background:radial-gradient(circle,rgba(31,98,255,.22),transparent 65%);filter:blur(10px);animation:ynBreath 3s ease-in-out infinite}',
    '.yn-loader svg{position:relative;width:78px;height:78px;overflow:visible;filter:drop-shadow(0 6px 14px rgba(31,98,255,.35))}',
    '.yn-loader svg path{fill:none;stroke:#1f62ff;stroke-width:4;stroke-linecap:round;opacity:0;animation:ynArc 1.3s ease-in-out infinite}',
    '.yn-loader svg path:nth-child(2){animation-delay:.18s}.yn-loader svg path:nth-child(3){animation-delay:.36s}',
    '.yn-loader svg circle{fill:#1f62ff;transform-box:fill-box;transform-origin:center;animation:ynBeat 1.3s ease-in-out infinite}',
    ".yn-word{position:relative;display:flex;justify-content:center;margin-top:12px;font:800 clamp(36px,8vw,60px)/1 'Plus Jakarta Sans',system-ui,sans-serif;letter-spacing:.06em;color:#0a2463}",
    '.yn-word span{display:inline-block;opacity:0;transform:translateY(34px) rotateX(-70deg) scale(.9);filter:blur(8px);animation:ynLetter .8s cubic-bezier(.2,.9,.2,1) forwards;animation-delay:calc(.25s + var(--i) * .08s)}',
    '.yn-word span:nth-child(n+4){color:#1f62ff}',
    '.yn-tag{position:relative;margin-top:10px;font:500 13px/1 system-ui,sans-serif;letter-spacing:.18em;text-transform:uppercase;color:#5d6c8f;opacity:0;animation:ynRise .6s 1s ease forwards}',
    '.yn-bar{position:relative;width:200px;height:4px;border-radius:9px;background:rgba(10,36,99,.12);margin:20px auto 0;overflow:hidden}',
    '.yn-bar i{display:block;height:100%;background:linear-gradient(90deg,#1f62ff,#12b5ff,#a855f7);transform-origin:left;transform:scaleX(0);box-shadow:0 0 12px rgba(18,181,255,.8)}',
    '.yn-pct{position:relative;margin-top:10px;font:700 12px/1 system-ui,sans-serif;letter-spacing:.2em;color:#1f62ff;font-variant-numeric:tabular-nums}',

    /* ---------- progress + navbar ---------- */
    '.yn-progress{position:fixed;top:0;left:0;right:0;height:3px;z-index:9998;pointer-events:none;transform-origin:left;transform:scaleX(0);background:linear-gradient(90deg,#1f62ff,#12b5ff,#a855f7);box-shadow:0 0 14px rgba(31,98,255,.7),0 0 4px rgba(168,85,247,.8)}',
    '.navbar{transition:box-shadow .3s ease,background-color .3s ease,backdrop-filter .3s ease}',
    '.navbar.scrolled{box-shadow:0 8px 28px rgba(10,36,99,.10);backdrop-filter:saturate(1.4) blur(12px);-webkit-backdrop-filter:saturate(1.4) blur(12px)}',

    /* ---------- hero ---------- */
    '.hero,#home{position:relative;overflow-x:clip}',
    '.yn-canvas{position:absolute;inset:0;width:100%;height:100%;pointer-events:none;z-index:0;opacity:.85}',
    '.hero>*:not(.yn-canvas):not(.yn-blob){position:relative;z-index:1}',
    '.yn-blob{position:absolute;border-radius:50%;filter:blur(70px);opacity:.42;pointer-events:none;z-index:0;will-change:transform;animation:ynDrift 16s ease-in-out infinite alternate}',
    '.yn-blob.b1{width:420px;height:420px;left:-8%;top:-6%;background:#1f62ff}',
    '.yn-blob.b2{width:360px;height:360px;right:-6%;top:12%;background:#12b5ff;animation-duration:20s;animation-delay:-6s}',
    '.yn-blob.b3{width:300px;height:300px;left:38%;bottom:-12%;background:#a855f7;opacity:.3;animation-duration:24s;animation-delay:-11s}',
    '.yn-hero{animation:ynRise .9s cubic-bezier(.2,.8,.2,1) both}',
    '.hero h1 span{background-size:200% 100%;animation:ynShine 4.5s ease-in-out infinite alternate}',
    '.hero-visual,.hero-art{will-change:transform;transform-style:preserve-3d}',

    /* ---------- scene svg ---------- */
    '.scene .signal path{animation:ynWave 2.4s ease-in-out infinite}',
    '.scene .signal path:nth-child(2){animation-delay:.25s}.scene .signal path:nth-child(3){animation-delay:.5s}',
    '.scene .pulse{transform-box:fill-box;transform-origin:center;animation:ynBeat 1.6s ease-in-out infinite}',
    '.scene .house{animation:ynFloat 7s ease-in-out infinite}',
    '.scene .trail{stroke-dasharray:12 14;animation:ynFlow 1.4s linear infinite}',
    '.scene .house rect[fill="#ffd27a"],.scene .house rect[fill="#ffc861"]{animation:ynLight 3.2s ease-in-out infinite alternate}',
    '.yn-ring{fill:none;stroke:#22d3ee;stroke-width:2;opacity:0;transform-box:fill-box;transform-origin:center;animation:ynRing 2.4s ease-out infinite}',
    '.yn-star{opacity:.2;animation:ynTwinkle 3.5s ease-in-out infinite}',
    '.yn-shoot{stroke:#fff;stroke-width:2;stroke-linecap:round;opacity:0;filter:drop-shadow(0 0 4px #22d3ee);animation:ynShoot 7s linear infinite}',
    '.yn-packet{filter:drop-shadow(0 0 5px #22d3ee) drop-shadow(0 0 10px #a855f7)}',

    /* ---------- reveal ---------- */
    '.yn-pre{opacity:0!important;transform:translate3d(0,var(--yy,34px),0) scale(var(--ys,.97))!important;filter:blur(8px)}',
    '.yn-in{opacity:1!important;transform:none!important;filter:none;transition:opacity .9s cubic-bezier(.2,.7,.2,1),transform .9s cubic-bezier(.2,.7,.2,1),filter .9s ease}',
    '.yn-done{opacity:1!important}',

    /* ---------- judul kata demi kata ---------- */
    '.yn-split{perspective:700px}',
    '.yn-w{display:inline-block;opacity:0;transform:translateY(.7em) rotateX(-55deg) scale(.96);transform-origin:50% 100%;filter:blur(6px);transition:opacity .7s ease,transform .8s cubic-bezier(.2,.9,.2,1),filter .7s ease;transition-delay:calc(var(--wi) * 70ms)}',
    '.yn-split-in .yn-w{opacity:1;transform:none;filter:none}',

    /* ---------- kartu: spotlight ---------- */
    '.yn-glow{position:absolute;inset:0;border-radius:inherit;pointer-events:none;opacity:0;transition:opacity .35s ease;background:radial-gradient(380px circle at var(--mx,50%) var(--my,50%),rgba(31,98,255,.16),rgba(18,181,255,.06) 40%,transparent 65%);z-index:2}',
    '.yn-hov>.yn-glow{opacity:1}',

    /* ---------- tombol ---------- */
    '.btn,.package-button,.nav-login{position:relative;overflow:hidden}',
    '.hero-actions .btn,.hero-actions a{transition:transform .25s cubic-bezier(.2,.8,.2,1)}',
    '.yn-ripple{position:absolute;border-radius:50%;pointer-events:none;transform:scale(0);animation:ynRipple .7s ease-out forwards;background:rgba(31,98,255,.18)}',
    '.yn-ripple.light{background:rgba(255,255,255,.45)}',

    /* ---------- kursor ---------- */
    '.yn-cur{position:fixed;left:0;top:0;pointer-events:none;z-index:10000;opacity:0;transition:opacity .3s ease;will-change:transform}',
    '.yn-cur.show{opacity:1}',
    '.yn-cur-dot i{display:block;width:7px;height:7px;margin:-3.5px 0 0 -3.5px;border-radius:50%;background:#1f62ff;box-shadow:0 0 10px rgba(31,98,255,.8)}',
    '.yn-cur-ring i{display:block;width:38px;height:38px;margin:-19px 0 0 -19px;border-radius:50%;border:1.5px solid rgba(31,98,255,.5);transition:transform .3s cubic-bezier(.2,.8,.2,1),background .3s ease,border-color .3s ease}',
    '.yn-cur-ring.hov i{transform:scale(1.7);background:rgba(31,98,255,.08);border-color:rgba(18,181,255,.85)}',
    '.yn-cur-ring.down i{transform:scale(.7);background:rgba(31,98,255,.18)}',

    /* ---------- alur layanan ---------- */
    '.flow-lane li.on{animation:ynPop .6s cubic-bezier(.2,.9,.2,1)}',

    /* ---------- keyframes ---------- */
    '@keyframes ynArc{0%,100%{opacity:0}40%{opacity:1}}',
    '@keyframes ynLetter{to{opacity:1;transform:none;filter:none}}',
    '@keyframes ynBreath{50%{transform:scale(1.18);opacity:.8}}',
    '@keyframes ynRise{from{opacity:0;transform:translateY(24px)}to{opacity:1;transform:none}}',
    '@keyframes ynShine{from{background-position:0% 50%}to{background-position:100% 50%}}',
    '@keyframes ynWave{0%,100%{opacity:.25}35%{opacity:1}}',
    '@keyframes ynBeat{50%{transform:scale(1.35)}}',
    '@keyframes ynFloat{50%{transform:translateY(-4px)}}',
    '@keyframes ynFlow{to{stroke-dashoffset:-52}}',
    '@keyframes ynLight{from{opacity:.78}to{opacity:1}}',
    '@keyframes ynRing{0%{transform:scale(1);opacity:.8}100%{transform:scale(7);opacity:0}}',
    '@keyframes ynTwinkle{50%{opacity:.95}}',
    '@keyframes ynRipple{to{transform:scale(1);opacity:0}}',
    '@keyframes ynDrift{0%{transform:translate3d(0,0,0) scale(1)}50%{transform:translate3d(50px,-30px,0) scale(1.15)}100%{transform:translate3d(-30px,40px,0) scale(.95)}}',
    '@keyframes ynShoot{0%{transform:translate(0,0);opacity:0}2%{opacity:1}12%{transform:translate(230px,100px);opacity:0}100%{transform:translate(230px,100px);opacity:0}}',
    '@keyframes ynPop{0%{transform:scale(.9)}60%{transform:scale(1.07)}100%{transform:scale(1)}}',

    '@media (prefers-reduced-motion:reduce){.yn-loader,.yn-canvas,.yn-progress,.yn-blob,.yn-cur{display:none!important}}',
    '@media (hover:none),(pointer:coarse){.yn-cur{display:none!important}}'
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
      '<div class="yn-bar"><i></i></div>' +
      '<div class="yn-pct">0%</div></div>';
    d.body.appendChild(el);

    var bar = $('.yn-bar i', el), pct = $('.yn-pct', el);
    var prevOverflow = d.body.style.overflow;
    d.body.style.overflow = 'hidden';

    var t0 = Date.now(), loaded = d.readyState === 'complete', fin = false, prog = 0;
    var timer = setInterval(function () {
      var target = Math.min(1, (Date.now() - t0) / LOADER_MIN);
      if (!loaded) target = Math.min(target, 0.92);
      prog += (target - prog) * 0.2;
      var v = fin ? 1 : prog;
      bar.style.transform = 'scaleX(' + v.toFixed(3) + ')';
      pct.textContent = Math.round(v * 100) + '%';
      if (!fin && loaded && target >= 1 && prog > 0.985) out();
    }, 40);

    function out() {
      if (fin) return;
      fin = true;
      bar.style.transform = 'scaleX(1)';
      pct.textContent = '100%';
      setTimeout(function () {
        el.classList.add('out');
        d.body.style.overflow = prevOverflow;
        clearInterval(timer);
        try { sessionStorage.setItem('yn_seen', '1'); } catch (e) {}
        setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 1100);
        done();
      }, 220);
    }
    if (!loaded) on(w, 'load', function () { loaded = true; });
    setTimeout(out, 4200); // batas aman
  }

  function heroIntro() {
    var sel = '.hero .eyebrow,.hero h1,.hero .hero-description,.hero .hero-actions,.hero .hero-stats,.hero .hero-visual';
    $$(sel).forEach(function (el, i) {
      if (el.classList.contains('hero-in')) return;
      el.style.animationDelay = (i * 0.13) + 's';
      el.classList.add('yn-hero');
    });
  }

  /* ===================================================== 2. NAV */
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
      var h = d.documentElement.scrollHeight - w.innerHeight;
      b.style.transform = 'scaleX(' + (h > 0 ? Math.min(1, (w.pageYOffset || 0) / h) : 0) + ')';
    });
  }

  function navbar() {
    var nav = $('.navbar');
    onScrollRaf(function () { if (nav) nav.classList.toggle('scrolled', (w.pageYOffset || 0) > 30); });

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

  function easeInOut(t) { return t < 0.5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2; }
  function smoothAnchors() {
    on(d, 'click', function (e) {
      var a = e.target.closest && e.target.closest('a[href^="#"]');
      if (!a) return;
      var id = a.getAttribute('href').slice(1);
      var t = id && d.getElementById(id);
      if (!t) return;
      e.preventDefault();
      var from = w.pageYOffset || 0;
      var to = t.getBoundingClientRect().top + from - HEADER + 1;
      if (reduce) { w.scrollTo(0, to); }
      else {
        var dist = to - from, dur = Math.min(1300, 500 + Math.abs(dist) * 0.35), t0 = null;
        (function step(ts) {
          if (t0 === null) t0 = ts;
          var p = Math.min(1, (ts - t0) / dur);
          w.scrollTo(0, from + dist * easeInOut(p));
          if (p < 1) requestAnimationFrame(step);
        })(performance.now());
      }
      if (w.history && history.replaceState) history.replaceState(null, '', '#' + id);
    });
  }

  /* ===================================================== 3. REVEAL + COUNTER + SPLIT */
  var REVEAL_SEL = '.reveal,.section-heading,.advantage-card,.feature,.package-card,.paket-card,.coverage-box,.coverage,.flow-lane,.proses-list li,.footer-column,.footer-brand,.footer-copy';
  var TILT_SEL = '.package-card,.paket-card,.advantage-card,.feature';
  var revealEls = [], revealStarted = false;

  function prepareReveal() {
    $$(REVEAL_SEL).forEach(function (el) {
      if (revealEls.indexOf(el) > -1 || (el.closest && el.closest('.hero'))) return;
      revealEls.push(el);
      if (reduce) return;
      var idx = Array.prototype.indexOf.call(el.parentNode.children, el);
      el.style.setProperty('--yd', Math.min(idx, 6) * 90 + 'ms');
      if (el.matches('.coverage-box,.coverage')) { el.style.setProperty('--yy', '40px'); el.style.setProperty('--ys', '.94'); }
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
    }, 1000 + delay);
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

  // Pecah teks judul menjadi kata-kata (menjaga elemen anak seperti <span>)
  var splitTargets = [];
  function splitNode(node, counter) {
    var kids = Array.prototype.slice.call(node.childNodes);
    kids.forEach(function (n) {
      if (n.nodeType === 3) {
        var parts = n.nodeValue.split(/(\s+)/), frag = d.createDocumentFragment();
        parts.forEach(function (p) {
          if (!p) return;
          if (/^\s+$/.test(p)) { frag.appendChild(d.createTextNode(' ')); return; }
          var s = d.createElement('span');
          s.className = 'yn-w';
          s.style.setProperty('--wi', counter.n++);
          s.textContent = p;
          frag.appendChild(s);
        });
        node.replaceChild(frag, n);
      } else if (n.nodeType === 1 && n.nodeName !== 'BR') {
        splitNode(n, counter);
      }
    });
  }
  function prepareSplit() {
    if (reduce) return;
    $$('section h2,.section-heading h2').forEach(function (h) {
      if (h.classList.contains('yn-split') || (h.closest && h.closest('.hero'))) return;
      var label = h.textContent.replace(/\s+/g, ' ').trim();
      if (!label || label.length > 120) return;
      h.setAttribute('aria-label', label);
      splitNode(h, { n: 0 });
      $$('.yn-w', h).forEach(function (s) { s.setAttribute('aria-hidden', 'true'); });
      h.classList.add('yn-split');
      splitTargets.push(h);
    });
  }
  function startSplit() {
    if (!splitTargets.length) return;
    if (!('IntersectionObserver' in w)) { splitTargets.forEach(function (h) { h.classList.add('yn-split-in'); }); return; }
    var io = new IntersectionObserver(function (es) {
      es.forEach(function (e) {
        if (!e.isIntersecting) return;
        io.unobserve(e.target);
        e.target.classList.add('yn-split-in');
      });
    }, { threshold: 0.35 });
    splitTargets.forEach(function (h) { io.observe(h); });
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
    var start = null, dur = 1800;
    function step(ts) {
      if (start === null) start = ts;
      var t = Math.min(1, (ts - start) / dur), e = t === 1 ? 1 : 1 - Math.pow(2, -10 * t); // easeOutExpo
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
      var g = svgEl('g', {}), i;
      for (i = 0; i < 18; i++) {
        var s = svgEl('circle', {
          cx: (Math.random() * 540 + 10).toFixed(0),
          cy: (Math.random() * 110 + 8).toFixed(0),
          r: (Math.random() * 1.4 + 0.6).toFixed(1),
          fill: '#fff', 'class': 'yn-star'
        });
        s.style.animationDelay = (Math.random() * 4).toFixed(2) + 's';
        g.appendChild(s);
      }
      // bintang jatuh
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
        var an = svgEl('animate', { attributeName: 'opacity', values: '0;1;1;0', keyTimes: '0;.08;.92;1', dur: dur, begin: b, repeatCount: 'indefinite' });
        c.appendChild(am);
        c.appendChild(an);
        trail.parentNode.insertBefore(c, trail.nextSibling);
      });
    }
  }

  // Blob aurora di latar hero
  function aurora(hero) {
    if (reduce) return;
    ['b1', 'b2', 'b3'].forEach(function (c) {
      var b = d.createElement('div');
      b.className = 'yn-blob ' + c;
      b.setAttribute('aria-hidden', 'true');
      hero.insertBefore(b, hero.firstChild);
    });
  }

  // Parallax 3D mengikuti mouse + sedikit bergeser saat scroll
  function heroMotion(hero) {
    var vis = $('.hero-visual,.hero-art', hero);
    if (!vis || reduce) return;
    var tx = 0, ty = 0, cx = 0, cy = 0, raf = 0;
    function frame() {
      cx += (tx - cx) * 0.08;
      cy += (ty - cy) * 0.08;
      var py = Math.min(70, (w.pageYOffset || 0) * 0.06);
      vis.style.transform = 'translate3d(0,' + py.toFixed(1) + 'px,0) perspective(1100px) rotateY(' + cx.toFixed(2) + 'deg) rotateX(' + cy.toFixed(2) + 'deg)';
      raf = (Math.abs(tx - cx) > 0.02 || Math.abs(ty - cy) > 0.02) ? requestAnimationFrame(frame) : 0;
    }
    function kick() { if (!raf) raf = requestAnimationFrame(frame); }
    on(w, 'scroll', kick, { passive: true });
    if (fine) {
      on(hero, 'mousemove', function (e) {
        var r = hero.getBoundingClientRect();
        tx = ((e.clientX - r.left) / r.width - 0.5) * 11;
        ty = -((e.clientY - r.top) / r.height - 0.5) * 8;
        kick();
      });
      on(hero, 'mouseleave', function () { tx = 0; ty = 0; kick(); });
    }
  }

  // Jaringan partikel + paket data yang berjalan antar titik
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

    function build() {
      var r = hero.getBoundingClientRect();
      W = r.width; H = r.height;
      cv.width = Math.round(W * dpr); cv.height = Math.round(H * dpr);
      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
      var n = Math.max(16, Math.min(52, Math.round(W * H / 22000)));
      pts = []; packets = [];
      for (var i = 0; i < n; i++) {
        pts.push({
          x: Math.random() * W, y: Math.random() * H,
          vx: (Math.random() - 0.5) * 0.4, vy: (Math.random() - 0.5) * 0.4,
          r: Math.random() * 1.7 + 0.8, c: COLORS[Math.floor(Math.random() * 3)],
          ph: Math.random() * 6.28
        });
      }
    }
    function draw(ts) {
      ctx.clearRect(0, 0, W, H);
      var L = 135, i, j, p, q, dx, dy, dist, links = [];
      for (i = 0; i < pts.length; i++) {
        p = pts[i];
        // menjauhi kursor dengan halus
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
        var tw = 0.45 + 0.25 * Math.sin(ts * 0.002 + p.ph);
        ctx.fillStyle = 'rgba(' + p.c + ',' + tw.toFixed(2) + ')';
        ctx.beginPath(); ctx.arc(p.x, p.y, p.r, 0, 6.2832); ctx.fill();
      }

      // lahirkan paket data baru di salah satu koneksi
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
    if ('IntersectionObserver' in w) {
      new IntersectionObserver(function (es) { visible = es[0].isIntersecting; visible ? start() : stop(); }).observe(hero);
    }
    start();
  }

  // Parallax scroll untuk elemen dengan data-parallax="0.15"
  function parallax() {
    if (reduce) return;
    var els = $$('[data-parallax]');
    if (!els.length) return;
    onScrollRaf(function () {
      var vh = w.innerHeight;
      els.forEach(function (el) {
        var r = el.getBoundingClientRect();
        if (r.bottom < -100 || r.top > vh + 100) return;
        var sp = parseFloat(el.getAttribute('data-parallax')) || 0.1;
        var off = (r.top + r.height / 2 - vh / 2) * -sp;
        el.style.transform = 'translate3d(0,' + off.toFixed(1) + 'px,0)';
      });
    });
  }

  /* ===================================================== 5. INTERAKSI */
  function attachTilt(el) {
    if (!fine || reduce || el._yt || !el.matches(TILT_SEL)) return;
    el._yt = 1;
    if (w.getComputedStyle(el).position === 'static') el.style.position = 'relative';
    var glow = d.createElement('span');
    glow.className = 'yn-glow';
    glow.setAttribute('aria-hidden', 'true');
    el.appendChild(glow);
    var lift = el.matches('.package-card,.paket-card') ? ' translateY(-8px)' : ' translateY(-3px)';
    on(el, 'mouseenter', function () { el.style.transition = 'transform .15s ease-out,box-shadow .3s ease'; el.classList.add('yn-hov'); });
    on(el, 'mousemove', function (e) {
      var r = el.getBoundingClientRect();
      var x = (e.clientX - r.left) / r.width - 0.5, y = (e.clientY - r.top) / r.height - 0.5;
      el.style.setProperty('--mx', (x + 0.5) * 100 + '%');
      el.style.setProperty('--my', (y + 0.5) * 100 + '%');
      el.style.transform = 'perspective(800px) rotateX(' + (-y * 8).toFixed(2) + 'deg) rotateY(' + (x * 8).toFixed(2) + 'deg)' + lift;
    });
    on(el, 'mouseleave', function () {
      el.classList.remove('yn-hov');
      el.style.transition = 'transform .7s cubic-bezier(.2,.7,.2,1),box-shadow .3s ease';
      el.style.transform = '';
    });
  }

  function buttons() {
    var SEL = '.btn,.package-button,.nav-login';
    on(d, 'click', function (e) {
      var b = e.target.closest && e.target.closest(SEL);
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
    $$('.hero-actions .btn,.hero-actions a,.package-button,.nav-login').forEach(function (b) {
      on(b, 'mousemove', function (e) {
        var r = b.getBoundingClientRect();
        b.style.transform = 'translate(' + ((e.clientX - r.left - r.width / 2) * 0.22).toFixed(1) + 'px,' + ((e.clientY - r.top - r.height / 2) * 0.3).toFixed(1) + 'px)';
      });
      on(b, 'mouseleave', function () { b.style.transform = ''; });
    });
  }

  // Kursor kustom
  function cursor() {
    if (!fine || reduce) return;
    var dot = d.createElement('div'), ring = d.createElement('div');
    dot.className = 'yn-cur yn-cur-dot'; dot.innerHTML = '<i></i>';
    ring.className = 'yn-cur yn-cur-ring'; ring.innerHTML = '<i></i>';
    dot.setAttribute('aria-hidden', 'true'); ring.setAttribute('aria-hidden', 'true');
    d.body.appendChild(ring); d.body.appendChild(dot);

    var mx = 0, my = 0, rx = 0, ry = 0, raf = 0, shown = false;
    function loop() {
      rx += (mx - rx) * 0.18; ry += (my - ry) * 0.18;
      dot.style.transform = 'translate3d(' + mx + 'px,' + my + 'px,0)';
      ring.style.transform = 'translate3d(' + rx.toFixed(1) + 'px,' + ry.toFixed(1) + 'px,0)';
      raf = (Math.abs(mx - rx) > 0.1 || Math.abs(my - ry) > 0.1) ? requestAnimationFrame(loop) : 0;
    }
    on(d, 'mousemove', function (e) {
      mx = e.clientX; my = e.clientY;
      if (!shown) { shown = true; rx = mx; ry = my; dot.classList.add('show'); ring.classList.add('show'); }
      if (!raf) raf = requestAnimationFrame(loop);
      var hov = e.target.closest && e.target.closest('a,button,.btn,input,select,textarea,label,' + TILT_SEL);
      ring.classList.toggle('hov', !!hov);
    }, { passive: true });
    on(d, 'mousedown', function () { ring.classList.add('down'); });
    on(d, 'mouseup', function () { ring.classList.remove('down'); });
    on(d.documentElement, 'mouseleave', function () { dot.classList.remove('show'); ring.classList.remove('show'); shown = false; });
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
    safe(cursor);
    safe(prepareReveal);
    safe(prepareSplit);
    safe(prepareCounters);
    safe(scene);
    safe(flowLanes);
    safe(parallax);
    if (hero) {
      safe(function () { aurora(hero); });
      safe(function () { network(hero); });
      safe(function () { heroMotion(hero); });
    }
    safe(initScrollHub);

    function afterIntro() {
      if (introDone) return;
      introDone = true;
      safe(heroIntro);
      safe(startReveal);
      safe(startSplit);
      safe(startCounters);
    }
    safe(function () { loader(afterIntro); });
    setTimeout(afterIntro, 5500); // jaga-jaga: konten tidak boleh tetap tersembunyi
  }

  if (d.readyState === 'loading') on(d, 'DOMContentLoaded', init); else init();
})();
