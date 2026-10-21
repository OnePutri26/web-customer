/*!
 * YesNet - Landing page v4
 * Tanpa library. Semua gaya ada di landing.css, file ini hanya mengatur perilaku:
 * intro, reveal, menu mobile, filter wilayah (Jakarta/Surabaya), pemilih paket,
 * simulasi promo, cek coverage, serta efek hero (partikel, aurora, parallax 3D).
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
  var REGION_NAMES = { jakarta: 'Jakarta', surabaya: 'Surabaya' };

  /* penghubung antar modul (diisi saat init) */
  var hooks = { pick: null, calc: null };
  var region = 'jakarta';

  function $(s, r) { return (r || d).querySelector(s); }
  function $$(s, r) { return Array.prototype.slice.call((r || d).querySelectorAll(s)); }
  function on(el, ev, fn, opt) { el.addEventListener(ev, fn, opt || false); }
  function safe(fn) { try { fn(); } catch (e) { if (w.console) console.warn('[yesnet]', e); } }
  function clear(el) { while (el.firstChild) el.removeChild(el.firstChild); }
  function txt(s) { return d.createTextNode(s); }
  function mk(tag, cls, text) {
    var el = d.createElement(tag);
    if (cls) el.className = cls;
    if (text !== undefined) el.textContent = text;
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
        }, 800);
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
    var b = mk('div', 'yn-progress');
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
    var glow = mk('span', 'yn-glow');
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
    setTimeout(function () { el.classList.add('done'); attachTilt(el); }, 900 + delay);
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
          if (/^\s+$/.test(p)) { frag.appendChild(txt(' ')); return; }
          var s = mk('span', 'yn-w', p);
          s.style.setProperty('--wi', c.n++);
          s.setAttribute('aria-hidden', 'true');
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

  /* Animasi angka umum (untuk simulasi promo) */
  function tweenNum(el, to, fmt) {
    var from = typeof el._v === 'number' ? el._v : 0;
    el._v = to;
    if (reduce || from === to) { el.textContent = fmt(to); return; }
    var t0 = null, dur = 650;
    function step(ts) {
      if (t0 === null) t0 = ts;
      var t = Math.min(1, (ts - t0) / dur), e = 1 - Math.pow(1 - t, 3);
      el.textContent = fmt(from + (to - from) * e);
      if (t < 1 && el._v === to) requestAnimationFrame(step); else el.textContent = fmt(el._v);
    }
    requestAnimationFrame(step);
  }
  function rupiah(v) { return 'Rp ' + fmtNum(Math.round(v), 0); }

  /* ===================================================== HERO */
  function aurora(hero) {
    if (reduce) return;
    ['b1', 'b2', 'b3'].forEach(function (c) {
      var b = mk('div', 'yn-blob ' + c);
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
    var cv = mk('canvas', 'yn-canvas');
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

  /* ===================================================== FILTER WILAYAH (JAKARTA / SURABAYA) */
  function regionFilter() {
    var btns = $$('.region-btn[data-region]'), grid = $('.packages-grid');
    if (!btns.length || !grid) return;
    var cards = $$('.package-card', grid), cap = $('#region-caption'), empty = $('#packages-empty');
    var footerAddr = $('#footer-alamat'), addrText = footerAddr && $('.addr-text', footerAddr);
    region = d.body.getAttribute('data-region') || 'jakarta';

    function renderPrice(card, price, animate) {
      var box = $('.package-price', card);
      if (!box) return;
      function build() {
        clear(box);
        if (price) {
          box.appendChild(mk('span', 'cur', 'Rp'));
          box.appendChild(txt(price));
          box.appendChild(mk('small', '', '/bulan'));
        } else {
          box.appendChild(mk('span', 'cur', 'Hubungi kami'));
        }
      }
      if (!animate || reduce) { build(); return; }
      box.classList.remove('swap-in');
      box.classList.add('swap-out');
      setTimeout(function () {
        build();
        box.classList.remove('swap-out');
        box.classList.add('swap-in');
        setTimeout(function () { box.classList.remove('swap-in'); }, 450);
      }, 170);
    }

    function setCaption(r, n) {
      if (!cap) return;
      clear(cap);
      cap.appendChild(txt('Menampilkan '));
      cap.appendChild(mk('strong', '', n + ' paket'));
      cap.appendChild(txt(' untuk wilayah '));
      cap.appendChild(mk('strong', '', REGION_NAMES[r] || r));
      cap.appendChild(txt('.'));
    }

    function apply(r, animate) {
      region = r;
      d.body.setAttribute('data-region', r);
      btns.forEach(function (b) {
        var act = b.getAttribute('data-region') === r;
        b.classList.toggle('active', act);
        b.setAttribute('aria-pressed', act ? 'true' : 'false');
      });

      var shown = 0;
      cards.forEach(function (card) {
        var ok = (card.getAttribute('data-avail') || '').split(' ').indexOf(r) > -1;
        card.hidden = !ok;
        if (!ok) return;
        shown++;
        card.setAttribute('data-region', r);
        var price = card.getAttribute('data-price-' + r) || '';
        card.setAttribute('data-price', price);
        renderPrice(card, price, animate);

        var a = $('.package-button', card);
        if (a) {
          a.setAttribute('href', a.getAttribute('href').replace(/wilayah=[a-z]+/i, 'wilayah=' + r));
          a.setAttribute('aria-label', 'Pilih paket ' + card.getAttribute('data-name') + ' wilayah ' + (REGION_NAMES[r] || r));
        }
        showEl(card);
        if (animate && !reduce) {
          card.classList.remove('region-in');
          void card.offsetWidth;
          card.style.setProperty('--k', shown - 1);
          card.classList.add('region-in');
        }
      });

      if (empty) empty.hidden = shown > 0;
      setCaption(r, shown);

      if (addrText && footerAddr) {
        var nextAddr = footerAddr.getAttribute('data-alamat-' + r);
        if (nextAddr && addrText.textContent !== nextAddr) {
          addrText.style.opacity = '0';
          setTimeout(function () { addrText.textContent = nextAddr; addrText.style.opacity = '1'; }, reduce ? 0 : 220);
        }
      }

      try { history.replaceState(null, '', '?wilayah=' + r + '#paket'); } catch (e) {}
      if (hooks.pick) hooks.pick();
      if (hooks.calc) hooks.calc(true);
    }

    btns.forEach(function (b) {
      on(b, 'click', function (e) {
        e.preventDefault();
        var r = b.getAttribute('data-region');
        if (r === region) return;
        apply(r, true);
      });
    });
  }

  /* ===================================================== PEMILIH KEBUTUHAN */
  function picker() {
    var chips = $$('.chip[data-pick]'), out = $('#pick-result');
    if (!chips.length || !out) return;

    function show(ch, scroll) {
      var id = ch.getAttribute('data-pick');
      var card = $('.package-card[data-id="' + id + '"]');
      if (!card) return;
      chips.forEach(function (c) {
        var act = c === ch;
        c.classList.toggle('active', act);
        c.setAttribute('aria-pressed', act ? 'true' : 'false');
      });
      $$('.package-card.recommended').forEach(function (c) { c.classList.remove('recommended'); });
      clear(out);

      if (card.hidden) {
        out.appendChild(txt('Paket yang cocok belum tersedia di wilayah ' + (REGION_NAMES[region] || region) + '. Coba pilih wilayah lain atau hubungi kami.'));
        out.classList.add('show');
        return;
      }
      card.classList.add('recommended');
      out.appendChild(txt('Kami sarankan paket '));
      var b = mk('strong');
      var pr = card.getAttribute('data-price-' + region);
      b.textContent = card.getAttribute('data-name') + ' (' + card.getAttribute('data-speed') + ' Mbps' + (pr ? ', Rp ' + pr + '/bulan' : '') + ')';
      out.appendChild(b);
      var btn = $('.package-button', card);
      if (btn) {
        var a = mk('a', 'picker-go', 'Pilih paket ini →');
        a.href = btn.getAttribute('href');
        out.appendChild(a);
      }
      out.classList.add('show');

      if (scroll) {
        var r = card.getBoundingClientRect();
        if (r.top < 90 || r.bottom > w.innerHeight - 20) card.scrollIntoView({ block: 'center', behavior: reduce ? 'auto' : 'smooth' });
      }
    }

    chips.forEach(function (ch) { on(ch, 'click', function () { show(ch, true); }); });
    hooks.pick = function () { var act = $('.chip.active'); if (act) show(act, false); };
  }

  /* ===================================================== PROMO + SIMULASI HEMAT */
  function promoCalc() {
    var box = $('#promo-calc');
    if (!box) return;
    var data;
    try { data = JSON.parse(box.getAttribute('data-promos') || '{}'); } catch (e) { return; }
    var keys = Object.keys(data);
    if (!keys.length) return;

    var selPromo = $('#calc-promo'), selTier = $('#calc-tier'), selPaket = $('#calc-paket');
    var res = $('#calc-result'), note = $('#calc-note'), go = $('#calc-go'), wa = $('#calc-wa');
    var regionLbl = $('.calc-region', box);
    var base = {
      bayar: $('#rs-bayar'), bayarBln: $('#rs-bayar-bln'),
      dapat: $('#rs-dapat'), gratis: $('#rs-gratis'),
      hemat: $('#rs-hemat'), eff: $('#rs-eff')
    };
    var NOTE_DEFAULT = note ? note.textContent : '';

    function opt(value, label) { var o = d.createElement('option'); o.value = value; o.textContent = label; return o; }

    keys.forEach(function (k) { selPromo.appendChild(opt(k, data[k].nama)); });

    function fillTiers(keep) {
      var cur = keep ? selTier.value : '0';
      clear(selTier);
      data[selPromo.value].tiers.forEach(function (t, i) { selTier.appendChild(opt(String(i), t.label + ' (total ' + t.total + ' bulan)')); });
      selTier.value = (+cur < data[selPromo.value].tiers.length) ? cur : '0';
    }

    function fillPaket() {
      var cur = selPaket.value;
      clear(selPaket);
      $$('.package-card').forEach(function (card) {
        if (card.hidden) return;
        selPaket.appendChild(opt(card.getAttribute('data-id'), card.getAttribute('data-name') + ' - ' + card.getAttribute('data-speed') + ' Mbps'));
      });
      if (cur && $('option[value="' + cur + '"]', selPaket)) selPaket.value = cur;
    }

    function markTier() {
      $$('.tier').forEach(function (b) {
        b.classList.toggle('on', b.getAttribute('data-promo') === selPromo.value && b.getAttribute('data-tier') === selTier.value);
      });
    }

    function dash() {
      ['bayar', 'hemat'].forEach(function (k) { base[k]._v = undefined; base[k].textContent = '-'; });
      base.bayarBln.textContent = ''; base.eff.textContent = '';
    }

    function calc(bump) {
      if (regionLbl) regionLbl.textContent = REGION_NAMES[region] || region;
      var pr = data[selPromo.value], t = pr && pr.tiers[+selTier.value];
      var card = selPaket.value ? $('.package-card[data-id="' + selPaket.value + '"]') : null;
      if (!t || !card) {
        dash();
        base.dapat.textContent = '-'; base.gratis.textContent = '';
        if (note) note.textContent = 'Paket belum tersedia di wilayah ini. Pilih wilayah lain atau hubungi kami.';
        return;
      }
      var gratis = t.total - t.bayar;
      base.dapat.textContent = t.total + ' bulan';
      base.gratis.textContent = 'Gratis ' + gratis + ' bulan';
      base.bayarBln.textContent = 'untuk ' + t.bayar + ' bulan';

      var raw = parseFloat(card.getAttribute('data-raw-' + region));
      if (isNaN(raw) || raw <= 0) {
        dash();
        base.bayarBln.textContent = 'untuk ' + t.bayar + ' bulan';
        if (note) note.textContent = 'Harga paket ini belum tampil. Hubungi kami untuk total biaya promo.';
      } else {
        var total = raw * t.bayar, normal = raw * t.total;
        tweenNum(base.bayar, total, rupiah);
        tweenNum(base.hemat, normal - total, rupiah);
        base.eff.textContent = 'Setara ' + rupiah(total / t.total) + ' per bulan';
        if (note) note.textContent = NOTE_DEFAULT;
      }

      var name = card.getAttribute('data-name');
      var href = box.getAttribute('data-pilih') + '?paket=' + encodeURIComponent(card.getAttribute('data-id')) +
        '&wilayah=' + encodeURIComponent(region) + '&promo=' + encodeURIComponent(selPromo.value) + '&bayar=' + t.bayar;
      if (go) go.setAttribute('href', href);
      if (wa) {
        var msg = 'Halo YesNet, saya tertarik promo ' + pr.nama + ' (' + t.label + ') untuk ' + name + ' wilayah ' + (REGION_NAMES[region] || region) + '. Mohon info pendaftarannya.';
        wa.setAttribute('href', box.getAttribute('data-wa') + '?text=' + encodeURIComponent(msg));
      }
      markTier();
      if (bump && !reduce) { res.classList.remove('bump'); void res.offsetWidth; res.classList.add('bump'); }
    }

    fillTiers(false);
    fillPaket();
    calc(false);

    on(selPromo, 'change', function () { fillTiers(false); calc(true); });
    on(selTier, 'change', function () { calc(true); });
    on(selPaket, 'change', function () { calc(true); });

    // klik skema pada kartu promo -> isi simulasi + sorot kotak simulasi
    $$('.tier').forEach(function (b) {
      on(b, 'click', function () {
        selPromo.value = b.getAttribute('data-promo');
        fillTiers(false);
        selTier.value = b.getAttribute('data-tier');
        calc(true);
        box.classList.remove('flash'); void box.offsetWidth; box.classList.add('flash');
        var r = box.getBoundingClientRect();
        if (r.top < 80 || r.bottom > w.innerHeight) box.scrollIntoView({ block: 'center', behavior: reduce ? 'auto' : 'smooth' });
      });
    });

    hooks.calc = function () { fillPaket(); calc(true); };
  }

  /* ===================================================== COVERAGE */
  // Form coverage: validasi ringan + indikator loading agar tidak klik dua kali
  function coverageForms() {
    $$('form[data-coverage]').forEach(function (form) {
      var input = $('input[name="alamat"]', form), btn = $('button[type="submit"]', form);
      on(form, 'submit', function (e) {
        var v = input ? input.value.replace(/\s+/g, ' ').trim() : '';
        if (v.length < 10) {
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
      var b = e.target.closest && e.target.closest('.btn,.package-button,.nav-login,.region-btn');
      if (!b || reduce) return;
      var r = b.getBoundingClientRect(), s = Math.max(r.width, r.height) * 2;
      var sp = mk('span');
      var light = /btn-primary|btn-wa|btn-promo/.test(b.className) || b.classList.contains('active') || (b.classList.contains('package-button') && b.closest('.popular, .recommended'));
      sp.className = 'yn-ripple' + (light ? ' light' : '');
      sp.style.cssText = 'width:' + s + 'px;height:' + s + 'px;left:' + (e.clientX - r.left - s / 2) + 'px;top:' + (e.clientY - r.top - s / 2) + 'px';
      b.appendChild(sp);
      on(sp, 'animationend', function () { if (sp.parentNode) sp.parentNode.removeChild(sp); });
    });

    if (!fine || reduce) return;
    $$('.hero .btn-primary,.hero .btn-outline,.hero .btn-promo,.cta-actions .btn').forEach(function (b) {
      on(b, 'mousemove', function (e) {
        var r = b.getBoundingClientRect();
        b.style.setProperty('--bx', ((e.clientX - r.left - r.width / 2) * 0.16).toFixed(1) + 'px');
        b.style.setProperty('--by', ((e.clientY - r.top - r.height / 2) * 0.22).toFixed(1) + 'px');
      });
      on(b, 'mouseleave', function () { b.style.setProperty('--bx', '0px'); b.style.setProperty('--by', '0px'); });
    });
  }

  /* ===================================================== INIT */
  function init() {
    var hero = $('.hero');
    safe(progressBar);
    safe(navbar);
    safe(mobileBar);
    safe(buttons);
    safe(regionFilter);
    safe(picker);
    safe(promoCalc);
    safe(coverageForms);
    safe(prepareSplit);
    safe(prepareCounters);
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