<?php
require_once __DIR__ . '/config/app.php';

$selectedPackageId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$selectedPackageId) {
    $selectedPackageId = (int) flow('paket_id');
}

$p = $selectedPackageId > 0 ? getPaket($conn, (int) $selectedPackageId) : null;
if (!$p) {
    redirect('paket.php');
}

if (empty(flow('tersedia'))) {
    $_SESSION['pending_coverage_package_id'] = (int) $p['id'];
    redirect('coverage.php');
}

if ((int) flow('paket_id') !== (int) $p['id']) {
    flowSet(['paket_id' => (int) $p['id'], 'verified' => false]);
}

/*
| Data tampilan: kecepatan paket ini dibandingkan dengan paket aktif tercepat
| di tabel paket_wifi (untuk meter kecepatan). Gagal query tidak mengganggu halaman.
*/
$speed = max(0, (int) $p['speed_mbps']);
$maxSpeed = $speed;
try {
    $res = $conn->query("
        SELECT MAX(CAST(speed_mbps AS UNSIGNED))
        FROM paket_wifi
        WHERE status IS NULL
           OR TRIM(status) = ''
           OR LOWER(TRIM(status)) IN ('aktif', 'active', '1', 'tersedia', 'available')
    ");
    $row = $res ? $res->fetch_row() : null;
    $maxSpeed = max($maxSpeed, (int) ($row[0] ?? 0));
} catch (Throwable $ex) {
    error_log('Detail paket: gagal membaca kecepatan tertinggi: ' . $ex->getMessage());
}
$speedPct = $maxSpeed > 0 ? max(8, min(100, (int) round($speed / $maxSpeed * 100))) : 100;
$isFastest = $speed > 0 && $speed >= $maxSpeed;
$screens = max(1, intdiv($speed, 5)); // perkiraan: ±5 Mbps per layar streaming HD

$namaPaket = trim((string) preg_replace('/^Paket\s+/i', '', (string) $p['nama_paket']));
$alamat = (string) flow('alamat');
$deskripsi = trim((string) ($p['deskripsi'] ?? ''));
$loggedIn = isCustomer();
$ctaHref = $loggedIn ? 'verifikasi.php' : 'register.php?paket_id=' . (int) $p['id'];
$ctaText = $loggedIn ? 'Lanjut verifikasi data' : 'Lanjut daftar customer';

$pageTitle = 'Detail Paket';
$step = 1;
$narrow = false; // layout dua kolom; ubah ke true untuk kembali ke tampilan sempit
require __DIR__ . '/inc/header.php';
?>
<style>
    .dp { --dp-ink: #0a1a33; --dp-brand: #1677ff; --dp-ok: #12b76a; --dp-line: #e3e9f2; --dp-paper: #f3f6fb; --dp-muted: #667085;
          container-type: inline-size; width: 100%; max-width: 980px; margin: 0 auto; font-family: inherit; color: #1d2939; }
    .dp *, .dp *::before, .dp *::after { box-sizing: border-box; }
    .dp a:focus-visible, .dp button:focus-visible { outline: 3px solid rgba(22,119,255,.45); outline-offset: 2px; }

    .dp-grid { display: grid; gap: 20px; grid-template-columns: minmax(0, 1fr); align-items: start; }
    .dp-card { background: #fff; border: 1px solid var(--dp-line); border-radius: 22px; overflow: hidden;
               box-shadow: 0 1px 2px rgba(10,26,51,.04), 0 24px 48px -28px rgba(10,26,51,.3); }

    /* ---- Panel utama paket ---- */
    .dp-hero { position: relative; isolation: isolate; padding: 30px 28px 28px; color: #fff; background: var(--dp-ink); overflow: hidden; }
    .dp-hero::before { content: ""; position: absolute; z-index: -1; right: -90px; top: -90px; width: 340px; height: 340px; border-radius: 50%;
        background: repeating-radial-gradient(circle, rgba(90,162,255,.30) 0 1.5px, transparent 1.5px 30px); opacity: .55; }
    .dp-hero::after { content: ""; position: absolute; z-index: -1; inset: 0;
        background: radial-gradient(70% 120% at 0% 0%, rgba(22,119,255,.28), transparent 65%); }
    .dp-name { margin: 0; font-size: 15px; font-weight: 700; color: #b9c8e2; }
    .dp-speed { display: flex; align-items: baseline; gap: 10px; margin: 6px 0 0; }
    .dp-speed strong { font-size: clamp(56px, 12cqw, 88px); line-height: .95; letter-spacing: -3px; font-weight: 800; font-variant-numeric: tabular-nums; }
    .dp-speed span { font-size: 22px; font-weight: 700; color: #8fb7ff; }

    .dp-meter { margin-top: 24px; max-width: 460px; }
    .dp-track { position: relative; height: 10px; border-radius: 999px; background: rgba(255,255,255,.14); overflow: hidden; }
    .dp-fill { position: absolute; inset: 0 auto 0 0; width: var(--pct); border-radius: inherit;
               background: linear-gradient(90deg, #3b8bff, #5dd6a4); transform-origin: left; animation: dp-fill 1.1s cubic-bezier(.2,.7,.2,1) both; }
    @keyframes dp-fill { from { transform: scaleX(0); } to { transform: scaleX(1); } }
    .dp-meter p { margin: 9px 0 0; font-size: 13px; color: #b9c8e2; }
    .dp-meter p b { color: #fff; }

    .dp-body { padding: 26px 28px 28px; }
    .dp-priceline { display: flex; align-items: baseline; flex-wrap: wrap; gap: 6px 12px; }
    .dp-price { font-size: 32px; font-weight: 800; letter-spacing: -1px; color: var(--dp-ink); }
    .dp-price small { font-size: 15px; font-weight: 600; color: var(--dp-muted); letter-spacing: 0; }
    .dp-free { display: inline-flex; align-items: center; gap: 6px; padding: 5px 12px; border-radius: 999px; background: #ecfdf3; color: #067647; font-size: 13px; font-weight: 700; }
    .dp-desc { margin: 12px 0 0; color: #475467; font-size: 15px; line-height: 1.65; max-width: 62ch; }

    .dp-specs { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px, 1fr)); gap: 12px; margin: 22px 0 0; padding: 0; list-style: none; }
    .dp-specs li { display: flex; align-items: center; gap: 12px; padding: 14px 16px; border-radius: 14px; background: var(--dp-paper); border: 1px solid var(--dp-line); }
    .dp-ico { flex: none; width: 40px; height: 40px; border-radius: 12px; display: grid; place-items: center; background: #fff; border: 1px solid var(--dp-line); color: var(--dp-brand); }
    .dp-ico svg { width: 20px; height: 20px; fill: none; stroke: currentColor; stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
    .dp-specs small { display: block; font-size: 12.5px; color: var(--dp-muted); font-weight: 600; }
    .dp-specs b { font-size: 15px; color: var(--dp-ink); }

    /* ---- Ringkasan & aksi ---- */
    .dp-aside { padding: 24px 24px 26px; }
    .dp-aside h2 { margin: 0 0 14px; font-size: 17px; letter-spacing: -.2px; color: var(--dp-ink); }
    .dp-rows { margin: 0; }
    .dp-rows div { display: flex; justify-content: space-between; gap: 14px; padding: 10px 0; border-bottom: 1px dashed var(--dp-line); font-size: 14.5px; }
    .dp-rows dt { color: var(--dp-muted); }
    .dp-rows dd { margin: 0; font-weight: 700; text-align: right; color: var(--dp-ink); }
    .dp-rows .total { border-bottom: 0; padding-top: 14px; font-size: 16px; }
    .dp-rows .total dd { font-size: 19px; color: var(--dp-brand); }

    .dp-addr { display: flex; gap: 12px; margin-top: 16px; padding: 14px; border-radius: 14px; background: var(--dp-paper); border: 1px solid var(--dp-line); }
    .dp-addr svg { flex: none; width: 20px; height: 20px; margin-top: 2px; fill: none; stroke: var(--dp-brand); stroke-width: 2; stroke-linecap: round; stroke-linejoin: round; }
    .dp-addr small { display: block; font-size: 12.5px; color: var(--dp-muted); font-weight: 600; }
    .dp-addr p { margin: 2px 0 0; font-size: 14px; overflow-wrap: anywhere; }
    .dp-addr a { display: inline-block; margin-top: 6px; font-size: 13px; font-weight: 700; color: var(--dp-brand); text-decoration: none; }
    .dp-addr a:hover { text-decoration: underline; }

    .dp-cta { display: flex; flex-direction: column; gap: 10px; margin-top: 20px; }
    .dp-btn { display: flex; align-items: center; justify-content: center; gap: 8px; min-height: 52px; padding: 0 20px; border-radius: 14px;
              border: 1.5px solid var(--dp-line); background: #fff; color: var(--dp-ink); font: inherit; font-weight: 700; font-size: 15.5px; text-decoration: none;
              transition: transform .12s, background .15s, box-shadow .15s; }
    .dp-btn:hover { background: var(--dp-paper); }
    .dp-btn:active { transform: translateY(1px); }
    .dp-btn.primary { background: var(--dp-brand); border-color: var(--dp-brand); color: #fff; box-shadow: 0 12px 22px -12px rgba(22,119,255,.75); }
    .dp-btn.primary:hover { background: #0f67e6; }
    .dp-btn svg { width: 18px; height: 18px; fill: none; stroke: currentColor; stroke-width: 2.2; stroke-linecap: round; stroke-linejoin: round; }
    .dp-login { margin: 14px 0 0; text-align: center; font-size: 13.5px; color: var(--dp-muted); }
    .dp-login a { color: var(--dp-brand); font-weight: 700; text-decoration: none; }

    .dp-next { margin: 22px 0 0; padding: 18px 0 0; border-top: 1px solid var(--dp-line); list-style: none; counter-reset: s; display: grid; gap: 12px; }
    .dp-next li { counter-increment: s; display: flex; gap: 12px; align-items: flex-start; font-size: 14px; color: #475467; }
    .dp-next li::before { content: counter(s); flex: none; width: 24px; height: 24px; border-radius: 50%; display: grid; place-items: center;
                          font-size: 12px; font-weight: 800; color: var(--dp-brand); background: #eaf3ff; }
    .dp-next b { color: var(--dp-ink); }

    @container (min-width: 760px) {
        .dp-grid { grid-template-columns: minmax(0, 1.45fr) minmax(300px, 1fr); }
        .dp-aside { position: sticky; top: 16px; }
    }
    @container (max-width: 480px) {
        .dp-hero { padding: 24px 20px; }
        .dp-body, .dp-aside { padding-left: 20px; padding-right: 20px; }
    }
    @media (prefers-reduced-motion: reduce) { .dp-fill { animation: none; } .dp-btn { transition: none; } }
</style>

<div class="page-head enter">
    <h1>Detail Paket <?= e($namaPaket) ?></h1>
    <p>Periksa paket dan alamat pemasangan sebelum melanjutkan.</p>
</div>

<div class="dp enter">
    <div class="dp-grid">

        <section class="dp-card" aria-labelledby="dpTitle">
            <div class="dp-hero">
                <p class="dp-name" id="dpTitle"><?= e($p['nama_paket']) ?></p>
                <div class="dp-speed"><strong><?= $speed ?></strong><span>Mbps</span></div>

                <div class="dp-meter">
                    <div class="dp-track" role="img" aria-label="Kecepatan <?= $speed ?> Mbps dari maksimum <?= $maxSpeed ?> Mbps">
                        <div class="dp-fill" style="--pct: <?= $speedPct ?>%"></div>
                    </div>
                    <p>
                        <?php if ($isFastest): ?>
                            <b>Paket tercepat</b> YesNet.
                        <?php else: ?>
                            <b><?= $speedPct ?>%</b> dari paket tercepat (<?= $maxSpeed ?> Mbps).
                        <?php endif; ?>
                        Cukup untuk sekitar <b><?= $screens ?></b> layar streaming HD sekaligus (perkiraan).
                    </p>
                </div>
            </div>

            <div class="dp-body">
                <div class="dp-priceline">
                    <div class="dp-price"><?= e(rupiah($p['harga'])) ?> <small>/bulan</small></div>
                    <span class="dp-free">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 13l4 4L19 7"/></svg>
                        Instalasi gratis
                    </span>
                </div>

                <?php if ($deskripsi !== ''): ?>
                    <p class="dp-desc"><?= e($deskripsi) ?></p>
                <?php endif; ?>

                <ul class="dp-specs">
                    <li>
                        <span class="dp-ico"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M18.4 6.6a8 8 0 1 0 0 10.8"/><path d="M8 12h8M16 12l-3-3M16 12l-3 3" transform="translate(-1 0)"/></svg></span>
                        <div><small>Kuota</small><b>Unlimited</b></div>
                    </li>
                    <li>
                        <span class="dp-ico"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5a10 10 0 0 1 14 0M8.5 16a5 5 0 0 1 7 0"/><circle cx="12" cy="19" r="1"/></svg></span>
                        <div><small>Perangkat</small><b>WiFi Router</b></div>
                    </li>
                    <li>
                        <span class="dp-ico"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M14.7 6.3a4 4 0 0 0-5.4 5.4L4 17l3 3 5.3-5.3a4 4 0 0 0 5.4-5.4l-2.4 2.4-2.6-.6-.6-2.6z"/></svg></span>
                        <div><small>Biaya instalasi</small><b>Gratis</b></div>
                    </li>
                    <li>
                        <span class="dp-ico"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 3l8 3v6c0 4.5-3.2 8-8 9-4.8-1-8-4.5-8-9V6z"/><path d="M9 12l2 2 4-4"/></svg></span>
                        <div><small>Jaringan</small><b>Tersedia di lokasi Anda</b></div>
                    </li>
                </ul>
            </div>
        </section>

        <aside class="dp-card dp-aside" aria-labelledby="dpSum">
            <h2 id="dpSum">Ringkasan pesanan</h2>

            <dl class="dp-rows">
                <div><dt>Paket</dt><dd><?= e($namaPaket) ?> · <?= $speed ?> Mbps</dd></div>
                <div><dt>Biaya instalasi</dt><dd>Gratis</dd></div>
                <div class="total"><dt>Biaya bulanan</dt><dd><?= e(rupiah($p['harga'])) ?></dd></div>
            </dl>

            <div class="dp-addr">
                <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s7-6.2 7-11.5A7 7 0 0 0 5 9.5C5 14.8 12 21 12 21z"/><circle cx="12" cy="9.5" r="2.5"/></svg>
                <div>
                    <small>Alamat pemasangan</small>
                    <p><?= e($alamat) ?></p>
                    <a href="coverage.php?alamat=<?= e(rawurlencode($alamat)) ?>">Ganti alamat</a>
                </div>
            </div>

            <div class="dp-cta">
                <a class="dp-btn primary" href="<?= e($ctaHref) ?>">
                    <?= e($ctaText) ?>
                    <svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>
                </a>
                <a class="dp-btn" href="paket.php?mode=coverage">Ganti paket</a>
            </div>

            <?php if (!$loggedIn): ?>
                <p class="dp-login">Sudah punya akun? <a href="login.php">Login customer</a></p>
            <?php endif; ?>

            <ol class="dp-next" aria-label="Langkah berikutnya">
                <li><span><b><?= $loggedIn ? 'Verifikasi data' : 'Daftar akun' ?></b><br>Lengkapi data diri untuk pemasangan.</span></li>
                <li><span><b>Pembayaran</b><br>Bayar tagihan pertama secara online.</span></li>
                <li><span><b>Pemasangan</b><br>Tim YesNet memasang jaringan di alamat Anda.</span></li>
            </ol>
        </aside>

    </div>
</div>
<?php require __DIR__ . '/inc/footer.php'; ?>