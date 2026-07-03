<?php

namespace Civi\Mee;

use Civi\Test\EndToEndInterface;
use Civi\Test\TransactionalInterface;

/**
 * Tests voor mee_civicrm_configure() in nl.onvergetelijk.mee.
 *
 * @group e2e
 *
 * mee_civicrm_configure() bepaalt of een persoon dit jaar / dit event /
 * dit eventjaar deelneemt (deel) of leiding geeft (leid). Elke combinatie
 * levert tellers (yes/not/mss/stf/tst) en een tekstuele samenvatting (txt).
 *
 * Testbenadering:
 *   - Self-service modus: aanroep met alleen contact_id, zodat de functie
 *     intern base_find_allpart() en base_pid2part() aanroept.
 *   - Directe modus: volledige arrays meegeven zodat de waterval-logica
 *     geïsoleerd getest kan worden zonder DB-afhankelijkheid.
 *
 * Scenario's:
 *   A: Retourstructuur — alle verwachte sleutels aanwezig (self-service)
 *   B: Tellers zijn integers >= 0 (self-service)
 *   C: Txt-velden zijn geen objecten (self-service)
 *   D: contact_id = 0 → geen crash, NULL terug
 *   E: contact_id in retourarray klopt met invoer
 *   F: DEEL event met positieve status → diteventdeelyes=1, txt='YES'
 *   G: DEEL event met negatieve status → diteventdeelnot=1, txt='ANN'
 *   H: DEEL event met wachtstatus → diteventdeelmss=1, txt='MSS'
 *   I: LEID event met positieve status → diteventleidyes=1, txt='YES'
 *   J: Geen event type match → diteventdeelnot=1, txt='NOT'
 *   K: ditjaar_pos_deel_count > 0 → ditjaardeelyes=1, txt='YES'
 *   L: ditjaar één negatieve deelname → ditjaardeeltxt='ANN'
 *   M: ditjaar leiding met staf-functie → ditjaarleidstf=1, txt='STF'
 */
class MeeConfigureTest extends \PHPUnit\Framework\TestCase implements EndToEndInterface, TransactionalInterface {

  use \Civi\Test\Api3TestTrait;

  private int $contactId;

  /** Minimale allpart_array voor een contact zonder actieve deelname. */
  private static function legeAllpartArray(int $contactId): array {
    return [
      'refdate'                             => date('Y-m-d'),
      'refyear'                             => (int) date('Y'),
      'result_allpart_all_count'            => 0,
      'result_allpart_pen_count'            => 0,
      'result_allpart_wait_count'           => 0,
      'result_allpart_neg_count'            => 0,
      'result_allpart_pos_count'            => 0,
      'result_allpart_pos_deel_count'       => 0,
      'result_allpart_pos_leid_count'       => 0,
      'result_allpart_all_deel_count'       => 0,
      'result_allpart_all_leid_count'       => 0,
      'result_allpart_one_part_id'          => NULL,
      'result_allpart_one_deel_part_id'     => NULL,
      'result_allpart_one_leid_part_id'     => NULL,
      'result_allpart_one_event_id'         => NULL,
      'result_allpart_one_deel_event_id'    => NULL,
      'result_allpart_one_leid_event_id'    => NULL,
      'result_allpart_one_event_type_id'    => NULL,
      'result_allpart_one_deel_event_type_id' => NULL,
      'result_allpart_one_leid_event_type_id' => NULL,
      'result_allpart_one_status_id'        => NULL,
      'result_allpart_one_deel_status_id'   => NULL,
      'result_allpart_one_leid_status_id'   => NULL,
      'result_allpart_one_kampkort'         => NULL,
      'result_allpart_one_deel_kampkort'    => NULL,
      'result_allpart_one_leid_kampkort'    => NULL,
      'result_allpart_pos_part_id'          => NULL,
      'result_allpart_pos_deel_part_id'     => NULL,
      'result_allpart_pos_leid_part_id'     => NULL,
      'result_allpart_pos_event_id'         => NULL,
      'result_allpart_pos_deel_event_id'    => NULL,
      'result_allpart_pos_leid_event_id'    => NULL,
      'result_allpart_pos_event_type_id'    => NULL,
      'result_allpart_pos_deel_event_type_id' => NULL,
      'result_allpart_pos_leid_event_type_id' => NULL,
      'result_allpart_pos_status_id'        => NULL,
      'result_allpart_pos_deel_status_id'   => NULL,
      'result_allpart_pos_leid_status_id'   => NULL,
      'result_allpart_pos_kampkort'         => NULL,
      'result_allpart_pos_deel_kampkort'    => NULL,
      'result_allpart_pos_leid_kampkort'    => NULL,
      'result_allpart_pos_leid_kampfunctie' => NULL,
      'result_allpart_pos_kampfunctie'      => NULL,
    ];
  }

  /** Minimale partditevent-array zonder actieve deelname. */
  private static function legePartditeventArray(int $contactId, string $displayname): array {
    return [
      'contact_id'          => $contactId,
      'displayname'         => $displayname,
      'id'                  => NULL,
      'event_id'            => NULL,
      'role_id'             => NULL,
      'status_id'           => NULL,
      'status_name'         => NULL,
      'register_date'       => NULL,
      'event_start_date'    => NULL,
      'event_end_date'      => NULL,
      'part_kampjaar'       => NULL,
      'kenmerken_kampnaam'  => NULL,
      'kenmerken_kampkort'  => NULL,
      'part_kampnaam'       => NULL,
      'part_kampkort'       => NULL,
      'part_functie'        => NULL,
      'part_rol'            => NULL,
      'part_leid_kamp'      => NULL,
      'part_leid_functie'   => NULL,
      'event_type_id'       => NULL,
      'event_type_label'    => NULL,
    ];
  }

  public function setUp(): void {
    parent::setUp();
    if (!function_exists('mee_civicrm_configure')) {
      $this->markTestSkipped('mee_civicrm_configure() niet beschikbaar; is nl.onvergetelijk.mee geïnstalleerd?');
    }
    if (!function_exists('base_find_allpart')) {
      $this->markTestSkipped('base_find_allpart() niet beschikbaar; is nl.onvergetelijk.base geïnstalleerd?');
    }
    if (!function_exists('get_event_types')) {
      $this->markTestSkipped('get_event_types() niet beschikbaar; is nl.onvergetelijk.base geïnstalleerd?');
    }
    if (!function_exists('find_partstatus')) {
      $this->markTestSkipped('find_partstatus() niet beschikbaar; is nl.onvergetelijk.partstatus geïnstalleerd?');
    }

    $this->contactId = $this->callAPISuccess('Contact', 'create', [
      'contact_type' => 'Individual',
      'first_name'   => 'Mee',
      'last_name'    => 'Testpersoon',
    ])['id'];
  }

  // ########################################################################
  // ### SCENARIO A: RETOURSTRUCTUUR (self-service)
  // ########################################################################

  /**
   * Nieuw contact zonder deelnames → retourarray bevat alle verwachte sleutels.
   */
  public function testRetourstructuurBevatAlleSleutels() {
    $result = mee_civicrm_configure($this->contactId);

    $this->assertIsArray($result, 'mee_civicrm_configure() moet een array teruggeven.');

    $verwachteSleutels = [
      // ditjaar deel
      'ditjaardeelyes', 'ditjaardeelnot', 'ditjaardeelmss', 'ditjaardeelstf', 'ditjaardeeltst', 'ditjaardeeltxt',
      // ditjaar leid
      'ditjaarleidyes', 'ditjaarleidnot', 'ditjaarleidmss', 'ditjaarleidstf', 'ditjaarleidtst', 'ditjaarleidtxt',
      // ditevent deel
      'diteventdeelyes', 'diteventdeelnot', 'diteventdeelmss', 'diteventdeelstf', 'diteventdeeltst', 'diteventdeeltxt',
      // ditevent leid
      'diteventleidyes', 'diteventleidnot', 'diteventleidmss', 'diteventleidstf', 'diteventleidtst', 'diteventleidtxt',
      // eventjaar
      'eventjaardeelyes', 'eventjaardeelnot', 'eventjaarleidyes', 'eventjaarleidnot',
      // meta
      'contact_id', 'displayname',
    ];

    foreach ($verwachteSleutels as $sleutel) {
      $this->assertArrayHasKey($sleutel, $result, "Sleutel '$sleutel' ontbreekt in de retourarray van mee_civicrm_configure().");
    }
  }

  // ########################################################################
  // ### SCENARIO B: TELLERS ZIJN INTEGERS (self-service)
  // ########################################################################

  /**
   * De yes/not/mss/stf/tst tellers zijn integer-waarden >= 0.
   */
  public function testTellersZijnIntegers() {
    $result  = mee_civicrm_configure($this->contactId);
    $tellers = [
      'ditjaardeelyes', 'ditjaardeelnot', 'ditjaardeelmss', 'ditjaardeelstf', 'ditjaardeeltst',
      'ditjaarleidyes', 'ditjaarleidnot', 'ditjaarleidmss', 'ditjaarleidstf', 'ditjaarleidtst',
      'diteventdeelyes', 'diteventdeelnot', 'diteventdeelmss', 'diteventdeelstf', 'diteventdeeltst',
      'diteventleidyes', 'diteventleidnot', 'diteventleidmss', 'diteventleidstf', 'diteventleidtst',
    ];

    foreach ($tellers as $sleutel) {
      $waarde = $result[$sleutel];
      $this->assertIsNumeric($waarde,           "Teller '$sleutel' moet numeriek zijn (is: $waarde).");
      $this->assertGreaterThanOrEqual(0, (int) $waarde, "Teller '$sleutel' mag niet negatief zijn.");
    }
  }

  // ########################################################################
  // ### SCENARIO C: TXT-VELDEN ZIJN GEEN OBJECTEN (self-service)
  // ########################################################################

  /**
   * De txt-velden zijn string, integer of NULL — nooit een object.
   */
  public function testTxtVeldenZijnGeenObjecten() {
    $result   = mee_civicrm_configure($this->contactId);
    $txtVelden = [
      'ditjaardeeltxt', 'ditjaarleidtxt',
      'diteventdeeltxt', 'diteventleidtxt',
      'eventjaardeelyes', 'eventjaarleidyes',
    ];

    foreach ($txtVelden as $sleutel) {
      $this->assertFalse(
        is_object($result[$sleutel] ?? NULL),
        "Sleutel '$sleutel' mag geen object zijn; verwacht string of null."
      );
    }
  }

  // ########################################################################
  // ### SCENARIO D: GEEN CONTACT_ID → GEEN CRASH
  // ########################################################################

  /**
   * contact_id = 0 → functie retourneert vroeg zonder crash.
   */
  public function testZonderContactIdGeenCrash() {
    $result = mee_civicrm_configure(0);
    $this->assertTrue(
      $result === NULL || is_array($result),
      'mee_civicrm_configure(0) mag geen exception gooien en moet NULL of array teruggeven.'
    );
  }

  // ########################################################################
  // ### SCENARIO E: CONTACT_ID IN RETOURARRAY KLOPT
  // ########################################################################

  /**
   * contact_id in de retourarray komt overeen met de invoer.
   */
  public function testContactIdInRetourArray() {
    $result = mee_civicrm_configure($this->contactId);
    $this->assertEquals(
      $this->contactId,
      $result['contact_id'],
      'contact_id in de retourarray moet overeenkomen met de invoer-contact_id.'
    );
  }

  // ########################################################################
  // ### SCENARIO F: DEEL EVENT + POSITIEVE STATUS → YES
  // ########################################################################

  /**
   * Directe modus: deelnemersevent met positieve status → diteventdeelyes=1, txt='YES'.
   *
   * We halen het eerste deel-event-type-id op uit get_event_types() en het
   * eerste positieve status-id uit find_partstatus(), zodat de test niet op
   * hard-coded IDs hoeft te vertrouwen.
   */
  public function testDeelEventPositieveStatusGeeftYes() {
    $eventtypes    = get_event_types();
    $partstatus    = find_partstatus();

    $deelEventTypes = $eventtypes['deel_all'] ?? [];
    $positiveIds    = array_values($partstatus['ids']['Positive'] ?? []);

    if (empty($deelEventTypes) || empty($positiveIds)) {
      $this->markTestSkipped('Geen deel-event-types of positieve statussen beschikbaar in de testomgeving.');
    }

    $allpart      = self::legeAllpartArray($this->contactId);
    $partditevent = self::legePartditeventArray($this->contactId, 'Mee Testpersoon');

    // Simuleer: contact zit op een deelnemersevent met positieve status
    $partditevent['event_type_id']  = $deelEventTypes[0];
    $partditevent['status_id']      = $positiveIds[0];
    $partditevent['part_rol']       = 'deelnemer';

    $status_array = ['status_id' => $positiveIds[0], 'status_label' => 'Confirmed'];

    $result = mee_civicrm_configure(
      $this->contactId,
      $allpart,
      $partditevent,
      $status_array
    );

    $this->assertIsArray($result,                    'Retourwaarde moet een array zijn.');
    $this->assertEquals(1, $result['diteventdeelyes'], 'diteventdeelyes moet 1 zijn bij positieve deelname.');
    $this->assertEquals(0, $result['diteventdeelnot'], 'diteventdeelnot moet 0 zijn bij positieve deelname.');
    $this->assertEquals(0, $result['diteventdeelmss'], 'diteventdeelmss moet 0 zijn bij positieve deelname.');
    $this->assertEquals('YES', $result['diteventdeeltxt'], "diteventdeeltxt moet 'YES' zijn bij positieve deelname.");
  }

  // ########################################################################
  // ### SCENARIO G: DEEL EVENT + NEGATIEVE STATUS → ANN
  // ########################################################################

  /**
   * Directe modus: deelnemersevent met negatieve status → diteventdeelnot=1, txt='ANN'.
   */
  public function testDeelEventNegatiefStatusGeeftAnn() {
    $eventtypes  = get_event_types();
    $partstatus  = find_partstatus();

    $deelEventTypes = $eventtypes['deel_all'] ?? [];
    $negativeIds    = array_values($partstatus['ids']['Negative'] ?? []);

    if (empty($deelEventTypes) || empty($negativeIds)) {
      $this->markTestSkipped('Geen deel-event-types of negatieve statussen beschikbaar in de testomgeving.');
    }

    $allpart      = self::legeAllpartArray($this->contactId);
    $partditevent = self::legePartditeventArray($this->contactId, 'Mee Testpersoon');

    $partditevent['event_type_id'] = $deelEventTypes[0];
    $partditevent['status_id']     = $negativeIds[0];
    $partditevent['part_rol']      = 'deelnemer';

    $status_array = ['status_id' => $negativeIds[0], 'status_label' => 'Cancelled'];

    $result = mee_civicrm_configure(
      $this->contactId,
      $allpart,
      $partditevent,
      $status_array
    );

    $this->assertIsArray($result,                     'Retourwaarde moet een array zijn.');
    $this->assertEquals(0, $result['diteventdeelyes'],  'diteventdeelyes moet 0 zijn bij negatieve deelname.');
    $this->assertEquals(1, $result['diteventdeelnot'],  'diteventdeelnot moet 1 zijn bij negatieve deelname.');
    $this->assertEquals('ANN', $result['diteventdeeltxt'], "diteventdeeltxt moet 'ANN' zijn bij negatieve deelname.");
  }

  // ########################################################################
  // ### SCENARIO H: DEEL EVENT + WACHTSTATUS → MSS
  // ########################################################################

  /**
   * Directe modus: deelnemersevent met wachtstatus → diteventdeelmss=1, txt='MSS'.
   */
  public function testDeelEventWachtstatusGeeftMss() {
    $eventtypes  = get_event_types();
    $partstatus  = find_partstatus();

    $deelEventTypes = $eventtypes['deel_all'] ?? [];
    $waitingIds     = array_values($partstatus['ids']['Waiting'] ?? []);

    if (empty($deelEventTypes) || empty($waitingIds)) {
      $this->markTestSkipped('Geen deel-event-types of wacht-statussen beschikbaar in de testomgeving.');
    }

    $allpart      = self::legeAllpartArray($this->contactId);
    $partditevent = self::legePartditeventArray($this->contactId, 'Mee Testpersoon');

    $partditevent['event_type_id'] = $deelEventTypes[0];
    $partditevent['status_id']     = $waitingIds[0];
    $partditevent['part_rol']      = 'deelnemer';

    $status_array = ['status_id' => $waitingIds[0], 'status_label' => 'Waiting'];

    $result = mee_civicrm_configure(
      $this->contactId,
      $allpart,
      $partditevent,
      $status_array
    );

    $this->assertIsArray($result,                    'Retourwaarde moet een array zijn.');
    $this->assertEquals(0, $result['diteventdeelyes'], 'diteventdeelyes moet 0 zijn op wachtlijst.');
    $this->assertEquals(0, $result['diteventdeelnot'], 'diteventdeelnot moet 0 zijn op wachtlijst.');
    $this->assertEquals(1, $result['diteventdeelmss'], 'diteventdeelmss moet 1 zijn op wachtlijst.');
    $this->assertEquals('MSS', $result['diteventdeeltxt'], "diteventdeeltxt moet 'MSS' zijn op wachtlijst.");
  }

  // ########################################################################
  // ### SCENARIO I: LEID EVENT + POSITIEVE STATUS → YES
  // ########################################################################

  /**
   * Directe modus: leidingsevent met positieve status → diteventleidyes=1, txt='YES'.
   */
  public function testLeidEventPositieveStatusGeeftYes() {
    $eventtypes  = get_event_types();
    $partstatus  = find_partstatus();

    $leidEventTypes = $eventtypes['leid_all'] ?? [];
    $positiveIds    = array_values($partstatus['ids']['Positive'] ?? []);

    if (empty($leidEventTypes) || empty($positiveIds)) {
      $this->markTestSkipped('Geen leid-event-types of positieve statussen beschikbaar in de testomgeving.');
    }

    $allpart      = self::legeAllpartArray($this->contactId);
    $partditevent = self::legePartditeventArray($this->contactId, 'Mee Testpersoon');

    $partditevent['event_type_id'] = $leidEventTypes[0];
    $partditevent['status_id']     = $positiveIds[0];
    $partditevent['part_rol']      = 'leiding';

    $status_array = ['status_id' => $positiveIds[0], 'status_label' => 'Confirmed'];

    $result = mee_civicrm_configure(
      $this->contactId,
      $allpart,
      $partditevent,
      $status_array
    );

    $this->assertIsArray($result,                    'Retourwaarde moet een array zijn.');
    $this->assertEquals(1, $result['diteventleidyes'], 'diteventleidyes moet 1 zijn bij positieve leidingsstatus.');
    $this->assertEquals(0, $result['diteventleidnot'], 'diteventleidnot moet 0 zijn bij positieve leidingsstatus.');
    $this->assertEquals('YES', $result['diteventleidtxt'], "diteventleidtxt moet 'YES' zijn bij positieve leidingsstatus.");
  }

  // ########################################################################
  // ### SCENARIO J: GEEN EVENT TYPE MATCH → NOT
  // ########################################################################

  /**
   * Directe modus: event_type_id dat niet in deel_all of leid_all zit →
   * diteventdeelyes=0, diteventdeelnot=1, txt='NOT'.
   */
  public function testOnbekendeEventTypeGeeftNot() {
    $allpart      = self::legeAllpartArray($this->contactId);
    $partditevent = self::legePartditeventArray($this->contactId, 'Mee Testpersoon');

    // 999999 bestaat bijna zeker niet als event-type-id
    $partditevent['event_type_id'] = 999999;
    $partditevent['status_id']     = NULL;

    $result = mee_civicrm_configure(
      $this->contactId,
      $allpart,
      $partditevent
    );

    $this->assertIsArray($result,                    'Retourwaarde moet een array zijn.');
    $this->assertEquals(0, $result['diteventdeelyes'], 'diteventdeelyes moet 0 zijn bij onbekend event-type.');
    $this->assertEquals(1, $result['diteventdeelnot'], 'diteventdeelnot moet 1 zijn bij onbekend event-type.');
    $this->assertEquals('NOT', $result['diteventdeeltxt'], "diteventdeeltxt moet 'NOT' zijn bij onbekend event-type.");
  }

  // ########################################################################
  // ### SCENARIO K: DITJAAR POS DEEL COUNT > 0 → DITJAARDEELYES
  // ########################################################################

  /**
   * Directe modus: allpart_array met pos_deel_count=1 en een positieve status →
   * ditjaardeelyes=1, ditjaardeeltxt='YES'.
   */
  public function testDitjaarPositiefDeelCountGeeftYes() {
    $partstatus  = find_partstatus();
    $positiveIds = array_values($partstatus['ids']['Positive'] ?? []);

    if (empty($positiveIds)) {
      $this->markTestSkipped('Geen positieve statussen beschikbaar in de testomgeving.');
    }

    $eventtypes     = get_event_types();
    $deelEventTypes = $eventtypes['deel'] ?? [];

    if (empty($deelEventTypes)) {
      $this->markTestSkipped('Geen deel-event-types beschikbaar in de testomgeving.');
    }

    $allpart = self::legeAllpartArray($this->contactId);

    // Simuleer: er is één positieve deelname dit jaar
    $allpart['result_allpart_pos_deel_count']          = 1;
    $allpart['result_allpart_pos_count']               = 1;
    $allpart['result_allpart_pos_deel_status_id'] = $positiveIds[0];
    $allpart['result_allpart_pos_deel_event_type_id']  = $deelEventTypes[0];

    $partditevent = self::legePartditeventArray($this->contactId, 'Mee Testpersoon');

    $result = mee_civicrm_configure(
      $this->contactId,
      $allpart,
      $partditevent
    );

    $this->assertIsArray($result,                      'Retourwaarde moet een array zijn.');
    $this->assertEquals(1, $result['ditjaardeelyes'],  'ditjaardeelyes moet 1 zijn als er een positieve deelname is.');
    $this->assertEquals(0, $result['ditjaardeelnot'],  'ditjaardeelnot moet 0 zijn als er een positieve deelname is.');
    $this->assertEquals('YES', $result['ditjaardeeltxt'], "ditjaardeeltxt moet 'YES' zijn als er een positieve deelname is.");
  }

  // ########################################################################
  // ### SCENARIO L: DITJAAR NEGATIEVE DEELNAME → ANN
  // ########################################################################

  /**
   * Directe modus: allpart_array met één negatieve deelname (geen positief) →
   * ditjaardeeltxt='ANN'.
   */
  public function testDitjaarNegatiefDeelGeeftAnn() {
    $partstatus  = find_partstatus();
    $negativeIds = array_values($partstatus['ids']['Negative'] ?? []);

    if (empty($negativeIds)) {
      $this->markTestSkipped('Geen negatieve statussen beschikbaar in de testomgeving.');
    }

    $allpart = self::legeAllpartArray($this->contactId);

    // Simuleer: geen positieve deelname, maar wel een geannuleerde
    $allpart['result_allpart_pos_deel_count']           = 0;
    $allpart['result_allpart_one_deel_status_id']  = $negativeIds[0];

    $partditevent = self::legePartditeventArray($this->contactId, 'Mee Testpersoon');

    $result = mee_civicrm_configure(
      $this->contactId,
      $allpart,
      $partditevent
    );

    $this->assertIsArray($result,                      'Retourwaarde moet een array zijn.');
    $this->assertEquals(0, $result['ditjaardeelyes'],  'ditjaardeelyes moet 0 zijn bij een geannuleerde deelname.');
    $this->assertEquals(1, $result['ditjaardeelnot'],  'ditjaardeelnot moet 1 zijn bij een geannuleerde deelname.');
    $this->assertEquals('ANN', $result['ditjaardeeltxt'], "ditjaardeeltxt moet 'ANN' zijn bij een geannuleerde deelname.");
  }

  // ########################################################################
  // ### SCENARIO M: DITJAAR LEIDING MET STAF-FUNCTIE → STF
  // ########################################################################

  /**
   * Directe modus: leiding met positieve inschrijving maar staf-functie →
   * ditjaarleidstf=1, ditjaarleidyes=0, txt='STF'.
   *
   * Staf-functies die dit gedrag triggeren: 'bestuurslid', 'kampstaf'.
   */
  public function testDitjaarLeidingStafFunctieGeeftStf() {
    $partstatus  = find_partstatus();
    $positiveIds = array_values($partstatus['ids']['Positive'] ?? []);

    if (empty($positiveIds)) {
      $this->markTestSkipped('Geen positieve statussen beschikbaar in de testomgeving.');
    }

    $eventtypes     = get_event_types();
    $leidEventTypes = $eventtypes['leid'] ?? [];

    if (empty($leidEventTypes)) {
      $this->markTestSkipped('Geen leid-event-types beschikbaar in de testomgeving.');
    }

    $allpart = self::legeAllpartArray($this->contactId);

    // Simuleer: positieve leiding-inschrijving maar als bestuurslid
    $allpart['result_allpart_pos_leid_count']           = 1;
    $allpart['result_allpart_pos_count']                = 1;
    $allpart['result_allpart_pos_leid_status_id']  = $positiveIds[0];
    $allpart['result_allpart_pos_leid_event_type_id']   = $leidEventTypes[0];
    $allpart['result_allpart_pos_leid_kampfunctie']     = 'bestuurslid'; // staf-functie

    $partditevent = self::legePartditeventArray($this->contactId, 'Mee Testpersoon');

    $result = mee_civicrm_configure(
      $this->contactId,
      $allpart,
      $partditevent
    );

    $this->assertIsArray($result,                      'Retourwaarde moet een array zijn.');
    $this->assertEquals(0, $result['ditjaarleidyes'],  'ditjaarleidyes moet 0 zijn voor een bestuurslid (staf).');
    $this->assertEquals(1, $result['ditjaarleidstf'],  'ditjaarleidstf moet 1 zijn voor een bestuurslid (staf).');
    $this->assertEquals('STF', $result['ditjaarleidtxt'], "ditjaarleidtxt moet 'STF' zijn voor een bestuurslid.");
  }

}
