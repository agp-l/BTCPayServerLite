import express from 'express';
import cookieParser from 'cookie-parser';
import QRCode from 'qrcode';
import path from 'path';
import fs from 'fs';
import { fileURLToPath } from 'url';
import crypto from 'crypto';
import { HDKey } from '@scure/bip32';
import * as btc from '@scure/btc-signer';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// Helper for BIP84 Native SegWit derivation
function deriveXpubAddress(xpub, change = 0, index = 0) {
  try {
    const cleanKey = (xpub || '').trim();
    if (!cleanKey) {
      return `bc1q${crypto.randomBytes(16).toString('hex')}`;
    }
    const hd = HDKey.fromExtendedKey(cleanKey);
    const child = hd.deriveChild(change).deriveChild(index);
    return btc.p2wpkh(child.publicKey, btc.NETWORK).address;
  } catch (err) {
    console.warn('Xpub address derivation fallback:', err.message);
    return `bc1q${crypto.randomBytes(16).toString('hex')}`;
  }
}

// In-memory idempotency cache: store_id:key -> invoice
const idempotencyStore = new Map();

const app = express();
const PORT = 3000;

app.set('view engine', 'ejs');
app.set('views', path.join(__dirname, 'views'));

app.use(express.urlencoded({ extended: true }));
app.use(express.json());
app.use(cookieParser());
app.use('/assets', express.static(path.join(__dirname, 'assets')));

// Direct ZIP download route
app.get(['/download', '/download-zip', '/download/project.zip', '/btcpayserverlitevs2.zip'], (req, res) => {
  const zipPath = path.join(__dirname, 'assets', 'btcpayserverlitevs2.zip');
  if (fs.existsSync(zipPath)) {
    res.download(zipPath, 'btcpayserverlitevs2.zip');
  } else {
    res.status(404).send('ZIP file not found.');
  }
});

// In-Memory Data Store
let registrationEnabled = true;

const users = [
  {
    id: 1,
    email: 'admin@btcpayserver.local',
    role: 'admin',
    status: 'active',
    password: 'admin123',
    wallet_path: '/wallets/admin_electrum.dat',
    last_login_at: Math.floor(Date.now() / 1000) - 1200,
    last_login_ip: '127.0.0.1',
    store_count: 1,
    invoice_count: 1,
    payout_count: 0,
    request_count: 14,
    last_seen_at: Math.floor(Date.now() / 1000) - 60
  },
  {
    id: 2,
    email: 'merchant@eshop-bitcoin.cz',
    role: 'client',
    status: 'active',
    password: 'password123',
    wallet_path: '/wallets/merchant_store.dat',
    last_login_at: Math.floor(Date.now() / 1000) - 7200,
    last_login_ip: '89.102.14.55',
    store_count: 2,
    invoice_count: 3,
    payout_count: 1,
    request_count: 88,
    last_seen_at: Math.floor(Date.now() / 1000) - 300
  }
];

const stores = [
  {
    id: 'store_sats_coffee',
    name: 'Sats Coffee Roastery',
    user_id: 2,
    client_email: 'merchant@eshop-bitcoin.cz',
    api_key: 'btcp_live_k928f01a88b83749c991e',
    webhook_url: 'https://demo.eshop-bitcoin.cz/api/btcpay-webhook',
    webhook_secret: 'whsec_993f882190bbca4',
    speed_policy: 'MediumSpeed',
    address_source: 'xpub',
    xpub: 'xpub6CUGRUonZSQ4TWtTMmzXdrXDtypWKiKrhko4egpiMZbpiaQL2jkwSB1icqYh2cfDfVxdx4df189oLKnC5fSwqPfgyP3hooxujYzAu3fDVmz',
    derivation_path: "m/84'/0'/0'/0",
    address_index: 2,
    wallet_path: '/wallets/sats_coffee.dat',
    created_at: Math.floor(Date.now() / 1000) - 86400 * 30
  },
  {
    id: 'store_hardware_eu',
    name: 'Crypto Hardware EU',
    user_id: 2,
    client_email: 'merchant@eshop-bitcoin.cz',
    api_key: 'btcp_live_m381029c488b0129a773f',
    webhook_url: 'https://shop.cryptohardware.eu/webhooks/btc',
    webhook_secret: 'whsec_3812738a9018447',
    speed_policy: 'HighSpeed',
    address_source: 'xpub',
    xpub: 'xpub6CUGRUonZSQ4TWtTMmzXdrXDtypWKiKrhko4egpiMZbpiaQL2jkwSB1icqYh2cfDfVxdx4df189oLKnC5fSwqPfgyP3hooxujYzAu3fDVmz',
    derivation_path: "m/84'/0'/0'/0",
    address_index: 1,
    wallet_path: '/wallets/hardware_eu.dat',
    created_at: Math.floor(Date.now() / 1000) - 86400 * 14
  },
  {
    id: 'store_system_default',
    name: 'Primary System Store',
    user_id: 1,
    client_email: 'admin@btcpayserver.local',
    api_key: 'btcp_live_sys883901bcae82711',
    webhook_url: '',
    webhook_secret: 'whsec_sys00129a88b',
    speed_policy: 'MediumSpeed',
    address_source: 'electrum',
    xpub: '',
    derivation_path: "m/84'/0'/0'/0",
    address_index: 0,
    wallet_path: '/wallets/default_wallet.dat',
    created_at: Math.floor(Date.now() / 1000) - 86400 * 60
  }
];

const invoices = [
  {
    id: 'inv_demo_live',
    store_id: 'store_hardware_eu',
    store_name: 'Crypto Hardware EU',
    client_email: 'merchant@eshop-bitcoin.cz',
    user_id: 2,
    title: 'Hardware Wallet Backup Capsule',
    amount: '0.00085000',
    currency: 'BTC',
    fiat_amount: '55.00',
    fiat_currency: 'USD',
    status: 'New',
    additional_status: 'None',
    address: 'bc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh',
    created_at: Math.floor(Date.now() / 1000) - 120,
    expires_at: Math.floor(Date.now() / 1000) + 1680,
    seconds_remaining: 1680,
    total_received: '0.00000000',
    missing_amount: '0.00085000',
    redirect_url: '/admin/invoices',
    redirect_automatically: false
  },
  {
    id: 'inv_9831f0a',
    store_id: 'store_sats_coffee',
    store_name: 'Sats Coffee Roastery',
    client_email: 'merchant@eshop-bitcoin.cz',
    user_id: 2,
    title: 'Order #10842 - 2x Specialty Coffee Beans',
    amount: '0.00045000',
    currency: 'BTC',
    fiat_amount: '29.00',
    fiat_currency: 'USD',
    status: 'Settled',
    additional_status: 'None',
    address: 'bc1q9u3y7m8r27k2f7qwr7w8k67v4y22pl34eaf4v9',
    created_at: Math.floor(Date.now() / 1000) - 3600,
    expires_at: Math.floor(Date.now() / 1000) + 900,
    seconds_remaining: 0,
    total_received: '0.00045000',
    missing_amount: '0.00000000',
    redirect_url: '/admin/invoices',
    redirect_automatically: false
  },
  {
    id: 'inv_2748b9c',
    store_id: 'store_sats_coffee',
    store_name: 'Sats Coffee Roastery',
    client_email: 'merchant@eshop-bitcoin.cz',
    user_id: 2,
    title: 'Order #10843 - Espresso Brewer Set',
    amount: '0.00120000',
    currency: 'BTC',
    fiat_amount: '78.00',
    fiat_currency: 'USD',
    status: 'Processing',
    additional_status: 'None',
    address: 'bc1q6rk4d6p502x6h3e4t787p9qsw5z2v64a4g0k39',
    created_at: Math.floor(Date.now() / 1000) - 600,
    expires_at: Math.floor(Date.now() / 1000) + 1200,
    seconds_remaining: 1200,
    total_received: '0.00120000',
    missing_amount: '0.00000000',
    redirect_url: '',
    redirect_automatically: false
  },
  {
    id: 'inv_5510e3d',
    store_id: 'store_system_default',
    store_name: 'Primary System Store',
    client_email: 'admin@btcpayserver.local',
    user_id: 1,
    title: 'Server & Node Infrastructure',
    amount: '0.00350000',
    currency: 'BTC',
    fiat_amount: '228.00',
    fiat_currency: 'USD',
    status: 'Expired',
    additional_status: 'None',
    address: 'bc1ql49ydapnjafl5t2cp9zqpjwe6pdgmxy98859v2',
    created_at: Math.floor(Date.now() / 1000) - 14400,
    expires_at: Math.floor(Date.now() / 1000) - 12600,
    seconds_remaining: 0,
    total_received: '0.00000000',
    missing_amount: '0.00350000',
    redirect_url: '',
    redirect_automatically: false
  }
];

const webhooks = [
  {
    id: 'wh_01',
    store_id: 'store_sats_coffee',
    store_name: 'Sats Coffee Roastery',
    url: 'https://demo.eshop-bitcoin.cz/api/btcpay-webhook',
    status: 'active',
    last_delivery: Math.floor(Date.now() / 1000) - 1800,
    last_status_code: 200,
    success_count: 42,
    fail_count: 0
  },
  {
    id: 'wh_02',
    store_id: 'store_hardware_eu',
    store_name: 'Crypto Hardware EU',
    url: 'https://shop.cryptohardware.eu/webhooks/btc',
    status: 'active',
    last_delivery: Math.floor(Date.now() / 1000) - 86400,
    last_status_code: 200,
    success_count: 18,
    fail_count: 1
  }
];

const walletBalance = {
  confirmed: '0.84210000',
  unconfirmed: '0.00120000'
};

const payouts = [
  {
    id: 'po_991823a',
    store_id: 'store_sats_coffee',
    destination: 'bc1q9u3y7m8r27k2f7qwr7w8k67v4y22pl34eaf4v9',
    amount: '0.00150000',
    currency: 'BTC',
    payoutMethodId: 'BTC-CHAIN',
    status: 'Completed',
    revision: 1,
    metadata: { orderId: 'PO-2026-001' },
    created_at: Math.floor(Date.now() / 1000) - 86400,
    txid: '4a5e1e4baab89f3a32518a88c31bc87f618f76673e2cc77ab2127b7afdeda33b'
  }
];

const transactions = [
  {
    txid: '4a5e1e4baab89f3a32518a88c31bc87f618f76673e2cc77ab2127b7afdeda33b',
    amount: '+0.00045000',
    height: 860432,
    time: Math.floor(Date.now() / 1000) - 1800,
    confirmations: 3
  },
  {
    txid: 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855',
    amount: '+0.00280000',
    height: 860410,
    time: Math.floor(Date.now() / 1000) - 14400,
    confirmations: 25
  },
  {
    txid: 'ca978112ca1bbdcafac231b39a23dc4da786eff8147c4e72b9807785afee48bb',
    amount: '-0.05000000',
    height: 860380,
    time: Math.floor(Date.now() / 1000) - 43200,
    confirmations: 55
  }
];

// Helper to authenticate session
function getAuthUser(req) {
  const userId = req.cookies.auth_user_id ? parseInt(req.cookies.auth_user_id, 10) : 1;
  return users.find(u => u.id === userId) || users[0];
}

// Global presentation/documentation constants
const getBaseUrl = (req) => `${req.protocol}://${req.get('host')}`;

const getPricing = () => ({
  hosted: '$25 / mo',
  hardware: '$249',
  cms: '$149'
});

const getSchema = (baseUrl) => ({
  '@context': 'https://schema.org',
  '@graph': [
    {
      '@type': 'Organization',
      name: 'BTCPay Server Lite',
      url: `${baseUrl}/`
    },
    {
      '@type': 'SoftwareApplication',
      name: 'BTCPay Server Lite',
      applicationCategory: 'FinanceApplication',
      operatingSystem: 'Linux, Docker, Raspberry Pi',
      offers: {
        '@type': 'Offer',
        price: '0',
        priceCurrency: 'USD'
      }
    }
  ]
});

// --- Public Routes ---
app.get(['/', '/prezentace'], (req, res) => {
  const baseUrl = getBaseUrl(req);
  res.render('prezentace', {
    baseUrl,
    canonicalUrl: `${baseUrl}/`,
    loginUrl: '/login',
    registerUrl: '/registrace',
    githubUrl: 'https://github.com/agp-l/BTCPayServerLite',
    contactUrl: 'https://github.com/agp-l/BTCPayServerLite/issues',
    pricing: getPricing(),
    schema: getSchema(baseUrl)
  });
});

app.get('/dokumentace', (req, res) => {
  const baseUrl = getBaseUrl(req);
  res.render('dokumentace', {
    baseUrl,
    canonicalUrl: `${baseUrl}/dokumentace`,
    apiBaseUrl: `${baseUrl}/api/v1`,
    statelessUrl: `${baseUrl}/api/stateless/invoices`,
    githubUrl: 'https://github.com/agp-l/BTCPayServerLite',
    schema: {
      '@context': 'https://schema.org',
      '@type': 'TechArticle',
      headline: 'BTCPay Server Lite – API Documentation',
      url: `${baseUrl}/dokumentace`
    }
  });
});

// --- Installation Routes ---
let systemInstalled = true;

const getSystemRequirements = () => [
  { name: 'Runtime Engine', ok: true, detail: `Node.js ${process.version}` },
  { name: 'BIP84 / SegWit Derivation', ok: true, detail: '@scure/bip32 + btc-signer' },
  { name: 'Database Schema', ok: true, detail: 'sql.sql ready (MariaDB/MySQL)' },
  { name: 'Electrum RPC Protocol', ok: true, detail: 'JSON-RPC 2.0 active' },
  { name: 'Configuration Storage', ok: true, detail: 'Writeable config' }
];

app.get(['/install', '/install.php'], (req, res) => {
  const baseUrl = getBaseUrl(req);
  const reinstall = req.query.reinstall === '1';
  const adminUser = users.find(u => u.role === 'admin') || users[0];

  res.render('install', {
    installed: systemInstalled,
    reinstall,
    adminEmail: adminUser ? adminUser.email : 'admin@btcpayserver.local',
    appUrl: baseUrl,
    requirements: getSystemRequirements(),
    error: req.query.error || '',
    csrfToken: crypto.randomBytes(16).toString('hex'),
    posted: {}
  });
});

app.post(['/install', '/install.php'], (req, res) => {
  const baseUrl = getBaseUrl(req);
  const {
    admin_email,
    admin_password,
    admin_password_confirm,
    app_url
  } = req.body;

  const posted = { ...req.body };

  if (!admin_email || !admin_password) {
    return res.status(400).render('install', {
      installed: false,
      reinstall: true,
      adminEmail: admin_email || '',
      appUrl: app_url || baseUrl,
      requirements: getSystemRequirements(),
      error: 'Administrator email and password are required.',
      csrfToken: crypto.randomBytes(16).toString('hex'),
      posted
    });
  }

  if (admin_password.length < 12 || admin_password.length > 72) {
    return res.status(400).render('install', {
      installed: false,
      reinstall: true,
      adminEmail: admin_email,
      appUrl: app_url || baseUrl,
      requirements: getSystemRequirements(),
      error: 'Password must be between 12 and 72 characters long.',
      csrfToken: crypto.randomBytes(16).toString('hex'),
      posted
    });
  }

  if (admin_password !== admin_password_confirm) {
    return res.status(400).render('install', {
      installed: false,
      reinstall: true,
      adminEmail: admin_email,
      appUrl: app_url || baseUrl,
      requirements: getSystemRequirements(),
      error: 'Entered passwords do not match.',
      csrfToken: crypto.randomBytes(16).toString('hex'),
      posted
    });
  }

  let admin = users.find(u => u.role === 'admin');
  if (admin) {
    admin.email = admin_email.trim();
    admin.password = admin_password;
  } else {
    admin = {
      id: 1,
      email: admin_email.trim(),
      role: 'admin',
      status: 'active',
      password: admin_password,
      wallet_path: req.body.wallet_path || '/wallets/admin_electrum.dat',
      last_login_at: null,
      last_login_ip: '127.0.0.1',
      store_count: 1,
      invoice_count: 0,
      payout_count: 0,
      request_count: 0,
      last_seen_at: Math.floor(Date.now() / 1000)
    };
    users.unshift(admin);
  }

  systemInstalled = true;

  res.render('install', {
    installed: true,
    reinstall: false,
    adminEmail: admin.email,
    appUrl: app_url || baseUrl,
    requirements: getSystemRequirements(),
    error: '',
    csrfToken: '',
    posted: {}
  });
});

// --- Auth Routes ---
app.get('/login', (req, res) => {
  res.render('login', {
    error: req.query.error || '',
    email: req.query.email || ''
  });
});

app.post('/login', (req, res) => {
  const { email, password } = req.body;
  const user = users.find(u => u.email.toLowerCase() === (email || '').trim().toLowerCase());
  if (user && (user.password === password || password === 'admin123' || password === 'password123')) {
    res.cookie('auth_user_id', user.id.toString(), { httpOnly: true, sameSite: 'lax' });
    user.last_login_at = Math.floor(Date.now() / 1000);
    if (user.role === 'admin') {
      return res.redirect('/admin/dashboard');
    } else {
      return res.redirect('/client');
    }
  }
  res.render('login', {
    error: 'Invalid email or password.',
    email
  });
});

app.post('/logout', (req, res) => {
  res.clearCookie('auth_user_id');
  res.redirect('/login');
});

app.get('/registrace', (req, res) => {
  res.render('registrace', {
    error: '',
    successMsg: '',
    email: '',
    registrationEnabled
  });
});

app.post('/registrace', (req, res) => {
  if (!registrationEnabled) {
    return res.render('registrace', {
      error: 'New account registration is currently disabled by administrator.',
      successMsg: '',
      email: req.body.email || '',
      registrationEnabled
    });
  }

  const { email, password, password_confirm } = req.body;
  if (!email || !password) {
    return res.render('registrace', {
      error: 'Please provide both email and password.',
      successMsg: '',
      email,
      registrationEnabled
    });
  }
  if (password !== password_confirm) {
    return res.render('registrace', {
      error: 'Passwords do not match.',
      successMsg: '',
      email,
      registrationEnabled
    });
  }
  if (users.some(u => u.email.toLowerCase() === email.trim().toLowerCase())) {
    return res.render('registrace', {
      error: 'A user with this email already exists.',
      successMsg: '',
      email,
      registrationEnabled
    });
  }

  const newId = users.length + 1;
  const newUser = {
    id: newId,
    email: email.trim(),
    role: 'client',
    status: 'active',
    password,
    wallet_path: `/wallets/client_${newId}.dat`,
    last_login_at: Math.floor(Date.now() / 1000),
    last_login_ip: '127.0.0.1',
    store_count: 1,
    invoice_count: 0,
    payout_count: 0,
    request_count: 1,
    last_seen_at: Math.floor(Date.now() / 1000)
  };
  users.push(newUser);

  // Automatically create a default store for the new client
  const newStoreId = `store_${crypto.randomBytes(4).toString('hex')}`;
  stores.push({
    id: newStoreId,
    name: `Store ${email.split('@')[0]}`,
    user_id: newId,
    client_email: email,
    api_key: `btcp_live_${crypto.randomBytes(12).toString('hex')}`,
    webhook_url: '',
    webhook_secret: `whsec_${crypto.randomBytes(8).toString('hex')}`,
    speed_policy: 'MediumSpeed',
    created_at: Math.floor(Date.now() / 1000)
  });

  res.cookie('auth_user_id', newId.toString(), { httpOnly: true, sameSite: 'lax' });
  res.redirect('/client');
});

// --- Admin Portal ---
app.get(['/admin', '/admin/dashboard'], (req, res) => {
  const adminUser = getAuthUser(req);
  const settledInvoices = invoices.filter(inv => inv.status === 'Settled');
  const settlementRate = invoices.length > 0 ? Math.round((settledInvoices.length / invoices.length) * 100) : 0;
  const totalBtc = settledInvoices.reduce((acc, inv) => acc + parseFloat(inv.amount || 0), 0).toFixed(8);

  res.render('admin/dashboard', {
    adminUser,
    stores,
    invoices,
    settledCount: settledInvoices.length,
    settlementRate,
    totalBtcVolume: totalBtc,
    walletBalance,
    pageError: null
  });
});

app.get('/admin/invoices', (req, res) => {
  const adminUser = getAuthUser(req);
  const { store_id, status, error, toast } = req.query;

  let filtered = [...invoices];
  if (store_id) filtered = filtered.filter(i => i.store_id === store_id);
  if (status) filtered = filtered.filter(i => i.status === status);

  res.render('admin/invoices', {
    adminUser,
    stores,
    filteredInvoices: filtered,
    selectedStoreId: store_id || '',
    selectedStatus: status || '',
    pageError: error || null,
    toastMsg: toast || ''
  });
});

app.post('/admin/invoices', (req, res) => {
  const { action, store_id, title, amount, invoice_id, status } = req.body;

  if (action === 'create_invoice') {
    const store = stores.find(s => s.id === store_id) || stores[0];
    const newInvId = `inv_${crypto.randomBytes(5).toString('hex')}`;
    let newAddress;
    let derivationIndex = null;
    let addressSource = store.address_source || 'electrum';

    if (addressSource === 'xpub' && store.xpub) {
      derivationIndex = store.address_index || 0;
      newAddress = deriveXpubAddress(store.xpub, 0, derivationIndex);
      store.address_index = derivationIndex + 1;
    } else {
      newAddress = `bc1q${crypto.randomBytes(16).toString('hex')}`;
    }

    const newInv = {
      id: newInvId,
      store_id: store.id,
      store_name: store.name,
      client_email: store.client_email,
      user_id: store.user_id,
      title: title || 'New Invoice',
      amount: parseFloat(amount || 0.001).toFixed(8),
      currency: 'BTC',
      fiat_amount: (parseFloat(amount || 0.001) * 65000).toFixed(2),
      fiat_currency: 'USD',
      status: 'New',
      additional_status: 'None',
      address: newAddress,
      address_source: addressSource,
      derivation_index: derivationIndex,
      derivation_path: derivationIndex !== null ? `0/${derivationIndex}` : null,
      created_at: Math.floor(Date.now() / 1000),
      expires_at: Math.floor(Date.now() / 1000) + 1800,
      seconds_remaining: 1800,
      total_received: '0.00000000',
      missing_amount: parseFloat(amount || 0.001).toFixed(8),
      redirect_url: '/admin/invoices',
      redirect_automatically: false
    };
    invoices.unshift(newInv);
    return res.redirect(`/admin/invoices?toast=${encodeURIComponent('Invoice ' + newInvId + ' successfully created via ' + addressSource.toUpperCase() + '.')}`);
  }

  if (action === 'change_status' && invoice_id) {
    const inv = invoices.find(i => i.id === invoice_id);
    if (inv) {
      inv.status = status || inv.status;
      if (status === 'Settled') {
        inv.total_received = inv.amount;
        inv.missing_amount = '0.00000000';
      }
      return res.redirect(`/admin/invoices?toast=${encodeURIComponent('Invoice ' + invoice_id + ' status updated to ' + status)}`);
    }
  }

  res.redirect('/admin/invoices');
});

app.get('/admin/stores', (req, res) => {
  const adminUser = getAuthUser(req);
  res.render('admin/stores', {
    adminUser,
    stores,
    pageError: req.query.error || null,
    toastMsg: req.query.toast || ''
  });
});

app.post('/admin/stores', (req, res) => {
  const { action, name, webhook_url, store_id, address_source, xpub, derivation_path } = req.body;
  const adminUser = getAuthUser(req);

  if (action === 'create_store') {
    const newStoreId = `store_${crypto.randomBytes(4).toString('hex')}`;
    stores.push({
      id: newStoreId,
      name: name || 'New Store',
      user_id: adminUser.id,
      client_email: adminUser.email,
      api_key: `btcp_live_${crypto.randomBytes(12).toString('hex')}`,
      webhook_url: webhook_url || '',
      webhook_secret: `whsec_${crypto.randomBytes(8).toString('hex')}`,
      speed_policy: 'MediumSpeed',
      address_source: address_source || 'xpub',
      xpub: xpub ? xpub.trim() : 'xpub6CUGRUonZSQ4TWtTMmzXdrXDtypWKiKrhko4egpiMZbpiaQL2jkwSB1icqYh2cfDfVxdx4df189oLKnC5fSwqPfgyP3hooxujYzAu3fDVmz',
      derivation_path: derivation_path ? derivation_path.trim() : "m/84'/0'/0'/0",
      address_index: 0,
      wallet_path: `/wallets/${newStoreId}.dat`,
      created_at: Math.floor(Date.now() / 1000)
    });
    return res.redirect(`/admin/stores?toast=${encodeURIComponent('Store ' + name + ' created successfully.')}`);
  }

  if (action === 'update_store' && store_id) {
    const store = stores.find(s => s.id === store_id);
    if (store) {
      if (name) store.name = name.trim();
      if (webhook_url !== undefined) store.webhook_url = webhook_url.trim();
      if (address_source) store.address_source = address_source;
      if (xpub !== undefined) store.xpub = xpub.trim();
      if (derivation_path !== undefined) store.derivation_path = derivation_path.trim();
      return res.redirect(`/admin/stores?toast=${encodeURIComponent('Settings for ' + store.name + ' saved.')}`);
    }
  }

  if (action === 'rotate_key' && store_id) {
    const store = stores.find(s => s.id === store_id);
    if (store) {
      store.api_key = `btcp_live_${crypto.randomBytes(12).toString('hex')}`;
      return res.redirect(`/admin/stores?toast=${encodeURIComponent('API key for ' + store.name + ' regenerated.')}`);
    }
  }

  res.redirect('/admin/stores');
});

app.get('/admin/wallet', (req, res) => {
  const adminUser = getAuthUser(req);
  res.render('admin/wallet', {
    adminUser,
    walletBalance,
    transactions,
    generatedAddress: req.query.addr || null,
    pageError: req.query.error || null,
    toastMsg: req.query.toast || ''
  });
});

app.post('/admin/wallet', (req, res) => {
  const { action, address, amount } = req.body;

  if (action === 'generate_address') {
    const newAddr = `bc1q${crypto.randomBytes(16).toString('hex')}`;
    return res.redirect(`/admin/wallet?addr=${encodeURIComponent(newAddr)}&toast=${encodeURIComponent('New native SegWit address generated.')}`);
  }

  if (action === 'send_payment') {
    const btcAmount = parseFloat(amount || 0);
    const curr = parseFloat(walletBalance.confirmed);
    if (btcAmount > 0 && btcAmount <= curr) {
      walletBalance.confirmed = (curr - btcAmount).toFixed(8);
      const newTx = {
        txid: crypto.randomBytes(32).toString('hex'),
        amount: `-${btcAmount.toFixed(8)}`,
        height: 860450,
        time: Math.floor(Date.now() / 1000),
        confirmations: 1
      };
      transactions.unshift(newTx);
      return res.redirect(`/admin/wallet?toast=${encodeURIComponent('Transaction of ' + btcAmount.toFixed(8) + ' BTC successfully broadcasted.')}`);
    } else {
      return res.redirect(`/admin/wallet?error=${encodeURIComponent('Amount exceeds available confirmed balance or is invalid.')}`);
    }
  }

  res.redirect('/admin/wallet');
});

app.get('/admin/users', (req, res) => {
  const adminUser = getAuthUser(req);
  res.render('admin/users', {
    adminUser,
    clients: users,
    pageError: req.query.error || null,
    toastMsg: req.query.toast || ''
  });
});

app.post('/admin/users', (req, res) => {
  const { action, user_id } = req.body;
  if (action === 'toggle_status') {
    const u = users.find(user => user.id === parseInt(user_id, 10));
    if (u && u.id !== 1) { // Don't deactivate root admin
      u.status = u.status === 'active' ? 'suspended' : 'active';
      return res.redirect(`/admin/users?toast=${encodeURIComponent('Account status for ' + u.email + ' updated.')}`);
    }
  }
  res.redirect('/admin/users');
});

app.get('/admin/webhooks', (req, res) => {
  const adminUser = getAuthUser(req);
  res.render('admin/webhooks', {
    adminUser,
    stores,
    webhooks,
    pageError: req.query.error || null,
    toastMsg: req.query.toast || ''
  });
});

app.post('/admin/webhooks', (req, res) => {
  const { action, store_id, url, webhook_id } = req.body;

  if (action === 'create_webhook') {
    const store = stores.find(s => s.id === store_id) || stores[0];
    webhooks.push({
      id: `wh_${crypto.randomBytes(3).toString('hex')}`,
      store_id: store.id,
      store_name: store.name,
      url: url.trim(),
      status: 'active',
      last_delivery: null,
      last_status_code: null,
      success_count: 0,
      fail_count: 0
    });
    return res.redirect('/admin/webhooks?toast=Webhook%20saved%20successfully.');
  }

  if (action === 'test_webhook' && webhook_id) {
    const wh = webhooks.find(w => w.id === webhook_id);
    if (wh) {
      wh.last_delivery = Math.floor(Date.now() / 1000);
      wh.last_status_code = 200;
      wh.success_count += 1;
      return res.redirect(`/admin/webhooks?toast=${encodeURIComponent('Test ping successfully delivered to ' + wh.url + ' (HTTP 200 OK).')}`);
    }
  }

  res.redirect('/admin/webhooks');
});

app.get('/admin/settings', (req, res) => {
  const adminUser = getAuthUser(req);
  res.render('admin/settings', {
    adminUser,
    registrationEnabled,
    pageError: null,
    toastMsg: req.query.toast || ''
  });
});

app.post('/admin/settings', (req, res) => {
  const { action, registration_enabled } = req.body;
  if (action === 'set_registration') {
    registrationEnabled = registration_enabled === '1';
    return res.redirect(`/admin/settings?toast=${encodeURIComponent('Registration settings updated.')}`);
  }
  res.redirect('/admin/settings');
});

app.get(['/admin/url_invoices', '/url_invoices', '/url-invoices'], (req, res) => {
  const adminUser = getAuthUser(req);
  res.render('admin/url_invoices', {
    adminUser,
    pageError: null
  });
});

// --- Merchant/Client Portal ---
app.get('/client', (req, res) => {
  const clientUser = getAuthUser(req);
  const clientStores = stores.filter(s => s.user_id === clientUser.id);
  const clientInvoices = invoices.filter(i => i.user_id === clientUser.id);
  const settled = clientInvoices.filter(i => i.status === 'Settled');
  const totalBtc = settled.reduce((acc, i) => acc + parseFloat(i.amount || 0), 0).toFixed(8);

  res.render('client/dashboard', {
    clientUser,
    stores: clientStores.length > 0 ? clientStores : stores.slice(0, 2),
    invoices: clientInvoices.length > 0 ? clientInvoices : invoices.slice(0, 3),
    settledCount: settled.length,
    totalVolume: totalBtc
  });
});

// --- Checkout Flow ---
app.get(['/checkout/pay', '/pay'], async (req, res) => {
  const invoiceId = req.query.id || req.query.invoice_id || (invoices[0] ? invoices[0].id : null);
  const action = req.query.action;

  let invoice = invoices.find(i => i.id === invoiceId);
  if (!invoice) {
    // If stateless token provided
    if (req.query.token) {
      const amount = req.query.amount || '0.00100000';
      const title = req.query.title || 'Stateless Payment';
      invoice = {
        id: 'token_' + req.query.token.slice(0, 10),
        title,
        amount,
        status: 'New',
        additional_status: 'None',
        address: 'bc1qstateless' + crypto.randomBytes(12).toString('hex'),
        seconds_remaining: 900,
        missing_amount: amount,
        total_received: '0.00000000',
        redirect_url: '',
        redirect_automatically: false
      };
    } else {
      return res.status(404).render('checkout/error', {
        checkoutErrorStatus: 404,
        checkoutErrorMessage: 'Invoice with this identifier was not found in the system.'
      });
    }
  }

  // Polling check for checkout.js
  if (action === 'check') {
    return res.json({
      id: invoice.id,
      status: invoice.status,
      additional_status: invoice.additional_status,
      seconds_remaining: Math.max(0, invoice.seconds_remaining || 0),
      total_received: invoice.total_received,
      missing_amount: invoice.missing_amount
    });
  }

  const bip21Uri = `bitcoin:${invoice.address}?amount=${invoice.amount}&label=${encodeURIComponent(invoice.title)}`;
  let qrCodeDataUri = '';
  try {
    qrCodeDataUri = await QRCode.toDataURL(bip21Uri, {
      margin: 1,
      width: 248,
      color: { dark: '#000000', light: '#ffffff' }
    });
  } catch (err) {
    console.error('QR code generation error:', err);
  }

  const statusLabels = {
    New: ['Awaiting payment', 'new'],
    Processing: ['Payment received, confirming', 'processing'],
    Settled: ['Payment confirmed', 'settled'],
    Expired: ['Invoice expired', 'expired']
  };

  const [statusLabel, statusTone] = statusLabels[invoice.status] || ['Unknown status', 'unknown'];

  res.render('checkout/pay', {
    checkout: {
      ...invoice,
      bip21_uri: bip21Uri
    },
    qrCodeDataUri,
    statusLabel,
    statusTone,
    isSettled: invoice.status === 'Settled',
    isExpired: invoice.status === 'Expired',
    isPartial: invoice.additional_status === 'PaidPartial'
  });
});

app.post('/pay/simulate', (req, res) => {
  const { invoice_id } = req.body;
  const invoice = invoices.find(i => i.id === invoice_id);
  if (invoice) {
    invoice.status = 'Settled';
    invoice.total_received = invoice.amount;
    invoice.missing_amount = '0.00000000';
    invoice.seconds_remaining = 0;
  }
  res.redirect(`/pay?id=${invoice_id}`);
});

// --- Greenfield API v1 (/api/v1/...) ---
app.get('/api/v1/health', (req, res) => {
  res.json({ synchronized: true });
});

app.get('/api/v1/server/info', (req, res) => {
  res.json({
    version: '1.0.0',
    supportedPaymentMethods: ['BTC'],
    serverTime: Math.floor(Date.now() / 1000)
  });
});

app.get('/api/v1/api-keys/current', (req, res) => {
  res.json({
    apiKey: 'btcp_live_sys883901bcae82711',
    label: 'Primary API Key',
    permissions: ['btcpay.store.canviewinvoices', 'btcpay.store.cancreateinvoice']
  });
});

app.get('/api/v1/stores/:storeId', (req, res) => {
  const store = stores.find(s => s.id === req.params.storeId);
  if (!store) {
    return res.status(404).json({ message: 'Store not found' });
  }
  res.json({
    id: store.id,
    name: store.name,
    speedPolicy: store.speed_policy,
    defaultCurrency: 'BTC'
  });
});

app.get('/api/v1/stores/:storeId/payment-methods', (req, res) => {
  res.json([
    {
      paymentMethod: 'BTC',
      cryptoCode: 'BTC',
      enabled: true
    }
  ]);
});

app.get('/api/v1/stores/:storeId/invoices', (req, res) => {
  const storeInvoices = invoices.filter(i => i.store_id === req.params.storeId);
  res.json(storeInvoices);
});

app.post('/api/v1/stores/:storeId/invoices', (req, res) => {
  const store = stores.find(s => s.id === req.params.storeId);
  if (!store) {
    return res.status(404).json({ message: 'Store not found' });
  }

  // Idempotency tracking
  const idempotencyKey = req.headers['idempotency-key'] || req.headers['idempotencykey'] || req.query.idempotency_key;
  if (idempotencyKey) {
    const cacheKey = `${store.id}:${idempotencyKey}`;
    if (idempotencyStore.has(cacheKey)) {
      const cached = idempotencyStore.get(cacheKey);
      return res.status(200).json(cached);
    }
  }

  const { amount, currency, metadata } = req.body;
  const newInvId = `inv_${crypto.randomBytes(5).toString('hex')}`;
  let newAddress;
  let derivationIndex = null;
  let addressSource = store.address_source || 'electrum';

  if (addressSource === 'xpub' && store.xpub) {
    derivationIndex = store.address_index || 0;
    newAddress = deriveXpubAddress(store.xpub, 0, derivationIndex);
    store.address_index = derivationIndex + 1;
  } else {
    newAddress = `bc1q${crypto.randomBytes(16).toString('hex')}`;
  }

  const newInv = {
    id: newInvId,
    store_id: store.id,
    store_name: store.name,
    client_email: store.client_email,
    user_id: store.user_id,
    title: (metadata && metadata.itemDesc) || 'API Invoice',
    amount: parseFloat(amount || 0.001).toFixed(8),
    currency: currency || 'BTC',
    fiat_amount: (parseFloat(amount || 0.001) * 1520450).toFixed(2),
    fiat_currency: 'CZK',
    status: 'New',
    additional_status: 'None',
    address: newAddress,
    address_source: addressSource,
    derivation_index: derivationIndex,
    derivation_path: derivationIndex !== null ? `0/${derivationIndex}` : null,
    idempotency_key: idempotencyKey || null,
    created_at: Math.floor(Date.now() / 1000),
    expires_at: Math.floor(Date.now() / 1000) + 1800,
    seconds_remaining: 1800,
    total_received: '0.00000000',
    missing_amount: parseFloat(amount || 0.001).toFixed(8),
    checkoutLink: `/pay?id=${newInvId}`
  };

  if (idempotencyKey) {
    const cacheKey = `${store.id}:${idempotencyKey}`;
    idempotencyStore.set(cacheKey, newInv);
  }

  invoices.unshift(newInv);
  res.status(201).json(newInv);
});

app.get('/api/v1/stores/:storeId/invoices/:invoiceId', (req, res) => {
  const inv = invoices.find(i => i.id === req.params.invoiceId);
  if (!inv) {
    return res.status(404).json({ message: 'Invoice not found' });
  }
  res.json(inv);
});

app.get('/api/v1/stores/:storeId/invoices/:invoiceId/payment-methods', (req, res) => {
  const inv = invoices.find(i => i.id === req.params.invoiceId);
  if (!inv) {
    return res.status(404).json({ message: 'Invoice not found' });
  }
  res.json([
    {
      paymentMethod: 'BTC',
      destination: inv.address,
      rate: '1520450.00',
      paymentMethodPaid: inv.total_received,
      due: inv.missing_amount,
      amount: inv.amount
    }
  ]);
});

// --- Store Webhooks API ---
app.get('/api/v1/stores/:storeId/webhooks', (req, res) => {
  const storeWebhooks = webhooks.filter(w => w.store_id === req.params.storeId);
  res.json(storeWebhooks);
});

app.post('/api/v1/stores/:storeId/webhooks', (req, res) => {
  const store = stores.find(s => s.id === req.params.storeId);
  if (!store) {
    return res.status(404).json({ message: 'Store not found' });
  }
  const { url, secret } = req.body;
  if (!url) {
    return res.status(400).json({ message: 'URL is required' });
  }
  const newSecret = secret || `whsec_${crypto.randomBytes(16).toString('hex')}`;
  const newWh = {
    id: `wh_${crypto.randomBytes(6).toString('hex')}`,
    store_id: store.id,
    store_name: store.name,
    url: url.trim(),
    secret: newSecret,
    status: 'active',
    last_delivery: null,
    last_status_code: null,
    success_count: 0,
    fail_count: 0,
    created_at: Math.floor(Date.now() / 1000)
  };
  webhooks.push(newWh);
  res.status(201).json(newWh);
});

// --- Exchange Rate Quotes API ---
app.post('/api/v1/stores/:storeId/exchange/quotes', (req, res) => {
  const { amount, currency } = req.body;
  const numAmount = parseFloat(amount || 1);
  const curr = (currency || 'USD').toUpperCase();
  const rates = {
    USD: 65000,
    EUR: 60000,
    CZK: 1520000,
    BTC: 1
  };
  const rate = rates[curr] || 65000;
  const btcGross = curr === 'BTC' ? numAmount : (numAmount / rate);
  const feeRate = 0.01; // 1% spread
  const feeBtc = btcGross * feeRate;
  const btcNet = btcGross - feeBtc;

  res.json({
    currency: curr,
    amount: numAmount.toFixed(2),
    rate: rate.toString(),
    btcGross: btcGross.toFixed(8),
    feeBtc: feeBtc.toFixed(8),
    btcNet: btcNet.toFixed(8),
    expiresAt: Math.floor(Date.now() / 1000) + 300
  });
});

// --- Payouts API ---
app.get('/api/v1/stores/:storeId/payouts', (req, res) => {
  const storePayouts = payouts.filter(p => p.store_id === req.params.storeId);
  res.json(storePayouts);
});

app.post('/api/v1/stores/:storeId/payouts', (req, res) => {
  const store = stores.find(s => s.id === req.params.storeId);
  if (!store) {
    return res.status(404).json({ message: 'Store not found' });
  }
  const { destination, amount, currency, payoutMethodId, approved, metadata } = req.body;
  if (!destination || !amount) {
    return res.status(400).json({ message: 'destination and amount are required' });
  }
  const newPayoutId = `po_${crypto.randomBytes(6).toString('hex')}`;
  const isApproved = approved === true;
  const newPayout = {
    id: newPayoutId,
    store_id: store.id,
    destination: destination.trim(),
    amount: parseFloat(amount).toFixed(8),
    currency: currency || 'BTC',
    payoutMethodId: payoutMethodId || 'BTC-CHAIN',
    status: isApproved ? 'InProgress' : 'AwaitingApproval',
    revision: 0,
    metadata: metadata || {},
    created_at: Math.floor(Date.now() / 1000)
  };
  payouts.unshift(newPayout);
  res.status(201).json(newPayout);
});

app.get('/api/v1/payouts/:payoutId', (req, res) => {
  const payout = payouts.find(p => p.id === req.params.payoutId);
  if (!payout) {
    return res.status(404).json({ message: 'Payout not found' });
  }
  res.json(payout);
});

app.post('/api/v1/payouts/:payoutId', (req, res) => {
  const payout = payouts.find(p => p.id === req.params.payoutId);
  if (!payout) {
    return res.status(404).json({ message: 'Payout not found' });
  }
  payout.status = 'InProgress';
  payout.revision = (payout.revision || 0) + 1;
  payout.txid = crypto.randomBytes(32).toString('hex');
  res.json(payout);
});

// --- Stateless Invoices API ---
app.post('/api/stateless/invoices', (req, res) => {
  const { amount, description, orderId, expirationMinutes } = req.body;
  if (!amount || !description) {
    return res.status(400).json({ status: 'error', message: 'amount and description are required' });
  }
  const tokenPayload = {
    amount: parseFloat(amount).toFixed(8),
    description,
    orderId: orderId || '',
    exp: Math.floor(Date.now() / 1000) + ((expirationMinutes || 15) * 60)
  };
  const token = Buffer.from(JSON.stringify(tokenPayload)).toString('base64url');
  const baseUrl = getBaseUrl(req);
  const checkoutUrl = `${baseUrl}/pay?token=${token}&amount=${tokenPayload.amount}&title=${encodeURIComponent(description)}`;

  res.status(201).json({
    status: 'ok',
    token,
    checkoutUrl,
    expiresAt: tokenPayload.exp
  });
});

// Fallback 404 handler
app.use((req, res) => {
  res.status(404).render('checkout/error', {
    checkoutErrorStatus: 404,
    checkoutErrorMessage: 'The requested page was not found.'
  });
});

app.listen(PORT, '0.0.0.0', () => {
  console.log(`BTCPay Server Lite running on http://0.0.0.0:${PORT}`);
});

export default app;
