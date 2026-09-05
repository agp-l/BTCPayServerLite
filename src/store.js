const crypto = require('crypto');
const config = require('./config');

// In-memory data store replicating BTCPay Server Lite database & Electrum daemon
class DataStore {
  constructor() {
    this.config = config;
    this.secretKey = config.secret_key || 'MujVelmiTajnySifrovaciKlic_2026_Brno';
    this.adminApiKey = config.admin_api_key || 'master_klic_admin_777';
    this.reset();
  }

  reset() {
    this.settings = {
      registrationEnabled: true,
      appName: 'BTCPay Server Lite',
      appUrl: this.config.app_url || 'http://localhost:3000',
      electrumHost: this.config.rpc_host || '127.0.0.1',
      electrumPort: this.config.rpc_port || 7777,
      network: 'mainnet'
    };

    this.users = [
      {
        id: 1,
        email: 'admin@btcpay.local',
        password: 'admin', // in-memory demo password
        role: 'admin',
        status: 'active',
        created_at: Math.floor(Date.now() / 1000) - 86400 * 30,
        last_login_at: Math.floor(Date.now() / 1000) - 3600,
        last_login_ip: '127.0.0.1',
        wallet_path: 'wallets/admin_default.dat'
      },
      {
        id: 2,
        email: 'merchant@shop.cz',
        password: 'password123',
        role: 'client',
        status: 'active',
        created_at: Math.floor(Date.now() / 1000) - 86400 * 15,
        last_login_at: Math.floor(Date.now() / 1000) - 7200,
        last_login_ip: '89.177.42.10',
        wallet_path: 'wallets/client_merchant.dat'
      },
      {
        id: 3,
        email: 'ondrej@kryptoobchod.cz',
        password: 'password123',
        role: 'client',
        status: 'active',
        created_at: Math.floor(Date.now() / 1000) - 86400 * 7,
        last_login_at: Math.floor(Date.now() / 1000) - 18000,
        last_login_ip: '194.228.32.5',
        wallet_path: 'wallets/client_ondrej.dat'
      }
    ];

    this.stores = [
      {
        id: 'STR-8821-X9A',
        name: 'Knihkupectví Satoshi',
        user_id: 2,
        api_key: 'btcpay_' + crypto.randomBytes(16).toString('hex'),
        wallet_path: 'wallets/client_merchant.dat',
        created_at: Math.floor(Date.now() / 1000) - 86400 * 14
      },
      {
        id: 'STR-4412-B2C',
        name: 'Pražská Pražírna Kávy',
        user_id: 2,
        api_key: 'btcpay_' + crypto.randomBytes(16).toString('hex'),
        wallet_path: 'wallets/client_merchant.dat',
        created_at: Math.floor(Date.now() / 1000) - 86400 * 10
      },
      {
        id: 'STR-9930-Q5D',
        name: 'Hardware & Gadgets e-shop',
        user_id: 3,
        api_key: 'btcpay_' + crypto.randomBytes(16).toString('hex'),
        wallet_path: 'wallets/client_ondrej.dat',
        created_at: Math.floor(Date.now() / 1000) - 86400 * 6
      },
      {
        id: 'STR-0001-SYS',
        name: 'Systémový obchod darů',
        user_id: 1,
        api_key: 'btcpay_' + crypto.randomBytes(16).toString('hex'),
        wallet_path: 'wallets/admin_default.dat',
        created_at: Math.floor(Date.now() / 1000) - 86400 * 30
      }
    ];

    const now = Math.floor(Date.now() / 1000);
    this.invoices = [
      {
        id: 'INV-2026-084',
        store_id: 'STR-8821-X9A',
        user_id: 2,
        amount: '0.00042000',
        currency: 'BTC',
        fiat_amount: '1990',
        fiat_currency: 'CZK',
        description: 'Objednávka knihy Standard Bitcoinu',
        order_id: 'ORD-2026-084',
        status: 'New',
        additional_status: '',
        btc_paid: '0.00000000',
        btc_due: '0.00042000',
        address: 'bc1q8x7k2m9v4pj3m0zlk8wypn3f5e9y3p3n6r0a',
        bip21_uri: 'bitcoin:bc1q8x7k2m9v4pj3m0zlk8wypn3f5e9y3p3n6r0a?amount=0.00042000&label=Objednavka%20ORD-2026-084',
        redirect_url: 'https://example.com/order-confirmed',
        redirect_automatically: false,
        created_at: now - 300,
        expires_at: now + 3300
      },
      {
        id: 'INV-2026-083',
        store_id: 'STR-8821-X9A',
        user_id: 2,
        amount: '0.00125000',
        currency: 'BTC',
        fiat_amount: '5900',
        fiat_currency: 'CZK',
        description: 'Hardware peněženka Trezor Safe 3',
        order_id: 'ORD-2026-083',
        status: 'Settled',
        additional_status: '',
        btc_paid: '0.00125000',
        btc_due: '0.00000000',
        address: 'bc1qlw5d7w8v9u2x0q3n6m8v4p1k7y5t9z2r4a6b8',
        bip21_uri: 'bitcoin:bc1qlw5d7w8v9u2x0q3n6m8v4p1k7y5t9z2r4a6b8?amount=0.00125000',
        redirect_url: '',
        redirect_automatically: false,
        created_at: now - 3600 * 4,
        expires_at: now - 3600 * 3
      },
      {
        id: 'INV-2026-082',
        store_id: 'STR-4412-B2C',
        user_id: 2,
        amount: '0.00018500',
        currency: 'BTC',
        fiat_amount: '850',
        fiat_currency: 'CZK',
        description: 'Výběrová káva Etiopie Yirgacheffe 1kg',
        order_id: 'KAVA-441',
        status: 'Processing',
        additional_status: '',
        btc_paid: '0.00018500',
        btc_due: '0.00000000',
        address: 'bc1q0m8v4p1k7y5t9z2r4a6b8c1qlw5d7w8v9u2x0',
        bip21_uri: 'bitcoin:bc1q0m8v4p1k7y5t9z2r4a6b8c1qlw5d7w8v9u2x0?amount=0.00018500',
        redirect_url: '',
        redirect_automatically: false,
        created_at: now - 1800,
        expires_at: now + 1800
      },
      {
        id: 'INV-2026-081',
        store_id: 'STR-9930-Q5D',
        user_id: 3,
        amount: '0.00550000',
        currency: 'BTC',
        fiat_amount: '26000',
        fiat_currency: 'CZK',
        description: 'Coldcard Mk4 + bezpečnostní pouzdro',
        order_id: 'ORD-HW-991',
        status: 'Settled',
        additional_status: '',
        btc_paid: '0.00550000',
        btc_due: '0.00000000',
        address: 'bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4',
        bip21_uri: 'bitcoin:bc1qw508d6qejxtdg4y5r3zarvary0c5xw7kv8f3t4?amount=0.00550000',
        redirect_url: '',
        redirect_automatically: false,
        created_at: now - 86400 * 2,
        expires_at: now - 86400 * 2 + 3600
      },
      {
        id: 'INV-2026-080',
        store_id: 'STR-4412-B2C',
        user_id: 2,
        amount: '0.00009500',
        currency: 'BTC',
        fiat_amount: '450',
        fiat_currency: 'CZK',
        description: 'Aeropress filtr a příslušenství',
        order_id: 'KAVA-429',
        status: 'Expired',
        additional_status: '',
        btc_paid: '0.00000000',
        btc_due: '0.00009500',
        address: 'bc1q9w7e5r3t1y8u2i4o6p0a9s8d7f6g5h4j3k2l1',
        bip21_uri: 'bitcoin:bc1q9w7e5r3t1y8u2i4o6p0a9s8d7f6g5h4j3k2l1?amount=0.00009500',
        redirect_url: '',
        redirect_automatically: false,
        created_at: now - 86400 * 3,
        expires_at: now - 86400 * 3 + 3600
      }
    ];

    this.webhooks = [
      {
        id: 'WH-8821-01',
        store_id: 'STR-8821-X9A',
        user_id: 2,
        url: 'https://shop.satoshi.cz/api/btcpay-webhook',
        secret: 'whsec_' + crypto.randomBytes(24).toString('hex'),
        created_at: now - 86400 * 10,
        last_delivery_at: now - 3600 * 4,
        last_status: 200
      },
      {
        id: 'WH-4412-01',
        store_id: 'STR-4412-B2C',
        user_id: 2,
        url: 'https://kavarna.cz/webhooks/btcpay',
        secret: 'whsec_' + crypto.randomBytes(24).toString('hex'),
        created_at: now - 86400 * 5,
        last_delivery_at: now - 1800,
        last_status: 200
      }
    ];

    this.payouts = [
      {
        id: 'PO-01',
        store_id: 'STR-8821-X9A',
        user_id: 2,
        amount: '0.01000000',
        destination: 'bc1qgdjqv0av3q56jvd82tkdjpy7gdp9ut8tlqmgrpmv24sq90ecnvqqjwvw97',
        status: 'Completed',
        txid: 'd6b1e5a8f9c2d3e4b5a6c7d8e9f0a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8',
        created_at: now - 86400 * 4
      }
    ];

    this.activityLogs = [
      {
        id: 1,
        store_id: 'STR-8821-X9A',
        user_id: 2,
        endpoint: '/api/v1/stores/STR-8821-X9A/invoices',
        method: 'POST',
        plugin: 'WooCommerce BTCPay Plugin v2.1',
        ip: '89.177.42.10',
        status: 200,
        time: now - 300
      },
      {
        id: 2,
        store_id: 'STR-4412-B2C',
        user_id: 2,
        endpoint: '/api/v1/stores/STR-4412-B2C/invoices/INV-2026-082',
        method: 'GET',
        plugin: 'Custom POS Terminal',
        ip: '194.228.32.5',
        status: 200,
        time: now - 1800
      },
      {
        id: 3,
        store_id: 'STR-8821-X9A',
        user_id: 2,
        endpoint: '/api/v1/stores/STR-8821-X9A/invoices/INV-2026-083',
        method: 'GET',
        plugin: 'WooCommerce BTCPay Plugin v2.1',
        ip: '89.177.42.10',
        status: 200,
        time: now - 3600 * 4
      }
    ];

    this.wallet = {
      name: 'wallets/default.dat',
      balance_confirmed: '1.45892040',
      balance_unconfirmed: '0.00018500',
      receive_address: 'bc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh',
      connection_status: `Electrum RPC daemon online (${this.config.rpc_host}:${this.config.rpc_port})`,
      fiat_rate_czk: 1542000,
      available_wallets: ['default_wallet_1', 'wallet_1', 'default.dat', 'wallets/admin_default.dat', 'wallets/client_merchant.dat', 'wallets/client_ondrej.dat']
    };
  }

  // User methods
  findUserByEmail(email) {
    return this.users.find(u => u.email.toLowerCase() === email.toLowerCase());
  }

  findUserById(id) {
    return this.users.find(u => u.id === Number(id));
  }

  createUser(email, password, role = 'client') {
    const id = this.users.length > 0 ? Math.max(...this.users.map(u => u.id)) + 1 : 1;
    const user = {
      id,
      email,
      password,
      role,
      status: 'active',
      created_at: Math.floor(Date.now() / 1000),
      last_login_at: null,
      last_login_ip: null,
      wallet_path: `wallets/client_${id}.dat`
    };
    this.users.push(user);
    return user;
  }

  // Store methods
  getStores(userId = null) {
    return this.stores.filter(s => userId === null || s.user_id === Number(userId)).map(store => {
      const client = this.findUserById(store.user_id);
      const invoiceCount = this.invoices.filter(i => i.store_id === store.id).length;
      const webhookCount = this.webhooks.filter(w => w.store_id === store.id).length;
      const payoutCount = this.payouts.filter(p => p.store_id === store.id).length;
      return {
        ...store,
        client_email: client ? client.email : 'Systém / bez klienta',
        invoice_count: invoiceCount,
        webhook_count: webhookCount,
        payout_count: payoutCount
      };
    });
  }

  getStoreById(id) {
    return this.stores.find(s => s.id === id);
  }

  createStore(name, userId) {
    const id = 'STR-' + Math.floor(1000 + Math.random() * 9000) + '-' + crypto.randomBytes(2).toString('hex').toUpperCase();
    const user = this.findUserById(userId);
    const store = {
      id,
      name,
      user_id: Number(userId),
      api_key: 'btcpay_' + crypto.randomBytes(16).toString('hex'),
      wallet_path: user ? user.wallet_path : 'wallets/default.dat',
      created_at: Math.floor(Date.now() / 1000)
    };
    this.stores.push(store);
    return store;
  }

  renameStore(id, newName) {
    const store = this.getStoreById(id);
    if (store) {
      store.name = newName;
      return true;
    }
    return false;
  }

  rotateStoreApiKey(id) {
    const store = this.getStoreById(id);
    if (store) {
      store.api_key = 'btcpay_' + crypto.randomBytes(16).toString('hex');
      return store.api_key;
    }
    return null;
  }

  // Invoice methods
  getInvoices(filters = {}) {
    const opts = filters || {};
    let result = [...this.invoices];
    if (opts.user_id !== undefined && opts.user_id !== null && opts.user_id !== '') {
      result = result.filter(i => i.user_id === Number(opts.user_id));
    }
    if (opts.store_id) {
      result = result.filter(i => i.store_id === opts.store_id);
    }
    if (opts.status) {
      result = result.filter(i => i.status === opts.status);
    }
    return result.sort((a, b) => b.created_at - a.created_at).map(inv => {
      const store = this.getStoreById(inv.store_id);
      const client = this.findUserById(inv.user_id);
      return {
        ...inv,
        store_name: store ? store.name : 'Neznámý obchod',
        client_email: client ? client.email : 'Systém / bez klienta'
      };
    });
  }

  getInvoiceById(id) {
    const inv = this.invoices.find(i => i.id === id);
    if (!inv) return null;
    const store = this.getStoreById(inv.store_id);
    const client = this.findUserById(inv.user_id);
    return {
      ...inv,
      store_name: store ? store.name : 'Neznámý obchod',
      client_email: client ? client.email : 'Systém / bez klienta'
    };
  }

  createInvoice({ store_id, amount, description, order_id = '', redirect_url = '' }) {
    const store = this.getStoreById(store_id);
    if (!store) throw new Error('Obchod nebyl nalezen.');

    const id = 'INV-' + new Date().getFullYear() + '-' + Math.floor(100 + Math.random() * 900);
    const now = Math.floor(Date.now() / 1000);
    const address = 'bc1q' + crypto.randomBytes(18).toString('hex');
    const bip21_uri = `bitcoin:${address}?amount=${amount}` + (order_id ? `&label=Order%20${encodeURIComponent(order_id)}` : '');

    const inv = {
      id,
      store_id: store.id,
      user_id: store.user_id,
      amount: String(amount),
      currency: 'BTC',
      fiat_amount: String(Math.round(parseFloat(amount) * this.wallet.fiat_rate_czk)),
      fiat_currency: 'CZK',
      description: description || `Platba faktury ${id}`,
      order_id: order_id || id,
      status: 'New',
      additional_status: '',
      btc_paid: '0.00000000',
      btc_due: String(amount),
      address,
      bip21_uri,
      redirect_url: redirect_url || '',
      redirect_automatically: false,
      created_at: now,
      expires_at: now + 3600
    };

    this.invoices.unshift(inv);
    return inv;
  }

  updateInvoiceStatus(id, newStatus) {
    const inv = this.invoices.find(i => i.id === id);
    if (!inv) return false;
    inv.status = newStatus;
    if (newStatus === 'Settled') {
      inv.btc_paid = inv.amount;
      inv.btc_due = '0.00000000';
    }
    return true;
  }

  // Webhook methods
  getWebhooks(filters = {}) {
    const opts = typeof filters === 'string' ? { store_id: filters } : (filters || {});
    let result = [...this.webhooks];
    if (opts.user_id !== undefined && opts.user_id !== null && opts.user_id !== '') {
      result = result.filter(w => w.user_id === Number(opts.user_id));
    }
    if (opts.store_id) {
      result = result.filter(w => w.store_id === opts.store_id);
    }
    return result.map(w => {
      const store = this.getStoreById(w.store_id);
      const client = this.findUserById(w.user_id);
      return {
        ...w,
        store_name: store ? store.name : 'Neznámý obchod',
        client_email: client ? client.email : 'Systém / bez klienta'
      };
    });
  }

  createWebhook(store_id, url) {
    const store = this.getStoreById(store_id);
    if (!store) throw new Error('Obchod nebyl nalezen.');
    const id = 'WH-' + Math.floor(1000 + Math.random() * 9000) + '-01';
    const wh = {
      id,
      store_id,
      user_id: store.user_id,
      url,
      secret: 'whsec_' + crypto.randomBytes(24).toString('hex'),
      created_at: Math.floor(Date.now() / 1000),
      last_delivery_at: null,
      last_status: null
    };
    this.webhooks.push(wh);
    return wh;
  }

  updateWebhookUrl(id, url) {
    const wh = this.webhooks.find(w => w.id === id);
    if (wh) {
      wh.url = url;
      return true;
    }
    return false;
  }

  rotateWebhookSecret(id) {
    const wh = this.webhooks.find(w => w.id === id);
    if (wh) {
      wh.secret = 'whsec_' + crypto.randomBytes(24).toString('hex');
      return wh.secret;
    }
    return null;
  }

  deleteWebhook(id) {
    const idx = this.webhooks.findIndex(w => w.id === id);
    if (idx !== -1) {
      this.webhooks.splice(idx, 1);
      return true;
    }
    return false;
  }

  // Dashboard calculations
  getDashboardSummary(userId = null) {
    const userStores = this.getStores(userId);
    const storeIds = new Set(userStores.map(s => s.id));
    const userInvoices = this.invoices.filter(i => storeIds.has(i.store_id));

    const totalInvoices = userInvoices.length;
    const settledInvoices = userInvoices.filter(i => i.status === 'Settled').length;
    const settlementRate = totalInvoices > 0 ? ((settledInvoices / totalInvoices) * 100).toFixed(1) : '0.0';

    let totalVolumeBtc = 0;
    for (const inv of userInvoices) {
      if (inv.status === 'Settled') {
        totalVolumeBtc += parseFloat(inv.amount) || 0;
      }
    }

    return {
      total_stores: userStores.length,
      total_invoices: totalInvoices,
      settled_invoices: settledInvoices,
      settlement_rate: settlementRate,
      total_btc_volume: totalVolumeBtc.toFixed(8)
    };
  }

  // Client user management
  getClientsList() {
    return this.users.filter(u => u.role === 'client').map(client => {
      const clientStores = this.stores.filter(s => s.user_id === client.id);
      const clientStoreIds = new Set(clientStores.map(s => s.id));
      const clientInvoices = this.invoices.filter(i => clientStoreIds.has(i.store_id));
      const lastInvoice = clientInvoices.length > 0 ? Math.max(...clientInvoices.map(i => i.created_at)) : null;
      const clientPayouts = this.payouts.filter(p => p.user_id === client.id);
      const clientRequests = this.activityLogs.filter(a => a.user_id === client.id);
      const lastRequest = clientRequests.length > 0 ? Math.max(...clientRequests.map(r => r.time)) : null;

      return {
        ...client,
        store_count: clientStores.length,
        invoice_count: clientInvoices.length,
        last_invoice_at: lastInvoice,
        payout_count: clientPayouts.length,
        request_count: clientRequests.length,
        last_request_at: lastRequest,
        wallet_count: 1
      };
    });
  }

  getClientDetail(userId) {
    const client = this.findUserById(userId);
    if (!client) return null;
    const clientStores = this.stores.filter(s => s.user_id === client.id);
    const clientStoreIds = new Set(clientStores.map(s => s.id));
    const clientInvoices = this.invoices.filter(i => clientStoreIds.has(i.store_id));
    const clientWebhooks = this.webhooks.filter(w => clientStoreIds.has(w.store_id));

    return {
      client,
      stores: clientStores,
      invoices: clientInvoices,
      webhook_count: clientWebhooks.length,
      integration_count: clientStores.length,
      wallet_balance: {
        confirmed: '0.04250000',
        unconfirmed: '0.00000000'
      },
      wallet_error: null
    };
  }

  setClientStatus(userId, status) {
    const user = this.findUserById(userId);
    if (user) {
      user.status = status;
      return true;
    }
    return false;
  }

  // Aliases & Additional Helpers for server.js
  findInvoiceById(id) {
    return this.getInvoiceById(id);
  }

  getAllClients() {
    return this.getClientsList();
  }

  getUserDetail(userId) {
    return this.getClientDetail(userId);
  }

  regenerateApiKey(storeId) {
    return this.rotateStoreApiKey(storeId);
  }

  updateWebhook(id, url) {
    return this.updateWebhookUrl(id, url);
  }

  regenerateWebhookSecret(id) {
    return this.rotateWebhookSecret(id);
  }

  getWebhooksForClient(userId) {
    return this.getWebhooks({ user_id: userId });
  }

  getPayouts(userId = null) {
    if (userId === null) return this.payouts;
    return this.payouts.filter(p => p.user_id === Number(userId));
  }

  getActivityLogs(userId = null) {
    if (userId === null) return this.activityLogs;
    return this.activityLogs.filter(a => a.user_id === Number(userId));
  }

  getWallets() {
    return this.wallet.available_wallets || ['default.dat', 'merchant_wallet.dat'];
  }

  getWalletBalance(walletName) {
    return {
      confirmed: this.wallet.balance_confirmed || '1.45892040',
      unconfirmed: this.wallet.balance_unconfirmed || '0.00018500',
    };
  }

  getReceiveAddress(walletName) {
    return this.wallet.receive_address || 'bc1qxy2kgdygjrsqtzq2n0yrf2493p83kkfjhx0wlh';
  }

  updateUserLastLogin(userId, ip) {
    const user = this.findUserById(userId);
    if (user) {
      user.last_login_at = Math.floor(Date.now() / 1000);
      user.last_login_ip = ip;
    }
  }

  registerClient(email, password, storeName) {
    const user = this.createUser(email, password, 'client');
    this.createStore(storeName, user.id);
    return user;
  }

  // Stateless invoice creation and verification
  createStatelessInvoice({ wallet, amount, description, order_id = '', expirationMinutes = 15 }) {
    const now = Math.floor(Date.now() / 1000);
    const expires_at = now + (Number(expirationMinutes) * 60);
    const address = 'bc1q' + crypto.randomBytes(18).toString('hex');

    const payload = {
      wallet: wallet || 'default.dat',
      amount: String(amount),
      description: description || 'Bez popisu',
      order_id: String(order_id || ''),
      address,
      created_at: now,
      expires_at
    };

    const payloadString = JSON.stringify(payload);
    const hmac = crypto.createHmac('sha256', this.secretKey || 'stateless-default-secret');
    hmac.update(payloadString);
    const sig = hmac.digest('hex');

    const token = Buffer.from(payloadString).toString('base64url') + '.' + sig;
    return {
      ...payload,
      token
    };
  }

  verifyStatelessToken(token) {
    if (!token || typeof token !== 'string') return null;
    const parts = token.split('.');
    if (parts.length !== 2) return null;

    const [b64Payload, signature] = parts;
    try {
      const payloadString = Buffer.from(b64Payload, 'base64url').toString('utf8');
      const hmac = crypto.createHmac('sha256', this.secretKey || 'stateless-default-secret');
      hmac.update(payloadString);
      const expectedSig = hmac.digest('hex');

      if (!crypto.timingSafeEqual(Buffer.from(signature, 'utf8'), Buffer.from(expectedSig, 'utf8'))) {
        return null;
      }

      return JSON.parse(payloadString);
    } catch (e) {
      return null;
    }
  }
}

const storeInstance = new DataStore();
module.exports = storeInstance;

