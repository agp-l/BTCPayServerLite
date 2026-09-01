<?php
// Veřejná prezentační stránka BTCPay Server Lite.
declare(strict_types=1);

use BtcPayLite\UrlManager;

if (!isset($urlManager) || !$urlManager instanceof UrlManager) {
    require_once __DIR__ . '/../vendor/autoload.php';
    $config = isset($config) && is_array($config) ? $config : require __DIR__ . '/../config.php';
    $urlManager = new UrlManager(
        $_SERVER,
        is_string($config['app_url'] ?? null) ? $config['app_url'] : null
    );
}

$baseUrl = rtrim($urlManager->getBaseUrl(), '/');
$canonicalUrl = $baseUrl . '/prezentace';
$registerUrl = $baseUrl . '/registrace';
$loginUrl = $baseUrl . '/login';
$githubUrl = 'https://github.com/agp-l/BTCPayServerLite';
$contactUrl = $githubUrl . '/issues/new';

/* Orientační ceník – částky lze změnit zde bez zásahu do HTML. */
$pricing = [
    'hosted' => '490 Kč',
    'hardware' => '4 900 Kč',
    'cms' => '6 900 Kč',
];

$schema = [
    '@context' => 'https://schema.org',
    '@graph' => [
        [
            '@type' => 'SoftwareApplication',
            'name' => 'BTCPay Server Lite',
            'applicationCategory' => 'FinanceApplication',
            'operatingSystem' => 'Linux, PHP',
            'description' => 'Lehká open-source platební brána pro přijímání on-chain Bitcoin plateb v e-shopech a online službách.',
            'url' => $canonicalUrl,
            'downloadUrl' => $githubUrl,
            'softwareVersion' => 'Open source',
            'license' => 'https://opensource.org/license/mit',
            'offers' => [
                '@type' => 'Offer',
                'price' => '490',
                'priceCurrency' => 'CZK',
                'description' => 'Měsíční provoz serverové části.',
            ],
        ],
        [
            '@type' => 'FAQPage',
            'mainEntity' => [
                [
                    '@type' => 'Question',
                    'name' => 'Potřebuji rozumět Bitcoinu nebo programování?',
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => 'Ne. Řešení lze dodat jako hotovou službu, nainstalovat na váš hardware nebo napojit na váš e-shop či CMS.',
                    ],
                ],
                [
                    '@type' => 'Question',
                    'name' => 'Kam přijaté bitcoiny přijdou?',
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => 'Platby jsou sledované přes Electrum a směřují na adresy peněženky nastavené pro váš obchod.',
                    ],
                ],
                [
                    '@type' => 'Question',
                    'name' => 'Je BTCPay Server Lite zdarma?',
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => 'Zdrojový kód je dostupný zdarma pod MIT licencí. Platíte jen tehdy, když chcete provoz, instalaci nebo integraci na míru.',
                    ],
                ],
            ],
        ],
    ],
];

function e(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}
?>
<!doctype html>
<html lang="cs">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Bitcoin platby pro e-shop | BTCPay Server Lite</title>
    <meta name="description" content="Přijímejte Bitcoin ve svém e-shopu nebo online službě. Lehká open-source platební brána s Electrum peněženkou, hotovým provozem i integrací do CMS.">
    <meta name="keywords" content="Bitcoin platby e-shop, Bitcoin platební brána, přijímat BTC, BTCPay Server Lite, Electrum, WooCommerce Bitcoin, open source platební brána">
    <meta name="author" content="BTCPay Server Lite">
    <meta name="robots" content="index, follow, max-image-preview:large">
    <meta name="theme-color" content="#07150f">
    <link rel="canonical" href="<?= e($canonicalUrl) ?>">

    <meta property="og:type" content="website">
    <meta property="og:locale" content="cs_CZ">
    <meta property="og:site_name" content="BTCPay Server Lite">
    <meta property="og:title" content="Bitcoin platby pro váš e-shop. Jednoduše.">
    <meta property="og:description" content="Malá, efektivní a otevřená platební brána, která propojí váš web s Bitcoinem.">
    <meta property="og:url" content="<?= e($canonicalUrl) ?>">
    <meta name="twitter:card" content="summary">
    <meta name="twitter:title" content="BTCPay Server Lite">
    <meta name="twitter:description" content="Přidejte do svého e-shopu možnost platit Bitcoinem.">

    <script type="application/ld+json"><?= json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>

    <style>
        :root {
            --ink: #2c2c2c;
            --ink-soft: #202221;
            --paper: #fff;
            --paper-deep: #fff;
            --white: #ffffff;
            --muted: #080808;
            --line: rgba(7, 21, 15, .13);
            --brand: #20c875;
            --brand-dark: #0f9c45;
            --mint: #ebebc3;
            --lime: #fdf16b;
            --radius: 24px;
            --shadow: 0 24px 70px rgba(7, 21, 15, .11);
        }

        * { box-sizing: border-box; }
        html { scroll-behavior: smooth; }
        body {
            margin: 0;
            color: var(--ink);
            background: var(--paper);
            font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            font-size: 16px;
            line-height: 1.65;
            text-rendering: optimizeLegibility;
        }
        body::before {
            content: "";
            position: fixed;
            inset: 0;
            z-index: -2;
            pointer-events: none;
            background:
                radial-gradient(circle at 82% 4%, rgba(54, 54, 54, 0.18), transparent 30rem),
                radial-gradient(circle at 5% 30%, rgba(49, 49, 49, 0.18), transparent 34rem);
        }
        a { color: inherit; }
        img, svg { display: block; }
        button, a { -webkit-tap-highlight-color: transparent; }
        :focus-visible { outline: 3px solid var(--brand); outline-offset: 4px; }
        .skip-link { position: fixed; left: 1rem; top: -5rem; z-index: 100; padding: .7rem 1rem; background: var(--white); border-radius: 10px; }
        .skip-link:focus { top: 1rem; }
        .wrap { width: min(1180px, calc(100% - 40px)); margin-inline: auto; }
        .eyebrow {
            display: inline-flex;
            align-items: center;
            gap: .55rem;
            margin: 0 0 1rem;
            color: var(--ink-soft);
            font-size: .78rem;
            font-weight: 800;
            letter-spacing: .14em;
            text-transform: uppercase;
        }
        .eyebrow::before { content: ""; width: 26px; height: 2px; background: var(--brand); }
        h1, h2, h3, p { margin-top: 0; }
        h1, h2, h3 { line-height: 1.08; letter-spacing: -.035em; }
        h1 { margin-bottom: 1.5rem; font-size: clamp(3rem, 7.8vw, 6.8rem); font-weight: 850; }
        h2 { margin-bottom: 1rem; font-size: clamp(2.15rem, 4.5vw, 4.2rem); font-weight: 830; }
        h3 { font-size: 1.35rem; font-weight: 780; }
        .muted { color: var(--muted); }
        .brand-accent { color: var(--brand); }

        .nav-shell { padding-top: 18px; }
        .nav {
            display: flex;
            align-items: center;
            justify-content: space-between;
            min-height: 70px;
            padding: 10px 12px 10px 18px;
            border: 1px solid rgba(255, 255, 255, .7);
            border-radius: 18px;
            background: rgba(255, 255, 255, .78);
            box-shadow: 0 12px 40px rgba(7, 21, 15, .07);
            backdrop-filter: blur(16px);
        }
        .brand { display: inline-flex; align-items: center; gap: .7rem; text-decoration: none; font-weight: 850; letter-spacing: -.03em; }
        .brand-mark {
            display: grid;
            place-items: center;
            width: 38px;
            aspect-ratio: 1;
            border-radius: 50%;
            color: var(--white);
            background: var(--brand);
            font-size: 0.9rem;
            font-weight: 900;
        }
        .brand small { display: block; margin-top: -4px; color: var(--muted); font-size: .65rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; }
        .nav-links { display: flex; align-items: center; gap: 1.6rem; }
        .nav-links > a:not(.button) { color: var(--ink-soft); font-size: .92rem; font-weight: 700; text-decoration: none; }
        .nav-links > a:not(.button):hover { color: var(--brand-dark); }
        .button {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: .65rem;
            min-height: 52px;
            padding: .78rem 1.25rem;
            border: 1px solid transparent;
            border-radius: 13px;
            font-weight: 800;
            text-decoration: none;
            transition: transform .2s ease, box-shadow .2s ease, background .2s ease;
        }
        .button:hover { transform: translateY(-2px); }
        .button-primary { color: var(--white); background: var(--ink); box-shadow: 0 14px 30px rgba(7, 21, 15, .18); }
        .button-primary:hover { background: #102b1e; box-shadow: 0 18px 36px rgba(7, 21, 15, .24); }
        .button-brand { color: #041b10; background: var(--brand); box-shadow: 0 14px 30px rgba(32, 200, 117, .24); }
        .button-brand:hover { background: #ffffff; }
        .button-ghost { border-color: var(--line); background: rgb(255, 255, 255); }

        .hero { padding: clamp(4.5rem, 2vw, 8rem) 0 5rem; overflow: hidden; }
        .hero-grid { display: grid; grid-template-columns: 1.1fr .9fr; gap: clamp(3rem, 7vw, 7rem); align-items: center; }
        .hero-copy { position: relative; z-index: 1; }
        .hero-lead { max-width: 700px; margin-bottom: 2rem; color: var(--muted); font-size: clamp(1.08rem, 1.8vw, 1.3rem); }
        .hero-actions { display: flex; flex-wrap: wrap; gap: .85rem; margin-bottom: 2rem; }
        .trust-row { display: flex; flex-wrap: wrap; gap: .7rem 1.2rem; color: var(--ink-soft); font-size: .86rem; font-weight: 700; }
        .trust-row span { display: inline-flex; align-items: center; gap: .4rem; }
        .check { display: inline-grid; place-items: center; width: 19px; height: 19px; border-radius: 50%; background: var(--mint); font-size: .7rem; }

        .payment-stage { position: relative; min-height: 590px; }
        .payment-stage::before {
            content: "";
            position: absolute;
            width: 370px;
            height: 370px;
            right: -70px;
            top: 35px;
            border-radius: 50%;
            background: var(--brand);
            filter: saturate(.95);
        }
        .checkout {
            position: absolute;
            top: 0;
            left: 0;
            width: min(390px, 88%);
            padding: 27px;
            border: 1px solid rgba(255, 255, 255, .3);
            border-radius: 30px;
            color: var(--white);
            background: var(--ink);
            box-shadow: 0 34px 90px rgba(7, 21, 15, .28);
            transform: rotate(-2deg);
        }
        .checkout-top { display: flex; justify-content: space-between; align-items: center; margin-bottom: 2.2rem; font-size: .78rem; color: #b9cbc1; }
        .checkout-top strong { color: var(--white); font-size: .93rem; }
        .status-pill { padding: .38rem .7rem; border-radius: 999px; color: #12271b; background: var(--mint); font-weight: 800; }
        .amount { margin-bottom: .15rem; font-size: clamp(2.5rem, 5vw, 4rem); font-weight: 850; letter-spacing: -.06em; }
        .fiat { color: #a9bbb1; }
        .qr {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 3px;
            width: 154px;
            padding: 13px;
            margin: 2rem auto;
            border-radius: 18px;
            background: var(--white);
            box-shadow: 0 15px 40px rgba(0, 0, 0, .2);
        }
        .qr i { aspect-ratio: 1; border-radius: 1px; background: transparent; }
        .qr i:nth-child(3n), .qr i:nth-child(5n+1), .qr i:nth-child(7n+2), .qr i:nth-child(11n) { background: var(--ink); }
        .address { padding: .85rem 1rem; overflow: hidden; border: 1px solid rgba(255, 255, 255, .12); border-radius: 12px; color: #b9cbc1; background: rgba(255, 255, 255, .06); font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .72rem; text-overflow: ellipsis; white-space: nowrap; }
        .confirmation {
            position: absolute;
            z-index: 2;
            right: -5px;
            bottom: 38px;
            width: min(305px, 75%);
            padding: 22px;
            border-radius: 22px;
            background: var(--white);
            box-shadow: var(--shadow);
            transform: rotate(3deg);
        }
        .confirmation-head { display: flex; align-items: center; gap: .8rem; margin-bottom: 1rem; }
        .confirmation-icon { display: grid; place-items: center; width: 48px; height: 48px; border-radius: 15px; background: var(--lime); font-weight: 900; }
        .confirmation p { margin: 0; color: var(--muted); font-size: .86rem; }
        .confirmation strong { display: block; }
        .progress { height: 8px; margin-top: 1rem; overflow: hidden; border-radius: 99px; background: var(--paper-deep); }
        .progress span { display: block; width: 78%; height: 100%; border-radius: inherit; background: var(--brand); }
        .float-badge { position: absolute; z-index: 3; top: 67px; right: -14px; padding: .7rem 1rem; border-radius: 999px; background: var(--lime); box-shadow: 0 12px 30px rgba(7, 21, 15, .15); font-size: .82rem; font-weight: 850; transform: rotate(7deg); }

        .proof { padding: 1rem 0 6rem; }
        .proof-line { display: grid; grid-template-columns: 1fr auto 1fr auto 1fr; align-items: center; gap: 1.2rem; padding: 1.5rem 0; border-block: 1px solid var(--line); }
        .proof-item { text-align: center; }
        .proof-item strong { display: block; font-size: 1.1rem; }
        .proof-item span { color: var(--muted); font-size: .84rem; }
        .proof-dot { width: 5px; height: 5px; border-radius: 50%; background: var(--brand); }

        section { scroll-margin-top: 7rem; }
        .section { padding: clamp(5rem, 9vw, 8rem) 0; }
        .section-head { display: grid; grid-template-columns: .75fr 1.25fr; gap: 3rem; align-items: end; margin-bottom: 3.5rem; }
        .section-head p { max-width: 620px; margin-bottom: .35rem; color: var(--muted); font-size: 1.08rem; }

        .steps { background: var(--ink); color: var(--white); }
        .steps .eyebrow { color: var(--mint); }
        .steps .section-head p { color: #a9bbb1; }
        .step-list { display: grid; grid-template-columns: repeat(3, 1fr); border-top: 1px solid rgba(255, 255, 255, .14); }
        .step { position: relative; padding: 2.3rem 2.3rem 0 0; }
        .step + .step { padding-left: 2.3rem; border-left: 1px solid rgba(255, 255, 255, .14); }
        .step-number { display: block; margin-bottom: 3rem; color: var(--brand); font-size: .9rem; font-weight: 850; letter-spacing: .12em; }
        .step p { color: #a9bbb1; }

        .benefit-layout { display: grid; grid-template-columns: .8fr 1.2fr; gap: clamp(3rem, 8vw, 8rem); align-items: start; }
        .sticky-copy { position: sticky; top: 2rem; }
        .benefit-list { border-top: 1px solid var(--line); }
        .benefit { display: grid; grid-template-columns: 50px 1fr; gap: 1.2rem; padding: 2rem 0; border-bottom: 1px solid var(--line); }
        .benefit-icon { display: grid; place-items: center; width: 44px; height: 44px; border-radius: 14px; color: var(--ink); background: var(--mint); font-size: 1.2rem; font-weight: 900; }
        .benefit p { margin-bottom: 0; color: var(--muted); }

        .tech-band { padding: 0 0 clamp(5rem, 9vw, 8rem); }
        .tech-panel {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 3rem;
            padding: clamp(2rem, 5vw, 4.5rem);
            border-radius: 34px;
            color: var(--white);
            background: linear-gradient(135deg, #2e2e2e 0%, #1e1e1e 70%);
            box-shadow: var(--shadow);
        }
        .tech-panel p { color: #b8c9bf; }
        .code-flow { align-self: center; }
        .flow-row { display: flex; align-items: center; gap: .65rem; margin-bottom: .8rem; }
        .flow-box { flex: 1; padding: .9rem 1rem; border: 1px solid rgba(255, 255, 255, .14); border-radius: 12px; background: rgba(255, 255, 255, .06); font-size: .88rem; font-weight: 750; text-align: center; }
        .flow-arrow { color: var(--brand); font-weight: 900; }
        .tech-tags { display: flex; flex-wrap: wrap; gap: .5rem; margin-top: 1.5rem; }
        .tech-tags span { padding: .45rem .7rem; border: 1px solid rgba(255, 255, 255, .14); border-radius: 999px; color: var(--mint); font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .72rem; }

        .pricing { background: var(--white); }
        .pricing-intro { max-width: 760px; margin-bottom: 3.5rem; }
        .pricing-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 1rem; }
        .price-card { position: relative; display: flex; flex-direction: column; min-height: 520px; padding: 2rem; border: 1px solid var(--line); border-radius: var(--radius); background: var(--paper); }
        .price-card.featured { color: var(--white); background: var(--ink); transform: translateY(-12px); box-shadow: var(--shadow); }
        .price-card .plan { color: var(--muted); font-size: .79rem; font-weight: 850; letter-spacing: .12em; text-transform: uppercase; }
        .price-card.featured .plan, .price-card.featured .price-note { color: #ebde9a; }
        .popular { position: absolute; top: 1.2rem; right: 1.2rem; padding: .35rem .65rem; border-radius: 999px; color: var(--ink); background: var(--lime); font-size: .68rem; font-weight: 900; text-transform: uppercase; }
        .price { margin: 1.2rem 0 .2rem; font-size: clamp(2.3rem, 4vw, 3.7rem); font-weight: 850; letter-spacing: -.06em; }
        .price sup { font-size: .9rem; letter-spacing: 0; }
        .price-note { min-height: 3.3rem; color: var(--muted); font-size: .86rem; }
        .price-card ul { display: grid; gap: .8rem; margin: 1.6rem 0 2rem; padding: 1.5rem 0 0; border-top: 1px solid currentColor; border-color: var(--line); list-style: none; }
        .price-card.featured ul { border-color: rgba(255, 255, 255, .15); }
        .price-card li { position: relative; padding-left: 1.6rem; font-size: .92rem; }
        .price-card li::before { content: "✓"; position: absolute; left: 0; color: var(--brand); font-weight: 900; }
        .price-card .button { margin-top: auto; }
        .pricing-foot { margin: 1.8rem 0 0; color: var(--muted); font-size: .84rem; text-align: center; }

        .open-source { overflow: hidden; }
        .open-grid { display: grid; grid-template-columns: 1.1fr .9fr; gap: 4rem; align-items: center; }
        .terminal { padding: 1.4rem; border-radius: 22px; color: #e8fff2; background: #09110d; box-shadow: var(--shadow); font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .82rem; }
        .terminal-top { display: flex; align-items: center; gap: .4rem; padding-bottom: 1rem; border-bottom: 1px solid rgba(255, 255, 255, .1); }
        .terminal-top i { width: 9px; height: 9px; border-radius: 50%; background: #ff655f; }
        .terminal-top i:nth-child(2) { background: #ffbd2e; }
        .terminal-top i:nth-child(3) { background: #27c840; }
        .terminal pre { margin: 1.2rem 0 0; overflow: auto; color: #a9bbb1; line-height: 1.8; white-space: pre-wrap; }
        .terminal .cmd { color: var(--mint); }
        .open-actions { display: flex; flex-wrap: wrap; gap: .8rem; margin-top: 1.8rem; }

        .faq { background: var(--paper-deep); }
        .faq-grid { display: grid; grid-template-columns: .72fr 1.28fr; gap: clamp(3rem, 8vw, 8rem); }
        details { padding: 1.3rem 0; border-bottom: 1px solid var(--line); }
        details:first-child { border-top: 1px solid var(--line); }
        summary { display: flex; align-items: center; justify-content: space-between; gap: 1rem; cursor: pointer; font-size: 1.05rem; font-weight: 800; list-style: none; }
        summary::-webkit-details-marker { display: none; }
        summary::after { content: "+"; display: grid; place-items: center; width: 30px; height: 30px; border: 1px solid var(--line); border-radius: 50%; flex: 0 0 auto; }
        details[open] summary::after { content: "−"; background: var(--brand); }
        details p { max-width: 700px; margin: 1rem 2.8rem 0 0; color: var(--muted); }

        .cta { padding: clamp(5rem, 9vw, 8rem) 0 2.2rem; color: var(--white); background: var(--ink); }
        .cta-box { position: relative; overflow: hidden; padding: clamp(2.3rem, 6vw, 5.5rem); border-radius: 34px; background: var(--brand); color: #041b10; }
        .cta-box::after { content: "₿"; position: absolute; right: -3rem; bottom: -7rem; color: rgba(255, 255, 255, 0.72); font-size: 22rem; font-weight: 900; line-height: 1; transform: rotate(12deg); }
        .cta-box h2, .cta-box p, .cta-box .hero-actions { position: relative; z-index: 1; }
        .cta-box p { max-width: 650px; font-size: 1.12rem; }
        .footer { display: flex; align-items: center; justify-content: space-between; gap: 2rem; padding-top: 4rem; color: #96aa9f; font-size: .85rem; }
        .footer-links { display: flex; flex-wrap: wrap; gap: 1rem; }
        .footer a { text-decoration: none; }
        .footer a:hover { color: var(--white); }

        @media (prefers-reduced-motion: reduce) {
            html { scroll-behavior: auto; }
            *, *::before, *::after { animation: none !important; transition: none !important; }
        }
        @media (max-width: 920px) {
            .nav-links > a:not(.button) { display: none; }
            .hero-grid, .section-head, .benefit-layout, .tech-panel, .open-grid, .faq-grid { grid-template-columns: 1fr; }
            .hero { padding-top: 4rem; }
            .payment-stage { width: min(620px, 100%); margin-inline: auto; }
            .section-head { gap: .5rem; }
            .step-list, .pricing-grid { grid-template-columns: 1fr; }
            .step { padding: 2rem 0; }
            .step + .step { padding-left: 0; border-left: 0; border-top: 1px solid rgba(255, 255, 255, .14); }
            .step-number { margin-bottom: 1rem; }
            .sticky-copy { position: static; }
            .price-card { min-height: 0; }
            .price-card.featured { transform: none; }
        }
        @media (max-width: 620px) {
            .wrap { width: min(100% - 26px, 1180px); }
            .nav { padding-left: 12px; }
            .brand span:last-child { font-size: .92rem; }
            .nav .button { min-height: 44px; padding: .65rem .8rem; font-size: .8rem; }
            h1 { font-size: clamp(2.75rem, 15vw, 4.4rem); }
            .hero-actions { display: grid; }
            .hero-actions .button { width: 100%; }
            .payment-stage { min-height: 510px; }
            .payment-stage::before { width: 270px; height: 270px; right: -50px; }
            .checkout { width: 88%; padding: 20px; }
            .qr { width: 125px; margin: 1.4rem auto; }
            .confirmation { right: 0; bottom: 15px; padding: 17px; }
            .float-badge { right: 0; top: 40px; }
            .proof-line { grid-template-columns: 1fr; }
            .proof-dot { margin-inline: auto; }
            .benefit { grid-template-columns: 42px 1fr; }
            .tech-panel, .cta-box { border-radius: 24px; }
            .flow-row { align-items: stretch; flex-direction: column; }
            .flow-arrow { transform: rotate(90deg); text-align: center; }
            .footer { align-items: flex-start; flex-direction: column; }
        }
    </style>
</head>
<body>
<a class="skip-link" href="#obsah">Přejít na hlavní obsah</a>

<div class="nav-shell wrap">
    <nav class="nav" aria-label="Hlavní navigace">
        <a class="brand" href="<?= e($canonicalUrl) ?>" aria-label="BTCPay Server Lite – úvod">
            <span class="brand-mark" aria-hidden="true">BTC</span>
            <span>Pay Server Lite<small>Bitcoin payments</small></span>
        </a>
        <div class="nav-links">
            <a href="#jak-to-funguje">Jak to funguje</a>
            <a href="#moznosti">Možnosti</a>
            <a href="#cenik">Ceník</a>
            <a href="<?= e($baseUrl . '/dokumentace') ?>">Dokumentace</a>
            <a class="button button-primary" href="<?= e($registerUrl) ?>">Vyzkoušet</a>
        </div>
    </nav>
</div>

<main id="obsah">
    <header class="hero">
        <div class="wrap hero-grid">
            <div class="hero-copy">
                <p class="eyebrow">Bitcoin pro normální e-shop</p>
                <h1>Platby bez karet.<br><span class="brand-accent">Přímo v Bitcoinu.</span></h1>
                <p class="hero-lead">Dejte zákazníkům další možnost, jak zaplatit. BTCPay Server Lite propojí váš e-shop, rezervační systém nebo online službu s Bitcoinem — jednoduše, přehledně a bez zbytečně složité infrastruktury.</p>
                <div class="hero-actions">
                    <a class="button button-brand" href="<?= e($registerUrl) ?>">Chci přijímat Bitcoin <span aria-hidden="true">→</span></a>
                    <a class="button button-ghost" href="#jak-to-funguje">Jak to funguje</a>
                </div>
                <div class="trust-row" aria-label="Hlavní výhody">
                    <span><i class="check" aria-hidden="true">✓</i> Open source</span>
                    <span><i class="check" aria-hidden="true">✓</i> Žádná procenta z tržby</span>
                    <span><i class="check" aria-hidden="true">✓</i> Vaše peněženka</span>
                </div>
            </div>

            <div class="payment-stage" aria-label="Ukázka průběhu Bitcoin platby">
                <div class="float-badge">Platba za pár kliknutí</div>
                <div class="checkout">
                    <div class="checkout-top"><strong>Objednávka #2026-084</strong><span class="status-pill">Čeká na platbu</span></div>
                    <div class="amount">0,00042 BTC</div>
                    <div class="fiat">≈ 1 990 Kč</div>
                    <div class="qr" aria-hidden="true">
                        <?php for ($i = 0; $i < 49; $i++): ?><i></i><?php endfor; ?>
                    </div>
                    <div class="address">bc1q8x7k2m9v4…p3n6r0a</div>
                </div>
                <div class="confirmation">
                    <div class="confirmation-head">
                        <span class="confirmation-icon" aria-hidden="true">✓</span>
                        <div><strong>Platba přijata</strong><p>Objednávka se automaticky aktualizuje.</p></div>
                    </div>
                    <div class="progress" aria-hidden="true"><span></span></div>
                </div>
            </div>
        </div>
    </header>

    <div class="proof">
        <div class="wrap proof-line">
            <div class="proof-item"><strong>Open source</strong><span>MIT licence a veřejný kód</span></div>
            <i class="proof-dot" aria-hidden="true"></i>
            <div class="proof-item"><strong>Electrum</strong><span>Ověřený motor peněženky</span></div>
            <i class="proof-dot" aria-hidden="true"></i>
            <div class="proof-item"><strong>PHP API</strong><span>Pro e-shopy a online služby</span></div>
        </div>
    </div>

    <section id="jak-to-funguje" class="section steps">
        <div class="wrap">
            <div class="section-head">
                <div><p class="eyebrow">Jednoduchý princip</p><h2>Tři kroky.<br>Hotová platba.</h2></div>
                <p>Zákazník nakupuje stejně jako vždy. Jen místo karty zvolí Bitcoin, naskenuje QR kód a obchod dostane informaci o platbě.</p>
            </div>
            <div class="step-list">
                <article class="step">
                    <span class="step-number">01 — OBJEDNÁVKA</span>
                    <h3>Zákazník vybere Bitcoin</h3>
                    <p>V košíku nebo na platební stránce zvolí možnost zaplatit BTC.</p>
                </article>
                <article class="step">
                    <span class="step-number">02 — QR KÓD</span>
                    <h3>Otevře svou peněženku</h3>
                    <p>Naskenuje QR kód. Částka i adresa se vyplní automaticky.</p>
                </article>
                <article class="step">
                    <span class="step-number">03 — POTVRZENO</span>
                    <h3>E-shop ví, že je zaplaceno</h3>
                    <p>Systém platbu sleduje a předá vašemu webu aktuální stav objednávky.</p>
                </article>
            </div>
        </div>
    </section>

    <section id="moznosti" class="section">
        <div class="wrap benefit-layout">
            <div class="sticky-copy">
                <p class="eyebrow">Malý systém. Velký užitek.</p>
                <h2>Jen to, co pro Bitcoin platby opravdu potřebujete.</h2>
                <p class="muted">BTCPay Server Lite nevznikl jako obří účetní platforma. Je to lehká cesta, jak přijímat on-chain BTC a předat výsledek vašemu webu.</p>
            </div>
            <div class="benefit-list">
                <article class="benefit">
                    <span class="benefit-icon" aria-hidden="true">₿</span>
                    <div><h3>Bitcoin rovnou do vaší peněženky</h3><p>Každá objednávka dostane vlastní adresu a platby zpracovává Electrum. Přehledně vidíte, co je nové, rozpracované nebo zaplacené.</p></div>
                </article>
                <article class="benefit">
                    <span class="benefit-icon" aria-hidden="true">↗</span>
                    <div><h3>Napojení na e-shop i vlastní službu</h3><p>API a webhooky propojí platbu s objednávkou, rezervací, členstvím, digitálním obsahem nebo jinou službou.</p></div>
                </article>
                <article class="benefit">
                    <span class="benefit-icon" aria-hidden="true">◎</span>
                    <div><h3>Lehký provoz bez zbytečného kolosu</h3><p>PHP, databáze a Electrum daemon. Systém je vhodný hlavně pro malé a středně vytížené obchody, které chtějí jednoduché řešení.</p></div>
                </article>
                <article class="benefit">
                    <span class="benefit-icon" aria-hidden="true">⌁</span>
                    <div><h3>Otevřený kód, žádný černý box</h3><p>Projekt je open source pod MIT licencí. Můžete si ho zkontrolovat, upravit, provozovat sami nebo si objednat pomoc.</p></div>
                </article>
            </div>
        </div>
    </section>

    <section class="tech-band" aria-labelledby="tech-title">
        <div class="wrap tech-panel">
            <div>
                <p class="eyebrow">Pro váš vývojový tým</p>
                <h2 id="tech-title">Jednoduché zvenku.<br>Čitelné uvnitř.</h2>
                <p>Pro běžného zákazníka je to platební stránka s QR kódem. Pro vývojáře je k dispozici PHP řešení, Greenfield-kompatibilní API, podepsané webhooky a stateless režim.</p>
                <div class="tech-tags"><span>PHP 8+</span><span>JSON API</span><span>Webhooks</span><span>Electrum RPC</span><span>BTC-CHAIN</span></div>
            </div>
            <div class="code-flow" aria-label="Schéma propojení systému">
                <div class="flow-row"><div class="flow-box">Váš e-shop / CMS</div><span class="flow-arrow" aria-hidden="true">→</span><div class="flow-box">BTCPay Lite API</div></div>
                <div class="flow-row"><div class="flow-box">Stav objednávky</div><span class="flow-arrow" aria-hidden="true">←</span><div class="flow-box">Webhook</div></div>
                <div class="flow-row"><div class="flow-box">Bitcoin síť</div><span class="flow-arrow" aria-hidden="true">↔</span><div class="flow-box">Electrum wallet</div></div>
            </div>
        </div>
    </section>

    <section id="cenik" class="section pricing">
        <div class="wrap">
            <div class="pricing-intro">
                <p class="eyebrow">Vyberte si svou cestu</p>
                <h2>Hotový provoz, vlastní server nebo integrace na míru.</h2>
                <p class="muted">Zdrojový kód je zdarma. Platíte za pohodlí, instalaci a práci, kterou nechcete řešit sami.</p>
            </div>
            <div class="pricing-grid">
                <article class="price-card featured">
                    <span class="popular">Nejjednodušší start</span>
                    <span class="plan">Provoz jako služba</span>
                    <div class="price"><?= e($pricing['hosted']) ?><sup> / měsíc</sup></div>
                    <p class="price-note">Pro ty, kdo chtějí Bitcoin platby bez správy serveru.</p>
                    <ul>
                        <li>Provoz serverové části</li>
                        <li>Základní nastavení obchodu</li>
                        <li>Průběžné aktualizace systému</li>
                        <li>Základní technická podpora</li>
                    </ul>
                    <a class="button button-brand" href="<?= e($registerUrl) ?>">Začít přijímat BTC</a>
                </article>
                <article class="price-card">
                    <span class="plan">Instalace na váš HW</span>
                    <div class="price"><sup>od </sup><?= e($pricing['hardware']) ?></div>
                    <p class="price-note">Jednorázové zprovoznění na vašem serveru nebo mini PC.</p>
                    <ul>
                        <li>Instalace BTCPay Server Lite</li>
                        <li>Nastavení Electrum daemonu</li>
                        <li>Základní zabezpečení a test</li>
                        <li>Předání přístupů a dokumentace</li>
                    </ul>
                    <a class="button button-primary" href="<?= e($contactUrl) ?>?title=Poptavka%3A%20instalace%20BTCPay%20Lite%20na%20vlastni%20HW" target="_blank" rel="noopener noreferrer">Chci vlastní instalaci</a>
                </article>
                <article class="price-card">
                    <span class="plan">Napojení na CMS / API</span>
                    <div class="price"><sup>od </sup><?= e($pricing['cms']) ?></div>
                    <p class="price-note">Implementace do vašeho e-shopu, webu nebo služby.</p>
                    <ul>
                        <li>Analýza nákupního procesu</li>
                        <li>Napojení API a webhooků</li>
                        <li>Test platebního průchodu</li>
                        <li>Úpravy podle konkrétního CMS</li>
                    </ul>
                    <a class="button button-primary" href="<?= e($contactUrl) ?>?title=Poptavka%3A%20integrace%20BTCPay%20Lite%20do%20CMS" target="_blank" rel="noopener noreferrer">Poptat integraci</a>
                </article>
            </div>
            <p class="pricing-foot">Uvedené ceny jsou orientační. Konečná cena instalace a integrace závisí na rozsahu, stavu vašeho webu a požadované podpoře.</p>
        </div>
    </section>

    <section class="section open-source">
        <div class="wrap open-grid">
            <div>
                <p class="eyebrow">Open source pod MIT licencí</p>
                <h2>Nevěříte slibům?<br>Podívejte se do kódu.</h2>
                <p class="muted">BTCPay Server Lite je otevřený projekt. Vývojář může řešení projít, otestovat a přizpůsobit. Vy se můžete rozhodnout, jestli chcete hotovou službu, vlastní provoz, nebo úplně samostatnou instalaci.</p>
                <div class="open-actions">
                    <a class="button button-primary" href="<?= e($githubUrl) ?>" target="_blank" rel="noopener noreferrer">Otevřít projekt na GitHubu <span aria-hidden="true">↗</span></a>
                    <a class="button button-ghost" href="<?= e($loginUrl) ?>">Přihlášení</a>
                </div>
            </div>
            <div class="terminal" aria-label="Ukázka technického základu projektu">
                <div class="terminal-top" aria-hidden="true"><i></i><i></i><i></i></div>
                <pre><span class="cmd">$</span> composer install
<span class="cmd">✓</span> PHP platební aplikace připravena

<span class="cmd">$</span> electrum daemon status
<span class="cmd">✓</span> connected

E-shop      → faktura
Electrum    → BTC adresa
Blockchain  → stav platby
Webhook     → objednávka zaplacena</pre>
            </div>
        </div>
    </section>

    <section id="faq" class="section faq">
        <div class="wrap faq-grid">
            <div><p class="eyebrow">Časté otázky</p><h2>Jasně a bez drobného písma.</h2></div>
            <div>
                <details open>
                    <summary>Potřebuji rozumět Bitcoinu nebo programování?</summary>
                    <p>Ne. Můžete využít hotový provoz nebo si objednat instalaci a napojení. Základní používání je podobné běžné správě objednávek.</p>
                </details>
                <details>
                    <summary>Kam přijaté bitcoiny přijdou?</summary>
                    <p>Platby směřují na adresy Electrum peněženky nastavené pro váš obchod. Konkrétní způsob správy peněženky zvolíme podle varianty provozu.</p>
                </details>
                <details>
                    <summary>Je software opravdu zdarma?</summary>
                    <p>Ano. Zdrojový kód je dostupný pod MIT licencí. Placený je provoz serveru, instalace, podpora nebo vývoj integrace na míru.</p>
                </details>
                <details>
                    <summary>Strhává si systém procenta z plateb?</summary>
                    <p>BTCPay Server Lite si z přijaté tržby žádné procento nestrhává. Běžné síťové poplatky Bitcoinu se mohou uplatnit při odesílání transakcí z peněženky.</p>
                </details>
                <details>
                    <summary>Funguje to s WooCommerce nebo jiným CMS?</summary>
                    <p>Systém nabízí Greenfield-kompatibilní API, webhooky a vzorového PHP klienta. Konkrétní CMS je před ostrým spuštěním potřeba propojit a otestovat; tuto integraci lze dodat na míru.</p>
                </details>
                <details>
                    <summary>Podporuje Lightning Network?</summary>
                    <p>Aktuální verze je zaměřená na Bitcoin on-chain platby (BTC-CHAIN) přes Electrum. Lightning Network zatím není součástí řešení.</p>
                </details>
            </div>
        </div>
    </section>

    <section class="cta" id="kontakt">
        <div class="wrap">
            <div class="cta-box">
                <h2>Váš první Bitcoin zákazník může být blíž, než si myslíte.</h2>
                <p>Napište, jaký e-shop nebo službu provozujete. Společně vybereme nejjednodušší cestu, jak začít přijímat BTC.</p>
                <div class="hero-actions">
                    <a class="button button-primary" href="<?= e($contactUrl) ?>?title=Poptavka%3A%20chci%20prijimat%20Bitcoin" target="_blank" rel="noopener noreferrer">Nezávazně se zeptat</a>
                    <a class="button button-ghost" href="<?= e($registerUrl) ?>">Vytvořit účet</a>
                </div>
            </div>
            <footer class="footer">
                <span>© <?= date('Y') ?> BTCPay Server Lite. Open-source software pod MIT licencí.</span>
                <div class="footer-links">
                    <a href="<?= e($baseUrl . '/dokumentace') ?>">Dokumentace API</a>
                    <a href="<?= e($githubUrl) ?>" target="_blank" rel="noopener noreferrer">GitHub</a>
                    <a href="<?= e($contactUrl) ?>" target="_blank" rel="noopener noreferrer">Kontakt</a>
                    <a href="<?= e($loginUrl) ?>">Přihlášení</a>
                </div>
            </footer>
        </div>
    </section>
</main>
</body>
</html>
