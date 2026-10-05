# Dokumentace projektu

[Hlavní README](../README.md) · veřejná PHP stránka `/dokumentace`

## Aktuální návody a kontrakty

| Dokument | Obsah |
|---|---|
| [DEPLOYMENT](DEPLOYMENT.md) | Nasazení, PHP/Composer, ACL, obnova, tři workery a ochrana webserveru |
| [CONFIGURATION](CONFIGURATION.md) | Konfigurační volby, local HTTP, sdílené cesty a endpoint budget |
| [DATABASE_UPGRADE](DATABASE_UPGRADE.md) | Katalog 001–011, rozsah kontroly a recovery přerušeného DDL |
| [PAYMENT_MONITORING](PAYMENT_MONITORING.md) | Cadence, konkrétní late rescan, cron/systemd, fronta a diagnostika |
| [CAPACITY](CAPACITY.md) | HTTP versus RPC, souhrnné limity a plánovací výpočty bez garance výkonu |
| [CORE_PAYMENT_ARCHITECTURE](CORE_PAYMENT_ARCHITECTURE.md) | Vlastníci operací, transakční hranice, důvěra a platební politika |
| [RECEIVE_COORDINATION](RECEIVE_COORDINATION.md) | Společné XPUB rezervace, receive sync, repair a obnova |
| [WALLET_HISTORY](WALLET_HISTORY.md) | Příjmy, nepotvrzený zůstatek a vlastní převody |
| [STORE_CREATION_TROUBLESHOOTING](STORE_CREATION_TROUBLESHOOTING.md) | Provisioning, PHP/Python, cesty a oprávnění |
| [SESSION_LOGIN](SESSION_LOGIN.md) | Relace, 30denní zařízení a odvolání tokenů |
| [API](API.md) | Implementované endpointy, nullable health, HMAC, stateless a payout hranice |
| [DEPENDENCIES](DEPENDENCIES.md) | Zamčené balíčky, vendor a konkrétní známá bezpečnostní upozornění |
| [TESTING](TESTING.md) | DB/Apache/procesní testy a meze důkazů |

## Stav a další práce

- [Současný stav a nastavení simple-store](PROJECT_STATUS_2026_10.md)
- [Jediný aktuální plán](ROADMAP.md)
- [Checkpoint současné stabilizace](STABILIZATION_2026_10.md)
- [Stručná historie dokončené práce](HISTORY.md)

Při změně kódu upravit příslušný kontrakt, README a veřejnou PHP dokumentaci.
Do checkpointu patří provedená kontrola a její prostředí; další úkoly pouze do
ROADMAP. Historické výsledky nesmějí sloužit jako aktuální úkoly nebo důkaz
nové produkční kapacity. Původní mezistavy jsou dohledatelné v Git historii.
