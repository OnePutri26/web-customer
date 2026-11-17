(() => {
    'use strict';

    const DL_URL = 'speedtest-download.php';
    const UL_URL = 'speedtest-upload.php';

    const CFG = {
        pings: 10,
        dlStreams: 4, dlMs: 8000, dlKb: 8192,
        ulStreams: 3, ulMs: 8000, ulBytes: 4 * 1024 * 1024,
        warmMs: 1500
    };

    const csrf = () =>
        document.querySelector('meta[name="speedtest-csrf"]')?.content || '';
    const headers = () => ({ 'X-YESNET-CSRF': csrf() });

    let cb = {};
    let master = null;
    let running = false;

    const emit = (name, ...args) => {
        try { if (typeof cb[name] === 'function') cb[name](...args); } catch (e) { /* abaikan */ }
    };

    function httpError(status) {
        if (status === 401) return new Error('Sesi berakhir. Silakan login kembali.');
        if (status === 403) return new Error('Token keamanan tidak valid. Muat ulang halaman.');
        if (status === 429) return new Error('Terlalu banyak pengujian. Tunggu sebentar lalu coba lagi.');
        return new Error(`Server uji menolak permintaan (HTTP ${status}).`);
    }

    /* ---------- PING & JITTER ---------- */
    async function measurePing(signal) {
        const samples = [];

        for (let i = 0; i < CFG.pings + 1; i++) {
            const t0 = performance.now();
            const res = await fetch(`${DL_URL}?ping=1&t=${Date.now()}_${i}`, {
                cache: 'no-store', credentials: 'same-origin',
                headers: headers(), signal
            });
            if (!res.ok) throw httpError(res.status);
            await res.arrayBuffer();

            if (i > 0) samples.push(performance.now() - t0); // sampel 0 = setup koneksi

            emit('onProgress', {
                percent: (i / CFG.pings) * 10,
                phase: 'Mengukur ping'
            });
        }

        const sorted = [...samples].sort((a, b) => a - b);
        const ping = sorted[Math.floor(sorted.length / 2)];
        let jitter = 0;
        for (let i = 1; i < samples.length; i++) {
            jitter += Math.abs(samples[i] - samples[i - 1]);
        }
        jitter /= Math.max(1, samples.length - 1);

        return { ping, jitter };
    }

    /* ---------- FASE UKUR (DOWNLOAD / UPLOAD) ---------- */
    async function runPhase({ label, key, ms, from, to, streams, worker }) {
        const ac = new AbortController();
        const relay = () => ac.abort();
        master.signal.addEventListener('abort', relay, { once: true });

        let bytes = 0;
        let base = null;
        const t0 = performance.now();
        const add = (n) => { bytes += n; };

        const rate = () => {
            const el = performance.now() - t0;
            if (base === null && el >= CFG.warmMs) base = { b: bytes, t: el };
            if (base && el > base.t) {
                return ((bytes - base.b) * 8 / 1e6) / ((el - base.t) / 1000);
            }
            return el > 0 ? (bytes * 8 / 1e6) / (el / 1000) : 0;
        };

        const timer = setTimeout(() => ac.abort(), ms);
        const ui = setInterval(() => {
            const el = performance.now() - t0;
            emit('onProgress', {
                percent: from + (to - from) * Math.min(el / ms, 1),
                phase: label,
                [key]: rate()
            });
        }, 250);

        const results = await Promise.allSettled(
            Array.from({ length: streams }, () => worker(ac.signal, add))
        );

        clearTimeout(timer);
        clearInterval(ui);
        master.signal.removeEventListener('abort', relay);

        if (master.signal.aborted) throw new DOMException('Dibatalkan', 'AbortError');

        const failed = results.find((r) => r.status === 'rejected');
        if (bytes === 0 && failed) throw failed.reason;
        if (bytes === 0) throw new Error('Tidak ada data yang diterima.');

        return rate();
    }

    const downloadWorker = async (signal, add) => {
        try {
            while (!signal.aborted) {
                const res = await fetch(
                    `${DL_URL}?kb=${CFG.dlKb}&t=${Date.now()}_${Math.random()}`,
                    { cache: 'no-store', credentials: 'same-origin', headers: headers(), signal }
                );
                if (!res.ok) throw httpError(res.status);

                const reader = res.body.getReader();
                for (;;) {
                    const { done, value } = await reader.read();
                    if (done) break;
                    add(value.byteLength);
                }
            }
        } catch (e) {
            if (signal.aborted) return;
            throw e;
        }
    };

    function makePayload() {
        const data = new Uint8Array(CFG.ulBytes);
        for (let i = 0; i < data.length; i += 65536) {
            crypto.getRandomValues(data.subarray(i, Math.min(i + 65536, data.length)));
        }
        return new Blob([data], { type: 'application/octet-stream' });
    }

    const uploadWorker = (payload) => (signal, add) =>
        new Promise((resolve, reject) => {
            const next = () => {
                if (signal.aborted) return resolve();

                const xhr = new XMLHttpRequest();
                let last = 0;

                xhr.open('POST', `${UL_URL}?t=${Date.now()}_${Math.random()}`);
                xhr.setRequestHeader('Content-Type', 'application/octet-stream');
                xhr.setRequestHeader('X-YESNET-CSRF', csrf());
                xhr.withCredentials = true;

                xhr.upload.onprogress = (e) => {
                    add(e.loaded - last);
                    last = e.loaded;
                };
                xhr.onload = () => {
                    if (xhr.status >= 400) return reject(httpError(xhr.status));
                    add(Math.max(0, payload.size - last));
                    next();
                };
                xhr.onerror = () =>
                    signal.aborted ? resolve() : reject(new Error('Koneksi upload gagal.'));
                xhr.onabort = () => resolve();

                signal.addEventListener('abort', () => xhr.abort(), { once: true });
                xhr.send(payload);
            };
            next();
        });

    /* ---------- KONTRAK ADAPTER ---------- */
    window.YESNET_SPEEDTEST = {
        async initialize(callbacks = {}) {
            cb = callbacks;
            if (!window.fetch || !window.ReadableStream || !window.crypto?.getRandomValues) {
                throw new Error('Browser tidak mendukung pengujian ini. Perbarui browser Anda.');
            }
            emit('onServer', { name: 'Server YESNET', id: 'yesnet-1' });
        },

        async start() {
            if (running) throw new Error('Pengujian sedang berjalan.');
            running = true;
            master = new AbortController();

            try {
                emit('onPhase', 'Mengukur ping');
                const { ping, jitter } = await measurePing(master.signal);

                emit('onPhase', 'Mengukur download');
                const download = await runPhase({
                    label: 'Download', key: 'download_mbps', ms: CFG.dlMs,
                    from: 10, to: 55, streams: CFG.dlStreams, worker: downloadWorker
                });

                emit('onPhase', 'Mengukur upload');
                const payload = makePayload();
                const upload = await runPhase({
                    label: 'Upload', key: 'upload_mbps', ms: CFG.ulMs,
                    from: 55, to: 100, streams: CFG.ulStreams, worker: uploadWorker(payload)
                });

                return {
                    download_mbps: Number(download.toFixed(2)),
                    upload_mbps: Number(upload.toFixed(2)),
                    ping_ms: Number(ping.toFixed(1)),
                    jitter_ms: Number(jitter.toFixed(1)),
                    packet_loss: null,
                    server_name: 'Server YESNET',
                    server_id: 'yesnet-1',
                    result_url: ''
                };
            } catch (e) {
                if (e && e.name === 'AbortError') throw new Error('Pengujian dibatalkan.');
                throw e;
            } finally {
                running = false;
                master = null;
            }
        },

        destroy() {
            if (master) master.abort();
        }
    };
})();