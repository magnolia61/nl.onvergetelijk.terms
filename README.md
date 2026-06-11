# nl.onvergetelijk.terms

## Functionele beschrijving

De `terms`-extensie beheert de akkoordverklaringen (algemene voorwaarden) van deelnemers en begeleiders. Wanneer iemand een formulier invult en een vinkje zet voor de voorwaarden, bewaakt `terms` dat de bijbehorende akkoord-datum wordt ingevuld, gesynchroniseerd naar het contactrecord en vastgelegd als audit-veld.

De module werkt voor zowel participants als contacten: als een participant akkoord gaat, worden de terms-velden op het contactrecord bijgewerkt. Auditfields worden gebruikt om bij te houden wanneer het akkoord is gegeven en vanuit welke context.

## Afhankelijkheden

- `nl.onvergetelijk.base`

---

## Technische documentatie

### Kernfuncties

- `terms_civicrm_customPre($op, $groupID, $entityID, &$params)` — pre-hook: filtert op relevante veldgroepen, detecteert syncronisatie-in-uitvoering (reentrancy-beveiliging) en roept `terms_civicrm_configure` aan
- `terms_civicrm_configure($entityID, $params, $op, $entityType)` — de hoofdmotor:
  1. Logica voor vinkjes en datums (akkoord-datum automatisch invullen als vinkje wordt gezet)
  2. Sync participant → contact (spiegelt akkoordvelden van deelnemer naar contact)
  3. Audit-velden bijwerken voor contactwijzigingen

### Reentrancy-beveiliging
`terms` bevat een "schild": via een static flag wordt bijgehouden of er al een synchronisatie in uitvoering is. Dit voorkomt dat een sync naar het contactrecord opnieuw een custompre-hook triggert die `terms` opnieuw start.

### Hooks geïmplementeerd
- `civicrm_customPre`
- `civicrm_config`, `civicrm_install`, `civicrm_enable`

---

*Beheerd door Stichting Onvergetelijke Zomerkampen.*
