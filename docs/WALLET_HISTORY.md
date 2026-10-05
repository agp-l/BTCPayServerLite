# Historie a příjem bitcoinů v peněžence

Administrace **Peněženka** načítá historii a zůstatky vybrané peněženky přímo
z Electra. Stránka je snímek; nové údaje načte tlačítko **Obnovit**. Kontrola
faktur na pozadí je oddělená a nevyžaduje otevřený prohlížeč.

- Příjem z jiné peněženky se zobrazuje zeleně jako **Přijatá platba** s kladnou částkou.
- Transakce s nulovým počtem potvrzení jsou nahoře s označením **Nepotvrzeno**.
  Zobrazuje se také samostatný nepotvrzený zůstatek vrácený Electrem; může být záporný.
  Tento údaj není automaticky součtem příchozích plateb.
- Potvrzené platby ukazují počet potvrzení a čas bloku. Nepotvrzené transakci
  nevymýšlíme datum; její potvrzení se projeví při dalším načtení.
- Převod mezi adresami téže peněženky je jeden **Převod ve vlastní peněžence**.
  Nevytváří nový příjem do celkového zůstatku: snižuje ho pouze síťový poplatek.
  Částky jednotlivých vlastních výstupů jsou v technických detailech. Při převodu
  mezi dvěma různými peněženkami je v odesílající peněžence odchozí a v přijímající
  peněžence příchozí záznam.

Historie používá podepsané integer `amount_sat` z novějšího Electra a podporuje
i starší BTC pole `bc_value` / `value`. Vratný výstup po odeslání se nepovažuje
za samostatnou přijatou platbu. Interní převod rozpoznáme pouze po úspěšném
dekódování všech výstupů, které patří vybrané peněžence.

## Faktura je zaplacená, ale v Electru chybí příjem

XPUB faktura vytvoří adresu bez kontaktu s daemonem. Kontrola faktury se ptá
na adresu přes blockchain rozhraní a nevyžaduje, aby ji Electrum již mělo ve svém
seznamu přijímacích adres. Zůstatek a historie peněženky ale obsahují jen adresy,
které její Electrum zná a synchronizovalo.

Na serveru musí být zvlášť plánované tři úlohy:

| Úloha | Účel |
| --- | --- |
| `payment_worker.php` | Kontrola adres faktur a uložení jejich stavu |
| `webhook_cron.php` | Doručení změn e-shopu |
| `wallet_receive_sync.php` | Doplnění rezervovaných XPUB adres do Electra |

Timer pro kontrolu plateb nespouští zbývající dvě úlohy. Při chybějící historii
ověřte správnou vybranou peněženku, online/synchronizovaný Electrum daemon a
receive worker. Na lokální instalaci XAMPP lze spustit kontrolu a jednu dávku:

```bash
cd /opt/lampp/htdocs/BTCPayLite
/opt/lampp/bin/php wallet_receive_sync.php --check-db
/opt/lampp/bin/php wallet_receive_sync.php
```

Po doběhnutí a synchronizaci Electra obnovte stránku peněženky. `partial` znamená,
že zbývající adresy doplní další dávka. `no_registered_wallets` znamená, že nejsou
registrované XPUB rozsahy; samotné opakování workeru je nevytvoří. Postup registrace,
limity a běžné plánování jsou v [koordinaci adres](RECEIVE_COORDINATION.md).

Ověření opravy: testy pokrývají příjem v mempoolu i po potvrzení, přesnost jednoho
satoshi, externí odeslání s vratkou, převod mezi vlastními adresami, neúplné detaily
výstupů, starší formát historie a izolaci vybrané peněženky. Skutečnou historii
místní instalace lze ověřit pouze proti jejímu Electru.
