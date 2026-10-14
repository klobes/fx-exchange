# Emiron FX — testet

Dy grupe:

| Suita | Çfarë kërkon | Çfarë mbulon |
|---|---|---|
| **Unit** | vetëm PHP + PHPUnit (pa DB, pa aplikacion) | `FxMoney`, hash-i i zinxhirit, struktura/përdorimi i lejeve (`config/permissions.php`) |
| **Feature** | aplikacioni Botble + lidhja ekzistuese e bazës (**s'duhet përdorues, bazë apo .env.testing i ri**) | sporteli, anulimi, arka, zinxhiri hash, partnerët, banka, fitimi/humbja, kalendari |

## Ekzekutimi (nga rrënja e projektit Botble)

```bash
vendor/bin/phpunit -c platform/plugins/emiron-fx/phpunit.xml.dist --testsuite Unit
vendor/bin/phpunit -c platform/plugins/emiron-fx/phpunit.xml.dist --testsuite Feature
```

## Si punon baza e testeve Feature (0.7.6)

Asnjë hap manual i bazës. Testet përdorin **të njëjtën lidhje** të bazës, por me **prefiks tabelash `fxtest_`**
(p.sh. `fxtest_fx_transactions`, `fxtest_fx_tills`, `fxtest_migrations`):

1. `FxTestCase` vendos prefiksin me kod para çdo query-je (Eloquent, `DB::table`, `Schema`).
2. Herën e parë krijon vetë tabelat e testit duke ekzekutuar migrimet e plugin-it (përfshirë
   `2026_10_01_000001_prepare_fx_sequences_series`, që shmang gabimin 1553 te `fx_sequences`).
3. Çdo test ekzekutohet brenda një transaksioni që **kthehet mbrapsht**.
4. **Tabelat e prodhimit (pa prefiks) s'preken kurrë.** Mbrojtje: nëse prefiksi s'përmban "test" ose
   `APP_ENV` s'është `testing`, testi hedh përjashtim para çdo query-je.

Tabelat `fxtest_*` mbeten në bazë (migrohen vetëm një herë). Për t'i hequr kur të duash:

```sql
SET FOREIGN_KEY_CHECKS = 0;
-- merr listën:
SELECT CONCAT('DROP TABLE `', table_name, '`;') FROM information_schema.tables
 WHERE table_schema = DATABASE() AND table_name LIKE 'fxtest\_%';
-- ekzekuto rreshtat e dalë, pastaj:
SET FOREIGN_KEY_CHECKS = 1;
```

## Shënime të rëndësishme

- **Testet e lidhura me sjellje të njohur:** `LedgerChainTest::test_tampering_with_flags_is_detected` është *incomplete* me qëllim (flags s'hyn në hash — rregullohet në 0.8.x) dhe `FxLedgerHashTest::test_fields_outside_the_canonical_list...` dokumenton sjelljen aktuale; të dyja kthehen kur të dalë hash-i i zgjeruar.
- **`PnlTest`** supozon kosto mesatare të ponderuar (0.7.x). Kur të kalohet në FIFO (0.8.x), rastet me disa blerje me kurse të ndryshme duhet përditësuar.
- Mbulimi nuk është ende i plotë: **raportet e BSH/AIF, klientët/AML/kopjet e dokumenteve, importi i kurseve (BSH, referenca) dhe testet HTTP të lejeve për çdo route** vijnë në versionet e ardhshme (shih PLANI_ZHVILLIMIT).
- Pa rezultat të gjelbër nuk publikohet funksion i ri (p.sh. kryqëzimi valutë↔valutë).
