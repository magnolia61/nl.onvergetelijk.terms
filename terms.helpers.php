<?php

/**
 * =======================================================================================
 * FUNCTIE-INDEX: terms.helpers.php
 * =======================================================================================
 *   terms_get_field_map()                De "Single Source of Truth" voor de terms en privacy velden.
 *   terms_generate_date_stamps()         Genereert datumstempels voor aangevinkte velden.
 *   terms_sync_participant_to_contact()  Synchroniseert foto-toestemming van Participant naar het gekoppelde...
 * =======================================================================================
 */

/**
 * =========================================================================================
 * EXTENSIE: TERMS (Helpers)
 * =========================================================================================
 * Bevat de mapping, datum-generatie, en cross-entity synchronisatie logica.
 * =========================================================================================
 */

/**
 * De "Single Source of Truth" voor de terms en privacy velden.
 * Koppelt de database-kolom aan de API-naam.
 */
function terms_get_field_map(): array {

	// Static cache — array wordt maar één keer opgebouwd per request.
	static $map = null;
	if ($map !== null) return $map;

	/* UITLEG: Mapping tussen fysieke database-kolommen en logische aliassen voor de API. */
	$map = [
		// PART_TERMS (Deelnemer - Groep 178)
		'akkoord_regels_datum_1786'				=> 'TERMS.akkoord_regels_datum',
		'akkoord_terms_datum_1787'				=> 'TERMS.akkoord_terms_datum',
		'akkoord_privacy_datum_1788'			=> 'TERMS.akkoord_privacy_datum',
		'akkoord_medisch_datum_1789'			=> 'TERMS.akkoord_medisch_datum',
		'akkoord_conduct_datum_1791'			=> 'TERMS.akkoord_conduct_datum',
		'akkoord_regels_check_1792'				=> 'TERMS.akkoord_regels_check',
		'akkoord_terms_check_1793'				=> 'TERMS.akkoord_terms_check',
		'akkoord_privacy_check_1794'			=> 'TERMS.akkoord_privacy_check',
		'akkoord_medisch_check_1795'			=> 'TERMS.akkoord_medisch_check',
		'akkoord_conduct_check_1796'			=> 'TERMS.akkoord_conduct_check',

		// Foto Snapshots (PART_TERMS - Groep 178)
		'akkoord_fotos_16plus_2311'				=> 'TERMS.akkoord_fotos_16plus',
		'akkoord_fotos_16plus_datum_2313'		=> 'TERMS.akkoord_fotos_16plus_datum',
		'akkoord_fotos_ouders_2314'				=> 'TERMS.akkoord_fotos_ouders',
		'akkoord_fotos_ouders_datum_2315'		=> 'TERMS.akkoord_fotos_ouders_datum',
		'akkoord_fotos_ouders_verlener_2316'	=> 'TERMS.akkoord_fotos_ouders_verlener',
		'akkoord_fotos_voorkeur_2317'			=> 'TERMS.akkoord_foto_voorkeur',
		'akkoord_fotos_toelichting_2318'		=> 'TERMS.akkoord_foto_toelichting',

		// PRIVACY (Contact - Groep 286)
		'toestemming_verlener_1525'				=> 'PRIVACY.toestemming_verlener',
		'toestemming_ouders_1526'				=> 'PRIVACY.toestemming_ouders',
		'toestemming_beeldgebruik_1524'			=> 'PRIVACY.toestemming_16plus',
		'kampfoto_s_ontvangen_1528'				=> 'PRIVACY.kampfoto_s_ontvangen',
		'datum_toestemming_1527'				=> 'PRIVACY.datum_toestemming',
		'geheim_adres_1386'						=> 'PRIVACY.geheim_adres',
		'contactvoorkeuren_1417'				=> 'PRIVACY.contactvoorkeuren',
		'opmerkingen_gdpr_1419'					=> 'PRIVACY.opmerkingen_gdpr',
		'datum_update_gdpr_1418'				=> 'PRIVACY.datum_update_gdpr',
		'privacy_modified_2102'					=> 'PRIVACY.privacy_modified',
	];

	return $map;
}

/**
 * Genereert datumstempels voor aangevinkte velden.
 */
function terms_generate_date_stamps(array $params, string $now): array {
	$trigger_map = [
		'TERMS.akkoord_regels_check'	=> 'TERMS.akkoord_regels_datum',
		'TERMS.akkoord_terms_check'		=> 'TERMS.akkoord_terms_datum',
		'TERMS.akkoord_privacy_check'	=> 'TERMS.akkoord_privacy_datum',
		'TERMS.akkoord_medisch_check'	=> 'TERMS.akkoord_medisch_datum',
		'TERMS.akkoord_conduct_check'	=> 'TERMS.akkoord_conduct_datum',
		'TERMS.akkoord_fotos_16plus'	=> 'TERMS.akkoord_fotos_16plus_datum',
		'TERMS.akkoord_fotos_ouders'	=> 'TERMS.akkoord_fotos_ouders_datum',
		'PRIVACY.toestemming_16plus'	=> 'PRIVACY.datum_toestemming',
		'PRIVACY.toestemming_ouders'	=> 'PRIVACY.datum_toestemming',
	];

	$res = [];
	foreach ($trigger_map as $check_key => $date_key) {
		$val = $params[$check_key] ?? null;
		if ($val == '1' && empty($params[$date_key])) {
			$res[$date_key] = $now;
			wachthond(TERMS_EXTDEBUG, 3, "Vinkje AAN voor $check_key, datum gezet");
		} elseif (($val === '0' || empty($val)) && !empty($params[$date_key])) {
			$res[$date_key] = 'null';
			wachthond(TERMS_EXTDEBUG, 3, "Vinkje UIT voor $check_key, datum gewist");
		}
	}
	return $res;
}

/**
 * Synchroniseert foto-toestemming van Participant naar het gekoppelde Contact.
 */
function terms_sync_participant_to_contact(int $entityID, array $params, array $res, string $now): void {
	global $terms_is_syncing;

	static $contact_id_cache = [];

	if (!isset($contact_id_cache[$entityID])) {
		$params_part_get = [
			'checkPermissions'	=> FALSE,
			'select'			=> ['contact_id'],
			'where'				=> [['id', '=', $entityID]],
		];
		$contact_id_cache[$entityID] = civicrm_api4('Participant', 'get', $params_part_get)->first()['contact_id'] ?? null;
	}

	$contact_id = $contact_id_cache[$entityID];
	if (!$contact_id) return;

	$sync_payload	= [];
	$voorkeur		= $params['TERMS.akkoord_foto_voorkeur']    ?? 'Niet opgegeven';
	$toelichting	= $params['TERMS.akkoord_foto_toelichting'] ?? '';

	if (($params['TERMS.akkoord_fotos_16plus'] ?? null) == '1') {
		$sync_payload['PRIVACY.toestemming_16plus'] = "1";
		$sync_payload['PRIVACY.datum_toestemming']  = $res['TERMS.akkoord_fotos_16plus_datum'] ?? $now;
	}

	if (($params['TERMS.akkoord_fotos_ouders'] ?? null) == '1') {
		$sync_payload['PRIVACY.toestemming_ouders'] = "1";
		$sync_payload['PRIVACY.datum_toestemming']  = $res['TERMS.akkoord_fotos_ouders_datum'] ?? $now;
	}

	if (empty($sync_payload)) {
		wachthond(TERMS_EXTDEBUG, 3, "Geen foto-toestemming gewijzigd, sync overgeslagen voor Contact $contact_id");
		return;
	}

	$opmerking = "Voorkeur ($now): " . $voorkeur;
	if (!empty($toelichting)) {
		$opmerking .= " | Toelichting: " . $toelichting;
	}

	$sync_payload['PRIVACY.opmerkingen_gdpr']	= $opmerking;
	$sync_payload['PRIVACY.datum_update_gdpr']	= $now;
	$sync_payload['PRIVACY.privacy_modified']	= $now;

	wachthond(TERMS_EXTDEBUG, 3, "Sync naar Contact ($contact_id) met voorkeur: $voorkeur");

	$terms_is_syncing = true;
	base_api_wrapper('Contact', $contact_id, $sync_payload, "TERMS_TO_PRIVACY_SYNC", TERMS_EXTDEBUG);
	$terms_is_syncing = false;
}