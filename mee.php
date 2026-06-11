<?php

/**
 * =======================================================================================
 * FUNCTIE-INDEX: mee.php
 * =======================================================================================
 *   mee_civicrm_configure()
 *   mee_civicrm_config()     Implements hook_civicrm_config().
 *   mee_civicrm_install()    Implements hook_civicrm_install().
 *   mee_civicrm_enable()     Implements hook_civicrm_enable().
 * =======================================================================================
 */

require_once 'mee.civix.php';

use CRM_Mee_ExtensionUtil as E;

function mee_civicrm_configure($contact_id, $allpart_array = NULL, $array_partditevent = NULL, $array_status  = NULL, $array_criteria = NULL) {

    $extdebug = 'mee.configure'; // Kanaal voor centrale debug-config; niveau wordt opgezocht in ozk.debug.config.php
    $apidebug = FALSE;

    $mee_configure_start = microtime(TRUE);
    watchdog('civicrm_timing', base_microtimer("START mee_configure [CID: $contact_id]"), NULL, WATCHDOG_DEBUG);

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,1, "### MEE 0.X - GAAT DEZE PERSOON MEE ALS DEEL OF LEID?",         "[START]");
    wachthond($extdebug,2, "########################################################################");

    if ($contact_id && (empty($allpart_array) || empty($array_partditevent))) {
        
        wachthond($extdebug,3, "########################################################################");
        wachthond($extdebug,3, "### MEE 0.1 SELF-SERVICE MODUS: DATA OPHALEN INDIEN ALLEEN ID IS MEEGEGEVEN");
        wachthond($extdebug,3, "########################################################################");

        // A. Check dependencies
        if (!function_exists('base_find_allpart') || !function_exists('base_pid2part') || !function_exists('base_cid2cont')) {
            wachthond($extdebug, 1, "CRITICAL ERROR: Base functies niet gevonden!");
            return;
        }
        // 1. Zorg dat we statussen hebben
        if (function_exists('find_partstatus')) {
            find_partstatus();
        }
        // 2. Haal ALLPART op
        if (empty($allpart_array)) {
            $allpart_array = base_find_allpart($contact_id, date("Y-m-d")) ?: [];
        }
        // 3. Haal PARTDITEVENT op
        if (empty($array_partditevent)) {
            $pid = $allpart_array['result_allpart_pos_part_id'] 
                ?? $allpart_array['result_allpart_pen_part_id']
                ?? $allpart_array['result_allpart_wait_part_id']
                ?? $allpart_array['result_allpart_one_part_id']
                ?? NULL;
            if ($pid) {
                $array_partditevent = base_pid2part($pid);
            } else {
                $array_partditevent = [];
            }
        }

        // BELANGRIJK: gebruik NULL (niet 0) als startwaarde, zodat partstatus_criteria()
        // de fallback-berekening via birth_date + event_start_date kan activeren
        // wanneer leeftijd_nextkamp_deci niet beschikbaar is. Met 0 mislukt de === NULL check.
        $leeftijd_ditevent_decimalen = NULL;
        $contact_data = base_cid2cont($contact_id);
        if (!empty($contact_data['leeftijd_nextkamp_deci'])) {
            $leeftijd_ditevent_decimalen = $contact_data['leeftijd_nextkamp_deci'];
            wachthond($extdebug, 4, "Self-Service Leeftijd (cid2cont)", $leeftijd_ditevent_decimalen);
        } else {
            wachthond($extdebug, 3, "leeftijd_nextkamp_deci niet beschikbaar. Fallback via birth_date in partstatus_criteria.", "[NULL]");
        }
        // 4. Construeer CRITERIA array (Let op: Nieuwe functienaam en parameters!)
        if (empty($array_criteria) && function_exists('partstatus_criteria')) {
            $array_criteria = partstatus_criteria($pid, $array_partditevent, $leeftijd_ditevent_decimalen);
            wachthond($extdebug, 4, "Criteria berekend via partstatus_criteria",      $array_criteria);
        }

        // 5. Construeer STATUS array (De executor in helpers.php)
        if (empty($array_status) && function_exists('partstatus_configure')) {
            $array_status   = partstatus_configure($pid, $array_partditevent, $array_criteria);
            wachthond($extdebug, 4, "Status geconfigureerd via partstatus_configure", $array_status);
        }
    }

    wachthond($extdebug,3, 'array_partditevent',    $array_partditevent);
    wachthond($extdebug,3, 'array_status',          $array_status);
    wachthond($extdebug,3, 'array_criteria',        $array_criteria);

    // -------------------------------------------------------------------------
    // EINDE SELF-SERVICE MODUS
    // -------------------------------------------------------------------------

    $array_criteria_ditevent= $array_criteria;
    $array_status_ditevent  = $array_status;

    // We halen de config op uit base.php via de nieuwe functienaam
    $eventtypes             = get_event_types();

    // 1. Map de basis types naar lokale variabelen
    $eventtypesdeel         = $eventtypes['deel'];
    $eventtypesdeeltop      = $eventtypes['deeltop'];
    $eventtypesleid         = $eventtypes['leid'];
    $eventtypesmeet         = $eventtypes['meet'];
    
    // 2. Map de test types
    $eventtypesdeeltest     = $eventtypes['deeltest'];
    $eventtypesleidtest     = $eventtypes['leidtest'];
    $eventtypesdeeltoptest  = $eventtypes['toptest']; // Let op mapping: 'toptest' -> 'deeltoptest'

    // 3. Map de gecombineerde lijsten (worden veel gebruikt in je logica)
    $eventtypesprod         = $eventtypes['prod'];
    $eventtypestest         = $eventtypes['test'];
    $eventtypesall          = $eventtypes['all'];

    $eventtypesdeelall      = $eventtypes['deel_all'];
    $eventtypesleidall      = $eventtypes['leid_all'];

    $today_kampjaar         = find_fiscalyear()['today_jaar'] ?? NULL;
    wachthond($extdebug,4, 'today_kampjaar',        $today_kampjaar);

    wachthond($extdebug,3, "########################################################################");
    wachthond($extdebug,3, "### MEE 0.2 VIND GECONFIGUREERDE DEELNAME STATUSSEN (POS/NEG)");
    wachthond($extdebug,3, "########################################################################");

    // 1. Haal alles op in één keer (Cache of Vers vanuit base.php)
    $partstatus_data        = find_partstatus();
    wachthond($extdebug,3, 'partstatus_data',  $partstatus_data);

    // 2. Wijs toe aan de lokale variabelen die je script verderop gebruikt
    $status_positive        = $partstatus_data['ids']['Positive'] ?? [];       //  BETEKENT DEELNAME = YES
    $status_pending         = $partstatus_data['ids']['Pending']  ?? [];       //  WACHT OP BEVESTIGING (VAN DE OUDERS)    Pending
    $status_waiting         = $partstatus_data['ids']['Waiting']  ?? [];       //  WACHT OP PLEK                           Waiting
    $status_negative        = $partstatus_data['ids']['Negative'] ?? [];       //  BETEKENT DEELNAME = NO (GECANCELED, AFGEKEURD OF OVERGEDRAGEN)

    wachthond($extdebug,4, 'statusids_positive',  $status_positive);
    wachthond($extdebug,4, 'statusids_pending',   $status_pending);
    wachthond($extdebug,4, 'statusids_waiting',   $status_waiting);
    wachthond($extdebug,4, 'statusids_negative',  $status_negative);

    wachthond($extdebug,4, "########################################################################");
    wachthond($extdebug,2, "### MEE 0.3 - GET VALUES FROM array_partditevent");
    wachthond($extdebug,2, "########################################################################");

    $displayname                            = $array_partditevent['displayname']                        ?? NULL;
    $contact_id                             = $array_partditevent['contact_id']                         ?? $contact_id;

    $ditevent_part_contact_id               = $array_partditevent['contact_id']                         ?? NULL;
    $ditevent_part_eventid                  = $array_partditevent['event_id']                           ?? NULL;
    $ditevent_part_id                       = $array_partditevent['id']                                 ?? NULL;
    $ditevent_part_role_id                  = $array_partditevent['role_id']                            ?? NULL;
    $ditevent_part_status_id                = $array_partditevent['status_id']                          ?? NULL;
    $ditevent_part_status_name              = $array_partditevent['status_name']                        ?? NULL;

    $ditevent_register_date                 = $array_partditevent['register_date']                      ?? NULL;
    $ditevent_event_start                   = $array_partditevent['event_start_date']                   ?? NULL;
    $ditevent_event_einde                   = $array_partditevent['event_end_date']                     ?? NULL;
    $ditevent_kampjaar                      = $array_partditevent['part_kampjaar']                      ?? NULL;

    $ditevent_event_kampnaam                = $array_partditevent['kenmerken_kampnaam']                 ?? NULL;
    $ditevent_event_kampkort                = $array_partditevent['kenmerken_kampkort']                 ?? NULL;

    $ditevent_part_kampnaam                 = $array_partditevent['part_kampnaam']                      ?? NULL;
    $ditevent_part_kampkort                 = $array_partditevent['part_kampkort']                      ?? NULL;
    $ditevent_part_functie                  = $array_partditevent['part_functie']                       ?? NULL;
    $ditevent_part_rol                      = $array_partditevent['part_rol']                           ?? NULL;

    $ditevent_leid_welkkamp                 = $array_partditevent['part_leid_kamp']                     ?? NULL;
    $ditevent_leid_functie                  = $array_partditevent['part_leid_functie']                  ?? NULL;

    $ditevent_event_type_id                 = $array_partditevent['event_type_id']                      ?? NULL;
    $ditevent_event_type_label              = $array_partditevent['event_type_label']                   ?? NULL;

    wachthond($extdebug,3, 'displayname',                           $displayname);
    wachthond($extdebug,3, 'contact_id',                            $contact_id);
    wachthond($extdebug,3, 'ditevent_contact_id',                   $ditevent_part_contact_id);

    wachthond($extdebug,3, 'ditevent_event_kampnaam',               $ditevent_event_kampnaam);
    wachthond($extdebug,3, 'ditevent_event_kampkort',               $ditevent_event_kampkort);
    wachthond($extdebug,3, 'ditevent_part_kampnaam',                $ditevent_part_kampnaam);
    wachthond($extdebug,3, 'ditevent_part_kampkort',                $ditevent_part_kampkort);

    wachthond($extdebug,3, 'ditevent_register_date',                $ditevent_register_date);
    wachthond($extdebug,3, 'ditevent_event_start',                  $ditevent_event_start);
    wachthond($extdebug,3, 'ditevent_event_einde',                  $ditevent_event_einde);
    wachthond($extdebug,3, 'ditevent_kampjaar',                     $ditevent_kampjaar);    

    wachthond($extdebug,3, 'ditevent_part_id',                      $ditevent_part_id);
    wachthond($extdebug,2, 'ditevent_part_eventid',                 $ditevent_part_eventid);
    wachthond($extdebug,3, 'ditevent_part_status_id',               $ditevent_part_status_id);
    wachthond($extdebug,3, 'ditevent_part_status_name',             $ditevent_part_status_name);

    wachthond($extdebug,3, 'ditevent_part_functie',                 $ditevent_part_functie);
    wachthond($extdebug,3, 'ditevent_part_rol',                     $ditevent_part_rol);
    wachthond($extdebug,3, 'ditevent_leid_welkkamp',                $ditevent_leid_welkkamp);
    wachthond($extdebug,3, 'ditevent_leid_functie',                 $ditevent_leid_functie);    

    wachthond($extdebug,3, 'ditevent_event_type_id',                $ditevent_event_type_id);
    wachthond($extdebug,3, 'ditevent_event_type_label',             $ditevent_event_type_label);    

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,2, "### MEE 0.4 - GET VALUES FROM array_allpart_eventjaar",    $allpart_array);
    wachthond($extdebug,3, "########################################################################");

    $eventjaar_refdate                  = $allpart_array['refdate'];
    $eventjaar_refyear                  = $allpart_array['refyear'];

    $eventjaar_all_count                = $allpart_array['result_allpart_all_count'];
    $eventjaar_pen_count                = $allpart_array['result_allpart_pen_count'];
    $eventjaar_wait_count               = $allpart_array['result_allpart_wait_count'];
    $eventjaar_neg_count                = $allpart_array['result_allpart_neg_count'];

    $eventjaar_pos_count                = $allpart_array['result_allpart_pos_count'];
    $eventjaar_pos_deel_count           = $allpart_array['result_allpart_pos_deel_count'];
    $eventjaar_pos_leid_count           = $allpart_array['result_allpart_pos_leid_count'];

    $eventjaar_all_deel_count           = $allpart_array['result_allpart_all_deel_count'];
    $eventjaar_all_leid_count           = $allpart_array['result_allpart_all_leid_count'];

    $eventjaar_one_part_id              = $allpart_array['result_allpart_one_part_id'];
    $eventjaar_one_deel_part_id         = $allpart_array['result_allpart_one_deel_part_id'];
    $eventjaar_one_leid_part_id         = $allpart_array['result_allpart_one_leid_part_id'];

    $eventjaar_one_event_id             = $allpart_array['result_allpart_one_event_id'];
    $eventjaar_one_deel_event_id        = $allpart_array['result_allpart_one_deel_event_id'];
    $eventjaar_one_leid_event_id        = $allpart_array['result_allpart_one_leid_event_id'];

    $eventjaar_one_event_type_id        = $allpart_array['result_allpart_one_event_type_id'];
    $eventjaar_one_deel_event_type_id   = $allpart_array['result_allpart_one_deel_event_type_id'];
    $eventjaar_one_leid_event_type_id   = $allpart_array['result_allpart_one_leid_event_type_id'];

    $eventjaar_one_part_status_id       = $allpart_array['result_allpart_one_part_status_id']       ?? NULL;
    $eventjaar_one_deel_part_status_id  = $allpart_array['result_allpart_one_deel_part_status_id']  ?? NULL;
    $eventjaar_one_leid_part_status_id  = $allpart_array['result_allpart_one_leid_part_status_id']  ?? NULL;

    $eventjaar_one_kampkort             = $allpart_array['result_allpart_one_kampkort'];
    $eventjaar_one_deel_kampkort        = $allpart_array['result_allpart_one_deel_kampkort'];
    $eventjaar_one_leid_kampkort        = $allpart_array['result_allpart_one_leid_kampkort'];

    $eventjaar_pos_part_id              = $allpart_array['result_allpart_pos_part_id'];
    $eventjaar_pos_deel_part_id         = $allpart_array['result_allpart_pos_deel_part_id'];
    $eventjaar_pos_leid_part_id         = $allpart_array['result_allpart_pos_leid_part_id'];

    $eventjaar_pos_event_id             = $allpart_array['result_allpart_pos_event_id'];
    $eventjaar_pos_deel_event_id        = $allpart_array['result_allpart_pos_deel_event_id'];
    $eventjaar_pos_leid_event_id        = $allpart_array['result_allpart_pos_leid_event_id'];

    $eventjaar_pos_event_type_id        = $allpart_array['result_allpart_pos_event_type_id'];
    $eventjaar_pos_deel_event_type_id   = $allpart_array['result_allpart_pos_deel_event_type_id'];
    $eventjaar_pos_leid_event_type_id   = $allpart_array['result_allpart_pos_leid_event_type_id'];

    $eventjaar_pos_part_status_id       = $allpart_array['result_allpart_pos_part_status_id']       ?? NULL;
    $eventjaar_pos_deel_part_status_id  = $allpart_array['result_allpart_pos_deel_part_status_id']  ?? NULL;
    $eventjaar_pos_leid_part_status_id  = $allpart_array['result_allpart_pos_leid_part_status_id']  ?? NULL;

    $eventjaar_pos_kampkort             = $allpart_array['result_allpart_pos_kampkort'];
    $eventjaar_pos_deel_kampkort        = $allpart_array['result_allpart_pos_deel_kampkort'];
    $eventjaar_pos_leid_kampkort        = $allpart_array['result_allpart_pos_leid_kampkort'];
    $eventjaar_pos_leid_functie         = $allpart_array['result_allpart_pos_leid_kampfunctie']; 

    // --- HIER MISTE DE TOEWIJZING ---
    // Koppel de opgehaalde eventjaar-tellers aan de ditjaar-variabelen voor de logica
    $ditjaar_pos_deel_count             = $eventjaar_pos_deel_count;
    $ditjaar_pos_leid_count             = $eventjaar_pos_leid_count;
    
    $ditjaar_all_deel_count             = $eventjaar_all_deel_count;
    $ditjaar_all_leid_count             = $eventjaar_all_leid_count;

    // Ook de status ID's doorzetten voor de fall-back logica (one_id)
    $ditjaar_one_deel_part_status_id    = $eventjaar_one_deel_part_status_id;
    $ditjaar_one_leid_part_status_id    = $eventjaar_one_leid_part_status_id;
    
    $ditjaar_one_deel_event_type_id     = $eventjaar_one_deel_event_type_id;
    $ditjaar_one_leid_event_type_id     = $eventjaar_one_leid_event_type_id;
    
    $ditjaar_leid_welkkamp              = $eventjaar_pos_leid_kampkort; // Alias voor oudere checks

    // --- EINDE TOEWIJZING ---

    wachthond($extdebug,2, "########################################################################");   
    wachthond($extdebug,1, "### MEE 0.6 - GET VALUES FROM array_criteria_ditevent", $array_criteria_ditevent);
    wachthond($extdebug,2, "########################################################################");   

    if ($ditevent_part_rol == 'deelnemer') {

        $ditevent_criteria_leeftijd         = $array_criteria_ditevent['criteria_leeftijd']    ?? NULL;
        $ditevent_criteria_school           = $array_criteria_ditevent['criteria_school']      ?? NULL;
        $ditevent_criteria_indicatie        = $array_criteria_ditevent['criteria_indicatie']   ?? NULL;
        $ditevent_criteria_oordeel          = $array_criteria_ditevent['criteria_oordeel']     ?? NULL;

        wachthond($extdebug,3, 'ditevent_criteria_leeftijd',        $ditevent_criteria_leeftijd);
        wachthond($extdebug,3, 'ditevent_criteria_school',          $ditevent_criteria_school);
        wachthond($extdebug,3, 'ditevent_criteria_indicatie',       $ditevent_criteria_indicatie);
        wachthond($extdebug,3, 'ditevent_criteria_oordeel',         $ditevent_criteria_oordeel);
    }

    wachthond($extdebug,2, "########################################################################");   
    wachthond($extdebug,1, "### MEE 0.7 - GET VALUES FROM array_status_ditevent", $array_status_ditevent);
    wachthond($extdebug,2, "########################################################################");   

    $ditevent_part_status_id            = $array_status_ditevent['status_id']           ?? NULL;
    $ditevent_part_status_name          = $array_status_ditevent['status_label']        ?? NULL;
    $ditevent_deelnamestatus            = $array_status_ditevent['status_label']        ?? NULL;

    wachthond($extdebug,3, 'ditevent_part_status_id',           $ditevent_part_status_id);
    wachthond($extdebug,3, 'ditevent_part_status_name',         $ditevent_part_status_name);
    wachthond($extdebug,3, 'ditevent_deelnamestatus',           $ditevent_deelnamestatus);

/*
    if ($ditevent_part_rol == 'deelnemer') {
        $ditevent_criteriacheck_start   = $array_status_ditevent['criteriacheck_start'];
        $ditevent_criteriacheck_einde   = $array_status_ditevent['criteriacheck_einde'];
        $ditevent_wachtlijst_erop       = $array_status_ditevent['wachtlijst_erop'];
        $ditevent_wachtlijst_eraf       = $array_status_ditevent['wachtlijst_eraf'];  
    }

    if ($ditevent_part_rol == 'deelnemer') {
        wachthond($extdebug,3, 'ditevent_criteriacheck_start',  $ditevent_criteriacheck_start);
        wachthond($extdebug,3, 'ditevent_criteriacheck_einde',  $ditevent_criteriacheck_einde);
        wachthond($extdebug,3, 'ditevent_wachtlijst_erop',      $ditevent_wachtlijst_erop);
        wachthond($extdebug,3, 'ditevent_wachtlijst_eraf',      $ditevent_wachtlijst_eraf);
    }
*/
    ###########################################################################################
    ### HIERONDER VANUIT CV EXT
    ###########################################################################################

    wachthond($extdebug,4, "########################################################################");
    wachthond($extdebug,4, 'statusids_positive',                    $status_positive);
    wachthond($extdebug,4, 'statusids_pending',                     $status_pending);
    wachthond($extdebug,4, 'statusids_waiting',                     $status_waiting);
    wachthond($extdebug,4, 'statusids_negative',                    $status_negative);

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,2, "### MEE 1.1 CHECK OF $displayname DIT EVENT MEEGAAT", "[DEEL $ditevent_event_kampkort]");
    wachthond($extdebug,3, "########################################################################");   

    $diteventdeelyes  = 3;
    $diteventdeelnot  = 3;
    $diteventdeelmss  = 3;
    $diteventdeeltop  = 3;
    $diteventdeeltst  = 3;
    $diteventdeelstf  = 0;
    $diteventdeeltxt  = 'ERR';

    wachthond($extdebug,3, 'eventtypesdeelall',             $eventtypesdeelall);
    wachthond($extdebug,3, 'ditevent_event_type_id',        $ditevent_event_type_id);
    wachthond($extdebug,3, 'status_positive',               $status_positive);
    wachthond($extdebug,3, 'ditevent_part_status_id',       $ditevent_part_status_id);

    if (in_array($ditevent_event_type_id, $eventtypesdeelall)) {

        # ZET BIJ DEELNEMER EVENT DE STATUS VOOR LEID OP NOT 
        $diteventleidyes = 0;
        $diteventleidnot = 1;
        $diteventleidmss = 0;
        $diteventleidtst = 0;
        $diteventleidstf = 0;
        $diteventleidtxt = 'NOT';
    
        if (       in_array($ditevent_part_status_id, array_values($status_positive)) ) {
            $diteventdeelyes = 1;
            $diteventdeelnot = 0;
            $diteventdeelmss = 0;
            $diteventdeeltxt = 'YES';

        } elseif ( in_array($ditevent_part_status_id, array_values($status_negative)) ) {    
            $diteventdeelyes = 0;
            $diteventdeelnot = 1;
            $diteventdeelmss = 0;
            $diteventdeeltxt = 'ANN';

        } elseif ( in_array($ditevent_part_status_id, array_values($status_waiting)) ) {
            $diteventdeelyes = 0;
            $diteventdeelnot = 0;
            $diteventdeelmss = 1;
            $diteventdeeltxt = 'MSS';

        } elseif (!in_array($ditevent_part_status_id, array_values($status_positive))) {
            $diteventdeelyes = 0;
            $diteventdeelnot = 1;
            $diteventdeelmss = 0;
            $diteventdeeltxt = 'NOT';
        } else {
            $diteventdeeltxt = 'NOP';
        }

    } else {

            $diteventdeelyes = 0;
            $diteventdeelnot = 1;
            $diteventdeelmss = 0;
            $diteventdeeltxt = 'NOT';

            wachthond($extdebug,2, 'eventtypesleidall',             $eventtypesleidall);
            wachthond($extdebug,2, 'ditevent_event_type_id',        $ditevent_event_type_id);
            wachthond($extdebug,2, 'status_positive',               $status_positive);
            wachthond($extdebug,2, 'ditevent_part_status_id',       $ditevent_part_status_id);
    }

    wachthond($extdebug,3, 'diteventdeelyes 0', $diteventdeelyes);
    wachthond($extdebug,3, 'diteventdeelnot 0', $diteventdeelnot);
    wachthond($extdebug,3, 'diteventdeelmss 0', $diteventdeelmss);
    wachthond($extdebug,3, 'diteventdeeltxt 0', $diteventdeeltxt);

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,2, "### MEE 1.2 CHECK OF $displayname DIT EVENT MEEGAAT", "[TOP $ditevent_event_kampkort]");
    wachthond($extdebug,3, "########################################################################");   

    if (in_array($ditevent_event_type_id, $eventtypesdeeltop)) {
        $diteventdeeltop = 1;
        wachthond($extdebug,2, 'BETREFT EEN AANMELDING DIT EVENT DEEL VOOR HET TOPKAMP:', $ditevent_event_kampkort);     
    } else {
        $diteventdeeltop = 0;
    }

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,2, "### MEE 1.3 CHECK OF $displayname DIT EVENT MEEGAAT", "[DEEL TST $ditevent_event_kampkort]");
    wachthond($extdebug,3, "########################################################################");   

    if (in_array($ditevent_event_type_id, $eventtypesdeeltest)) {
        $diteventdeeltst = 1;
        wachthond($extdebug,2, 'BETREFT EEN AANMELDING DIT EVENT DEEL VOOR EEN TEST EVENT:', $ditevent_event_kampkort);
    } else {
        $diteventdeeltst = 0;
    }

    wachthond($extdebug,2, "DITEVENT GAAT $displayname $diteventdeeltxt MEE ALS DEELNEMER", "[EID $ditevent_part_eventid / TYPE $ditevent_event_type_id]");

    wachthond($extdebug,3, 'diteventdeelyes F',  $diteventdeelyes);
    wachthond($extdebug,3, 'diteventdeelnot F',  $diteventdeelnot);
    wachthond($extdebug,3, 'diteventdeelmss F',  $diteventdeelmss);
    wachthond($extdebug,3, 'diteventdeeltop F',  $diteventdeeltop);
    wachthond($extdebug,3, 'diteventdeeltst F',  $diteventdeeltst);
    wachthond($extdebug,3, 'diteventdeeltxt F',  $diteventdeeltxt);

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,2, "### MEE 2.1 CHECK OF $displayname DIT EVENT MEEGAAT", "[LEID $ditevent_event_kampkort]");
    wachthond($extdebug,3, "########################################################################");   

    $diteventleidyes = 3;
    $diteventleidnot = 3;
    $diteventleidmss = 3;
    $diteventleidtst = 3;
    $diteventleidstf = 3;
    $diteventleidtxt = 'ERR';

    wachthond($extdebug,3, 'eventtypesleidall',         $eventtypesleidall);
    wachthond($extdebug,3, 'ditevent_event_type_id',    $ditevent_event_type_id);

    if (in_array($ditevent_event_type_id, $eventtypesleidall)) {

        # ZET BIJ LEIDING EVENT DE STATUS VOOR DEEL OP NOT 
        $diteventdeelyes = 0;
        $diteventdeelnot = 1;
        $diteventdeelmss = 0;
        $diteventdeeltst = 0;
        $diteventdeeltxt = 'NOT';

        if (       in_array($ditevent_part_status_id, array_values($status_positive)) )     {
            $diteventleidyes = 1;
            $diteventleidnot = 0;
            $diteventleidmss = 0;
            $diteventleidtxt = 'YES';

        } elseif ( in_array($ditevent_part_status_id, array_values($status_negative)) )     {

            $diteventleidyes = 0;
            $diteventleidnot = 1;
            $diteventleidmss = 0;
            $diteventleidtxt = 'ANN';

        } elseif ( in_array($ditevent_part_status_id, array_values($status_waiting)) )      {

            $diteventleidyes = 0;
            $diteventleidnot = 0;
            $diteventleidmss = 1;
            $diteventleidtxt = 'MSS';

        } else {
            $diteventleidtxt = 'NOP';

            wachthond($extdebug,3, 'eventtypesleidall',             $eventtypesleidall);
            wachthond($extdebug,3, 'ditevent_event_type_id',        $ditevent_event_type_id);
            wachthond($extdebug,3, 'status_positive',               $status_positive);
            wachthond($extdebug,3, 'ditevent_part_status_id',       $ditevent_part_status_id);
        }

    } else {
            $diteventleidyes = 0;
            $diteventleidnot = 1;
            $diteventleidmss = 0;
            $diteventleidtxt = 'NOT';

            wachthond($extdebug,3, 'eventtypesleidall',             $eventtypesleidall);
            wachthond($extdebug,3, 'ditevent_event_type_id',        $ditevent_event_type_id);
            wachthond($extdebug,3, 'status_positive',               $status_positive);
            wachthond($extdebug,3, 'ditevent_part_status_id',       $ditevent_part_status_id);
    }

    wachthond($extdebug,3, 'diteventleidyes 0', $diteventleidyes);
    wachthond($extdebug,3, 'diteventleidnot 0', $diteventleidnot);
    wachthond($extdebug,3, 'diteventleidmss 0', $diteventleidmss);
    wachthond($extdebug,3, 'diteventleidtxt 0', $diteventleidtxt);

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,2, "### MEE 2.2 CHECK OF $displayname DIT EVENT MEEGAAT",           "[WAARNODIG]");
    wachthond($extdebug,3, "########################################################################");   

    if (in_array($ditevent_leid_welkkamp, array("waarnodig"))) {
        $diteventleidyes = 0;
        $diteventleidmss = 1;
        $diteventleidstf = 0;
        $diteventleidtxt = 'MSS';
        wachthond($extdebug,2, 'BETREFT EEN AANMELDING DIT EVENT LEID WAAR NODIG:',     "ditevent_leid_welkkamp: $ditevent_leid_welkkamp");   
    } else {
        $diteventleidstf = 0;     
    }

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,2, "### MEE 2.3 CHECK OF $displayname DIT EVENT MEEGAAT", "[STAF $ditevent_event_kampkort]");
    wachthond($extdebug,3, "########################################################################");   

    // Welkkamp OR functie levert staf — één gecombineerde check zodat de else niet
    // het resultaat van de eerste check overschrijft als de tweede niet voldoet.
    if (in_array($ditevent_leid_welkkamp, array("bestuurstaken", "kampstaf")) || in_array($ditevent_leid_functie, array("bestuurslid", "kampstaf"))) {
        $diteventleidyes = 0;
        $diteventleidstf = 1;
        $diteventleidtxt = 'NOT';
        wachthond($extdebug,2, 'BETREFT EEN AANMELDING DIT EVENT LEID VOOR STAFTAKEN:', "welkkamp=$ditevent_leid_welkkamp / functie=$ditevent_leid_functie");
    } else {
        $diteventleidstf = 0;
    }

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,2, "### MEE 2.4 CHECK OF $displayname DIT EVENT MEEGAAT", "[LEID TST $ditevent_event_kampkort]");
    wachthond($extdebug,3, "########################################################################");   

    if (in_array($ditevent_event_type_id, $eventtypesleidtest)) {
        $diteventleidtst = 1;
        wachthond($extdebug,2, 'BETREFT EEN AANMELDING DIT EVENT LEID VOOR EEN TEST EVENT:', $ditevent_event_kampkort);
    } else {
        $diteventleidtst = 0;
    }

    wachthond($extdebug,3, 'diteventleidyes 1', $diteventleidyes);
    wachthond($extdebug,3, 'diteventleidnot 1', $diteventleidnot);
    wachthond($extdebug,3, 'diteventleidmss 1', $diteventleidmss);
    wachthond($extdebug,3, 'diteventleidtst 1', $diteventleidtst);
    wachthond($extdebug,3, 'diteventleidstf 1', $diteventleidstf);
    wachthond($extdebug,3, 'diteventleidtxt 1', $diteventleidtxt);

    if ($ditevent_part_id > 0) {
        wachthond($extdebug,4, 'eventypesdeel',     $eventtypesdeel);
        wachthond($extdebug,4, 'eventypesleid',     $eventtypesleid);
    }

    wachthond($extdebug,2, "DITEVENT GAAT $displayname $diteventleidtxt MEE ALS LEIDING", "[EID $ditevent_part_eventid / TYPE $ditevent_event_type_id]");

    wachthond($extdebug,3, 'diteventleidyes F', $diteventleidyes);
    wachthond($extdebug,3, 'diteventleidnot F', $diteventleidnot);
    wachthond($extdebug,3, 'diteventleidmss F', $diteventleidmss);
    wachthond($extdebug,3, 'diteventleidstf F', $diteventleidstf);
    wachthond($extdebug,3, 'diteventleidtst F', $diteventleidtst);
    wachthond($extdebug,3, 'diteventleidtxt F', $diteventleidtxt);

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,2, "### MEE 3.1 CHECK OF $displayname DIT JAAR MEEGAAT", "[DEEL]");
    wachthond($extdebug,3, "########################################################################");    

    // 1. Zet alles op 3 om te checken of deze worden overschreven verderop (zou moeten)
    $ditjaardeelyes = 3;
    $ditjaardeelnot = 3;
    $ditjaardeelmss = 3;
    $ditjaardeeltop = 3;
    $ditjaardeeltst = 3;
    $ditjaardeelstf = 0;
    $ditjaardeeltxt = 'ERR';

    // 2. De Waterval Logica (Hoogste prioriteit eerst)
    
    // A. IS ER EEN ACTIEVE (POSITIEVE) DEELNAME?
    if ($ditjaar_pos_deel_count > 0) {
        $ditjaardeelyes = 1;
        $ditjaardeelnot = 0;
        $ditjaardeelmss = 0;
        $ditjaardeeltxt = 'YES';

        // Check Topkamp (Gebruik hier specifiek de POSITIEVE event ID)
        // Let op: gebruik hier de variabele die we eerder bespraken: $eventjaar_pos_deel_event_type_id
        if (in_array($eventjaar_pos_deel_event_type_id, $eventtypesdeeltop)) {
            $ditjaardeeltop = 1;
        }

        // Check Test Event
        if (in_array($eventjaar_pos_deel_event_type_id, $eventtypesdeeltest)) {
            $ditjaardeeltst = 1;
        }
    }
    
    // B. GEEN JA? IS ER EEN WACHTLIJST?
    // Hier is de 'one_' variabele wel prima, of check op status waiting
    elseif (in_array($ditjaar_one_deel_part_status_id, array_values($status_waiting))) { 
        $ditjaardeelyes = 0;
        $ditjaardeelnot = 0;
        $ditjaardeelmss = 1;
        $ditjaardeeltxt = 'MSS';
        
        wachthond($extdebug,4, 'status_misschien', $status_waiting);
    }
    
    // C. GEEN WACHTLIJST? IS ER EEN ANNULERING?
    // Null-check noodzakelijk: status_negative bevat 0, en in PHP is null == 0 (loose comparison)
    elseif ($ditjaar_one_deel_part_status_id !== NULL && in_array($ditjaar_one_deel_part_status_id, array_values($status_negative))) {
        $ditjaardeelyes = 0;
        $ditjaardeelnot = 1; // Blijft 1
        $ditjaardeelmss = 0;
        $ditjaardeeltxt = 'ANN';
        
        wachthond($extdebug,4, 'status_negative', $status_negative);
    }

    // Logging
    wachthond($extdebug,3, 'ditjaardeelyes 0', $ditjaardeelyes);
    wachthond($extdebug,3, 'ditjaardeelnot 0', $ditjaardeelnot);
    wachthond($extdebug,3, 'ditjaardeelmss 0', $ditjaardeelmss);
    wachthond($extdebug,3, 'ditjaardeeltop 0', $ditjaardeeltop); 
    wachthond($extdebug,3, 'ditjaardeeltxt 0', $ditjaardeeltxt);

    wachthond($extdebug,2, "DITJAAR GAAT $displayname $ditjaardeeltxt MEE ALS DEELNEMER", "[TYPE $eventjaar_pos_deel_event_type_id]");

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,2, "### MEE 4.1 CHECK OF $displayname DIT JAAR MEEGAAT", "[LEID]");
    wachthond($extdebug,3, "########################################################################");    

    // 1. Reset defaults
    $ditjaarleidyes = 3;
    $ditjaarleidnot = 3;
    $ditjaarleidmss = 3;
    $ditjaarleidtst = 3;
    $ditjaarleidstf = 3;
    $ditjaarleidtxt = 'ERR';

    // DEBUG: Toon de variabelen waarop we gaan beslissen
    wachthond($extdebug, 3, "DEBUG 4.1 INPUTS:", [
        'Pos Count'     => $ditjaar_pos_leid_count,
        'One StatusID'  => $ditjaar_one_leid_part_status_id,
        'Functie'       => $eventjaar_pos_leid_functie,
        'Kampkort'      => $eventjaar_pos_leid_kampkort,
        'TypeID'        => $eventjaar_pos_leid_event_type_id
    ]);

    // 2. De Waterval Logica

    // A. IS ER EEN ACTIEVE (POSITIEVE) LEIDING INSCHRIJVING?
    if ($ditjaar_pos_leid_count > 0) {
        
        wachthond($extdebug, 2, "-> LOGICA: Count > 0, dus basis is JA/WEL");

        // Stap 1: In de basis gaat hij/zij mee
        $ditjaarleidyes = 1;
        $ditjaarleidnot = 0;
        $ditjaarleidtxt = 'YES'; // Of 'WEL'

        // Stap 2: Check op TEST event
        if (in_array($eventjaar_pos_leid_event_type_id, $eventtypesleidtest)) {
            wachthond($extdebug, 2, "-> LOGICA: Het is een TEST event type");
            $ditjaarleidtst = 1;
        }

        // Stap 3: Check op STAFFUNCTIES (Wel ingeschreven, maar niet mee op kamp)
        $staf_functies = ['bestuurslid', 'kampstaf'];
        $staf_kampen   = ['reunie', 'bestuurstaken', 'waarnodig'];

        // Debug de staff check
        $is_staf_func = in_array($eventjaar_pos_leid_functie, $staf_functies);
        $is_staf_kamp = in_array($eventjaar_pos_leid_kampkort, $staf_kampen);

        if ($is_staf_func OR $is_staf_kamp) {
             $ditjaarleidyes = 0; // Telt NIET als leiding op het veld
             $ditjaarleidstf = 1; // Telt WEL als staf
             $ditjaarleidtxt = 'STF';
             
             // Laat exact zien waarom hij hierin valt
             $reden = $is_staf_func ? "Functie match ($eventjaar_pos_leid_functie)" : "Kamp match ($eventjaar_pos_leid_kampkort)";
             wachthond($extdebug, 2, "-> OVERRIDE: PERSOON IS STAF/BESTUUR. Reden: $reden");
        } else {
             $ditjaarleidstf = 0;
             wachthond($extdebug, 3, "-> CHECK: Geen staf/bestuur override van toepassing");
        }
    }

    // B. GEEN JA? IS ER EEN WACHTLIJST?
    elseif (in_array($ditjaar_one_leid_part_status_id, array_values($status_waiting))) {
        wachthond($extdebug, 2, "-> LOGICA: Geen positieve inschrijving, maar wel STATUS WAITING ($ditjaar_one_leid_part_status_id)");
        
        $ditjaarleidyes = 0;
        $ditjaarleidnot = 0;
        $ditjaarleidmss = 1;
        $ditjaarleidtxt = 'MSS';
        
        wachthond($extdebug,4, 'status_misschien', $status_waiting);
    }
    
    // C. IS ER EEN ANNULERING?
    // Null-check noodzakelijk: status_negative bevat 0, en in PHP is null == 0 (loose comparison)
    elseif ($ditjaar_one_leid_part_status_id !== NULL && in_array($ditjaar_one_leid_part_status_id, array_values($status_negative))) {
        wachthond($extdebug, 2, "-> LOGICA: Status is NEGATIEF/GEANNULEERD ($ditjaar_one_leid_part_status_id)");

        $ditjaarleidyes = 0;
        $ditjaarleidnot = 1;
        $ditjaarleidtxt = 'ANN';
    } else {
        wachthond($extdebug, 2, "-> LOGICA: Geen van bovenstaande (Fall-through)");
    }

    // Logging
    wachthond($extdebug,3, 'ditjaarleidyes 0', $ditjaarleidyes);
    wachthond($extdebug,3, 'ditjaarleidnot 0', $ditjaarleidnot);
    wachthond($extdebug,3, 'ditjaarleidmss 0', $ditjaarleidmss);
    wachthond($extdebug,3, 'ditjaarleidstf 0', $ditjaarleidstf); 
    wachthond($extdebug,3, 'ditjaarleidtst 0', $ditjaarleidtst);
    wachthond($extdebug,3, 'ditjaarleidtxt 0', $ditjaarleidtxt);

    wachthond($extdebug,2, "DITJAAR GAAT $displayname $ditjaarleidtxt MEE ALS LEIDING", "[TYPE $eventjaar_pos_leid_event_type_id]");

    ##########################################################################################
    # BEPAAL DE DEELNAMESTATUS DIT JAAR LEID STF
    ##########################################################################################       

    if (in_array($ditjaar_leid_welkkamp, array("reunie", "bestuurstaken", "waarnodig"))) {
        $ditjaarleidyes = 0;
        $ditjaarleidstf = 1;
        $ditjaarleidtxt = 'NOT';
        wachthond($extdebug,2, 'BETREFT EEN AANMELDING DIT JAAR LEID VOOR STAFTAKEN:', $ditjaar_leid_welkkamp);
    }

    ##########################################################################################
    # BEPAAL DE DEELNAMESTATUS DIT JAAR LEID TST
    ##########################################################################################       

    if (in_array($ditjaar_one_leid_event_type_id, $eventtypesleidtest)) {
        $ditjaarleidtst = 1;
        wachthond($extdebug,2, 'BETREFT EEN AANMELDING DIT JAAR LEID VOOR EEN TEST EVENT:', $ditjaar_one_leid_event_type_id);
    } else {
        $ditjaarleidtst = 0;
    }

    wachthond($extdebug,3, 'ditjaarleidyes 1', $ditjaarleidyes);
    wachthond($extdebug,3, 'ditjaarleidnot 1', $ditjaarleidnot);
    wachthond($extdebug,3, 'ditjaarleidmss 1', $ditjaarleidmss);
    wachthond($extdebug,3, 'ditjaarleidtst 1', $ditjaarleidtst);
    wachthond($extdebug,3, 'ditjaarleidtxt 1', $ditjaarleidtxt);

/*
    ##########################################################################################
    # OVERRIDE IF STAFFUNCTIE EN NIET FYSIEK MEE OP KAMP
    ##########################################################################################
    if (in_array($ditjaar_pos_part_eventid, $kampids_leid) AND (in_array($ditevent_leid_welkkamp, array("reunie", "bestuurstaken", "waarnodig")))) {
      $ditjaarleidyes = 0;
      $ditjaarleidnot = 0;
      $ditjaarleidmss = 0;
      $ditjaarleidstf = 1;  // dit jaar aanmelding leiding als staffunctie, niet fysiek mee op kamp
      $ditjaarleidtxt = 'NOT';

      wachthond($extdebug,2, 'OVERRIDE DITJAAR MEE VOOR STAF:', $ditevent_leid_welkkamp);
      wachthond($extdebug,3, 'ditjaarleidyes S', $ditjaarleidyes);
      wachthond($extdebug,3, 'ditjaarleidnot S', $ditjaarleidnot);
      wachthond($extdebug,3, 'ditjaarleidmss S', $ditjaarleidmss);
      wachthond($extdebug,3, 'ditjaarleidstf S', $ditjaarleidstf);
      wachthond($extdebug,3, 'ditjaarleidtst S', $ditjaarleidtst);
      wachthond($extdebug,3, 'ditjaarleidtxt S', $ditjaarleidtxt);

    } else {
      $ditjaarleidstf = 0;
    }
*/
    wachthond($extdebug,2, "DITJAAR GAAT $displayname $ditjaarleidtxt MEE ALS LEIDING", "[EID $ditevent_part_eventid / TYPE $ditevent_event_type_id]");

    wachthond($extdebug,3, "########################################################################");
    wachthond($extdebug,3, 'ditjaarleidyes F', $ditjaarleidyes);
    wachthond($extdebug,3, 'ditjaarleidnot F', $ditjaarleidnot);
    wachthond($extdebug,3, 'ditjaarleidmss F', $ditjaarleidmss);
    wachthond($extdebug,3, 'ditjaarleidstf F', $ditjaarleidstf);
    wachthond($extdebug,3, 'ditjaarleidtst F', $ditjaarleidtst);
    wachthond($extdebug,3, 'ditjaarleidtxt F', $ditjaarleidtxt);

    wachthond($extdebug,2, "########################################################################");
    wachthond($extdebug,2, "### MEE 5.1 CHECK OF $displayname DIT EVENTJAAR $ditevent_kampjaar MEEGAAT", "[DEEL  $eventjaar_pos_kampkort]");
    wachthond($extdebug,3, "########################################################################");   

    $eventjaardeelyes   = 3;
    $eventjaardeelnot   = 3;
    $eventjaardeelmss   = 3;
    $eventjaardeeltop   = 3;
    $eventjaardeeltst   = 3;
    $eventjaardeelstf   = 0;
    $eventjaardeeltxt   = 'ERR';

    if (in_array($eventjaar_pos_deel_event_type_id, $eventtypesdeel)) {

        if (       in_array($eventjaar_pos_deel_part_status_id, array_values($status_positive)) )   {
                $eventjaardeelyes = 1;
                $eventjaardeelnot = 0;
                $eventjaardeelmss = 0;
                $eventjaardeeltxt = 'YES';
        } elseif ( in_array($eventjaar_pos_deel_part_status_id, array_values($status_negative)) )   {
                $eventjaardeelyes = 0;
                $eventjaardeelnot = 1;
                $eventjaardeelmss = 0;
                $eventjaardeeltxt = 'ANN';
        } elseif ( in_array($eventjaar_pos_deel_part_status_id, array_values($status_waiting)) )    {
                $eventjaardeelyes = 0;
                $eventjaardeelnot = 0;
                $eventjaardeelmss = 1;
                $eventjaardeeltxt = 'MSS';
        } elseif (!in_array($eventjaar_pos_deel_part_status_id, array_values($status_positive)))    {
                $eventjaardeelyes = 0;
                $eventjaardeelnot = 1;
                $eventjaardeelmss = 0;
                $eventjaardeeltxt = 'NOT';
      } else {
                $eventjaardeeltxt = 'NOP';        
      }

      wachthond($extdebug,3, 'eventjaardeelyes 0', $eventjaardeelyes);
      wachthond($extdebug,3, 'eventjaardeelnot 0', $eventjaardeelnot);
      wachthond($extdebug,3, 'eventjaardeelmss 0', $eventjaardeelmss);
      wachthond($extdebug,3, 'eventjaardeeltxt 0', $eventjaardeeltxt);
    } else {

                $eventjaardeelyes = 0;
                $eventjaardeelnot = 1;
                $eventjaardeelmss = 0;
                $eventjaardeeltxt = 'NOT';      
    }

    wachthond($extdebug,2, "EVENTJAAR $ditevent_kampjaar GAAT $displayname $eventjaardeeltxt MEE ALS DEELNEMER", "[EID $eventjaar_pos_deel_event_id / TYPE $eventjaar_pos_deel_event_type_id]");

    wachthond($extdebug,3, "########################################################################");
    wachthond($extdebug,3, 'eventjaardeelyes F', $eventjaardeelyes);
    wachthond($extdebug,3, 'eventjaardeelnot F', $eventjaardeelnot);
    wachthond($extdebug,3, 'eventjaardeelmss F', $eventjaardeelmss);
    wachthond($extdebug,3, 'eventjaardeeltop F', $eventjaardeeltop);
    wachthond($extdebug,3, 'eventjaardeeltst F', $eventjaardeeltst);
    wachthond($extdebug,3, 'eventjaardeeltxt F', $eventjaardeeltxt);

    wachthond($extdebug,2, "########################################################################");
    $eventjaar_pos_part_kampkort = $eventjaar_pos_part_kampkort ?? NULL;
    wachthond($extdebug,2, "### MEE 6.2 CHECK OF $displayname EVENTJAAR ($ditevent_kampjaar) MEEGAAT ALS LEIDING", $eventjaar_pos_part_kampkort);
    wachthond($extdebug,3, "########################################################################");

    $eventjaarleidyes = 3;
    $eventjaarleidnot = 3;
    $eventjaarleidmss = 3;
    $eventjaarleidstf = 3;
    $eventjaarleidtst = 3;
    $eventjaarleidtxt = 'ERR';

    wachthond($extdebug,4, 'eventjaar_pos_leid_event_type_id',  $eventjaar_pos_leid_event_type_id);
    wachthond($extdebug,4, 'eventjaar_pos_leid_part_status_id', $eventjaar_pos_leid_part_status_id);
    wachthond($extdebug,4, 'eventypesleid',                     $eventtypesleid);

    if (in_array($eventjaar_pos_leid_event_type_id, $eventtypesleid)) {

        if (       in_array($eventjaar_pos_leid_part_status_id, array_values($status_positive)) )   {
            $eventjaarleidyes = 1;
            $eventjaarleidnot = 0;
            $eventjaarleidmss = 0;
            $eventjaarleidstf = 0;        
            $eventjaarleidtxt = 'YES';
        } elseif ( in_array($eventjaar_pos_leid_part_status_id, array_values($status_negative)) )   {
            $eventjaarleidyes = 0;
            $eventjaarleidnot = 1;
            $eventjaarleidmss = 0;
            $eventjaarleidstf = 0;
            $eventjaarleidtxt = 'ANN';
        } elseif ( in_array($eventjaar_pos_leid_part_status_id, array_values($status_waiting)) )    {
            $eventjaarleidyes = 0;
            $eventjaarleidnot = 0;
            $eventjaarleidmss = 1;
            $eventjaarleidstf = 0;
            $eventjaarleidtxt = 'MSS';
        } elseif (!in_array($eventjaar_pos_leid_part_status_id, array_values($status_positive)))    {
            $eventjaarleidyes = 0;
            $eventjaarleidnot = 1;
            $eventjaarleidmss = 0;
            $eventjaarleidstf = 0;        
            $eventjaarleidtxt = 'NOT';
      } else {
        $eventjaarleidtxt = 'NOP';        
      }
      wachthond($extdebug,3, 'eventjaarleidyes 0', $eventjaarleidyes);
      wachthond($extdebug,3, 'eventjaarleidnot 0', $eventjaarleidnot);
      wachthond($extdebug,3, 'eventjaarleidmss 0', $eventjaarleidmss);
      wachthond($extdebug,3, 'eventjaarleidtxt 0', $eventjaarleidtxt);
    } else {
      $eventjaarleidyes = 0;
      $eventjaarleidnot = 1;
      $eventjaarleidmss = 0;
      $eventjaarleidstf = 0;
      $eventjaarleidtst = 0;
      $eventjaarleidtxt = 'NOT';
    }

        ##########################################################################################
        # OVERRIDE IF STAFFUNCTIE EN NIET FYSIEK MEE OP KAMP
        ##########################################################################################
/*
#       if (in_array($eventjaar_pos_leid_event_type_id, $eventtypesleid)) {

        if (in_array($eventjaar_one_part_eventid, $kampids_leid) AND (in_array($ditevent_leid_welkkamp, array("reunie", "bestuurstaken", "waarnodig")))) {
            $eventjaarleidyes = 0;
            $eventjaarleidnot = 0;
            $eventjaarleidmss = 0;
            $eventjaarleidstf = 1;    // dit eventjaar aanmelding leiding als staffunctie, niet fysiek mee op kamp
            $eventjaarleidtxt = 'NOT';

            wachthond($extdebug,2, 'OVERRIDE DITJAAR MEE VOOR STAF:', $ditevent_leid_welkkamp);
            wachthond($extdebug,3, 'eventjaarleidyes S', $eventjaarleidyes);
            wachthond($extdebug,3, 'eventjaarleidnot S', $eventjaarleidnot);
            wachthond($extdebug,3, 'eventjaarleidmss S', $eventjaarleidmss);
            wachthond($extdebug,3, 'eventjaarleidstf S', $eventjaarleidstf);
            wachthond($extdebug,3, 'eventjaarleidtst S', $eventjaarleidtst);
            wachthond($extdebug,3, 'eventjaarleidtxt S', $eventjaarleidtxt);

        } else {
            $eventjaarleidstf = 0;
        }
*/
    $eventjaar_pos_leid_part_eventid = $eventjaar_pos_leid_part_eventid ?? NULL;
    wachthond($extdebug,2, "EVENTJAAR $ditevent_kampjaar GAAT $displayname $eventjaarleidtxt MEE ALS LEIDING", "[EID $eventjaar_pos_leid_part_eventid / TYPE $eventjaar_pos_leid_event_type_id]");

    wachthond($extdebug,3, 'eventjaarleidyes F', $eventjaarleidyes);
    wachthond($extdebug,3, 'eventjaarleidnot F', $eventjaarleidnot);
    wachthond($extdebug,3, 'eventjaarleidmss F', $eventjaarleidmss);
    wachthond($extdebug,3, 'eventjaarleidstf F', $eventjaarleidstf);
    wachthond($extdebug,3, 'eventjaarleidtst F', $eventjaarleidtst);
    wachthond($extdebug,3, 'eventjaarleidtxt F', $eventjaarleidtxt);

    wachthond($extdebug,3, "########################################################################");
    wachthond($extdebug,3, "### MEE FIN: DIT JAAR",                                   $today_kampjaar);
    wachthond($extdebug,3, "########################################################################");

    $ditjaar_array = array(
        'contact_id'        => $contact_id,
        'displayname'       => $displayname,
        'ditjaardeelyes'    => $ditjaardeelyes,
        'ditjaardeelnot'    => $ditjaardeelnot,
        'ditjaardeelmss'    => $ditjaardeelmss,
        'ditjaardeelstf'    => $ditjaardeelstf,
        'ditjaardeeltst'    => $ditjaardeeltst,
        'ditjaardeeltxt'    => $ditjaardeeltxt,
        'ditjaarleidyes'    => $ditjaarleidyes,
        'ditjaarleidnot'    => $ditjaarleidnot,
        'ditjaarleidmss'    => $ditjaarleidmss,
        'ditjaarleidstf'    => $ditjaarleidstf,
        'ditjaarleidtst'    => $ditjaarleidtst,
        'ditjaarleidtxt'    => $ditjaarleidtxt,
    );

    wachthond($extdebug,3, 'ditjaar_array', $ditjaar_array);

    wachthond($extdebug,3, "########################################################################");
    wachthond($extdebug,3, "### MEE FIN: DIT EVENT ($ditevent_part_kampkort)",     $ditevent_kampjaar);
    wachthond($extdebug,3, "########################################################################");

    $ditevent_array = array(
        'contact_id'        => $contact_id,
        'displayname'       => $displayname,
        'diteventdeelyes'   => $diteventdeelyes,
        'diteventdeelnot'   => $diteventdeelnot,
        'diteventdeelmss'   => $diteventdeelmss,
        'diteventdeeltop'   => $diteventdeeltop,
        'diteventdeeltst'   => $diteventdeeltst,
        'diteventdeelstf'   => $diteventdeelstf,
        'diteventdeeltxt'   => $diteventdeeltxt,
        'diteventleidyes'   => $diteventleidyes,
        'diteventleidnot'   => $diteventleidnot,
        'diteventleidmss'   => $diteventleidmss,
        'diteventleidstf'   => $diteventleidstf,
        'diteventleidtst'   => $diteventleidtst,
        'diteventleidtxt'   => $diteventleidtxt,
    );

    wachthond($extdebug,3, 'ditevent_array', $ditevent_array);

    wachthond($extdebug,3, "########################################################################");
    wachthond($extdebug,3, "### MEE FIN: DIT EVENTJAAR",                           $ditevent_kampjaar);
    wachthond($extdebug,3, "########################################################################");

    $eventjaar_array = array(
        'contact_id'        => $contact_id,
        'displayname'       => $displayname,        
        'eventjaardeelyes'  => $eventjaardeelyes,
        'eventjaardeelnot'  => $eventjaardeelnot,
        'eventjaardeelmss'  => $eventjaardeelmss,
        'eventjaardeelstf'  => $eventjaardeelstf,
        'eventjaardeeltst'  => $eventjaardeeltst,
        'eventjaardeeltxt'  => $eventjaardeeltxt,
        'eventjaarleidyes'  => $eventjaarleidyes,
        'eventjaarleidnot'  => $eventjaarleidnot,
        'eventjaarleidmss'  => $eventjaarleidmss,
        'eventjaarleidstf'  => $eventjaarleidstf,
        'eventjaarleidtst'  => $eventjaarleidtst,
        'eventjaarleidtxt'  => $eventjaarleidtxt,
    );

    wachthond($extdebug,3, 'eventjaar_array', $eventjaar_array);

    wachthond($extdebug,3, "########################################################################");
    wachthond($extdebug,3, "### MEE FIN: MEE TEXTUAL STATUS",                        "[$displayname]");
    wachthond($extdebug,3, "########################################################################");
    wachthond($extdebug,3, 'ditjaardeeltxt F',   $ditjaar_array['ditjaardeeltxt']);
    wachthond($extdebug,3, 'ditjaarleidtxt F',   $ditjaar_array['ditjaarleidtxt']);
    wachthond($extdebug,3, 'diteventdeeltxt F',  $ditevent_array['diteventdeeltxt']);
    wachthond($extdebug,3, 'diteventleidtxt F',  $ditevent_array['diteventleidtxt']);
    wachthond($extdebug,3, 'eventjaardeeltxt F', $eventjaar_array['eventjaardeeltxt']);
    wachthond($extdebug,3, 'eventjaarleidtxt F', $eventjaar_array['eventjaarleidtxt']);    

    // =========================================================================
    // MERGE & RETURN
    // =========================================================================
    // We voegen alle arrays samen. Omdat de keys uniek zijn (ditjaar..., ditevent..., eventjaar...)
    // is dit veilig en krijg je één grote array met alle info terug.
    
    $return_array = array_merge($ditjaar_array, $ditevent_array, $eventjaar_array);

    wachthond($extdebug,1, "########################################################################");
    wachthond($extdebug,1, '### MEE - RETURN COMBINED ARRAY',                          $return_array);
    wachthond($extdebug,1, "########################################################################");

    $total_mee_configure_duur = number_format(microtime(TRUE) - $mee_configure_start, 3);
    wachthond($extdebug, 3, "MEE duur totaal: {$total_mee_configure_duur}s");
    watchdog('civicrm_timing', base_microtimer("EINDE mee_configure"), NULL, WATCHDOG_DEBUG);

    return $return_array;

}

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function mee_civicrm_config(&$config): void {
  _mee_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function mee_civicrm_install(): void {
  _mee_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function mee_civicrm_enable(): void {
  _mee_civix_civicrm_enable();
}
