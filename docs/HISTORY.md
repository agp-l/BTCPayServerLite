# Historie dokončené práce

Tento přehled uchovává výsledky; není plán ani instalační postup. Aktuální
kontrakty jsou v README a provozních návodech, jediný plán je [ROADMAP](ROADMAP.md).
Původní pracovní deníky a refactor checklisty byly odstraněny z aktuálních souborů,
protože obsahovaly rozporné mezistavy a již splněné příkazy. Plné texty zůstávají
v historii Gitu před stabilizací z 5. října 2026.

| Období | Dokončená práce a tehdejší důkaz |
|---|---|
| 7. září 2026 | Oddělení adres/provideru/workeru/checkoutu, lease/outbox a durable idempotency; tehdejší 53-file sada s MariaDB včetně souběhu a pádu před commitem. |
| 8.–9. září 2026 | Explicitní multi-wallet routing, XPUB provisioning, shared index a receive koordinátor, CLI sync a repair. Přidány reálné DB/procesní/HTTP fixtures, nikoli přístup k produkčnímu daemonu. |
| 9. září 2026 | Admin migrace, provozní diagnostika/ACL generátor, izolovaný offline provisioning, zapamatování zařízení a explicitní XPUB script policy. |
| 10. září 2026 | Platební monitor a bezpečné chybové kódy; provozovatel doložil expiraci dvou invoices bez chyby po opravě cache ACL. Na jeho žádost odstraněn Node/EJS prototyp. |
| 5. října 2026, před touto stabilizací | Opravy kopírování a simple-store integrace: store-scoped GET a validovaný `/pay?id=…`. PR [Lite #14](https://github.com/agp-l/BTCPayServerLite/pull/14) a [simple-store #1](https://github.com/agp-l/simple-store/pull/1) sloučeny. |
| 5. října 2026, receipt monitoring | Přidány přijaté outputs oddělené od balance, lokální raw TXID ověření a bounded historie; 10/30/60min cadence. Tyto funkce již existovaly ve výchozím main tohoto průchodu. |
| 5. října 2026, tento průchod | Webhook claim před HTTP, společný observation budget, pravdivý health, partial API, Apache ochrana a konkrétní pozdní rescan. Aktuální výsledky a commity v [checkpointu](STABILIZATION_2026_10.md). |

Předchozí simple-store harness měl šest skupin scénářů se skutečným lokálním
HTTP, oddělenými MariaDB, produkčním HMAC/outboxem a řízeným kurzem/blockchainem.
Příklad 1 079 Kč / 0,00107900 BTC ověřil partial, plný settlement, duplicitní
callback i sklad/doklad/mail právě jednou. Report tehdejšího CI:
[společná integrace](https://github.com/agp-l/simple-store/actions/runs/37290111846),
[Lite PHP](https://github.com/agp-l/BTCPayServerLite/actions/runs/37290081559).
Tyto historické běhy nebyly v tomto průchodu znovu provedeny proti skutečnému
Electru, HTTPS nebo SMTP. Počty starých testů nejsou dnešní výsledky.
