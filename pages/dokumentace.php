<?php
// Veřejná vývojářská dokumentace BTCPay Server Lite.
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
$canonicalUrl = $baseUrl . '/dokumentace';
$apiBaseUrl = $baseUrl . '/api/v1';
$statelessUrl = $baseUrl . '/api/stateless/invoices';
$githubUrl = 'https://github.com/agp-l/BTCPayServerLite';

function docsE(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$schema = [
    '@context' => 'https://schema.org',
    '@type' => 'TechArticle',
    'headline' => 'BTCPay Server Lite – dokumentace API',
    'description' => 'Veřejná dokumentace pro integraci Bitcoin plateb přes JSON API, checkout a podepsané webhooky.',
    'inLanguage' => 'cs-CZ',
    'url' => $canonicalUrl,
    'about' => ['Bitcoin', 'REST API', 'Electrum', 'E-commerce'],
    'isPartOf' => [
        '@type' => 'SoftwareApplication',
        'name' => 'BTCPay Server Lite',
        'url' => $baseUrl . '/',
    ],
];
?>
<!doctype html>
<html lang="cs">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Dokumentace JSON API | BTCPay Server Lite</title>
    <meta name="description"
        content="Dokumentace BTCPay Server Lite pro vývojáře: autentizace, JSON API, Bitcoin faktury, checkout, webhooky, stateless režim, cURL a PHP příklady.">
    <meta name="robots" content="index, follow">
    <meta name="theme-color" content="#07150f">
    <link rel="canonical" href="<?= docsE($canonicalUrl) ?>">
    <meta property="og:type" content="article">
    <meta property="og:locale" content="cs_CZ">
    <meta property="og:title" content="BTCPay Server Lite – dokumentace API">
    <meta property="og:description"
        content="Napojení e-shopu na Bitcoin platby krok za krokem. JSON, webhooky, cURL a PHP.">
    <meta property="og:url" content="<?= docsE($canonicalUrl) ?>">
    <script type="application/ld+json">
    <?= json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) ?>
    </script>
    <style>
    :root {
        --ink: #07150f;
        --ink-2: #12251c;
        --paper: #fff;
        --white: #fff;
        --muted: #202020;
        --line: #d8e0da;
        --brand: #20c875;
        --brand-soft: #e4f8ed;
        --mint: #6cf0a7;
        --blue: #72a7ff;
        --red: #ff746c;
        --code: #09110d;
        --radius: 18px;
        --shadow: 0 18px 60px rgba(7, 21, 15, .09);
    }

    * {
        box-sizing: border-box;
    }

    html {
        scroll-behavior: smooth;
    }

    body {
        margin: 0;
        color: var(--ink);
        background: var(--paper);
        font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        font-size: 16px;
        line-height: 1.65;
    }

    a {
        color: inherit;
    }

    button {
        font: inherit;
    }

    :focus-visible {
        outline: 3px solid var(--brand);
        outline-offset: 3px;
    }

    .skip-link {
        position: fixed;
        top: -5rem;
        left: 1rem;
        z-index: 100;
        padding: .7rem 1rem;
        border-radius: 10px;
        background: var(--white);
    }

    .skip-link:focus {
        top: 1rem;
    }

    .wrap {
        width: min(1420px, calc(100% - 40px));
        margin-inline: auto;
    }

    .topbar {
        position: sticky;
        top: 0;
        z-index: 20;
        border-bottom: 1px solid var(--line);
        background: rgba(236, 236, 236, 0.92);
        backdrop-filter: blur(16px);
    }

    .topbar-inner {
        display: flex;
        align-items: center;
        justify-content: space-between;
        min-height: 70px;
        gap: 1.5rem;
    }

    .brand {
        display: inline-flex;
        align-items: center;
        gap: .7rem;
        font-weight: 850;
        letter-spacing: -.03em;
        text-decoration: none;
    }

    .brand-mark {
        display: grid;
        place-items: center;
        width: 38px;
        height: 38px;
        border-radius: 50%;
        color: var(--white);
        background: var(--brand);
        font-size: 1.18rem;
    }

    .docs-label {
        margin-left: .45rem;
        padding-left: .8rem;
        border-left: 1px solid var(--line);
        color: var(--muted);
        font-size: .82rem;
        font-weight: 750;
        letter-spacing: .08em;
        text-transform: uppercase;
    }

    .top-links {
        display: flex;
        align-items: center;
        gap: 1.2rem;
    }

    .top-links a {
        color: var(--muted);
        font-size: .9rem;
        font-weight: 700;
        text-decoration: none;
    }

    .top-links a:hover {
        color: var(--ink);
    }

    .top-links .home-link {
        padding: .6rem .9rem;
        border: 1px solid var(--line);
        border-radius: 10px;
        color: var(--ink);
        background: var(--white);
    }

    .layout {
        display: grid;
        grid-template-columns: 245px minmax(0, 770px) minmax(260px, 1fr);
        gap: clamp(2rem, 4vw, 4.8rem);
        align-items: start;
        padding-block: 3rem 7rem;
    }

    .sidebar {
        position: sticky;
        top: 96px;
        max-height: calc(100vh - 116px);
        overflow: auto;
        padding-right: 1rem;
    }

    .side-title {
        margin: 0 0 .7rem;
        color: var(--muted);
        font-size: .72rem;
        font-weight: 850;
        letter-spacing: .13em;
        text-transform: uppercase;
    }

    .side-nav {
        display: grid;
        gap: .14rem;
        margin-bottom: 1.6rem;
    }

    .side-nav a {
        padding: .38rem .7rem;
        border-left: 2px solid transparent;
        color: var(--brand);
        font-size: .88rem;
        font-weight: 650;
        text-decoration: none;
    }

    .side-nav a:hover {
        border-left-color: var(--brand);
        color: var(--ink);
        background: rgba(255, 255, 255, .55);
    }

    .version {
        display: inline-flex;
        gap: .4rem;
        align-items: center;
        padding: .35rem .65rem;
        border: 1px solid var(--line);
        border-radius: 999px;
        color: var(--muted);
        background: var(--white);
        font-size: .72rem;
    }

    .version::before {
        content: "";
        width: 7px;
        height: 7px;
        border-radius: 50%;
        background: var(--mint);
    }

    .content {
        min-width: 0;
    }

    .hero {
        padding: 1.2rem 0 4.5rem;
    }

    .eyebrow {
        margin: 0 0 .8rem;
        color: var(--brand);
        font-size: .78rem;
        font-weight: 850;
        letter-spacing: .13em;
        text-transform: uppercase;
    }

    h1,
    h2,
    h3,
    h4 {
        line-height: 1.16;
        letter-spacing: -.03em;
    }

    h1 {
        max-width: 760px;
        margin: 0 0 1.2rem;
        font-size: clamp(2.8rem, 6vw, 5.3rem);
    }

    h2 {
        margin: 0 0 1rem;
        font-size: clamp(2rem, 3.6vw, 3.1rem);
    }

    h3 {
        margin: 2.2rem 0 .75rem;
        font-size: 1.38rem;
    }

    h4 {
        margin: 1.8rem 0 .55rem;
        font-size: 1.05rem;
    }

    p {
        margin-top: 0;
    }

    .lead {
        max-width: 710px;
        color: var(--code);
        font-size: 1.13rem;
    }

    .hero-tags {
        display: flex;
        flex-wrap: wrap;
        gap: .55rem;
        margin-top: 1.6rem;
    }

    .hero-tags span {
        padding: .38rem .65rem;
        border: 1px solid var(--line);
        border-radius: 999px;
        background: var(--white);
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: .75rem;
    }

    .doc-section {
        padding: 4.2rem 0;
        border-top: 1px solid var(--line);
        scroll-margin-top: 5.5rem;
    }

    .section-intro {
        max-width: 700px;
        color: var(--muted);
    }

    .note {
        display: grid;
        grid-template-columns: 34px 1fr;
        gap: .8rem;
        margin: 1.5rem 0;
        padding: 1.15rem;
        border: 1px solid #a6e7c5;
        border-radius: 14px;
        background: var(--brand-soft);
    }

    .note.info {
        border-color: #c6d8fa;
        background: #edf4ff;
    }

    .note.danger {
        border-color: #ffc5c1;
        background: #fff0ef;
    }

    .note-icon {
        display: grid;
        place-items: center;
        width: 30px;
        height: 30px;
        border-radius: 9px;
        background: var(--brand);
        font-weight: 900;
    }

    .note.info .note-icon {
        background: var(--blue);
    }

    .note.danger .note-icon {
        background: var(--red);
    }

    .note strong {
        display: block;
        margin-bottom: .15rem;
    }

    .note p {
        margin: 0;
        color: #4b5e54;
        font-size: .9rem;
    }

    .quick-grid {
        display: grid;
        grid-template-columns: repeat(3, 1fr);
        gap: .75rem;
        margin: 1.8rem 0;
    }

    .quick-step {
        padding: 1.15rem;
        border: 1px solid var(--line);
        border-radius: 14px;
        background: var(--white);
    }

    .quick-step span {
        display: inline-grid;
        place-items: center;
        width: 27px;
        height: 27px;
        margin-bottom: .8rem;
        border-radius: 8px;
        background: var(--brand-soft);
        color: #08713c;
        font-size: .78rem;
        font-weight: 900;
    }

    .quick-step strong {
        display: block;
        font-size: .92rem;
    }

    .quick-step p {
        margin: .35rem 0 0;
        color: var(--muted);
        font-size: .82rem;
    }

    .code-block {
        position: relative;
        margin: 1.3rem 0 1.8rem;
        overflow: hidden;
        border: 1px solid #1f3329;
        border-radius: var(--radius);
        background: var(--code);
        box-shadow: 0 13px 35px rgba(7, 21, 15, .12);
    }

    .code-head {
        display: flex;
        align-items: center;
        justify-content: space-between;
        min-height: 44px;
        padding: .5rem .65rem .5rem 1rem;
        border-bottom: 1px solid rgba(255, 255, 255, .1);
        color: #91a69a;
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: .72rem;
    }

    .copy-button {
        min-height: 31px;
        padding: .35rem .65rem;
        border: 1px solid rgba(255, 255, 255, .14);
        border-radius: 8px;
        color: #d7e5dd;
        background: rgba(255, 255, 255, .06);
        cursor: pointer;
        font-size: .72rem;
        font-weight: 700;
    }

    .copy-button:hover {
        background: rgba(255, 255, 255, .12);
    }

    pre {
        margin: 0;
        padding: 1.2rem;
        overflow: auto;
        color: #d9e7df;
        font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
        font-size: .82rem;
        line-height: 1.7;
        tab-size: 2;
    }

    .token-key {
        color: #90b8ff;
    }

    .token-string {
        color: #8cf0b4;
    }

    .token-number {
        color: #ffbf6e;
    }

    code.inline {
        padding: .14rem .34rem;
        border: 1px solid var(--line);
        border-radius: 5px;
        color: #075c34;
        background: var(--brand-soft);
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: .86em;
    }

    .endpoint {
        margin: 2.2rem 0 3.4rem;
    }

    .endpoint-title {
        display: flex;
        align-items: center;
        flex-wrap: wrap;
        gap: .65rem;
        margin-bottom: .8rem;
    }

    .method {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-width: 54px;
        padding: .28rem .5rem;
        border-radius: 7px;
        color: #07361e;
        background: #baf5d2;
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: .72rem;
        font-weight: 900;
    }

    .method.post {
        color: #075c34;
        background: #ccebdc;
    }

    .endpoint-path {
        overflow-wrap: anywhere;
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: .92rem;
        font-weight: 750;
    }

    .endpoint p {
        color: var(--muted);
    }

    .table-wrap {
        width: 100%;
        margin: 1.3rem 0 2rem;
        overflow-x: auto;
        border: 1px solid var(--line);
        border-radius: 14px;
        background: var(--white);
    }

    table {
        width: 100%;
        border-collapse: collapse;
        font-size: .86rem;
    }

    th,
    td {
        padding: .82rem .9rem;
        border-bottom: 1px solid var(--line);
        text-align: left;
        vertical-align: top;
    }

    th {
        color: var(--muted);
        background: #edf2ee;
        font-size: .72rem;
        letter-spacing: .08em;
        text-transform: uppercase;
    }

    tr:last-child td {
        border-bottom: 0;
    }

    td code {
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: .79rem;
        overflow-wrap: anywhere;
    }

    .status {
        display: inline-block;
        padding: .17rem .46rem;
        border-radius: 999px;
        font-size: .7rem;
        font-weight: 850;
    }

    .s-new {
        background: #edf0ee;
    }

    .s-processing {
        background: #fff0cf;
    }

    .s-settled {
        background: #d9f8e5;
    }

    .s-expired {
        background: #ffe0dd;
    }

    .param-list {
        display: grid;
        gap: 0;
        margin: 1rem 0 1.8rem;
        border-top: 1px solid var(--line);
    }

    .param {
        display: grid;
        grid-template-columns: 170px 1fr;
        gap: 1.2rem;
        padding: .9rem 0;
        border-bottom: 1px solid var(--line);
    }

    .param dt {
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: .84rem;
        font-weight: 800;
    }

    .param dd {
        margin: 0;
        color: var(--muted);
        font-size: .88rem;
    }

    .required {
        color: #08713c;
        font-size: .68rem;
        font-weight: 850;
        text-transform: uppercase;
    }

    .rail {
        position: sticky;
        top: 96px;
    }

    .rail-card {
        padding: 1.2rem;
        border: 1px solid var(--line);
        border-radius: var(--radius);
        background: var(--white);
        box-shadow: var(--shadow);
    }

    .rail-card+.rail-card {
        margin-top: 1rem;
    }

    .rail-label {
        margin: 0 0 .7rem;
        color: var(--muted);
        font-size: .7rem;
        font-weight: 850;
        letter-spacing: .12em;
        text-transform: uppercase;
    }

    .base-url {
        padding: .75rem;
        overflow-wrap: anywhere;
        border-radius: 10px;
        color: #cff9df;
        background: var(--code);
        font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
        font-size: .76rem;
    }

    .rail-list {
        display: grid;
        gap: .6rem;
        margin: 0;
        padding: 0;
        list-style: none;
        color: var(--muted);
        font-size: .82rem;
    }

    .rail-list li {
        display: flex;
        gap: .5rem;
    }

    .rail-list li::before {
        content: "✓";
        color: #0d9c4f;
        font-weight: 900;
    }

    .rail-link {
        display: block;
        margin-top: .9rem;
        color: #08713c;
        font-size: .82rem;
        font-weight: 800;
        text-decoration: none;
    }

    .rail-link:hover {
        text-decoration: underline;
    }

    .check-list {
        display: grid;
        gap: .75rem;
        margin: 1.3rem 0;
        padding: 0;
        list-style: none;
    }

    .check-list li {
        position: relative;
        padding-left: 1.75rem;
    }

    .check-list li::before {
        content: "✓";
        position: absolute;
        left: 0;
        top: .05rem;
        display: grid;
        place-items: center;
        width: 20px;
        height: 20px;
        border-radius: 6px;
        background: var(--mint);
        font-size: .72rem;
        font-weight: 900;
    }

    .footer {
        padding: 2.5rem 0;
        border-top: 1px solid var(--line);
        color: var(--muted);
        font-size: .82rem;
    }

    .footer-inner {
        display: flex;
        justify-content: space-between;
        gap: 2rem;
    }

    .footer-links {
        display: flex;
        gap: 1rem;
    }

    .footer a {
        text-decoration: none;
    }

    .footer a:hover {
        color: var(--ink);
    }

    @media (max-width: 1140px) {
        .layout {
            grid-template-columns: 210px minmax(0, 1fr);
        }

        .rail {
            display: none;
        }
    }

    @media (max-width: 780px) {
        .wrap {
            width: min(100% - 26px, 1420px);
        }

        .top-links a:not(.home-link) {
            display: none;
        }

        .docs-label {
            display: none;
        }

        .layout {
            display: block;
            padding-top: 1.2rem;
        }

        .sidebar {
            position: static;
            max-height: none;
            display: flex;
            gap: .35rem;
            margin: 0 -13px 2.5rem;
            padding: .2rem 13px .8rem;
            overflow-x: auto;
            border-bottom: 1px solid var(--line);
        }

        .sidebar>*:not(.side-nav) {
            display: none;
        }

        .side-nav {
            display: flex;
            flex: 0 0 auto;
            margin: 0;
        }

        .side-nav a {
            flex: 0 0 auto;
            padding: .48rem .65rem;
            border: 1px solid var(--line);
            border-radius: 999px;
            background: var(--white);
            white-space: nowrap;
        }

        .hero {
            padding-top: 1rem;
        }

        .quick-grid {
            grid-template-columns: 1fr;
        }

        .param {
            grid-template-columns: 1fr;
            gap: .25rem;
        }

        .footer-inner {
            flex-direction: column;
        }
    }

    @media (prefers-reduced-motion: reduce) {
        html {
            scroll-behavior: auto;
        }

        * {
            transition: none !important;
        }
    }
    </style>
</head>

<body>
    <a class="skip-link" href="#obsah">Přejít na dokumentaci</a>

    <header class="topbar">
        <div class="wrap topbar-inner">
            <a class="brand" href="<?= docsE($baseUrl . '/') ?>"><span class="brand-mark"
                    aria-hidden="true">₿</span><span>BTCPay Server Lite</span><span
                    class="docs-label">Dokumentace</span></a>
            <nav class="top-links" aria-label="Horní navigace">
                <a href="<?= docsE($githubUrl) ?>" target="_blank" rel="noopener noreferrer">GitHub ↗</a>
                <a href="<?= docsE($baseUrl . '/login') ?>">Přihlášení</a>
                <a class="home-link" href="<?= docsE($baseUrl . '/') ?>">Zpět na web</a>
            </nav>
        </div>
    </header>

    <div class="wrap layout">
        <aside class="sidebar" aria-label="Obsah dokumentace">
            <p class="side-title">Začínáme</p>
            <nav class="side-nav">
                <a href="#rychly-start">Rychlý start</a>
                <a href="#autentizace">Autentizace</a>
                <a href="#endpointy">Přehled endpointů</a>
            </nav>
            <p class="side-title">Příjem plateb</p>
            <nav class="side-nav">
                <a href="#faktury">Faktury</a>
                <a href="#stavy">Stavy plateb</a>
                <a href="#webhooky">Webhooky</a>
                <a href="#stateless">Stateless API</a>
            </nav>
            <p class="side-title">Další API</p>
            <nav class="side-nav">
                <a href="#kurzy">Kurzové nabídky</a>
                <a href="#vyplaty">Výplaty BTC</a>
                <a href="#chyby">Chyby a retry</a>
                <a href="#bezpecnost">Bezpečnost</a>
            </nav>
            <span class="version">API v1x</span>
        </aside>

        <main id="obsah" class="content">
            <header class="hero">
                <p class="eyebrow">Veřejná dokumentace pro vývojáře</p>
                <h1>Napojte svůj web na Bitcoin platby.</h1>
                <p class="lead">BTCPay Server Lite nabízí JSON API pro faktury, checkout, podepsané webhooky a lehký
                    stateless režim. Tato stránka popisuje skutečně implementované rozhraní aktuální verze.</p>
                <div class="hero-tags">
                    <span>application/json</span><span>BTC-CHAIN</span><span>HMAC-SHA256</span><span>PHP
                        8+</span><span>Electrum</span></div>
                <div class="note">
                    <span class="note-icon" aria-hidden="true">!</span>
                    <div><strong>Pouze Bitcoin on-chain</strong>
                        <p>Aktuální API nepodporuje Lightning Network, refundace ani pull payments. Pro příjem plateb
                            používejte platební metodu <code class="inline">BTC-CHAIN</code>.</p>
                    </div>
                </div>
            </header>

            <section id="rychly-start" class="doc-section">
                <p class="eyebrow">01 — Rychlý start</p>
                <h2>První faktura za pár minut.</h2>
                <p class="section-intro">V klientském portálu vytvořte obchod, zkopírujte jeho Store ID a API klíč.
                    Potom odešlete jeden JSON požadavek.</p>
                <div class="quick-grid">
                    <div class="quick-step"><span>1</span><strong>Získejte údaje</strong>
                        <p>URL serveru, Store ID a Store API klíč.</p>
                    </div>
                    <div class="quick-step"><span>2</span><strong>Vytvořte fakturu</strong>
                        <p>Pošlete částku, měnu a ID objednávky.</p>
                    </div>
                    <div class="quick-step"><span>3</span><strong>Otevřete checkout</strong>
                        <p>Přesměrujte zákazníka na vrácený odkaz.</p>
                    </div>
                </div>

                <h3>Vytvoření faktury pomocí cURL</h3>
                <div class="code-block">
                    <div class="code-head"><span>shell</span><button class="copy-button"
                            type="button">Kopírovat</button></div>
                    <pre><code>curl -X POST "<?= docsE($apiBaseUrl) ?>/stores/STORE_ID/invoices" \
  -H "Authorization: token STORE_API_KEY" \
  -H "Content-Type: application/json" \
  --data '{
    "amount": "1990.00",
    "currency": "CZK",
    "metadata": {
      "orderId": "OBJ-2026-084",
      "customerEmail": "zakaznik@example.cz"
    },
    "checkout": {
      "expirationMinutes": 20,
      "redirectURL": "https://shop.example.cz/dekujeme",
      "redirectAutomatically": true
    }
  }'</code></pre>
                </div>

                <h3>Typická odpověď</h3>
                <div class="code-block">
                    <div class="code-head"><span>200 · application/json</span><button class="copy-button"
                            type="button">Kopírovat</button></div>
                    <pre><code>{
  "id": "inv_8e9a31f7c20b",
  "storeId": "STORE_ID",
  "amount": "1990",
  "currency": "CZK",
  "type": "Standard",
  "checkoutLink": "<?= docsE($baseUrl) ?>/pay?id=inv_8e9a31f7c20b",
  "createdTime": 1788277200,
  "expirationTime": 1788278400,
  "monitoringTime": 1788278400,
  "archived": false,
  "status": "New",
  "additionalStatus": "None",
  "availableStatusesForManualMarking": [],
  "metadata": {
    "orderId": "OBJ-2026-084",
    "customerEmail": "zakaznik@example.cz"
  }
}</code></pre>
                </div>
                <div class="note info"><span class="note-icon" aria-hidden="true">i</span>
                    <div><strong>Částky posílejte jako řetězce</strong>
                        <p>Používejte <code class="inline">"1990.00"</code> nebo <code
                                class="inline">"0.00100000"</code>, nikoli JSON číslo. Vyhnete se zaokrouhlení v
                            JavaScriptu, PHP i databázi.</p>
                    </div>
                </div>
            </section>

            <section id="autentizace" class="doc-section">
                <p class="eyebrow">02 — Přístupové klíče</p>
                <h2>Autentizace</h2>
                <p class="section-intro">Klíč posílejte výhradně v HTTP hlavičce. Nikdy jej nevkládejte do URL,
                    JavaScriptu v prohlížeči, mobilní aplikace ani veřejného repozitáře.</p>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Účel</th>
                                <th>Hlavička</th>
                                <th>Klíč</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td>Faktury, obchody, webhooky, kurzy</td>
                                <td><code>Authorization: token …</code><br>nebo <code>Bearer …</code></td>
                                <td>Store API klíč</td>
                            </tr>
                            <tr>
                                <td>Stateless faktury</td>
                                <td><code>Authorization: Bearer …</code></td>
                                <td>Klíč z <code>api_clients</code></td>
                            </tr>
                            <tr>
                                <td>Odchozí BTC výplaty</td>
                                <td><code>Authorization: token …</code></td>
                                <td>Samostatný Payout API klíč</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="code-block">
                    <div class="code-head"><span>HTTP</span><button class="copy-button" type="button">Kopírovat</button>
                    </div>
                    <pre><code>Authorization: token YOUR_STORE_API_KEY
Content-Type: application/json
Accept: application/json</code></pre>
                </div>
                <div class="note danger"><span class="note-icon" aria-hidden="true">!</span>
                    <div><strong>Payout klíč musí být jiný</strong>
                        <p>Klíč pro výplaty nesmí být totožný se Store ani administrátorským API klíčem. Výplatní modul
                            je ve výchozím nastavení vypnutý.</p>
                    </div>
                </div>
            </section>

            <section id="endpointy" class="doc-section">
                <p class="eyebrow">03 — Reference</p>
                <h2>Přehled endpointů</h2>
                <p class="section-intro">Kanonický základ Greenfield API je <code
                        class="inline"><?= docsE($apiBaseUrl) ?></code>. Apache jej směruje na samostatný API
                    controller.</p>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Metoda</th>
                                <th>Cesta</th>
                                <th>Účel</th>
                                <th>Autentizace</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="method">GET</span></td>
                                <td><code>/health</code></td>
                                <td>Stav služby</td>
                                <td>Ne</td>
                            </tr>
                            <tr>
                                <td><span class="method">GET</span></td>
                                <td><code>/server/info</code></td>
                                <td>Verze a platební metody</td>
                                <td>Store</td>
                            </tr>
                            <tr>
                                <td><span class="method">GET</span></td>
                                <td><code>/api-keys/current</code></td>
                                <td>Oprávnění aktuálního klíče</td>
                                <td>Store</td>
                            </tr>
                            <tr>
                                <td><span class="method">GET</span></td>
                                <td><code>/stores/{storeId}</code></td>
                                <td>Nastavení obchodu</td>
                                <td>Store</td>
                            </tr>
                            <tr>
                                <td><span class="method">GET</span></td>
                                <td><code>/stores/{storeId}/payment-methods</code></td>
                                <td>Dostupné platební metody</td>
                                <td>Store</td>
                            </tr>
                            <tr>
                                <td><span class="method post">POST</span></td>
                                <td><code>/stores/{storeId}/invoices</code></td>
                                <td>Vytvoření faktury</td>
                                <td>Store</td>
                            </tr>
                            <tr>
                                <td><span class="method">GET</span></td>
                                <td><code>/stores/{storeId}/invoices/{invoiceId}</code></td>
                                <td>Stav faktury</td>
                                <td>Store</td>
                            </tr>
                            <tr>
                                <td><span class="method">GET</span></td>
                                <td><code>/stores/{storeId}/invoices/{invoiceId}/payment-methods</code></td>
                                <td>Adresa, BIP21 a dlužná částka</td>
                                <td>Store</td>
                            </tr>
                            <tr>
                                <td><span class="method">GET</span></td>
                                <td><code>/stores/{storeId}/webhooks</code></td>
                                <td>Seznam webhooků</td>
                                <td>Store</td>
                            </tr>
                            <tr>
                                <td><span class="method post">POST</span></td>
                                <td><code>/stores/{storeId}/webhooks</code></td>
                                <td>Registrace webhooku</td>
                                <td>Store</td>
                            </tr>
                            <tr>
                                <td><span class="method post">POST</span></td>
                                <td><code>/stores/{storeId}/exchange/quotes</code></td>
                                <td>Kurzová nabídka fiat/BTC</td>
                                <td>Store</td>
                            </tr>
                            <tr>
                                <td><span class="method">GET</span></td>
                                <td><code>/stores/{storeId}/payouts</code></td>
                                <td>Seznam výplat</td>
                                <td>Payout</td>
                            </tr>
                            <tr>
                                <td><span class="method post">POST</span></td>
                                <td><code>/stores/{storeId}/payouts</code></td>
                                <td>Vytvoření výplaty</td>
                                <td>Payout</td>
                            </tr>
                            <tr>
                                <td><span class="method">GET</span></td>
                                <td><code>/payouts/{payoutId}</code></td>
                                <td>Detail výplaty</td>
                                <td>Payout</td>
                            </tr>
                            <tr>
                                <td><span class="method post">POST</span></td>
                                <td><code>/payouts/{payoutId}</code></td>
                                <td>Schválení výplaty</td>
                                <td>Payout</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </section>

            <section id="faktury" class="doc-section">
                <p class="eyebrow">04 — Faktury</p>
                <h2>Vytvoření a načtení faktury</h2>
                <div class="endpoint">
                    <div class="endpoint-title"><span class="method post">POST</span><span
                            class="endpoint-path">/stores/{storeId}/invoices</span></div>
                    <p>Vytvoří novou Bitcoin fakturu a rezervuje pro ni přijímací adresu v Electrum peněžence obchodu.
                    </p>
                    <dl class="param-list">
                        <div class="param">
                            <dt>amount <span class="required">povinné</span></dt>
                            <dd>Desetinný řetězec. Např. <code class="inline">"1990.00"</code> nebo <code
                                    class="inline">"0.001"</code>.</dd>
                        </div>
                        <div class="param">
                            <dt>currency</dt>
                            <dd><code class="inline">BTC</code>, <code class="inline">SAT</code>/<code
                                    class="inline">SATS</code> nebo fiat měna dostupná u kurzového provideru. Výchozí je
                                BTC.</dd>
                        </div>
                        <div class="param">
                            <dt>metadata</dt>
                            <dd>Volitelný JSON objekt s ID objednávky a dalšími údaji vaší aplikace.</dd>
                        </div>
                        <div class="param">
                            <dt>checkout.expirationMinutes</dt>
                            <dd>Platnost 1 až 43 200 minut. Výchozí hodnota je 15 minut.</dd>
                        </div>
                        <div class="param">
                            <dt>checkout.redirectURL</dt>
                            <dd>Volitelná HTTP/HTTPS adresa pro návrat zákazníka.</dd>
                        </div>
                        <div class="param">
                            <dt>checkout.redirectAutomatically</dt>
                            <dd>Boolean. Automatické přesměrování po zaplacení.</dd>
                        </div>
                    </dl>
                </div>
                <div class="endpoint">
                    <div class="endpoint-title"><span class="method">GET</span><span
                            class="endpoint-path">/stores/{storeId}/invoices/{invoiceId}</span></div>
                    <p>Vrátí aktuální stav a metadata faktury. Fakturu lze načíst pouze klíčem obchodu, kterému patří.
                    </p>
                    <div class="code-block">
                        <div class="code-head"><span>shell</span><button class="copy-button"
                                type="button">Kopírovat</button></div>
                        <pre><code>curl "<?= docsE($apiBaseUrl) ?>/stores/STORE_ID/invoices/INVOICE_ID" \
  -H "Authorization: token STORE_API_KEY"</code></pre>
                    </div>
                </div>
                <div class="endpoint">
                    <div class="endpoint-title"><span class="method">GET</span><span
                            class="endpoint-path">/stores/{storeId}/invoices/{invoiceId}/payment-methods</span></div>
                    <p>Vrátí BTC adresu v poli <code class="inline">destination</code>, BIP21 URI v <code
                            class="inline">paymentLink</code>, očekávanou částku a zbývající částku <code
                            class="inline">due</code>.</p>
                </div>

                <h3>PHP klient bez další knihovny</h3>
                <div class="code-block">
                    <div class="code-head"><span>PHP 8+</span><button class="copy-button"
                            type="button">Kopírovat</button></div>
                    <pre><code>&lt;?php
$apiBase = '<?= docsE($apiBaseUrl) ?>';
$storeId = getenv('BTCPAY_STORE_ID');
$apiKey = getenv('BTCPAY_STORE_API_KEY');

$payload = [
    'amount' =&gt; '1490.00',
    'currency' =&gt; 'CZK',
    'metadata' =&gt; ['orderId' =&gt; 'OBJ-2026-085'],
    'checkout' =&gt; ['expirationMinutes' =&gt; 20],
];

$curl = curl_init($apiBase . '/stores/' . rawurlencode($storeId) . '/invoices');
curl_setopt_array($curl, [
    CURLOPT_POST =&gt; true,
    CURLOPT_RETURNTRANSFER =&gt; true,
    CURLOPT_TIMEOUT =&gt; 15,
    CURLOPT_HTTPHEADER =&gt; [
        'Authorization: token ' . $apiKey,
        'Content-Type: application/json',
        'Accept: application/json',
    ],
    CURLOPT_POSTFIELDS =&gt; json_encode($payload, JSON_THROW_ON_ERROR),
]);

$raw = curl_exec($curl);
$status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
if ($raw === false || $status &lt; 200 || $status &gt;= 300) {
    throw new RuntimeException('BTCPay API request failed: HTTP ' . $status);
}
$invoice = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
header('Location: ' . $invoice['checkoutLink'], true, 303);</code></pre>
                </div>
            </section>

            <section id="stavy" class="doc-section">
                <p class="eyebrow">05 — Životní cyklus</p>
                <h2>Stavy faktury</h2>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Stav</th>
                                <th>Význam</th>
                                <th>Co má udělat e-shop</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><span class="status s-new">New</span></td>
                                <td>Faktura čeká na platbu.</td>
                                <td>Objednávku držet jako nezaplacenou.</td>
                            </tr>
                            <tr>
                                <td><span class="status s-processing">Processing</span></td>
                                <td>Platba je viditelná, ale ještě není potvrzená.</td>
                                <td>Zobrazit „platba se potvrzuje“; zboží ještě neposílat.</td>
                            </tr>
                            <tr>
                                <td><span class="status s-settled">Settled</span></td>
                                <td>Očekávaná částka je potvrzená.</td>
                                <td>Označit objednávku jako zaplacenou. Operaci dělat idempotentně.</td>
                            </tr>
                            <tr>
                                <td><span class="status s-expired">Expired</span></td>
                                <td>Faktura vypršela bez dostatečné platby.</td>
                                <td>Nabídnout vytvoření nové faktury.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <p>Nespoléhejte pouze na návrat zákazníka z checkoutu. Autoritativní potvrzení zpracujte přes webhook
                    <code class="inline">InvoiceSettled</code> a stav si můžete následně ověřit přes GET endpoint
                    faktury.</p>
            </section>

            <section id="webhooky" class="doc-section">
                <p class="eyebrow">06 — Asynchronní události</p>
                <h2>Webhooky</h2>
                <p class="section-intro">Webhook oznámí vašemu serveru změnu faktury. Události se ukládají do
                    persistentní fronty a při dočasném selhání se opakují.</p>
                <div class="endpoint">
                    <div class="endpoint-title"><span class="method post">POST</span><span
                            class="endpoint-path">/stores/{storeId}/webhooks</span></div>
                    <div class="code-block">
                        <div class="code-head"><span>shell</span><button class="copy-button"
                                type="button">Kopírovat</button></div>
                        <pre><code>curl -X POST "<?= docsE($apiBaseUrl) ?>/stores/STORE_ID/webhooks" \
  -H "Authorization: token STORE_API_KEY" \
  -H "Content-Type: application/json" \
  --data '{
    "url": "https://shop.example.cz/webhooks/btcpay",
    "secret": "nahodny-tajny-retezec-minimalne-16-znaku"
  }'</code></pre>
                    </div>
                    <p>Pokud <code class="inline">secret</code> nepošlete, server jej vytvoří. Uložte hodnotu vrácenou
                        při registraci; v seznamu webhooků se secret znovu nevrací.</p>
                </div>
                <h3>Payload webhooku</h3>
                <div class="code-block">
                    <div class="code-head"><span>application/json</span><button class="copy-button"
                            type="button">Kopírovat</button></div>
                    <pre><code>{
  "deliveryId": "wd_f03370d1c0214629a4bf4f716bafc681",
  "webhookId": "wh_51c76e40c9d2",
  "storeId": "STORE_ID",
  "invoiceId": "inv_8e9a31f7c20b",
  "type": "InvoiceSettled",
  "timestamp": 1788277308
}</code></pre>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>Událost</th>
                                <th>Kdy vzniká</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>InvoiceProcessing</code></td>
                                <td>Platba je viditelná, ale nepotvrzená.</td>
                            </tr>
                            <tr>
                                <td><code>InvoiceSettled</code></td>
                                <td>Platba je potvrzená a částka dostačuje.</td>
                            </tr>
                            <tr>
                                <td><code>InvoiceExpired</code></td>
                                <td>Faktura vypršela.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <h3>Ověření podpisu v PHP</h3>
                <p>Podpis je v hlavičce <code class="inline">BTCPay-Sig: sha256=…</code>. HMAC počítejte nad přesnými
                    nezměněnými bajty HTTP těla, nikoli nad znovu zakódovaným JSONem.</p>
                <div class="code-block">
                    <div class="code-head"><span>PHP 8+</span><button class="copy-button"
                            type="button">Kopírovat</button></div>
                    <pre><code>&lt;?php
$secret = getenv('BTCPAY_WEBHOOK_SECRET');
$rawBody = file_get_contents('php://input');
$received = $_SERVER['HTTP_BTCPAY_SIG'] ?? '';
$expected = 'sha256=' . hash_hmac('sha256', $rawBody, $secret);

if (!hash_equals($expected, $received)) {
    http_response_code(401);
    exit;
}

$event = json_decode($rawBody, true, 32, JSON_THROW_ON_ERROR);

// deliveryId nebo kombinaci invoiceId + type uložte jako idempotency klíč.
if ($event['type'] === 'InvoiceSettled') {
    markOrderAsPaidOnce($event['invoiceId']);
}

http_response_code(204);</code></pre>
                </div>
                <ul class="check-list">
                    <li>Vraťte HTTP 2xx rychle; dlouhou práci předejte vlastní frontě.</li>
                    <li>Stejnou událost zpracujte bezpečně vícekrát.</li>
                    <li>Před změnou objednávky vždy ověřte HMAC podpis.</li>
                    <li>Webhook URL musí být veřejná HTTPS adresa; privátní sítě jsou v produkci blokované.</li>
                </ul>
            </section>

            <section id="stateless" class="doc-section">
                <p class="eyebrow">07 — Lehký režim</p>
                <h2>Stateless faktury</h2>
                <p class="section-intro">Stateless API vytvoří podepsaný platební odkaz bez databázového záznamu
                    faktury. Hodí se pro jednoduché platební odkazy; nenabízí webhooky ani samostatný veřejný status
                    endpoint.</p>
                <div class="endpoint">
                    <div class="endpoint-title"><span class="method post">POST</span><span
                            class="endpoint-path"><?= docsE($statelessUrl) ?></span></div>
                </div>
                <div class="code-block">
                    <div class="code-head"><span>shell</span><button class="copy-button"
                            type="button">Kopírovat</button></div>
                    <pre><code>curl -X POST "<?= docsE($statelessUrl) ?>" \
  -H "Authorization: Bearer STATELESS_API_KEY" \
  -H "Content-Type: application/json" \
  --data '{
    "amount": "0.00100000",
    "description": "Objednávka OBJ-2026-086",
    "order_id": "OBJ-2026-086",
    "expiration_minutes": 60
  }'</code></pre>
                </div>
                <div class="code-block">
                    <div class="code-head"><span>200 · application/json</span><button class="copy-button"
                            type="button">Kopírovat</button></div>
                    <pre><code>{
  "status": "success",
  "data": {
    "token": "SIGNED_TOKEN",
    "bip21_uri": "bitcoin:bc1q...?amount=0.00100000",
    "amount": "0.00100000",
    "description": "Objednávka OBJ-2026-086",
    "order_id": "OBJ-2026-086",
    "wallet": "merchant_wallet",
    "created_at": 1788277200,
    "expires_at": 1788280800,
    "expires_in_minutes": 60,
    "url": "<?= docsE($baseUrl) ?>/url-invoice?token=SIGNED_TOKEN"
  }
}</code></pre>
                </div>
                <dl class="param-list">
                    <div class="param">
                        <dt>amount <span class="required">povinné</span></dt>
                        <dd>Kladná BTC částka s nejvýše 8 desetinnými místy.</dd>
                    </div>
                    <div class="param">
                        <dt>description <span class="required">povinné</span></dt>
                        <dd>Popis do 255 bajtů.</dd>
                    </div>
                    <div class="param">
                        <dt>order_id</dt>
                        <dd>Volitelný identifikátor objednávky do 255 bajtů.</dd>
                    </div>
                    <div class="param">
                        <dt>expiration_minutes</dt>
                        <dd>10 až 43 200 minut; hodnoty mimo rozsah se omezí na nejbližší hranici. Výchozí je 15.</dd>
                    </div>
                </dl>
            </section>

            <section id="kurzy" class="doc-section">
                <p class="eyebrow">08 — Převod měn</p>
                <h2>Kurzová nabídka</h2>
                <p>Endpoint vrátí hrubou BTC částku, směnárenský poplatek a čistou výplatu podle nakonfigurovaného
                    tržního provideru a sazby serveru.</p>
                <div class="code-block">
                    <div class="code-head"><span>shell</span><button class="copy-button"
                            type="button">Kopírovat</button></div>
                    <pre><code>curl -X POST "<?= docsE($apiBaseUrl) ?>/stores/STORE_ID/exchange/quotes" \
  -H "Authorization: token STORE_API_KEY" \
  -H "Content-Type: application/json" \
  --data '{"amount":"500.00","currency":"CZK"}'</code></pre>
                </div>
                <div class="note info"><span class="note-icon" aria-hidden="true">i</span>
                    <div><strong>Krátkodobý výpočet, ne rezervovaný kurz</strong>
                        <p>Nabídka slouží pro okamžitý výpočet. Dostupnost fiat měny závisí na nakonfigurovaném kurzovém
                            provideru.</p>
                    </div>
                </div>
            </section>

            <section id="vyplaty" class="doc-section">
                <p class="eyebrow">09 — Volitelný modul</p>
                <h2>Odchozí BTC výplaty</h2>
                <div class="note danger"><span class="note-icon" aria-hidden="true">!</span>
                    <div><strong>Výplaty pohybují skutečnými BTC</strong>
                        <p>Modul je ve výchozím nastavení vypnutý. Aktivujte jej až po migraci, záloze peněženky,
                            nastavení samostatného klíče a testu na testnet/regtest.</p>
                    </div>
                </div>
                <h3>Bezpečné dvoukrokové vytvoření</h3>
                <div class="code-block">
                    <div class="code-head"><span>shell</span><button class="copy-button"
                            type="button">Kopírovat</button></div>
                    <pre><code>curl -X POST "<?= docsE($apiBaseUrl) ?>/stores/STORE_ID/payouts" \
  -H "Authorization: token PAYOUT_API_KEY" \
  -H "Idempotency-Key: exchange-order-2026-000001" \
  -H "Content-Type: application/json" \
  --data '{
    "destination": "bc1q...",
    "amount": "500.00",
    "currency": "CZK",
    "payoutMethodId": "BTC-CHAIN",
    "approved": false,
    "metadata": {"orderId":"EX-2026-000001"}
  }'</code></pre>
                </div>
                <p>Odpověď má stav <code class="inline">AwaitingApproval</code> a obsahuje <code
                        class="inline">id</code> a <code class="inline">revision</code>. Po nezávislé kontrole adresy a
                    částky výplatu schválíte:</p>
                <div class="code-block">
                    <div class="code-head"><span>shell</span><button class="copy-button"
                            type="button">Kopírovat</button></div>
                    <pre><code>curl -X POST "<?= docsE($apiBaseUrl) ?>/payouts/PAYOUT_ID" \
  -H "Authorization: token PAYOUT_API_KEY" \
  -H "Content-Type: application/json" \
  --data '{"revision":0}'</code></pre>
                </div>
                <ul class="check-list">
                    <li>Každá nová obchodní operace musí mít unikátní <code class="inline">Idempotency-Key</code> o
                        délce 16–128 znaků.</li>
                    <li>Při síťové chybě opakujte přesně stejný požadavek se stejným klíčem.</li>
                    <li>Stav <code class="inline">InProgress</code> potvrzuje broadcast, nikoli potvrzení v blockchainu.
                    </li>
                    <li><code class="inline">approved: true</code> používejte jen u silně omezené a auditované
                        automatizace.</li>
                </ul>
            </section>

            <section id="chyby" class="doc-section">
                <p class="eyebrow">10 — Odolná integrace</p>
                <h2>Chyby a opakování požadavků</h2>
                <p>Greenfield API vrací chybu ve tvaru <code class="inline">{"message":"…"}</code>. Stateless API
                    používá <code class="inline">{"status":"error","message":"…"}</code>.</p>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <th>HTTP</th>
                                <th>Význam</th>
                                <th>Doporučená reakce</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr>
                                <td><code>400</code></td>
                                <td>Neplatný JSON, částka, měna nebo parametr.</td>
                                <td>Neopakovat beze změny; opravit vstup.</td>
                            </tr>
                            <tr>
                                <td><code>401</code></td>
                                <td>Chybějící nebo neplatný klíč.</td>
                                <td>Zkontrolovat hlavičku a správný typ klíče.</td>
                            </tr>
                            <tr>
                                <td><code>404</code></td>
                                <td>Endpoint, obchod, faktura nebo výplata nebyly nalezeny.</td>
                                <td>Ověřit ID a scoping obchodu.</td>
                            </tr>
                            <tr>
                                <td><code>405</code></td>
                                <td>Nepovolená HTTP metoda.</td>
                                <td>Použít metodu z dokumentace.</td>
                            </tr>
                            <tr>
                                <td><code>409</code></td>
                                <td>Konflikt stavu, limitu nebo idempotence.</td>
                                <td>Načíst aktuální stav; nevytvářet duplikát.</td>
                            </tr>
                            <tr>
                                <td><code>413</code></td>
                                <td>Tělo nebo metadata jsou příliš velké.</td>
                                <td>Zmenšit požadavek; obecný limit těla je 65 536 bajtů.</td>
                            </tr>
                            <tr>
                                <td><code>500</code></td>
                                <td>Interní chyba serveru.</td>
                                <td>Logovat request ID na své straně a opakovat opatrně.</td>
                            </tr>
                            <tr>
                                <td><code>503</code></td>
                                <td>Server je zaneprázdněný, kurz nebo Electrum nejsou dostupné.</td>
                                <td>Respektovat <code>Retry-After</code> a použít exponenciální backoff.</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="code-block">
                    <div class="code-head"><span>JSON error</span><button class="copy-button"
                            type="button">Kopírovat</button></div>
                    <pre><code>{"message":"Invalid API key or store."}</code></pre>
                </div>
            </section>

            <section id="bezpecnost" class="doc-section">
                <p class="eyebrow">11 — Produkční checklist</p>
                <h2>Bezpečnost integrace</h2>
                <ul class="check-list">
                    <li>Všechny API požadavky odesílejte ze svého backendu přes HTTPS.</li>
                    <li>Klíče ukládejte do secret manageru nebo prostředí, ne do zdrojového kódu.</li>
                    <li>Store, Payout a Stateless klíče nesdílejte a pravidelně je měňte.</li>
                    <li>Webhook podpis ověřujte pomocí <code class="inline">hash_equals</code> nad raw HTTP tělem.</li>
                    <li>Zaplacení objednávky implementujte idempotentně.</li>
                    <li>Před ostrým provozem proveďte celý smoke test včetně webhooku a testovací platby.</li>
                    <li>Nikdy nevystavujte Electrum RPC port, wallet soubory, seed ani privátní klíče internetu.</li>
                    <li>Payout API zapínejte pouze s nízkými limity a odděleným klíčem.</li>
                </ul>
                <h3>Samostatný API tester</h3>
                <p>V repozitáři je připravený soubor <code class="inline">examples/btcpay_lite_api_tester.php</code>.
                    Lze jej nasadit jako jediný PHP soubor mimo produkční aplikaci a použít pro kontrolu všech
                    podporovaných endpointů a podpisu webhooku.</p>
                <p><a href="<?= docsE($githubUrl . '/blob/main/examples/btcpay_lite_api_tester.php') ?>" target="_blank"
                        rel="noopener noreferrer"><strong>Otevřít API tester na GitHubu ↗</strong></a></p>
            </section>
        </main>

        <aside class="rail" aria-label="Rychlé informace">
            <div class="rail-card">
                <p class="rail-label">API Base URL</p>
                <div class="base-url"><?= docsE($apiBaseUrl) ?></div>
                <a class="rail-link" href="#rychly-start">Vytvořit první fakturu →</a>
            </div>
            <div class="rail-card">
                <p class="rail-label">Kontrakt API</p>
                <ul class="rail-list">
                    <li>JSON objekty do 64 KiB</li>
                    <li>Částky jako řetězce</li>
                    <li>Unix timestampy v sekundách</li>
                    <li>On-chain BTC-CHAIN</li>
                    <li>Store-scoped přístup</li>
                </ul>
            </div>
            <div class="rail-card">
                <p class="rail-label">Potřebujete zdroj?</p>
                <ul class="rail-list">
                    <li>Veřejný MIT projekt</li>
                    <li>Ukázkový PHP klient</li>
                    <li>Kontraktní testy API</li>
                </ul>
                <a class="rail-link" href="<?= docsE($githubUrl) ?>" target="_blank" rel="noopener noreferrer">Zdrojový
                    kód ↗</a>
            </div>
        </aside>
    </div>

    <footer class="footer">
        <div class="wrap footer-inner"><span>BTCPay Server Lite · veřejná dokumentace API v1</span>
            <div class="footer-links"><a href="<?= docsE($baseUrl . '/') ?>">Prezentace</a><a
                    href="<?= docsE($githubUrl) ?>" target="_blank" rel="noopener noreferrer">GitHub</a></div>
        </div>
    </footer>

    <script>
    (() => {
        const buttons = document.querySelectorAll('.copy-button');
        buttons.forEach((button) => {
            button.addEventListener('click', async () => {
                const code = button.closest('.code-block')?.querySelector('code')?.innerText ||
                    '';
                if (!code) return;
                try {
                    await navigator.clipboard.writeText(code);
                    const previous = button.textContent;
                    button.textContent = 'Zkopírováno';
                    setTimeout(() => {
                        button.textContent = previous;
                    }, 1600);
                } catch (_) {
                    const area = document.createElement('textarea');
                    area.value = code;
                    area.setAttribute('readonly', '');
                    area.style.position = 'fixed';
                    area.style.opacity = '0';
                    document.body.appendChild(area);
                    area.select();
                    document.execCommand('copy');
                    area.remove();
                    button.textContent = 'Zkopírováno';
                    setTimeout(() => {
                        button.textContent = 'Kopírovat';
                    }, 1600);
                }
            });
        });
    })();
    </script>
</body>

</html>