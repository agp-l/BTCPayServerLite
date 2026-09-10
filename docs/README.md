# Dokumentace

[Hlavní README](../README.md) obsahuje současné chování a cestu od instalace k provozu.
Návody níže platí pro PHP aplikaci. Datované audity a pracovní záznamy zachycují
stav při vzniku; nejsou náhradou aktuálního instalačního postupu.

## Provoz a integrace

| Dokument | Obsah |
|---|---|
| [DEPLOYMENT](DEPLOYMENT.md) | Opakovatelné nasazení, PHP/Composer, ACL, obnova, samostatné workery |
| [CONFIGURATION](CONFIGURATION.md) | Konfigurační volby a sdílené runtime cesty |
| [DATABASE_UPGRADE](DATABASE_UPGRADE.md) | Admin aktualizátor, katalog migrací, rozsah porovnání a zotavení |
| [PAYMENT_MONITORING](PAYMENT_MONITORING.md) | Admin kontrola plateb, systemd, diagnostika a platební politika |
| [RECEIVE_COORDINATION](RECEIVE_COORDINATION.md) | XPUB indexy napříč admin/stateless/API, synchronizace a obnova |
| [STORE_CREATION_TROUBLESHOOTING](STORE_CREATION_TROUBLESHOOTING.md) | Chyby provisioningu, Python prostředí a oprávnění |
| [SESSION_LOGIN](SESSION_LOGIN.md) | Zapamatování přihlášení, limity a odvolání relací |
| [API](API.md) | Endpointy, checkout, webhooky, příklad klienta a limity payoutů |
| [TESTING](TESTING.md) | Testovací runtime, integrační DB a hranice důkazů |

## Architektura a další práce

- [Vlastníci core operací](CORE_PAYMENT_ARCHITECTURE.md)
- [Aktuální plán a podmínky dokončení](ROADMAP.md)

## Historie

- [Cílená revize core, září 2026](CORE_TARGETED_REVIEW_2026_09.md)
- [Audit XPUB provisioningu](XPUB_FIRST_MULTI_WALLET_AUDIT.md)
- [Průběh core stabilizace](CORE_STABILIZATION_PROGRESS.md)
- [Průběh instalátoru, multi-wallet a provozních oprav](INSTALLER_MULTI_WALLET_PROGRESS.md)
- [Původní plán refaktoru](archive/REFACTOR_PLAN.md)

Při změně chování upravte příslušný aktuální návod a stručně README. Do pracovního
záznamu přidejte nový checkpoint s provedenými kontrolami a otevřenými body;
staré výsledky testů nepřepisujte výsledkem nového běhu.
