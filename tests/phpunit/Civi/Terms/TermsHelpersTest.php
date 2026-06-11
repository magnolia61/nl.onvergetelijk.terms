<?php

namespace Civi\Terms;

use Civi\Test\EndToEndInterface;
use Civi\Test\TransactionalInterface;

/**
 * Test voor terms_get_field_map() en terms_generate_date_stamps() in nl.onvergetelijk.terms.
 *
 * @group e2e
 *
 * Beide functies bevatten geen DB-logica en werken puur op array-invoer.
 *
 * terms_get_field_map():
 *   - Retourneert een non-lege array
 *   - Alle sleutels bevatten een numeriek suffix
 *   - Alle waarden beginnen met 'TERMS.' of 'PRIVACY.'
 *   - Bevat akkoord_check velden (TERMS.akkoord_*_check)
 *   - Bevat akkoord_datum velden (TERMS.akkoord_*_datum)
 *   - Bevat privacy-velden (PRIVACY.toestemming_*, PRIVACY.datum_update_gdpr)
 *   - Static cache: tweede aanroep geeft identiek resultaat
 *
 * terms_generate_date_stamps():
 *   - Vinkje AAN (waarde='1') zonder bestaande datum → datum gevuld met $now
 *   - Vinkje UIT (waarde='0') met bestaande datum    → datum op 'null' gezet
 *   - Vinkje AAN met al bestaande datum              → datum NIET overschreven
 *   - Geen relevante vinkjes in $params              → lege array
 *   - Meerdere vinkjes AAN tegelijk                  → meerdere datums gevuld
 */
class TermsHelpersTest extends \PHPUnit\Framework\TestCase implements EndToEndInterface, TransactionalInterface {

  public function setUp(): void {
    parent::setUp();
    if (!function_exists('terms_get_field_map')) {
      $this->markTestSkipped('terms_get_field_map() niet beschikbaar; is nl.onvergetelijk.terms geïnstalleerd?');
    }
  }

  // ########################################################################
  // ### terms_get_field_map(): BASISSTRUCTUUR
  // ########################################################################

  /**
   * Retourneert een non-lege array.
   */
  public function testTermsMapIsNonLeegArray() {
    $result = terms_get_field_map();
    $this->assertIsArray($result, 'terms_get_field_map() moet een array teruggeven.');
    $this->assertNotEmpty($result, 'De terms field map mag niet leeg zijn.');
  }

  /**
   * Alle sleutels bevatten een numeriek suffix (kolomnaam_NNNN patroon).
   */
  public function testTermsMapSleutelsHebbenNumeriekeId() {
    foreach (terms_get_field_map() as $key => $value) {
      $this->assertMatchesRegularExpression('/_\d+$/', $key,
        "Sleutel '$key' moet eindigen op een numeriek suffix."
      );
    }
  }

  /**
   * Alle waarden beginnen met 'TERMS.' of 'PRIVACY.' (namespace-conventie).
   */
  public function testTermsMapWaardenBeginnenMetJuisteNamespace() {
    foreach (terms_get_field_map() as $key => $value) {
      $this->assertMatchesRegularExpression('/^(TERMS|PRIVACY)\./', $value,
        "Waarde '$value' voor sleutel '$key' moet beginnen met 'TERMS.' of 'PRIVACY.'."
      );
    }
  }

  /**
   * Bevat alle vijf akkoord-check velden.
   */
  public function testTermsMapBevatAkkoordCheckVelden() {
    $values = array_values(terms_get_field_map());
    foreach (['TERMS.akkoord_regels_check', 'TERMS.akkoord_terms_check', 'TERMS.akkoord_privacy_check',
              'TERMS.akkoord_medisch_check', 'TERMS.akkoord_conduct_check'] as $check) {
      $this->assertContains($check, $values, "'$check' moet aanwezig zijn in de terms map.");
    }
  }

  /**
   * Bevat alle vijf bijbehorende datum-velden.
   */
  public function testTermsMapBevatAkkoordDatumVelden() {
    $values = array_values(terms_get_field_map());
    foreach (['TERMS.akkoord_regels_datum', 'TERMS.akkoord_terms_datum', 'TERMS.akkoord_privacy_datum',
              'TERMS.akkoord_medisch_datum', 'TERMS.akkoord_conduct_datum'] as $datum) {
      $this->assertContains($datum, $values, "'$datum' moet aanwezig zijn in de terms map.");
    }
  }

  /**
   * Bevat privacy-velden: toestemming en datum_update_gdpr.
   */
  public function testTermsMapBevatPrivacyVelden() {
    $values = array_values(terms_get_field_map());
    $this->assertContains('PRIVACY.toestemming_16plus',  $values, 'PRIVACY.toestemming_16plus moet aanwezig zijn.');
    $this->assertContains('PRIVACY.toestemming_ouders',  $values, 'PRIVACY.toestemming_ouders moet aanwezig zijn.');
    $this->assertContains('PRIVACY.datum_update_gdpr',   $values, 'PRIVACY.datum_update_gdpr moet aanwezig zijn.');
  }

  /**
   * Static cache: tweede aanroep levert identiek resultaat.
   */
  public function testTermsMapStaticCacheGeeftZelfdeResultaat() {
    $eerste  = terms_get_field_map();
    $tweede  = terms_get_field_map();
    $this->assertSame($eerste, $tweede, 'Static cache: beide aanroepen moeten exact hetzelfde object teruggeven.');
  }

  // ########################################################################
  // ### terms_generate_date_stamps(): DATUMLOGICA
  // ########################################################################

  /**
   * Vinkje AAN (waarde='1') zonder bestaande datum → datum gevuld met $now.
   */
  public function testVinkjeAanZonderDatumVultDatum() {
    if (!function_exists('terms_generate_date_stamps')) {
      $this->markTestSkipped('terms_generate_date_stamps() niet beschikbaar.');
    }
    $now    = date('YmdHis');
    $params = ['TERMS.akkoord_regels_check' => '1'];
    $result = terms_generate_date_stamps($params, $now);

    $this->assertArrayHasKey('TERMS.akkoord_regels_datum', $result, 'Datum moet gegenereerd worden als vinkje AAN is.');
    $this->assertEquals($now, $result['TERMS.akkoord_regels_datum'], 'Datum moet gelijk zijn aan $now.');
  }

  /**
   * Vinkje UIT (waarde='0') met bestaande datum → datum op 'null' gezet.
   */
  public function testVinkjeUitMetDatumWistDatum() {
    if (!function_exists('terms_generate_date_stamps')) {
      $this->markTestSkipped('terms_generate_date_stamps() niet beschikbaar.');
    }
    $now    = date('YmdHis');
    $params = [
      'TERMS.akkoord_terms_check' => '0',
      'TERMS.akkoord_terms_datum' => '20240101000000',  // bestaande datum
    ];
    $result = terms_generate_date_stamps($params, $now);

    $this->assertArrayHasKey('TERMS.akkoord_terms_datum', $result, 'Datum-sleutel moet aanwezig zijn bij uitvinken.');
    $this->assertEquals('null', $result['TERMS.akkoord_terms_datum'], "Datum moet op 'null' gezet worden bij uitvinken.");
  }

  /**
   * Vinkje AAN met reeds bestaande datum → datum NIET overschreven.
   */
  public function testVinkjeAanMetBestaandeDatumBlijftOngewijzigd() {
    if (!function_exists('terms_generate_date_stamps')) {
      $this->markTestSkipped('terms_generate_date_stamps() niet beschikbaar.');
    }
    $now          = date('YmdHis');
    $bestaandeDatum = '20230601120000';
    $params       = [
      'TERMS.akkoord_privacy_check' => '1',
      'TERMS.akkoord_privacy_datum' => $bestaandeDatum,
    ];
    $result = terms_generate_date_stamps($params, $now);

    // Datum mag niet in resultaat staan (geen overschrijving)
    $this->assertArrayNotHasKey(
      'TERMS.akkoord_privacy_datum', $result,
      'Bestaande datum mag niet overschreven worden als vinkje al AAN stond.'
    );
  }

  /**
   * Geen relevante vinkjes → lege array teruggegeven.
   */
  public function testGeenVinkjesGeeftLegeArray() {
    if (!function_exists('terms_generate_date_stamps')) {
      $this->markTestSkipped('terms_generate_date_stamps() niet beschikbaar.');
    }
    $result = terms_generate_date_stamps([], date('YmdHis'));
    $this->assertIsArray($result, 'Resultaat moet altijd een array zijn.');
    $this->assertEmpty($result,   'Geen vinkjes in params moet een lege array opleveren.');
  }

  /**
   * Meerdere vinkjes AAN tegelijk → meerdere datums gevuld.
   */
  public function testMeerdereVinkjesAanVullenMeerdereDatums() {
    if (!function_exists('terms_generate_date_stamps')) {
      $this->markTestSkipped('terms_generate_date_stamps() niet beschikbaar.');
    }
    $now    = date('YmdHis');
    $params = [
      'TERMS.akkoord_regels_check'  => '1',
      'TERMS.akkoord_medisch_check' => '1',
    ];
    $result = terms_generate_date_stamps($params, $now);

    $this->assertArrayHasKey('TERMS.akkoord_regels_datum',  $result, 'Datum voor regels moet gevuld zijn.');
    $this->assertArrayHasKey('TERMS.akkoord_medisch_datum', $result, 'Datum voor medisch moet gevuld zijn.');
    $this->assertCount(2, $result, 'Precies twee datums moeten gegenereerd zijn.');
  }
}
