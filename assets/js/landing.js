
/*!
 * YesNet - Landing page v3
 * Tanpa library.
 * Fitur:
 * - Intro loader
 * - Reveal animation, split heading, counter
 * - Navbar desktop dan mobile
 * - Pemilih paket berdasarkan kartu dari PHP
 * - Form coverage
 * - Hero: partikel, aurora, parallax 3D
 * - Efek tombol dan flow layanan
 * - Mendukung prefers-reduced-motion
 *
 * Database tetap diakses oleh PHP, bukan JavaScript.
 */
(function () {
  'use strict';

  if (window.__yesnet) return;
  window.__yesnet = true;

  var w = window;
  var d = document;
  var root = d.documentElement;

  var reduce = !!(
    w.matchMedia &&
    w.matchMedia('(prefers-reduced-motion: reduce)').matches
  );

  var fine = !!(
    w.matchMedia &&
    w.matchMedia('(hover: hover) and (pointer: fine)').matches
  );

  var HAS_IO = 'IntersectionObserver' in w;
  var LOADER_MIN = 1000;
  var SVGNS = 'http://www.w3.org/2000/svg';

  function $(selector, scope) {
    return (scope || d).querySelector(selector);
  }

  function $$(selector, scope) {
    return Array.prototype.slice.call(
      (scope || d).querySelectorAll(selector)
    );
  }

  function on(element, eventName, callback, options) {
    if (element) {
      element.addEventListener(eventName, callback, options || false);
    }
  }

  function safe(callback) {
    try {
      callback();
    } catch (error) {
      if (w.console) {
        console.warn('[yesnet]', error);
      }
    }
  }

  function svgEl(name, attributes) {
    var element = d.createElementNS(SVGNS, name);

    Object.keys(attributes || {}).forEach(function (key) {
      element.setAttribute(key, attributes[key]);
    });

    return element;
  }

  root.classList.add('js');

  /* =====================================================
     INTRO / LOADER
  ===================================================== */

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
    var element = $('#loader');

    if (
      !element ||
      !root.classList.contains('loading') ||
      reduce
    ) {
      if (element && element.parentNode) {
        element.parentNode.removeChild(element);
      }

      root.classList.remove('loading');
      done();
      return;
    }

    var bar = $('.ld-bar i', element);
    var percent = $('.ld-pct', element);

    var startedAt = Date.now();
    var loaded = d.readyState === 'complete';
    var finished = false;
    var progress = 0;
    var timer;

    function finish() {
      if (finished) return;

      finished = true;
      clearInterval(timer);

      if (bar) {
        bar.style.transform = 'scaleX(1)';
      }

      if (percent) {
        percent.textContent = '100%';
      }

      setTimeout(function () {
        element.classList.add('out');
        done();

        try {
          sessionStorage.setItem('yn_seen', '1');
        } catch (error) {}

        setTimeout(function () {
          root.classList.remove('loading');

          if (element.parentNode) {
            element.parentNode.removeChild(element);
          }
        }, 1000);
      }, 160);
    }

    timer = setInterval(function () {
      var target = Math.min(
        1,
        (Date.now() - startedAt) / LOADER_MIN
      );

      if (!loaded) {
        target = Math.min(target, 0.92);
      }

      progress += (target - progress) * 0.22;

      if (bar) {
        bar.style.transform = 'scaleX(' + progress.toFixed(3) + ')';
      }

      if (percent) {
        percent.textContent =
          Math.round(progress * 100) + '%';
      }

      if (loaded && target >= 1 && progress > 0.985) {
        finish();
      }
    }, 40);

    if (!loaded) {
      on(w, 'load', function () {
        loaded = true;
      });
    }

    setTimeout(finish, 3500);
  }

  /* =====================================================
     NAVIGASI DAN SCROLL
  ===================================================== */

  var scrollSubscribers = [];

  function onScrollRaf(callback) {
    scrollSubscribers.push(callback);
  }

  function initScrollHub() {
    var ticking = false;

    function run() {
      ticking = false;

      scrollSubscribers.forEach(function (callback) {
        callback();
      });
    }

    on(w, 'scroll', function () {
      if (!ticking) {
        ticking = true;
        requestAnimationFrame(run);
      }
    }, { passive: true });

    on(w, 'resize', function () {
      requestAnimationFrame(run);
    });

    run();
  }

  function progressBar() {
    var bar = d.createElement('div');

    bar.className = 'yn-progress';
    bar.setAttribute('aria-hidden', 'true');

    d.body.appendChild(bar);

    onScrollRaf(function () {
      var height = root.scrollHeight - w.innerHeight;
      var scroll = w.pageYOffset || 0;

      var progress = height > 0
        ? Math.min(1, scroll / height)
        : 0;

      bar.style.transform = 'scaleX(' + progress + ')';
    });
  }

  function navbar() {
    var nav = $('.navbar');
    var toggle = $('.nav-toggle');
    var menu = $('#menu');

    onScrollRaf(function () {
      if (nav) {
        nav.classList.toggle(
          'scrolled',
          (w.pageYOffset || 0) > 30
        );
      }
    });

    function setMenu(open) {
      root.classList.toggle('menu-open', open);

      if (toggle) {
        toggle.setAttribute(
          'aria-expanded',
          open ? 'true' : 'false'
        );

        toggle.setAttribute(
          'aria-label',
          open ? 'Tutup menu' : 'Buka menu'
        );
      }
    }

    if (toggle) {
      on(toggle, 'click', function () {
        setMenu(!root.classList.contains('menu-open'));
      });

      on(d, 'keydown', function (event) {
        if (event.key === 'Escape') {
          setMenu(false);
        }
      });

      on(w, 'resize', function () {
        if (w.innerWidth > 960) {
          setMenu(false);
        }
      });

      if (menu) {
        on(menu, 'click', function (event) {
          if (
            event.target.closest &&
            event.target.closest('a')
          ) {
            setMenu(false);
          }
        });
      }

      on(d, 'click', function (event) {
        if (
          root.classList.contains('menu-open') &&
          !(
            event.target.closest &&
            event.target.closest('.navbar')
          )
        ) {
          setMenu(false);
        }
      });
    }

    var links = $$('.nav-center a[href^="#"]');
    var map = {};
    var sections = [];

    links.forEach(function (link) {
      var id = link.getAttribute('href').slice(1);
      var section = id && d.getElementById(id);

      if (section) {
        map[id] = link;
        sections.push(section);
      }
    });

    if (!sections.length || !HAS_IO) return;

    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;

        links.forEach(function (link) {
          link.classList.remove('active');
        });

        if (map[entry.target.id]) {
          map[entry.target.id].classList.add('active');
        }
      });
    }, {
      rootMargin: '-35% 0px -55% 0px'
    });

    sections.forEach(function (section) {
      observer.observe(section);
    });
  }

  function mobileBar() {
    var bar = $('#mbar');

    if (!bar) return;

    onScrollRaf(function () {
      bar.classList.toggle(
        'show',
        (w.pageYOffset || 0) > 520
      );
    });
  }

  /* =====================================================
     REVEAL / TILT
  ===================================================== */

  function attachTilt(element) {
    if (
      !fine ||
      reduce ||
      element._yt ||
      !element.classList.contains('tilt')
    ) {
      return;
    }

    element._yt = 1;

    if (w.getComputedStyle(element).position === 'static') {
      element.style.position = 'relative';
    }

    var glow = d.createElement('span');

    glow.className = 'yn-glow';
    glow.setAttribute('aria-hidden', 'true');

    element.appendChild(glow);

    on(element, 'mouseenter', function () {
      element.classList.add('yn-hov');
    });

    on(element, 'mousemove', function (event) {
      var rect = element.getBoundingClientRect();

      var x = (event.clientX - rect.left) / rect.width - 0.5;
      var y = (event.clientY - rect.top) / rect.height - 0.5;

      element.style.setProperty(
        '--mx',
        (x + 0.5) * 100 + '%'
      );

      element.style.setProperty(
        '--my',
        (y + 0.5) * 100 + '%'
      );

      element.style.setProperty(
        '--rx',
        (-y * 7).toFixed(2) + 'deg'
      );

      element.style.setProperty(
        '--ry',
        (x * 7).toFixed(2) + 'deg'
      );
    });

    on(element, 'mouseleave', function () {
      element.classList.remove('yn-hov');
      element.style.setProperty('--rx', '0deg');
      element.style.setProperty('--ry', '0deg');
    });
  }

  function showEl(element) {
    if (element.classList.contains('is-visible')) return;

    element.classList.add('is-visible');

    var delay = element._d || 0;

    setTimeout(function () {
      element.classList.add('done');
      attachTilt(element);
    }, 1000 + delay);
  }

  function startReveal() {
    var elements = $$('.reveal');

    elements.forEach(function (element) {
      var siblings = Array.prototype.filter.call(
        element.parentNode.children,
        function (child) {
          return child.classList.contains('reveal');
        }
      );

      element._d = Math.min(
        siblings.indexOf(element),
        5
      ) * 90;

      element.style.setProperty('--d', element._d + 'ms');
    });

    if (reduce || !HAS_IO) {
      elements.forEach(showEl);
      return;
    }

    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;

        observer.unobserve(entry.target);
        showEl(entry.target);
      });
    }, {
      threshold: 0.12,
      rootMargin: '0px 0px -6% 0px'
    });

    elements.forEach(function (element) {
      observer.observe(element);
    });
  }

  /* =====================================================
     SPLIT HEADING
  ===================================================== */

  var splitTargets = [];

  function splitNode(node, counter) {
    Array.prototype.slice.call(node.childNodes).forEach(function (child) {
      if (child.nodeType === 3) {
        var fragment = d.createDocumentFragment();

        child.nodeValue.split(/(\s+)/).forEach(function (part) {
          if (!part) return;

          if (/^\s+$/.test(part)) {
            fragment.appendChild(d.createTextNode(' '));
            return;
          }

          var span = d.createElement('span');

          span.className = 'yn-w';
          span.style.setProperty('--wi', counter.n++);
          span.setAttribute('aria-hidden', 'true');
          span.textContent = part;

          fragment.appendChild(span);
        });

        node.replaceChild(fragment, child);
      } else if (
        child.nodeType === 1 &&
        child.nodeName !== 'BR'
      ) {
        if (child.classList.contains('grad')) {
          child.classList.add('yn-w');
          child.style.setProperty('--wi', counter.n++);
          child.style.display = 'inline-block';
        } else {
          splitNode(child, counter);
        }
      }
    });
  }

  function prepareSplit() {
    if (reduce) return;

    $$('.section-heading h2').forEach(function (heading) {
      var label = heading.textContent
        .replace(/\s+/g, ' ')
        .trim();

      if (!label || label.length > 120) return;

      heading.setAttribute('aria-label', label);

      splitNode(heading, { n: 0 });

      heading.classList.add('yn-split');
      splitTargets.push(heading);
    });
  }

  function startSplit() {
    if (!splitTargets.length) return;

    if (!HAS_IO) {
      splitTargets.forEach(function (heading) {
        heading.classList.add('yn-split-in');
      });

      return;
    }

    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;

        observer.unobserve(entry.target);
        entry.target.classList.add('yn-split-in');
      });
    }, {
      threshold: 0.35
    });

    splitTargets.forEach(function (heading) {
      observer.observe(heading);
    });
  }

  /* =====================================================
     COUNTERS
  ===================================================== */

  function fmtNum(value, decimals) {
    var parts = value.toFixed(decimals).split('.');

    parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, '.');

    return parts.join(',');
  }

  var counters = [];

  function prepareCounters() {
    if (reduce) return;

    $$('[data-count]').forEach(function (element) {
      var decimals = parseInt(
        element.getAttribute('data-dec') || '0',
        10
      );

      counters.push(element);
      element.textContent = fmtNum(0, decimals);
    });
  }

  function runCounter(element) {
    var target = parseFloat(element.getAttribute('data-count'));
    var decimals = parseInt(
      element.getAttribute('data-dec') || '0',
      10
    );

    var startedAt = null;
    var duration = 1700;

    function step(timestamp) {
      if (startedAt === null) startedAt = timestamp;

      var progress = Math.min(
        1,
        (timestamp - startedAt) / duration
      );

      var easing = progress === 1
        ? 1
        : 1 - Math.pow(2, -10 * progress);

      element.textContent = fmtNum(
        target * easing,
        decimals
      );

      if (progress < 1) {
        requestAnimationFrame(step);
      }
    }

    requestAnimationFrame(step);
  }

  function startCounters() {
    if (!counters.length) return;

    function finalValue(element) {
      element.textContent = fmtNum(
        parseFloat(element.getAttribute('data-count')),
        parseInt(element.getAttribute('data-dec') || '0', 10)
      );
    }

    if (!HAS_IO) {
      counters.forEach(finalValue);
      return;
    }

    var observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        if (!entry.isIntersecting) return;

        observer.unobserve(entry.target);
        runCounter(entry.target);
      });
    }, {
      threshold: 0.6
    });

    counters.forEach(function (element) {
      observer.observe(element);
    });
  }

  /* =====================================================
     HERO SVG
  ===================================================== */

  function scene() {
    var svg = $('.scene');

    if (!svg || reduce) return;

    var house = $('.house', svg);

    if (house && house.parentNode) {
      var group = svgEl('g', {});

      for (var i = 0; i < 18; i++) {
        var star = svgEl('circle', {
          cx: (Math.random() * 540 + 10).toFixed(0),
          cy: (Math.random() * 110 + 8).toFixed(0),
          r: (Math.random() * 1.4 + 0.6).toFixed(1),
          fill: '#fff',
          'class': 'yn-star'
        });

        star.style.animationDelay =
          (Math.random() * 4).toFixed(2) + 's';

        group.appendChild(star);
      }

      for (var j = 0; j < 2; j++) {
        var wrap = svgEl('g', {
          transform: 'translate(' +
            (80 + j * 260 + Math.random() * 60).toFixed(0) +
            ',' +
            (10 + Math.random() * 30).toFixed(0) +
            ')'
        });

        var line = svgEl('line', {
          x1: '0',
          y1: '0',
          x2: '-44',
          y2: '-19',
          'class': 'yn-shoot'
        });

        line.style.animationDelay = (j * 3.6 + 1.2) + 's';

        wrap.appendChild(line);
        group.appendChild(wrap);
      }

      house.parentNode.insertBefore(group, house);
    }

    var pulse = $('.pulse', svg);

    if (pulse && pulse.parentNode) {
      [0, 0.8, 1.6].forEach(function (delay) {
        var ring = svgEl('circle', {
          cx: pulse.getAttribute('cx'),
          cy: pulse.getAttribute('cy'),
          r: '6',
          'class': 'yn-ring'
        });

        ring.style.animationDelay = delay + 's';

        pulse.parentNode.insertBefore(ring, pulse);
      });
    }

    var trail = $('.trail', svg);

    if (trail && trail.parentNode) {
      if (!trail.id) trail.id = 'ynTrail';

      [0, 1.3, 2.6, 3.9].forEach(function (begin) {
        var duration = '5.2s';
        var beginTime = begin + 's';

        var circle = svgEl('circle', {
          r: '4.5',
          fill: '#fff',
          opacity: '0',
          'class': 'yn-packet'
        });

        var motion = svgEl('animateMotion', {
          dur: duration,
          begin: beginTime,
          repeatCount: 'indefinite'
        });

        var path = svgEl('mpath', {});

        path.setAttribute('href', '#' + trail.id);
        path.setAttributeNS(
          'http://www.w3.org/1999/xlink',
          'href',
          '#' + trail.id
        );

        motion.appendChild(path);
        circle.appendChild(motion);

        circle.appendChild(svgEl('animate', {
          attributeName: 'opacity',
          values: '0;1;1;0',
          keyTimes: '0;.08;.92;1',
          dur: duration,
          begin: beginTime,
          repeatCount: 'indefinite'
        }));

        trail.parentNode.insertBefore(
          circle,
          trail.nextSibling
        );
      });
    }
  }

  function aurora(hero) {
    if (reduce) return;

    ['b1', 'b2', 'b3'].forEach(function (className) {
      var blob = d.createElement('div');

      blob.className = 'yn-blob ' + className;
      blob.setAttribute('aria-hidden', 'true');

      hero.insertBefore(blob, hero.firstChild);
    });
  }

  /* =====================================================
     HERO PARALLAX
  ===================================================== */

  function heroMotion(hero) {
    var visual = $('.hero-visual', hero);

    if (!visual || reduce) return;

    var targetX = 0;
    var targetY = 0;
    var currentX = 0;
    var currentY = 0;
    var raf = 0;

    function frame() {
      currentX += (targetX - currentX) * 0.08;
      currentY += (targetY - currentY) * 0.08;

      var pageY = Math.min(
        60,
        (w.pageYOffset || 0) * 0.05
      );

      visual.style.transform =
        'translate3d(0,' + pageY.toFixed(1) + 'px,0) ' +
        'perspective(1100px) ' +
        'rotateY(' + currentX.toFixed(2) + 'deg) ' +
        'rotateX(' + currentY.toFixed(2) + 'deg)';

      raf = (
        Math.abs(targetX - currentX) > 0.02 ||
        Math.abs(targetY - currentY) > 0.02
      ) ? requestAnimationFrame(frame) : 0;
    }

    function kick() {
      if (!raf) raf = requestAnimationFrame(frame);
    }

    on(w, 'scroll', kick, { passive: true });

    if (fine) {
      on(hero, 'mousemove', function (event) {
        var rect = hero.getBoundingClientRect();

        targetX =
          ((event.clientX - rect.left) / rect.width - 0.5) * 10;

        targetY =
          -((event.clientY - rect.top) / rect.height - 0.5) * 7;

        kick();
      });

      on(hero, 'mouseleave', function () {
        targetX = 0;
        targetY = 0;
        kick();
      });
    }
  }

  /* =====================================================
     HERO NETWORK CANVAS
  ===================================================== */

  function network(hero) {
    if (reduce) return;

    var canvas = d.createElement('canvas');

    canvas.className = 'yn-canvas';
    canvas.setAttribute('aria-hidden', 'true');

    hero.insertBefore(canvas, hero.firstChild);

    var ctx = canvas.getContext('2d');

    if (!ctx) return;

    var width = 0;
    var height = 0;
    var dpr = Math.min(w.devicePixelRatio || 1, 2);
    var points = [];
    var packets = [];

    var mouse = { x: -9999, y: -9999 };
    var raf = 0;
    var visible = true;

    var colors = [
      '31,98,255',
      '18,181,255',
      '168,85,247'
    ];

    var small = w.innerWidth < 700;

    function build() {
      var rect = hero.getBoundingClientRect();

      width = rect.width;
      height = rect.height;

      canvas.width = Math.round(width * dpr);
      canvas.height = Math.round(height * dpr);

      ctx.setTransform(dpr, 0, 0, dpr, 0, 0);

      var count = Math.max(
        14,
        Math.min(
          small ? 24 : 50,
          Math.round(width * height / 22000)
        )
      );

      points = [];
      packets = [];

      for (var i = 0; i < count; i++) {
        points.push({
          x: Math.random() * width,
          y: Math.random() * height,
          vx: (Math.random() - 0.5) * 0.4,
          vy: (Math.random() - 0.5) * 0.4,
          r: Math.random() * 1.7 + 0.8,
          c: colors[Math.floor(Math.random() * colors.length)],
          ph: Math.random() * 6.28
        });
      }
    }

    function draw(timestamp) {
      ctx.clearRect(0, 0, width, height);

      var limit = 135;
      var links = [];

      for (var i = 0; i < points.length; i++) {
        var point = points[i];

        var dx = point.x - mouse.x;
        var dy = point.y - mouse.y;
        var distance = Math.sqrt(dx * dx + dy * dy);

        if (distance < 120 && distance > 0.1) {
          var force = (1 - distance / 120) * 0.9;

          point.x += dx / distance * force;
          point.y += dy / distance * force;
        }

        point.x += point.vx;
        point.y += point.vy;

        if (point.x < 0 || point.x > width) {
          point.vx *= -1;
        }

        if (point.y < 0 || point.y > height) {
          point.vy *= -1;
        }

        for (var j = i + 1; j < points.length; j++) {
          var other = points[j];

          dx = point.x - other.x;
          dy = point.y - other.y;

          distance = Math.sqrt(dx * dx + dy * dy);

          if (distance < limit) {
            ctx.strokeStyle =
              'rgba(31,98,255,' +
              (0.24 * (1 - distance / limit)).toFixed(3) +
              ')';

            ctx.lineWidth = 1;
            ctx.beginPath();
            ctx.moveTo(point.x, point.y);
            ctx.lineTo(other.x, other.y);
            ctx.stroke();

            links.push([point, other]);
          }
        }

        dx = point.x - mouse.x;
        dy = point.y - mouse.y;
        distance = Math.sqrt(dx * dx + dy * dy);

        if (distance < 170) {
          ctx.strokeStyle =
            'rgba(18,181,255,' +
            (0.45 * (1 - distance / 170)).toFixed(3) +
            ')';

          ctx.beginPath();
          ctx.moveTo(point.x, point.y);
          ctx.lineTo(mouse.x, mouse.y);
          ctx.stroke();
        }

        ctx.fillStyle =
          'rgba(' + point.c + ',' +
          (0.45 + 0.25 * Math.sin(
            timestamp * 0.002 + point.ph
          )).toFixed(2) + ')';

        ctx.beginPath();
        ctx.arc(point.x, point.y, point.r, 0, 6.2832);
        ctx.fill();
      }

      if (
        links.length &&
        packets.length < 7 &&
        Math.random() < 0.05
      ) {
        var link = links[
          Math.floor(Math.random() * links.length)
        ];

        packets.push({
          a: link[0],
          b: link[1],
          t: 0,
          s: 0.012 + Math.random() * 0.012
        });
      }

      for (var k = packets.length - 1; k >= 0; k--) {
        var packet = packets[k];

        packet.t += packet.s;

        if (packet.t >= 1) {
          packets.splice(k, 1);
          continue;
        }

        var px = packet.a.x +
          (packet.b.x - packet.a.x) * packet.t;

        var py = packet.a.y +
          (packet.b.y - packet.a.y) * packet.t;

        var gradient = ctx.createRadialGradient(
          px, py, 0,
          px, py, 11
        );

        gradient.addColorStop(0, 'rgba(34,211,238,.95)');
        gradient.addColorStop(1, 'rgba(34,211,238,0)');

        ctx.fillStyle = gradient;
        ctx.beginPath();
        ctx.arc(px, py, 11, 0, 6.2832);
        ctx.fill();

        ctx.fillStyle = '#fff';
        ctx.beginPath();
        ctx.arc(px, py, 1.8, 0, 6.2832);
        ctx.fill();
      }

      raf = requestAnimationFrame(draw);
    }

    function start() {
      if (raf || !visible || d.hidden) return;
      raf = requestAnimationFrame(draw);
    }

    function stop() {
      if (raf) cancelAnimationFrame(raf);
      raf = 0;
    }

    build();

    var resizeTimer;

    on(w, 'resize', function () {
      clearTimeout(resizeTimer);

      resizeTimer = setTimeout(function () {
        small = w.innerWidth < 700;
        build();
      }, 200);
    });

    on(d, 'visibilitychange', function () {
      if (d.hidden) {
        stop();
      } else {
        start();
      }
    });

    if (fine) {
      on(hero, 'mousemove', function (event) {
        var rect = hero.getBoundingClientRect();

        mouse.x = event.clientX - rect.left;
        mouse.y = event.clientY - rect.top;
      });

      on(hero, 'mouseleave', function () {
        mouse.x = -9999;
        mouse.y = -9999;
      });
    }

    if (HAS_IO) {
      var observer = new IntersectionObserver(function (entries) {
        visible = entries[0].isIntersecting;

        if (visible) {
          start();
        } else {
          stop();
        }
      });

      observer.observe(hero);
    }

    start();
  }

  /* =====================================================
     PEMILIH PAKET
     Data harga dan paket berasal dari PHP.
     Tidak ada query database di JavaScript.
  ===================================================== */

  function picker() {
    var chips = $$('.chip[data-pick]');
    var output = $('#pick-result');

    if (!chips.length || !output) return;

    function formatRupiah(value) {
      if (value === null || value === undefined || value === '') {
        return '';
      }

      // PHP sebaiknya mengirim data-price sebagai angka polos,
      // misalnya "150000" atau "150000.00".
      var raw = String(value).trim();

      if (!/^\d+(?:[.,]\d+)?$/.test(raw)) {
        return '';
      }

      var amount = Number(raw.replace(',', '.'));

      if (!Number.isFinite(amount) || amount < 0) {
        return '';
      }

      return Math.round(amount).toLocaleString('id-ID');
    }

    chips.forEach(function (chip) {
      chip.setAttribute(
        'aria-pressed',
        chip.classList.contains('active') ? 'true' : 'false'
      );

      on(chip, 'click', function () {
        var id = chip.getAttribute('data-pick');

        var card = $$('.package-card').find(function (item) {
          return item.getAttribute('data-id') === id;
        });

        if (!card) {
          output.textContent =
            'Paket rekomendasi belum tersedia untuk pilihan ini.';

          output.classList.add('show');
          return;
        }

        chips.forEach(function (item) {
          var active = item === chip;

          item.classList.toggle('active', active);

          item.setAttribute(
            'aria-pressed',
            active ? 'true' : 'false'
          );
        });

        $$('.package-card.recommended').forEach(function (item) {
          item.classList.remove('recommended');
        });

        card.classList.add('recommended');

        var name = card.getAttribute('data-name') || 'WiFi YesNet';
        var speed = card.getAttribute('data-speed') || '';
        var price = formatRupiah(
          card.getAttribute('data-price')
        );

        output.textContent = '';

        output.appendChild(
          d.createTextNode('Kami sarankan paket ')
        );

        var strong = d.createElement('strong');

        var description = name;

        if (speed) {
          description += ' (' + speed + ' Mbps';
          description += price ? ', Rp ' + price + '/bulan)' : ')';
        } else if (price) {
          description += ' (Rp ' + price + '/bulan)';
        }

        strong.textContent = description;
        output.appendChild(strong);

        var button = $('.package-button', card);

        if (button && button.getAttribute('href')) {
          var link = d.createElement('a');

          link.className = 'picker-go';
          link.href = button.getAttribute('href');
          link.textContent = 'Pilih paket ini →';

          output.appendChild(link);
        }

        output.classList.add('show');

        var rect = card.getBoundingClientRect();

        if (rect.top < 90 || rect.bottom > w.innerHeight - 20) {
          card.scrollIntoView({
            block: 'center',
            behavior: reduce ? 'auto' : 'smooth'
          });
        }
      });
    });
  }

  /* =====================================================
     FORM COVERAGE
  ===================================================== */

  function coverageForms() {
    $$('form[data-coverage]').forEach(function (form) {
      var input = $('input[name="alamat"]', form);
      var button = $('button[type="submit"]', form);

      on(form, 'submit', function (event) {
        var value = input
          ? input.value.replace(/\s+/g, ' ').trim()
          : '';

        if (value.length < 10) {
          event.preventDefault();

          form.classList.remove('invalid');
          void form.offsetWidth;
          form.classList.add('invalid');

          if (input) input.focus();

          return;
        }

        if (input) {
          input.value = value;
        }

        if (button) {
          button.classList.add('loading');
          button.disabled = true;
        }
      });
    });

    on(w, 'pageshow', function () {
      $$('.btn.loading').forEach(function (button) {
        button.classList.remove('loading');
        button.disabled = false;
      });
    });
  }

  /* =====================================================
     EFEK TOMBOL
  ===================================================== */

  function buttons() {
    on(d, 'click', function (event) {
      var button = event.target.closest &&
        event.target.closest(
          '.btn, .package-button, .nav-login'
        );

      if (!button || reduce) return;

      var rect = button.getBoundingClientRect();
      var size = Math.max(rect.width, rect.height) * 2;

      var ripple = d.createElement('span');

      var light =
        /btn-primary|btn-wa/.test(button.className) ||
        (
          button.classList.contains('package-button') &&
          button.closest('.popular, .recommended')
        );

      ripple.className = 'yn-ripple' + (light ? ' light' : '');

      ripple.style.cssText =
        'width:' + size + 'px;' +
        'height:' + size + 'px;' +
        'left:' + (event.clientX - rect.left - size / 2) + 'px;' +
        'top:' + (event.clientY - rect.top - size / 2) + 'px;';

      button.appendChild(ripple);

      on(ripple, 'animationend', function () {
        if (ripple.parentNode) {
          ripple.parentNode.removeChild(ripple);
        }
      });
    });

    if (!fine || reduce) return;

    $$('.hero .btn-primary, .hero .btn-outline, .cta-actions .btn')
      .forEach(function (button) {
        on(button, 'mousemove', function (event) {
          var rect = button.getBoundingClientRect();

          button.style.setProperty(
            '--bx',
            (
              (event.clientX - rect.left - rect.width / 2) * 0.16
            ).toFixed(1) + 'px'
          );

          button.style.setProperty(
            '--by',
            (
              (event.clientY - rect.top - rect.height / 2) * 0.22
            ).toFixed(1) + 'px'
          );
        });

        on(button, 'mouseleave', function () {
          button.style.setProperty('--bx', '0px');
          button.style.setProperty('--by', '0px');
        });
      });
  }

  /* =====================================================
     FLOW LAYANAN
  ===================================================== */

  function flowLanes() {
    $$('.flow-lane').forEach(function (lane) {
      var steps = $$('.flow-track li', lane);

      if (!steps.length) return;

      if (reduce || !HAS_IO) {
        steps.forEach(function (step) {
          step.classList.add('on');
        });

        return;
      }

      var index = 0;
      var active = false;

      var observer = new IntersectionObserver(function (entries) {
        active = entries[0].isIntersecting;
      }, {
        threshold: 0.25
      });

      observer.observe(lane);

      // Interval hanya berjalan ketika panel terlihat.
      // Disimpan agar bisa dihentikan saat halaman disembunyikan.
      var interval = setInterval(function () {
        if (!active || d.hidden) return;

        if (index >= steps.length + 2) {
          steps.forEach(function (step) {
            step.classList.remove('on');
          });

          index = 0;
        }

        if (steps[index]) {
          steps[index].classList.add('on');
        }

        index++;
      }, 650);

      on(d, 'visibilitychange', function () {
        if (d.hidden) {
          steps.forEach(function (step) {
            step.classList.remove('on');
          });
        }
      });

      // Hentikan interval ketika lane dilepas dari dokumen.
      // Observer tetap digunakan selama lane masih berada di halaman.
      var parent = lane.parentNode;

      if (parent) {
        on(parent, 'DOMNodeRemoved', function (event) {
          if (event.target === lane) {
            clearInterval(interval);
            observer.disconnect();
          }
        });
      }
    });
  }

  /* =====================================================
     INIT
  ===================================================== */

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
      safe(function () {
        aurora(hero);
      });

      safe(function () {
        network(hero);
      });

      safe(function () {
        heroMotion(hero);
      });
    }

    safe(initScrollHub);

    safe(function () {
      loader(afterIntro);
    });

    // Fallback agar konten tidak terus tersembunyi
    // jika proses loader mengalami kendala.
    setTimeout(afterIntro, 5000);
  }

  if (d.readyState === 'loading') {
    on(d, 'DOMContentLoaded', init);
  } else {
    init();
  }
})();
