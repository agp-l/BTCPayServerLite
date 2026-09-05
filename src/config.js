const fs = require('fs');
const path = require('path');

function parsePhpConfig(filePath) {
  const defaults = {
    rpc_host: '127.0.0.1',
    rpc_port: 7777,
    rpc_user: 'ag',
    rpc_pass: 'silne-heslo',
    db_host: '127.0.0.1',
    db_port: 3306,
    db_name: 'btcpay_lite',
    db_user: 'root',
    db_pass: '',
    admin_api_key: 'master_klic_admin_777',
    secret_key: 'MujVelmiTajnySifrovaciKlic_2026_Brno',
    cron_key: 'super_tajny_cron_klic',
    app_url: 'http://localhost/BTCPayLite/',
    password_reset_from: 'no-reply@btcpayserver.local',
    wallet_path: '/opt/btcpay_wallets/wallet_1',
    electrum_cli_path: '/opt/electrum/run_electrum',
    electrum_data_dir: '/opt/electrum_config',
    store_wallets_dir: '/opt/btcpay_wallets',
    allow_local_webhooks: true,
    exchange_fee_bps: 200,
    api_clients: {
      'master_klic_admin_777': 'default_wallet_1',
      'MujVelmiTajnySifrovaciKlic_2026_Praha': 'wallet_1',
      'JinyKlicProKlientaPraha_777': 'wallet_1',
    },
    payout_api_enabled: true,
    payout_api_keys: {
      'store-id': 'samostatny-nahodny-klic-alespon-32-znaku',
    },
    payout_wallet_passwords: {
      'store-id': 'heslo-electrum-walletu',
    },
    payout_max_btc: '0.01000000',
    payout_daily_limit_btc: '0.05000000',
  };

  try {
    if (!fs.existsSync(filePath)) {
      return defaults;
    }

    const content = fs.readFileSync(filePath, 'utf8');
    const config = { ...defaults };

    // Parse simple key-values
    const stringMatches = content.matchAll(/'([a-zA-Z0-9_]+)'\s*=>\s*'([^']*)'/g);
    for (const match of stringMatches) {
      const key = match[1];
      const val = match[2];
      if (key in defaults && typeof defaults[key] === 'string') {
        config[key] = val;
      }
    }

    const numMatches = content.matchAll(/'([a-zA-Z0-9_]+)'\s*=>\s*([0-9]+)/g);
    for (const match of numMatches) {
      const key = match[1];
      const val = Number(match[2]);
      if (key in defaults && typeof defaults[key] === 'number') {
        config[key] = val;
      }
    }

    const boolMatches = content.matchAll(/'([a-zA-Z0-9_]+)'\s*=>\s*(true|false)/gi);
    for (const match of boolMatches) {
      const key = match[1];
      const val = match[2].toLowerCase() === 'true';
      if (key in defaults && typeof defaults[key] === 'boolean') {
        config[key] = val;
      }
    }

    // Parse api_clients array if present
    const apiClientsMatch = content.match(/'api_clients'\s*=>\s*\[([\s\S]*?)\]/);
    if (apiClientsMatch) {
      const inner = apiClientsMatch[1];
      const clientEntries = inner.matchAll(/'([^']+)'\s*=>\s*'([^']+)'/g);
      const clientsObj = {};
      for (const entry of clientEntries) {
        clientsObj[entry[1]] = entry[2];
      }
      if (Object.keys(clientsObj).length > 0) {
        config.api_clients = clientsObj;
      }
    }

    return config;
  } catch (err) {
    console.error('Error parsing config.php:', err);
    return defaults;
  }
}

const configPath = path.join(__dirname, '..', 'config.php');
const config = parsePhpConfig(configPath);

module.exports = config;
