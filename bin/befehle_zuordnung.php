<?php
/**
 * bin/befehle_zuordnung.php - Zuordnungstabelle der Befehlsbruecke, Linie Beschattung Fensterbilanz (linieneigen).
 *
 * Gruppe 3 / MQTT (0.12.19; Musterprojekt/entwurf_gruppe3_fb.txt Abschnitt 3, E1, E3, E11-E13;
 * ENTSCHEIDUNGEN_GRUPPE3_MQTT.md Punkt 3: "Die Messwert-Lieferung geht ueber die gemeinsame Befehlsbruecke. Kein
 * zweiter Haken, weil nichts eingreift. Sperrzeit 0 s mit Begruendung"). Muster: vb_g1_bruecke_bau/beispiel/bin/
 * befehle_zuordnung.php und die Tabelle der Linie Bewaesserung 0.9.44. Die Bruecke selbst (bin/befehlsbruecke.php,
 * .sh) ist die gemeinsame Stammfassung und wird hier nicht veraendert.
 *
 * Acht Befehle, alle Messwerte an das Plugin, keiner schaltet etwas (eingreifend false):
 *   messwert/strahlung, messwert/prognose, messwert/aussen, messwert/prognose1, messwert/pv_prognose,
 *   messwert/ist/{raum}, messwert/grenze/{raum}, messwert/stellung/{fenster}
 * 'tun' ruft fb_melden_ausfuehren() - dieselbe Funktion wie der HTTP-Endpunkt (aktion=melden): dieselbe Pruefung
 * (Name eingerichtet?, Zahl, Grenzen aus fb_messgroessen()/fb_raumgrenzen(), Stellung 0-100), dieselbe Ablage
 * (messwerte.json unter messwerte.lock), danach fb_lauf(false, false) im Rechentakt. Der Messwertname des HTTP-Wegs
 * hat einen Punkt ("ist.wohnzimmer"); die Bruecke erlaubt je Ebene nur a-z, 0-9, _ - der Punkt wird zur Ebene (E12).
 * Art, Grenzen und Format kommen aus EINER Quelle mit der HTTP-Vorlage: fb_vorlage_format() (<v.1> -> zahl, sonst
 * ganz), fb_messgroessen() und fb_raumgrenzen().
 *
 * Sperrzeit 0 s je Befehl (E3): ein wiederholter gleicher Wert ist der Frischebeleg (das Plugin glaubt einem Wert
 * hoechstens 'hoechstalter', ab Werk 15 min), kein doppelter Befehl; die Rechnung bremst der Rechentakt des Plugins.
 * Mit der Vorgabe 60 s fiele bei einem schnelleren eigenen Sendetakt jeder zweite Wert weg.
 *
 * Die Datei setzt keine Konfiguration und schreibt nichts.
 */
if (!function_exists('fb_config')) {
    /* Gruppe 36 (0.12.20, Pruefbericht T3-A; Bauform FerienFeiertage 1.2.28 FE14 / Abfahrts-Assistent 1.6.31):
     * ausdruecklich LBHOMEDIR + LBPPLUGINDIR (Pruefstaende) zuerst, dann installiert (bin/plugins/<ordner> ->
     * webfrontend/html/plugins/<ordner>) ODER Archiv (bin/ neben webfrontend/). Bis 0.12.19 fand ein Pruefstand mit
     * bin/ ausserhalb des Baums die Bibliothek nicht ("Bibliothek der Linie nicht geladen", Probe t3a Fall B). */
    $fb_kand = array();
    $fb_lb = (string) getenv('LBHOMEDIR');
    $fb_ordner = basename(rtrim((string) getenv('LBPPLUGINDIR'), '/'));
    if ($fb_lb !== '' && $fb_ordner !== ''
        && !in_array($fb_ordner, array('.', 'html', 'htmlauth', 'bin', 'plugins', 'webfrontend'), true)) {
        $fb_kand[] = $fb_lb . '/webfrontend/html/plugins/' . $fb_ordner . '/fb_lib.php';
    }
    if (basename(dirname(__DIR__)) === 'plugins') {
        $fb_kand[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/fb_lib.php';
    } else {
        $fb_kand[] = dirname(__DIR__) . '/webfrontend/html/fb_lib.php';
    }
    foreach ($fb_kand as $fb_k) {
        if (is_file($fb_k)) { require_once $fb_k; break; }
    }
}
$fb_geladen = function_exists('fb_config') && function_exists('fb_melden_ausfuehren')
              && function_exists('fb_messgroessen') && function_exists('fb_vorlage_format');
$fb_grund = "Messwert, kein Befehl: die Wiederholung desselben Werts ist der Frischebeleg (das Plugin glaubt einem "
          . "Wert hoechstens das eingestellte Hoechstalter, ab Werk 15 min); die Rechnung bremst der Rechentakt des Plugins";
$fb_befehle = array();
if ($fb_geladen) {
    $fb_eintrag = function ($art, $min, $max, $titel, $kachel, $name_von) use ($fb_grund) {
        return array(
            'art' => $art, 'min' => $art === 'ganz' ? (int) $min : (float) $min,
            'max' => $art === 'ganz' ? (int) $max : (float) $max, 'eingreifend' => false,
            'sperre_s' => 0, 'begruendung' => $fb_grund,
            'titel' => $titel, 'kachel' => $kachel, 'quittung' => 'ausgefuehrt',
            'tun' => function ($w, $t) use ($name_von) {
                return fb_melden_ausfuehren(call_user_func($name_von, $t), $w, 'mqtt');
            });
    };
    $fb_art = function ($messwert) { return fb_vorlage_format($messwert) === '<v.1>' ? 'zahl' : 'ganz'; };
    $fb_titel = array('strahlung' => 'Globalstrahlung', 'prognose' => "Tagesh\u{f6}chstwert heute",
                      'aussen' => "Au\u{df}entemperatur", 'prognose1' => "Tagesh\u{f6}chstwert morgen",
                      'pv_prognose' => 'PV-Prognose');
    $fb_kachel = array('strahlung' => 'BEFEHL.K_STRAHLUNG', 'prognose' => 'BEFEHL.K_PROGNOSE',
                       'aussen' => 'BEFEHL.K_AUSSEN', 'prognose1' => 'BEFEHL.K_PROGNOSE1',
                       'pv_prognose' => 'BEFEHL.K_PV');
    foreach (fb_messgroessen() as $fb_n => $fb_info) {
        if (!isset($fb_titel[$fb_n])) { continue; }
        $fb_befehle['messwert/' . $fb_n] = $fb_eintrag($fb_art($fb_n), $fb_info[3], $fb_info[4],
            'Fensterbilanz ' . $fb_titel[$fb_n] . ' (MQTT)', $fb_kachel[$fb_n],
            function ($t) use ($fb_n) { return $fb_n; });
    }
    list($fb_von, $fb_bis) = fb_raumgrenzen();
    $fb_befehle['messwert/ist/{raum}'] = $fb_eintrag($fb_art('ist.x'), $fb_von, $fb_bis,
        'Fensterbilanz Raumtemperatur <Raum> (MQTT)', 'BEFEHL.K_IST',
        function ($t) { return 'ist.' . (isset($t['raum']) ? (string) $t['raum'] : ''); });
    $fb_befehle['messwert/grenze/{raum}'] = $fb_eintrag($fb_art('grenze.x'), $fb_von, $fb_bis,
        'Fensterbilanz Beschattungsgrenze <Raum> (MQTT)', 'BEFEHL.K_GRENZE',
        function ($t) { return 'grenze.' . (isset($t['raum']) ? (string) $t['raum'] : ''); });
    $fb_befehle['messwert/stellung/{fenster}'] = $fb_eintrag($fb_art('stellung.x'), 0, 100,
        "Fensterbilanz Stellung <K\u{fc}rzel> (MQTT)", 'BEFEHL.K_STELLUNG',
        function ($t) { return 'stellung.' . (isset($t['fenster']) ? (string) $t['fenster'] : ''); });
}
return array(
    'fassung' => 1,
    'linie'   => 'fensterbilanz',
    'plugin'  => 'Fensterbilanz',
    'geladen' => $fb_geladen,
    'einstellungen' => function () {
        $c = fb_config(false);
        return array('praefix'     => (string) $c['mqtt_topic'],
                     'annehmen'    => !empty($c['befehle_mqtt_ein']) && !empty($c['mqtt_ein']),
                     'eingreifend' => false,
                     'mqtt_ein'    => !empty($c['mqtt_ein']));
    },
    /* Protokoll: Fehler und Warnungen der Bruecke gehen wie sie sind ins Protokoll der Linie. Je angenommenem oder
     * abgelehntem Befehl schreibt die Bruecke eine INFO-Zeile "<name> (<wert>): <ergebnis>" - bei acht Messwerten im
     * 5-Minuten-Takt rund hundert Zeilen je Stunde in eine Datei auf der Ramdisk, die bei 512 kB auf 400 Zeilen
     * gekuerzt wird (fb_log()). Diese Zeilen gehen deshalb ueber fb_log_wenn_neu() je Befehl, OHNE den Wert: im
     * Protokoll steht, wenn sich das Ergebnis eines Befehls aendert (angenommen <-> abgelehnt), nicht jeder Wert. Die
     * Werte selbst zeigen der Reiter Einbindung ("Zuletzt eingetroffen") und der Reiter Test (letzter Befehl). */
    'protokoll' => function ($stufe, $text) {
        if ($stufe === 'INFO' && preg_match('/^([a-z0-9_\/]{1,200}) \(.*\): (.*)\z/s', (string) $text, $m)) {
            fb_log_wenn_neu('bruecke_' . $m[1], 'Befehlsbruecke: ' . $m[1] . ': ' . $m[2]);
            return;
        }
        fb_log('Befehlsbruecke' . ($stufe === 'INFO' ? '' : ' ' . $stufe) . ': ' . $text);
    },
    'teile' => array('raum' => '[a-z0-9_]{1,20}', 'fenster' => '[a-z0-9_]{1,16}'),
    'befehle' => $fb_befehle,
);
