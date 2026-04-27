<?php

/**
 * =======================================================================================
 * FUNCTIE-INDEX: terms.php
 * =======================================================================================
 *   terms_civicrm_customPre()  HOOK: terms_civicrm_customPre
 *   terms_civicrm_configure()  MOTOR: terms_civicrm_configure
 *   terms_civicrm_config()     BOILERPLATE CIVICRM
 *   terms_civicrm_install()
 *   terms_civicrm_enable()
 * =======================================================================================
 */

require_once 'terms.civix.php';
require_once 'terms.helpers.php';
use CRM_Terms_ExtensionUtil as E;

// Centrale debug-level constante
define('TERMS_EXTDEBUG', 4);

/**
 * =========================================================================================
 * HOOK: terms_civicrm_customPre
 * =========================================================================================
 * Vangt wijzigingen af in de custom velden voordat ze de database raken.
 * Fungeert als schild voor API crashes en als verdeler voor ContactReference velden.
 * =========================================================================================
 */
function terms_civicrm_customPre(string $op, int $groupID, int $entityID, array &$params): void {

    global $terms_is_syncing;
    if (!isset($terms_is_syncing)) $terms_is_syncing = false;

    $group_part_terms   = 178;
    $group_privacy      = 286;

    if (!in_array($groupID, [$group_part_terms, $group_privacy]) || !in_array($op, ['create', 'edit'])) return;

    $entityTable        = $params[0]['entity_table'] ?? '';
    wachthond(TERMS_EXTDEBUG, 3, "entityTable",             $entityTable);

    wachthond(TERMS_EXTDEBUG, 2, "########################################################################");
    wachthond(TERMS_EXTDEBUG, 1, "### TERMS [PRE] 1.0 HET SCHILD & DE VERDELER",                  "[START]");
    wachthond(TERMS_EXTDEBUG, 2, "########################################################################");

    // SCHILD A: ContactReference velden strippen als ze leeg zijn (voorkomt API crash)
    $contact_ref_fields = [1525, 2316];
    foreach ($params as $key => $param) {
        if (isset($param['custom_field_id']) && in_array($param['custom_field_id'], $contact_ref_fields)) {
            $val = $param['value'] ?? '';
            if ($val === '' || $val === null || strtolower((string) $val) === 'null' || $val === 0 || $val === '0') {
                unset($params[$key]);
                wachthond(TERMS_EXTDEBUG, 3, "SCHILD: Leeg ContactRef veld " . $param['custom_field_id'] . " verwijderd.");
            }
        }
    }

    // VERDELER B: Voorkom dat CiviCRM Contact-velden op een Participant ID opslaat.
    if ($entityTable === 'civicrm_participant' && $groupID === $group_privacy) {
        wachthond(TERMS_EXTDEBUG, 2, "VERDELER: Contact Groep 286 (Privacy) onderschept op Participant formulier.");
        
        $params_part_get = [
            'checkPermissions'  => FALSE,
            'select'            => ['contact_id'],
            'where'             => [['id', '=', $entityID]],
        ];
        $contact_id = civicrm_api4('Participant', 'get', $params_part_get)->first()['contact_id'] ?? null;

        if ($contact_id) {
            $name_map  = terms_get_field_map();
            $extracted = base_extract_from_params($params, $name_map);
            
            if (!empty($extracted)) {
                wachthond(TERMS_EXTDEBUG, 3, "VERDELER: Data omgeleid naar Contact ID: $contact_id", $extracted);
                $terms_is_syncing = true;
                base_api_wrapper('Contact', $contact_id, $extracted, "TERMS_REDIRECTION_SYNC", TERMS_EXTDEBUG);
                $terms_is_syncing = false;
            }
        }

        // Wis de params array volledig zodat de CiviCRM kern dit niet meer probeert op te slaan
        foreach ($params as $k => $v) {
            unset($params[$k]);
        }
        wachthond(TERMS_EXTDEBUG, 2, "VERDELER: Core transactie voor Groep 286 afgebroken ter preventie van crash.");
        return; // Einde oefening voor deze run
    }

    if ($terms_is_syncing) {
        wachthond(TERMS_EXTDEBUG, 3, "### TERMS [PRE] CUSTOMPRE GENEGEERD (Sync in progress)");
        return;
    }

    wachthond(TERMS_EXTDEBUG, 2, "########################################################################");
    wachthond(TERMS_EXTDEBUG, 1, "### TERMS [PRE] 2.0 CONFIGURATIE MOTOR",                    "[$entityID]");
    wachthond(TERMS_EXTDEBUG, 2, "########################################################################");

    $name_map       = terms_get_field_map();
    $field_ids      = base_get_field_ids($name_map);
    $context_entity = ($groupID === $group_part_terms) ? 'Participant' : 'Contact';
    $params_terms   = base_extract_from_params($params, $name_map);

    /* DE MOTOR: Verwerkt vinkjes naar datums en regelt de cross-entity sync. */
    $data_to_inject = terms_civicrm_configure($entityID, $params_terms, 'hook', $context_entity);

    /* Injecteer berekende waarden (zoals datums) terug in de huidige transactie. */
    if (!empty($data_to_inject)) {
        wachthond(TERMS_EXTDEBUG, 2, "Injectie van berekende datums in Pre-hook transactie", $data_to_inject);
        base_inject_params($params, $data_to_inject, $field_ids, $entityID, "TERMS", TERMS_EXTDEBUG);
    }

    wachthond(TERMS_EXTDEBUG, 2, "########################################################################");
    wachthond(TERMS_EXTDEBUG, 1, "### TERMS [PRE] 3.0 EINDE CUSTOMPRE",                       "[$entityID]");
    wachthond(TERMS_EXTDEBUG, 2, "########################################################################");
}

/**
 * =========================================================================================
 * MOTOR: terms_civicrm_configure
 * =========================================================================================
 * Centrale configuratie en synchronisatie logica.
 * Berekent datums uit vinkjes en activeert de cross-entity synchronisatie.
 * =========================================================================================
 */
function terms_civicrm_configure(?int $entityID = null, array $params = [], string $op = 'direct', string $entityType  = 'Participant'): array {

    static $processing_terms = [];
    if ($entityID !== null && isset($processing_terms[$entityID])) return $params;
    if ($entityID !== null) $processing_terms[$entityID] = true;

    $now = date('YmdHis');
    $res = [];

    wachthond(TERMS_EXTDEBUG, 2, "########################################################################");
    wachthond(TERMS_EXTDEBUG, 1, "### TERMS [CFG] 1.0 LOGICA VOOR VINKJES EN DATUMS",             "[START]");
    wachthond(TERMS_EXTDEBUG, 2, "########################################################################");

    /* 1. DATUM-STEMPELS */
    $res = array_merge($res, terms_generate_date_stamps($params, $now));

    wachthond(TERMS_EXTDEBUG, 2, "########################################################################");
    wachthond(TERMS_EXTDEBUG, 1, "### TERMS [CFG] 2.0 SYNC PARTICIPANT NAAR CONTACT",              "[SYNC]");
    wachthond(TERMS_EXTDEBUG, 2, "########################################################################");

    /* 2. SYNC PARTICIPANT -> CONTACT */
    if ($entityType === 'Participant' && $entityID !== null) {
        terms_sync_participant_to_contact($entityID, $params, $res, $now);
    }

    wachthond(TERMS_EXTDEBUG, 2, "########################################################################");
    wachthond(TERMS_EXTDEBUG, 1, "### TERMS [CFG] 3.0 AUDIT-VELDEN VOOR CONTACT-WIJZIGINGEN",     "[AUDIT]");
    wachthond(TERMS_EXTDEBUG, 2, "########################################################################");

    /* 3. AUDIT-VELDEN VOOR CONTACT-WIJZIGINGEN */
    if ($entityType === 'Contact') {
        $res['PRIVACY.privacy_modified']    = $now;
        $res['PRIVACY.datum_update_gdpr']   = $now;
    }

    wachthond(TERMS_EXTDEBUG, 2, "########################################################################");
    wachthond(TERMS_EXTDEBUG, 1, "### TERMS [CFG] 4.0 sessie voor $entityType $entityID",         "[EINDE]");
    wachthond(TERMS_EXTDEBUG, 2, "########################################################################");

    if ($entityID !== null) unset($processing_terms[$entityID]);

    return $res;
}

/**
 * =========================================================================================
 * BOILERPLATE CIVICRM
 * =========================================================================================
 */

function terms_civicrm_config(&$config): void {
  _terms_civix_civicrm_config($config);
}

function terms_civicrm_install(): void {
  _terms_civix_civicrm_install();
}

function terms_civicrm_enable(): void {
  _terms_civix_civicrm_enable();
}