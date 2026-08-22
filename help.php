Zde je tvůj kompletní, aktualizovaný a stručný instalační manuál pro tvůj projekt BTC PAY SERVER LITE. Tento návod tě provede čistou instalací od nuly až po automatický start při restartu počítače.

1. Stažení a instalace Electra do /opt
Připravíme samotný program do systémové složky odděleně od tvých dat.

Bash
# Stažení Electra (pokud ho ještě nemáš) do dočasné složky
git clone https://github.com/spesmilo/electrum.git /tmp/electrum
cd /tmp/electrum
git checkout 4.6.2 # nebo jiná verze, kterou používáš

# Přesun na trvalé místo
sudo mv /tmp/electrum /opt/electrum

# Nastavení práv pro tebe (ag) a webový server (daemon)
sudo chown -R ag:daemon /opt/electrum
sudo chmod -R 755 /opt/electrum

# Instalace potřebných Python knihoven globálně
sudo python3 -m pip install -r /opt/electrum/contrib/requirements/requirements.txt --break-system-packages
2. Příprava složek pro data a konfiguraci
Tato část je kritická pro to, aby se PHP a Electrum nehádaly o přístupová práva.

Bash
# A) Složka pro konfiguraci démona
sudo mkdir -p /opt/electrum_config
sudo chown -R ag:daemon /opt/electrum_config
sudo chmod -R 775 /opt/electrum_config

# B) Složka pro klientské peněženky (bezpečný trezor)
sudo mkdir -p /opt/btcpay_wallets
sudo chown -R ag:daemon /opt/btcpay_wallets
sudo chmod -R 775 /opt/btcpay_wallets
sudo chmod g+s /opt/btcpay_wallets   # Nové soubory automaticky zdědí skupinu daemon
3. Nastavení RPC přihlášení
Démon musí mít nastavené jméno a heslo, aby s ním tvoje PHP (api.php) mohlo bezpečně komunikovat.

Bash
python3 /opt/electrum/run_electrum -D /opt/electrum_config setconfig rpcuser ag
python3 /opt/electrum/run_electrum -D /opt/electrum_config setconfig rpcpassword silne-heslo
4. Automatický start po restartu PC (Systemd služba)
Aby démon běžel trvale na pozadí a sám se zapnul při startu počítače, vytvoříme mu systémovou službu.

Vytvoř soubor:

Bash
sudo nano /etc/systemd/system/electrum.service
Vlož do něj tento kód:

Ini, TOML
[Unit]
Description=Electrum RPC Daemon
After=network.target

[Service]
User=ag
Group=daemon
Type=simple
ExecStart=/usr/bin/python3 /opt/electrum/run_electrum -D /opt/electrum_config daemon --rpcport 7777
Restart=always
RestartSec=10

[Install]
WantedBy=multi-user.target
(Ulož zkratkou Ctrl+O, Enter, a zavři Ctrl+X)

Aktivuj a spusť službu:

Bash
sudo systemctl daemon-reload
sudo systemctl enable electrum
sudo systemctl start electrum
Zkontroluj, zda svítí zeleně:

Bash
sudo systemctl status electrum
5. Rychlý tahák příkazů pro terminál (volitelné)
Když budeš chtít s Electrem pracovat ručně v terminálu, vždy používej parametr -D /opt/electrum_config.

Bash
# Jak ručně vytvořit novou peněženku (offline režim)
python3 /opt/electrum/run_electrum -D /opt/electrum_config create --offline -w "/opt/btcpay_wallets/moje_nova_penezenka"

# Jak zjistit zůstatek konkrétní peněženky
python3 /opt/electrum/run_electrum -D /opt/electrum_config getbalance -w "/opt/btcpay_wallets/wallet_1"

# Vypsat seznam právě načtených peněženek v paměti démona
python3 /opt/electrum/run_electrum -D /opt/electrum_config list_wallets