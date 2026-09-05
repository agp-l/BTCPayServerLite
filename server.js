const express = require('express');
const path = require('path');
const session = require('cookie-session');
const QRCode = require('qrcode');
const crypto = require('crypto');
const store = require('./src/store');

const app = express();
const PORT = 3000;

// Middleware configuration
app.use(express.urlencoded({ extended: true, limit: '2mb' }));
app.use(express.json({ limit: '2mb' }));

app.use(
  session({
    name: 'btcpay_session',
    keys: [process.env.SESSION_SECRET || 'btcpay-super-secret-key-2026'],
    maxAge: 24 * 60 * 60 * 1000,
    httpOnly: true,
    sameSite: 'lax',
  })
);

// Serve static assets
app.use('/assets', express.static(path.join(__dirname, 'assets')));

// View Engine setup
app.set('view engine', 'ejs');
app.set('views', path.join(__dirname, 'views'));

// Global CSRF helper & view locals
app.use((req, res, next) => {
  if (!req.session.csrfToken) {
    req.session.csrfToken = crypto.randomBytes(24).toString('hex');
  }
  res.locals.csrfToken = req.session.csrfToken;
  res.locals.currentUser = req.session.user || null;
  res.locals.role = req.session.user ? req.session.user.role : null;
  next();
});

// Auth Guard Middlewares
function requireAdmin(req, res, next) {
  if (!req.session.user || req.session.user.role !== 'admin') {
    return res.redirect('/login');
  }
  next();
}

function requireClient(req, res, next) {
  if (!req.session.user) {
    return res.redirect('/login');
  }
  if (req.session.user.role === 'admin') {
    return res.redirect('/admin/dashboard');
  }
  next();
}

// -------------------------------------------------------------
// Root & Authentication Routes
// -------------------------------------------------------------

app.get('/', (req, res) => {
  if (req.session.user) {
    if (req.session.user.role === 'admin') {
      return res.redirect('/admin/dashboard');
    }
    return res.redirect('/client');
  }
  res.redirect('/login');
});

app.get('/login', (req, res) => {
  if (req.session.user) {
    return res.redirect(req.session.user.role === 'admin' ? '/admin/dashboard' : '/client');
  }
  res.render('auth/login', {
    error: req.session.loginError || null,
    success: req.session.loginSuccess || null,
    defaultEmail: req.session.lastLoginEmail || 'admin@btcpay.local',
  });
  delete req.session.loginError;
  delete req.session.loginSuccess;
});

app.post('/login', (req, res) => {
  const { email, password } = req.body;
  const user = store.findUserByEmail(email);

  if (!user || user.password !== password) {
    req.session.loginError = 'Neplatný e-mail nebo heslo.';
    req.session.lastLoginEmail = email;
    return res.redirect('/login');
  }

  if (user.status !== 'active') {
    req.session.loginError = 'Tento účet byl pozastaven. Kontaktujte administrátora.';
    return res.redirect('/login');
  }

  // Update last login
  store.updateUserLastLogin(user.id, req.ip || '127.0.0.1');

  req.session.user = {
    id: user.id,
    email: user.email,
    role: user.role,
  };

  if (user.role === 'admin') {
    res.redirect('/admin/dashboard');
  } else {
    res.redirect('/client');
  }
});

app.get('/logout', (req, res) => {
  req.session = null;
  res.redirect('/login');
});

app.get('/registrace', (req, res) => {
  if (!store.registrationEnabled) {
    req.session.loginError = 'Registrace nových klientů jsou v současnosti zakázány administrátorem.';
    return res.redirect('/login');
  }
  res.render('auth/register', {
    error: req.session.regError || null,
  });
  delete req.session.regError;
});

app.post('/registrace', (req, res) => {
  if (!store.registrationEnabled) {
    req.session.loginError = 'Registrace jsou vypnuty.';
    return res.redirect('/login');
  }

  const { email, password, store_name } = req.body;
  if (!email || !password || !store_name) {
    req.session.regError = 'Vyplňte prosím všechna povinná pole.';
    return res.redirect('/registrace');
  }

  if (store.findUserByEmail(email)) {
    req.session.regError = 'Účet s tímto e-mailem již existuje.';
    return res.redirect('/registrace');
  }

  const user = store.registerClient(email, password, store_name);
  req.session.user = {
    id: user.id,
    email: user.email,
    role: user.role,
  };
  res.redirect('/client');
});

app.get('/forgot-password', (req, res) => {
  res.render('auth/forgot_password', {
    error: null,
    success: req.session.forgotSuccess || null,
  });
  delete req.session.forgotSuccess;
});

app.post('/forgot-password', (req, res) => {
  req.session.forgotSuccess = 'Pokud e-mail existuje, instrukce pro obnovení hesla byly odeslány.';
  res.redirect('/forgot-password');
});

// Password change
app.get(['/admin/account', '/client/account'], (req, res) => {
  if (!req.session.user) return res.redirect('/login');
  res.render('pages/account', {
    userEmail: req.session.user.email,
    role: req.session.user.role,
    backPath: req.session.user.role === 'admin' ? '/admin/dashboard' : '/client',
    error: null,
    success: null,
  });
});

app.post(['/admin/account', '/client/account'], (req, res) => {
  if (!req.session.user) return res.redirect('/login');
  const { current_password, new_password, new_password_confirm } = req.body;
  const user = store.findUserById(req.session.user.id);
  const backPath = req.session.user.role === 'admin' ? '/admin/dashboard' : '/client';

  if (!user || user.password !== current_password) {
    return res.render('pages/account', {
      userEmail: req.session.user.email,
      role: req.session.user.role,
      backPath,
      error: 'Současné heslo není správné.',
      success: null,
    });
  }

  if (new_password !== new_password_confirm) {
    return res.render('pages/account', {
      userEmail: req.session.user.email,
      role: req.session.user.role,
      backPath,
      error: 'Nová hesla se neshodují.',
      success: null,
    });
  }

  user.password = new_password;
  res.render('pages/account', {
    userEmail: req.session.user.email,
    role: req.session.user.role,
    backPath,
    error: null,
    success: 'Heslo bylo úspěšně aktualizováno.',
  });
});

// -------------------------------------------------------------
// Admin Section
// -------------------------------------------------------------

app.get(['/admin', '/admin/dashboard'], requireAdmin, (req, res) => {
  const selectedUserId = req.query.user_id !== undefined && req.query.user_id !== ''
    ? Number(req.query.user_id)
    : null;

  const summary = store.getDashboardSummary(selectedUserId);
  const clients = store.getAllClients();
  const recentInvoices = store.getInvoices({ userId: selectedUserId }).slice(0, 10);
  const recentStores = store.getStores(selectedUserId).slice(0, 8);

  res.render('admin/dashboard', {
    pageTitle: 'Dashboard - BTCPay Lite',
    activeMenu: 'dashboard',
    adminEmail: req.session.user.email,
    summary,
    clients,
    selectedUserId,
    recentInvoices,
    recentStores,
    pageError: null,
  });
});

app.get('/admin/stores', requireAdmin, (req, res) => {
  const selectedUserId = req.query.user_id !== undefined && req.query.user_id !== ''
    ? Number(req.query.user_id)
    : null;

  const stores = store.getStores(selectedUserId);
  const clients = store.getAllClients();

  res.render('admin/stores', {
    pageTitle: 'Obchody - BTCPay Lite',
    activeMenu: 'stores',
    adminEmail: req.session.user.email,
    stores,
    clients,
    selectedUserId,
    pageError: req.session.pageError || null,
    toastMsg: req.session.toastMsg || null,
  });
  delete req.session.pageError;
  delete req.session.toastMsg;
});

app.post('/admin/stores', requireAdmin, (req, res) => {
  const { action, store_id, store_name, user_id } = req.body;

  if (action === 'create') {
    if (!store_name) {
      req.session.pageError = 'Název obchodu je povinný.';
    } else {
      store.createStore(Number(user_id) || 1, store_name);
      req.session.toastMsg = 'Nový obchod byl vytvořen.';
    }
  } else if (action === 'rename') {
    if (store.renameStore(store_id, store_name)) {
      req.session.toastMsg = 'Název obchodu byl upraven.';
    }
  } else if (action === 'regenerate_key') {
    if (store.regenerateApiKey(store_id)) {
      req.session.toastMsg = 'API klíč byl vygenerován.';
    }
  }
  res.redirect('/admin/stores');
});

app.get('/admin/invoices', requireAdmin, (req, res) => {
  const selectedUserId = req.query.user_id !== undefined && req.query.user_id !== ''
    ? Number(req.query.user_id)
    : null;
  const selectedStoreId = req.query.store_id || null;
  const selectedStatus = req.query.status || null;

  const databaseInvoices = store.getInvoices({
    userId: selectedUserId,
    storeId: selectedStoreId,
    status: selectedStatus,
  });
  const clients = store.getAllClients();
  const filterStores = store.getStores();
  const invoicesHistory = databaseInvoices.slice(0, 5);

  res.render('admin/invoices', {
    pageTitle: 'Faktury - BTCPay Lite',
    activeMenu: 'invoices',
    adminEmail: req.session.user.email,
    databaseInvoices,
    clients,
    filterStores,
    selectedUserId,
    selectedStoreId,
    selectedStatus,
    invoicesHistory,
    newInvoiceUrl: req.session.newInvoiceUrl || null,
    pageError: req.session.pageError || null,
    toastMsg: req.session.toastMsg || null,
  });
  delete req.session.newInvoiceUrl;
  delete req.session.pageError;
  delete req.session.toastMsg;
});

app.post('/admin/invoices', requireAdmin, (req, res) => {
  const { action, store_id, amount, description, order_id, invoice_id, status } = req.body;

  if (action === 'create') {
    if (!store_id || !amount || !description) {
      req.session.pageError = 'Vyplňte prosím všechna povinná pole.';
    } else {
      const inv = store.createInvoice({
        store_id,
        amount,
        description,
        order_id,
      });
      req.session.newInvoiceUrl = `/pay?id=${inv.id}`;
      req.session.toastMsg = 'Faktura byla vystavena.';
    }
  } else if (action === 'change_status') {
    store.updateInvoiceStatus(invoice_id, status);
    req.session.toastMsg = `Stav faktury změněn na ${status}.`;
  }
  res.redirect('/admin/invoices');
});

app.get('/admin/wallet', requireAdmin, (req, res) => {
  const currentWalletName = req.query.w || 'default.dat';
  const balance = store.getWalletBalance(currentWalletName);
  const receiveAddress = store.getReceiveAddress(currentWalletName);

  res.render('admin/wallet', {
    pageTitle: 'Peněženka - BTCPay Lite',
    activeMenu: 'wallet',
    adminEmail: req.session.user.email,
    availableWallets: store.getWallets(),
    currentWalletName,
    balanceFormatted: balance.confirmed,
    fiatText: 'ČNB / CoinGecko kurz: ~ 2 150 000 CZK / BTC',
    fiatValueStr: `${(parseFloat(balance.confirmed) * 2150000).toLocaleString('cs-CZ')} CZK`,
    connStatus: 'Electrum daemon online',
    receiveAddress,
    sendResult: req.session.sendResult || null,
    sendSucceeded: req.session.sendSucceeded || false,
    pageError: null,
  });
  delete req.session.sendResult;
  delete req.session.sendSucceeded;
});

app.post('/admin/wallet', requireAdmin, (req, res) => {
  const { action, to, amount } = req.body;
  if (action === 'new_address') {
    req.session.sendResult = 'Vygenerována nová přijímací adresa.';
    req.session.sendSucceeded = true;
  } else if (action === 'send') {
    if (!to || !amount) {
      req.session.sendResult = 'Vyplňte adresu a částku.';
      req.session.sendSucceeded = false;
    } else {
      req.session.sendResult = `Transakce na ${to} (${amount} BTC) byla úspěšně odeslána do sítě. TXID: ${crypto.randomBytes(32).toString('hex')}`;
      req.session.sendSucceeded = true;
    }
  }
  res.redirect('/admin/wallet');
});

app.get('/admin/webhooks', requireAdmin, (req, res) => {
  const selectedUserId = req.query.user_id !== undefined && req.query.user_id !== ''
    ? Number(req.query.user_id)
    : null;
  const selectedStoreId = req.query.store_id || null;

  res.render('admin/webhooks', {
    pageTitle: 'Webhooky - BTCPay Lite',
    activeMenu: 'webhooks',
    adminEmail: req.session.user.email,
    webhooks: store.getWebhooks(selectedStoreId),
    stores: store.getStores(),
    clients: store.getAllClients(),
    filterStores: store.getStores(),
    selectedUserId,
    selectedStoreId,
    pageError: req.session.pageError || null,
    toastMsg: req.session.toastMsg || null,
  });
  delete req.session.pageError;
  delete req.session.toastMsg;
});

app.post('/admin/webhooks', requireAdmin, (req, res) => {
  const { action, store_id, url, webhook_id } = req.body;

  if (action === 'create') {
    if (!store_id || !url) {
      req.session.pageError = 'Vyberte obchod a zadejte HTTPS URL.';
    } else {
      store.createWebhook(store_id, url);
      req.session.toastMsg = 'Webhook byl úspěšně přidán.';
    }
  } else if (action === 'update') {
    store.updateWebhook(webhook_id, url);
    req.session.toastMsg = 'URL webhooku byla upravena.';
  } else if (action === 'regenerate_secret') {
    store.regenerateWebhookSecret(webhook_id);
    req.session.toastMsg = 'Podpisový secret byl obnoven.';
  } else if (action === 'delete') {
    store.deleteWebhook(webhook_id);
    req.session.toastMsg = 'Webhook byl smazán.';
  }
  res.redirect('/admin/webhooks');
});

app.all(['/admin/url_invoices', '/admin/url_invoices.php'], requireAdmin, (req, res) => {
  // Handle JSON API actions requested by url-invoices.js
  if (req.method === 'POST') {
    const apiAction = req.body.api_action;
    if (apiAction === 'create') {
      const { wallet, amount, description, order_id, expiration_minutes } = req.body;
      const invoice = store.createStatelessInvoice({
        wallet: wallet || 'default.dat',
        amount,
        description,
        order_id,
        expirationMinutes: Number(expiration_minutes) || 15,
      });

      const token = invoice.token;
      const invoiceUrl = `${req.protocol}://${req.get('host')}/url_pay?token=${encodeURIComponent(token)}`;

      return res.json({
        status: 'ok',
        token,
        url: invoiceUrl,
        amount: invoice.amount,
        desc: invoice.description,
        order_id: invoice.order_id,
        wallet: invoice.wallet,
        time: invoice.created_at,
      });
    }

    if (apiAction === 'verify') {
      const token = req.body.token;
      const invoice = store.verifyStatelessToken(token);
      if (!invoice) {
        return res.json({ status: 'error', message: 'Kryptografický podpis faktury je neplatný.' });
      }
      return res.json({
        status: 'ok',
        invoice: {
          token,
          url: `${req.protocol}://${req.get('host')}/url_pay?token=${encodeURIComponent(token)}`,
          amount: invoice.amount,
          desc: invoice.description,
          order_id: invoice.order_id,
          wallet: invoice.wallet,
          time: invoice.created_at,
          expires_at: invoice.expires_at,
          address: invoice.address,
        },
      });
    }

    if (apiAction === 'status') {
      const token = req.body.token;
      const invoice = store.verifyStatelessToken(token);
      if (!invoice) {
        return res.json({ status: 'error', message: 'Neplatný token faktury.' });
      }
      const now = Math.floor(Date.now() / 1000);
      const isExpired = now > invoice.expires_at;
      return res.json({
        status: 'ok',
        invoiceStatus: isExpired ? 'expired' : 'unpaid',
      });
    }
  }

  res.render('admin/url_invoices', {
    pageTitle: 'Stateless URL faktury - BTCPay Lite',
    activeMenu: 'url_invoices',
    adminEmail: req.session.user.email,
    availableWallets: store.getWallets(),
    defaultWallet: 'default.dat',
  });
});

app.get('/admin/users', requireAdmin, (req, res) => {
  const clients = store.getAllClients();
  const selectedUserId = req.query.user_id ? Number(req.query.user_id) : null;
  const detail = selectedUserId ? store.getUserDetail(selectedUserId) : null;

  res.render('admin/users', {
    pageTitle: 'Správa klientů - BTCPay Lite',
    activeMenu: 'users',
    adminEmail: req.session.user.email,
    clients,
    detail,
    pageError: req.session.pageError || null,
    toastMsg: req.session.toastMsg || null,
  });
  delete req.session.pageError;
  delete req.session.toastMsg;
});

app.post('/admin/users', requireAdmin, (req, res) => {
  const { action, user_id, status } = req.body;
  const user = store.findUserById(Number(user_id));

  if (action === 'set_status' && user) {
    user.status = status;
    req.session.toastMsg = `Účet byl změněn na ${status}.`;
  }
  res.redirect(`/admin/users?user_id=${user_id}`);
});

app.get('/admin/settings', requireAdmin, (req, res) => {
  res.render('admin/settings', {
    pageTitle: 'Nastavení - BTCPay Lite',
    activeMenu: 'settings',
    adminEmail: req.session.user.email,
    registrationEnabled: store.registrationEnabled,
    config: store.config,
    pageError: null,
    toastMsg: req.session.toastMsg || null,
  });
  delete req.session.toastMsg;
});

app.post('/admin/settings', requireAdmin, (req, res) => {
  const { action, registration_enabled } = req.body;
  if (action === 'set_registration') {
    store.registrationEnabled = registration_enabled === '1';
    req.session.toastMsg = 'Nastavení registrací uloženo.';
  }
  res.redirect('/admin/settings');
});

// -------------------------------------------------------------
// Client Merchant Portal
// -------------------------------------------------------------

app.all(['/client', '/client/index.php'], requireClient, (req, res) => {
  const userId = req.session.user.id;
  const clientSection = req.query.section || 'overview';
  const selectedStoreId = req.query.store_id || '';
  const selectedInvoiceStatus = req.query.status || '';

  if (req.method === 'POST') {
    const { action, store_name } = req.body;
    if (action === 'create_store') {
      if (store_name) {
        store.createStore(userId, store_name);
        req.session.toastMsg = 'Nový obchod byl vytvořen.';
      }
      return res.redirect('/client?section=stores');
    }
  }

  const stores = store.getStores(userId);
  const invoices = store.getInvoices({
    userId,
    storeId: selectedStoreId || null,
    status: selectedInvoiceStatus || null,
  });
  const webhooks = store.getWebhooksForClient(userId);
  const payouts = store.getPayouts(userId);
  const activities = store.getActivityLogs(userId);
  const walletBalance = store.getWalletBalance('merchant_wallet.dat');

  const clientStats = {
    total_stores: stores.length,
    total_invoices: invoices.length,
    paid_invoices: invoices.filter((i) => i.status === 'Settled').length,
  };

  const titles = {
    overview: 'Přehled platebního účtu',
    stores: 'Moje obchody a API klíče',
    invoices: 'Faktury a platby',
    webhooks: 'Správa webhooků',
    payouts: 'Výběry na on-chain adresu',
    activity: 'Auditní záznamy API',
  };

  res.render('client/index', {
    pageTitle: `${titles[clientSection] || 'Klientský portál'} - BTCPay Lite`,
    activeMenu: 'client',
    clientEmail: req.session.user.email,
    clientSection,
    sectionTitle: titles[clientSection] || 'Klientský portál',
    stores,
    invoices,
    webhooks,
    payouts,
    activities,
    clientStats,
    walletBalance,
    selectedStoreId,
    selectedInvoiceStatus,
    pageError: null,
    toastMsg: req.session.toastMsg || null,
  });
  delete req.session.toastMsg;
});

// -------------------------------------------------------------
// Checkout Interfaces (Database & Stateless)
// -------------------------------------------------------------

// Database invoice checkout
app.get(['/pay', '/checkout/pay.php'], async (req, res) => {
  const invoiceId = req.query.id;
  if (!invoiceId) {
    return res.status(400).render('checkout/error', {
      checkoutErrorStatus: 400,
      checkoutErrorMessage: 'Chybí identifikátor faktury (id).',
    });
  }

  const invoice = store.findInvoiceById(invoiceId);
  if (!invoice) {
    return res.status(404).render('checkout/error', {
      checkoutErrorStatus: 404,
      checkoutErrorMessage: 'Tato platební faktura nebyla nalezena.',
    });
  }

  const now = Math.floor(Date.now() / 1000);
  const secondsRemaining = Math.max(0, invoice.expires_at - now);
  const bip21Uri = `bitcoin:${invoice.address}?amount=${invoice.amount}&label=${encodeURIComponent(invoice.description)}`;

  let qrCodeDataUri = '';
  try {
    qrCodeDataUri = await QRCode.toDataURL(bip21Uri, {
      margin: 1,
      width: 250,
      color: {
        dark: '#1e293b',
        light: '#ffffff',
      },
    });
  } catch (e) {
    console.error('QR generation error:', e);
  }

  const statusLabels = {
    New: 'Čekáme na platbu',
    Processing: 'Platba přijata, čekáme na potvrzení',
    Settled: 'Platba potvrzena',
    Expired: 'Platnost faktury vypršela',
  };

  res.render('checkout/pay', {
    checkout: {
      id: invoice.id,
      title: invoice.description,
      status: invoice.status,
      amount: invoice.amount,
      missing_amount: '0.00000000',
      address: invoice.address,
      bip21_uri: bip21Uri,
      seconds_remaining: secondsRemaining,
      redirect_url: '',
      redirect_automatically: false,
    },
    statusLabel: statusLabels[invoice.status] || 'Čekáme na platbu',
    statusUrl: `/api/invoices/${invoice.id}/status`,
    isSettled: invoice.status === 'Settled',
    isExpired: invoice.status === 'Expired' || secondsRemaining <= 0,
    isPartial: false,
    qrCodeDataUri,
  });
});

// Stateless URL invoice checkout
app.get(['/url_pay', '/admin/url_pay.php'], async (req, res) => {
  const token = req.query.token;
  if (!token) {
    return res.status(400).render('checkout/error', {
      checkoutErrorStatus: 400,
      checkoutErrorMessage: 'Chybí podepsaný token faktury.',
    });
  }

  const invoice = store.verifyStatelessToken(token);
  if (!invoice) {
    return res.status(400).render('checkout/error', {
      checkoutErrorStatus: 400,
      checkoutErrorMessage: 'Platnost nebo podpis této faktury jsou neplatné.',
    });
  }

  const now = Math.floor(Date.now() / 1000);
  const secondsRemaining = Math.max(0, invoice.expires_at - now);
  const isExpired = secondsRemaining <= 0;
  const bip21Uri = `bitcoin:${invoice.address}?amount=${invoice.amount}&label=${encodeURIComponent(invoice.description)}`;

  let qrCodeDataUri = '';
  try {
    qrCodeDataUri = await QRCode.toDataURL(bip21Uri, {
      margin: 1,
      width: 250,
      color: {
        dark: '#1e293b',
        light: '#ffffff',
      },
    });
  } catch (e) {
    console.error('QR generation error:', e);
  }

  const status = isExpired ? 'expired' : 'unpaid';
  const statusLabels = {
    unpaid: 'Čeká na platbu',
    underpaid: 'Částečná platba',
    pending_mempool: 'Platba zachycena v síti',
    paid: 'Zaplaceno',
    expired: 'Platnost vypršela',
  };

  res.render('checkout/stateless_pay', {
    checkout: {
      description: invoice.description,
      order_id: invoice.order_id,
      amount: invoice.amount,
      address: invoice.address,
      status,
      seconds_remaining: secondsRemaining,
      expires_at: invoice.expires_at,
      bip21_uri: bip21Uri,
      qr_code_data_uri: qrCodeDataUri,
    },
    statusLabel: statusLabels[status],
    isTerminal: isExpired,
    statusUrl: `/api/stateless/invoices/status?token=${encodeURIComponent(token)}`,
  });
});

// -------------------------------------------------------------
// API Endpoints
// -------------------------------------------------------------

// Polled by database checkout (checkout.js)
app.get(['/api/invoices/:id/status', '/api.php'], (req, res) => {
  const id = req.params.id || req.query.id;
  if (!id) {
    return res.status(400).json({ error: 'Invoice ID is required' });
  }

  const invoice = store.findInvoiceById(id);
  if (!invoice) {
    return res.status(404).json({ error: 'Invoice not found' });
  }

  const now = Math.floor(Date.now() / 1000);
  const secondsRemaining = Math.max(0, invoice.expires_at - now);

  res.json({
    status: invoice.status,
    additional_status: 'None',
    missing_amount: '0.00000000',
    seconds_remaining: secondsRemaining,
    redirect_url: '',
    redirect_automatically: false,
  });
});

// Polled by stateless checkout (stateless-checkout.js)
app.get(['/api/stateless/invoices/status', '/api_stateless.php'], (req, res) => {
  const token = req.query.token;
  if (!token) {
    return res.status(400).json({ error: 'Token is required' });
  }

  const invoice = store.verifyStatelessToken(token);
  if (!invoice) {
    return res.status(400).json({ error: 'Invalid invoice signature' });
  }

  const now = Math.floor(Date.now() / 1000);
  const secondsRemaining = Math.max(0, invoice.expires_at - now);
  const status = secondsRemaining <= 0 ? 'expired' : 'unpaid';

  res.json({
    status,
    seconds_remaining: secondsRemaining,
  });
});

// Stateless API invoice creation (POST /api_stateless.php or /api/stateless/invoices)
app.post(['/api/stateless/invoices', '/api_stateless.php'], (req, res) => {
  const authHeader = req.headers['authorization'] || '';
  let bearerToken = '';
  if (authHeader.startsWith('Bearer ')) {
    bearerToken = authHeader.substring(7).trim();
  }

  const apiClients = (store.config && store.config.api_clients) || {};
  let walletName = 'default_wallet_1';

  // Check auth if provided or required
  if (bearerToken) {
    if (apiClients[bearerToken]) {
      walletName = apiClients[bearerToken];
    } else if (bearerToken !== store.config.admin_api_key) {
      return res.status(401).json({ status: 'error', message: 'Invalid API key or unknown API client.' });
    }
  }

  const { amount, description, desc, order_id, expiration_minutes } = req.body;
  if (!amount) {
    return res.status(400).json({ status: 'error', message: 'Missing amount field.' });
  }

  const invoice = store.createStatelessInvoice({
    wallet: req.body.wallet || walletName,
    amount: String(amount),
    description: description || desc || 'Stateless invoice',
    order_id: order_id || '',
    expirationMinutes: Number(expiration_minutes) || 15,
  });

  const paymentUrl = `${req.protocol}://${req.get('host')}/url_pay?token=${encodeURIComponent(invoice.token)}`;

  res.status(200).json({
    status: 'success',
    data: {
      token: invoice.token,
      url: paymentUrl,
      amount: invoice.amount,
      description: invoice.description,
      order_id: invoice.order_id,
      address: invoice.address,
      wallet: invoice.wallet,
      expires_at: invoice.expires_at,
      created_at: invoice.created_at,
    },
    // Backwards compatibility convenience properties
    token: invoice.token,
    url: paymentUrl,
  });
});

// Greenfield API compatible invoice creation
app.post(['/api/v1/stores/:storeId/invoices', '/api/invoices'], (req, res) => {
  const storeId = req.params.storeId || req.body.store_id;
  const { amount, currency, description, metadata } = req.body;

  const inv = store.createInvoice({
    store_id: storeId,
    amount: amount || '0.00100000',
    description: description || (metadata && metadata.itemDesc) || 'API invoice',
    order_id: metadata && metadata.orderId,
  });

  res.status(201).json({
    id: inv.id,
    storeId: inv.store_id,
    amount: inv.amount,
    currency: currency || 'BTC',
    status: inv.status,
    createdTime: inv.created_at,
    expirationTime: inv.expires_at,
    checkoutLink: `${req.protocol}://${req.get('host')}/pay?id=${inv.id}`,
  });
});

// Fallback 404 handler
app.use((req, res) => {
  res.status(404).send(`
    <!doctype html>
    <html lang="cs">
    <head><meta charset="utf-8"><title>Stránka nenalezena</title><link rel="stylesheet" href="/assets/auth.css"></head>
    <body class="auth-page"><main class="auth-shell">
      <section class="auth-card" style="text-align: center;">
        <h1>404</h1>
        <p>Požadovaná stránka nebyla nalezena.</p>
        <div style="margin-top: 1.5rem;"><a class="auth-submit" href="/">Zpět na úvod</a></div>
      </section>
    </main></body>
    </html>
  `);
});

// Start Server
app.listen(PORT, '0.0.0.0', () => {
  console.log(`BTCPay Lite server running on http://0.0.0.0:${PORT}`);
});
