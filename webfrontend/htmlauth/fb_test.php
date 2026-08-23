<?php
/**
 * Fensterbilanz - die Aktionen des Reiters Test
 *
 * Jeder Test gibt Klartext zurueck, keine Rueckgabewerte zum Auswerten:
 * gelesen wird das von einem Menschen.
 *
 * Die "Selbstpruefung" ist eine Reihe von Fragen mit Haken oder Kreuz. Sie
 * hat einen DRITTEN Zustand - ein Strich, wenn die Frage sich hier nicht
 * beantworten laesst. Ein rotes Kreuz, das nichts bedeutet, ist schlimmer
 * als keine Pruefung: man sucht dann dort.
 */

function fb_test_ausfuehren($welcher)
{
    switch ($welcher) {
        case 'selbstpruefung': return fb_test_selbstpruefung();
        case 'sonne':          return fb_test_sonne();
        case 'tagesgang':      return fb_test_tagesgang();
        case 'selbsttest':     return fb_test_selbsttest();
        case 'zeile':          return fb_test_zeile();
        case 'messwerte':      return fb_test_messwerte();
        case 'vorlage':        return fb_test_vorlage();
        case 'endpunkt':       return fb_test_endpunkt();
        case 'bilanz':         return fb_test_bilanz();
        case 'lernkurve':      return fb_test_lernkurve();
        case 'pv':             return fb_test_pv();
        case 'bericht':        return fb_test_bericht();
        case 'rechnen':        return fb_test_rechnen();
    }
    return fb_t('TEST.M_UNBEKANNT');
}

/* ==================================================================
 * Die Knoepfe der 0.10.0
 * ================================================================== */

/**
 * Was waere, wenn - rechnen ohne zu speichern.
 *
 * Es wird mit den TATSAECHLICHEN Messwerten gerechnet und nichts
 * geschrieben. fb_rechnen() fasst keine Datei an; das ist der Grund, warum
 * diese Seite ueberhaupt moeglich ist, ohne die laufende Anlage anzufassen.
 */
function fb_test_wasware($probe)
{
    $jetzt = time();
    $jetzt_cfg = fb_config();
    $mess = fb_messwerte();
    $bilanz = fb_json_lesen(fb_paths()['bilanz']);
    $a = fb_rechnen($jetzt_cfg, $mess, $jetzt, fb_stand(), $bilanz);
    $b = fb_rechnen($probe, $mess, $jetzt, fb_stand(), $bilanz);

    $z = array();
    $z[] = fb_klartext('TEST.WW_KOPF');
    $z[] = '';
    $z[] = sprintf('%-14s %-24s %-24s', fb_klartext('TEST.WW_SP_WERT'),
                   fb_klartext('TEST.WW_SP_JETZT'), fb_klartext('TEST.WW_SP_PROBE'));
    $z[] = str_repeat('-', 66);
    foreach (array('tagesgrenze', 'spreizung_tag', 'spreizung_raum', 'gewicht_raum',
                   'gewicht_tag', 'gewicht_bilanz', 'bilanz_voll_qm', 'gewicht_morgen',
                   'schwelle_ein', 'schwelle_aus', 'e_ref', 'himmelsmodell') as $k) {
        if ((string) $jetzt_cfg[$k] === (string) $probe[$k]) { continue; }
        $z[] = sprintf('%-14s %-24s %-24s', $k, (string) $jetzt_cfg[$k], (string) $probe[$k]);
    }
    $z[] = '';
    $z[] = sprintf('%-12s %8s %8s %6s  %s', fb_klartext('TAB.KUERZEL'),
                   fb_klartext('TEST.WW_SP_JETZT'), fb_klartext('TEST.WW_SP_PROBE'),
                   fb_klartext('TEST.WW_SP_DIFF'), fb_klartext('TAB.GRUND'));
    $z[] = str_repeat('-', 76);
    $anders = 0;
    foreach ((isset($b['fenster']) ? $b['fenster'] : array()) as $nr => $e) {
        $alt = isset($a['fenster'][$nr]) ? (int) $a['fenster'][$nr]['urteil'] : 0;
        $neu = (int) $e['urteil'];
        $alt_b = isset($a['fenster'][$nr]) ? (int) $a['fenster'][$nr]['beschatten'] : 0;
        if ($alt_b !== (int) $e['beschatten']) { $anders++; }
        $z[] = sprintf('%-12s %8s %8s %6s  %s', $e['kuerzel'],
            sprintf('%+d%s', $alt, $alt_b ? '*' : ' '),
            sprintf('%+d%s', $neu, $e['beschatten'] ? '*' : ' '),
            sprintf('%+d', $neu - $alt),
            fb_klartext('GRUND.' . strtoupper($e['grund'])));
    }
    $z[] = '';
    $z[] = sprintf(fb_klartext('TEST.WW_FUSS'), $anders);
    return implode("\n", $z) . "\n";
}

/** Die Tagesbilanz zum Ansehen. */
function fb_test_bilanz()
{
    $bilanz = fb_json_lesen(fb_paths()['bilanz']);
    if (empty($bilanz['datum'])) { return fb_klartext('TEST.BILANZ_LEER'); }
    $stand = fb_stand();
    $z = array();
    $z[] = sprintf(fb_klartext('TEST.BILANZ_KOPF'), (string) $bilanz['datum'],
                   array_sum($bilanz['fenster']) / 1000.0);
    $z[] = '';
    $z[] = sprintf('%-14s %10s %10s %10s', fb_klartext('TAB.KUERZEL'),
                   'Wh', fb_klartext('TEST.BILANZ_SP_ZU'), fb_klartext('TEST.BILANZ_SP_SPITZE'));
    $z[] = str_repeat('-', 48);
    foreach ((isset($bilanz['fenster']) ? $bilanz['fenster'] : array()) as $nr => $wh) {
        $k = isset($stand['fenster'][(string) $nr]) ? $stand['fenster'][(string) $nr]['kuerzel']
                                                    : ('#' . $nr);
        $zu = isset($bilanz['zu_s'][$nr]) ? (int) $bilanz['zu_s'][$nr] : 0;
        $sp = isset($bilanz['spitze'][$nr]) ? (int) $bilanz['spitze'][$nr]['w'] : 0;
        $z[] = sprintf('%-14s %10d %10s %10s', $k, (int) round($wh),
            sprintf('%d:%02d', (int) floor($zu / 3600), (int) floor(($zu % 3600) / 60)),
            $sp . ' W');
    }
    $z[] = '';
    $z[] = fb_klartext('TEST.BILANZ_RAEUME');
    foreach ((isset($bilanz['raeume']) ? $bilanz['raeume'] : array()) as $raum => $wh) {
        $mm = isset($bilanz['minmax'][$raum]) ? $bilanz['minmax'][$raum] : null;
        $z[] = sprintf('  %-20s %8d Wh%s', $raum, (int) round($wh),
            $mm ? sprintf('   %.1f bis %.1f Grad', $mm['min'], $mm['max']) : '');
    }
    $z[] = '';
    $z[] = sprintf(fb_klartext('TEST.BILANZ_STRAHLUNG'),
                   (float) $bilanz['strahlung'] / 1000.0);
    return implode("\n", $z) . "\n";
}

/** Die Aufheizkonstanten - mit Zahl der Tage UND Streuung. */
function fb_test_lernkurve()
{
    $cfg = fb_config();
    $daten = fb_json_lesen(fb_paths()['lernen']);
    $z = array();
    $z[] = fb_klartext('TEST.LERN_KOPF');
    if (empty($cfg['lernen_ein'])) { $z[] = fb_klartext('TEST.LERN_AUS'); }
    /* DIE WARNUNG GEHOERT VOR DIE ZAHL, NICHT DAHINTER.
     *
     * Die Aufheizkonstante ist der einzige Punkt, an dem dieses Plugin sein
     * Modell an der Wirklichkeit misst. Sie faellt aus dem gerechneten
     * Waermeeintrag, und der haengt geradewegs an der Glasflaeche. Steht
     * dort ueberall noch die Vorgabe, ist die Konstante um genau den Faktor
     * daneben, um den die Flaechen danebenliegen - und sie sieht dabei
     * genauso aus wie eine richtige. Zwei Nachkommastellen taeuschen eine
     * Genauigkeit vor, die es dann nicht gibt. */
    $ohne = fb_flaeche_vorgabe($cfg);
    if ($ohne) {
        $z[] = sprintf(fb_klartext('TEST.LERN_FLAECHE'),
                       count($ohne), fb_liste_kurz($ohne));
    }
    $z[] = '';
    if (!$daten) {
        $z[] = fb_klartext('TEST.LERN_LEER');
        return implode("\n", $z) . "\n";
    }
    $z[] = sprintf('%-20s %6s %14s %10s %8s', fb_klartext('EINST.L_RAUM'),
                   fb_klartext('TEST.LERN_SP_TAGE'), fb_klartext('TEST.LERN_SP_K'),
                   fb_klartext('TEST.LERN_SP_STREU'), 'R2');
    $z[] = str_repeat('-', 62);
    foreach (array_keys($daten) as $raum) {
        list($n, $k, $streu, $r2) = fb_lernkurve($raum, $daten);
        $z[] = sprintf('%-20s %6d %14s %10s %8s', $raum, $n,
            sprintf('%.2f K/kWh', $k), sprintf('%.2f K', $streu), sprintf('%.2f', $r2));
    }
    $z[] = '';
    $z[] = fb_klartext('TEST.LERN_FUSS');
    return implode("\n", $z) . "\n";
}

/** Die Gegenprobe gegen die Ertragsprognose. */
function fb_test_pv()
{
    $cfg = fb_config();
    $z = array();
    $z[] = fb_klartext('TEST.PV_KOPF');
    if (empty($cfg['pv_gegenprobe'])) { $z[] = fb_klartext('TEST.PV_AUS'); }
    $daten = fb_json_lesen(fb_paths()['pv']);
    $tage = isset($daten['tage']) ? $daten['tage'] : array();
    $z[] = '';
    if (!$tage) { $z[] = fb_klartext('TEST.PV_LEER'); return implode("\n", $z) . "\n"; }
    $z[] = sprintf('%-12s %12s %12s %10s', fb_klartext('TEST.PV_SP_TAG'),
                   'Wh/m2 gemessen', 'Wh/m2 Prognose', fb_klartext('TEST.PV_SP_QUOT'));
    $z[] = str_repeat('-', 50);
    foreach (array_slice($tage, -20) as $t) {
        $z[] = sprintf('%-12s %12.0f %12.0f %10s', (string) $t['d'],
            (float) $t['s'], (float) $t['p'],
            (float) $t['p'] > 0 ? sprintf('%.3f', (float) $t['s'] / (float) $t['p']) : '-');
    }
    $pv = fb_pv_pruefen(array_merge($cfg, array('pv_gegenprobe' => 1)), $daten);
    $z[] = '';
    $z[] = sprintf(fb_klartext('TEST.PV_ERGEBNIS'), $pv['tage'], $pv['abweichung'],
                   (int) $cfg['pv_abweichung']);
    if ($pv['warnt']) { $z[] = fb_klartext('TEST.PV_WARNT'); }
    return implode("\n", $z) . "\n";
}

/** Den Tagesbericht ansehen, ohne ihn zu schreiben. */
function fb_test_bericht()
{
    $cfg = fb_config();
    $bilanz = fb_json_lesen(fb_paths()['bilanz']);
    if (empty($bilanz['datum'])) { return fb_klartext('TEST.BILANZ_LEER'); }
    $z = array();
    $z[] = fb_klartext('TEST.BERICHT_KOPF');
    $z[] = '';
    $z[] = fb_bericht_text($cfg, $bilanz, fb_stand());
    $z[] = '';
    $z[] = sprintf(fb_klartext('TEST.BERICHT_FUSS'), (int) $cfg['bericht_stunde'],
                   (string) $bilanz['bericht'] !== '' ? (string) $bilanz['bericht']
                                                      : fb_klartext('ALLG.NIE'));
    return implode("\n", $z) . "\n";
}

/**
 * Eine Adresse abrufen, ohne dass eine Warnung als Befund liegenbleibt.
 *
 * Das vorangestellte @ unterdrueckt die AUSGABE einer Warnung, nicht den
 * Fehler selbst: ein gesetzter Fehlerbehandler bekommt sie weiterhin zu
 * sehen. Ein nicht erreichbarer Endpunkt - also genau der Fall, fuer den
 * die Pruefzeile da ist - hinterliess dadurch im Pruefstand einen Befund,
 * obwohl das Plugin die Lage sauber behandelt und ordentlich meldet.
 *
 * Deshalb wird der Behandler fuer die Dauer des Abrufs ausgetauscht und
 * unmittelbar danach wiederhergestellt. Ein Rueckgabewert false bleibt der
 * Rueckgabewert false - unterdrueckt wird die Meldung, nicht die Aussage.
 */
function fb_holen($adresse, $ctx)
{
    set_error_handler(function () { return true; });
    $text = file_get_contents($adresse, false, $ctx);
    restore_error_handler();
    return $text;
}

/** Die Datei mit dem Rechenlauf - Archiv und Installation unterscheiden sich. */
function fb_lauf_datei()
{
    $p = fb_paths();
    foreach (array($p['bindir'] . '/fb_lauf.php',
                   dirname(dirname(__DIR__)) . '/bin/fb_lauf.php') as $k) {
        if (is_file($k)) { return $k; }
    }
    return '';
}

/* ==================================================================
 * Selbstpruefung
 * ================================================================== */

/**
 * Eine Zeile der Selbstpruefung.
 *
 * $zustand: true = Haken, false = Kreuz, null = Hinweis (weder noch).
 *
 * Der Zaehler steht HIER und nicht am Ende: dort waere er ein
 * substr_count() ueber den fertigen Text und zaehlte die Kopfzeile mit, die
 * die drei Zeichen selbst erklaert. Gezaehlt wird, wo die Zeile entsteht.
 * Aufruf mit $frage === null gibt den Stand zurueck und setzt ihn zurueck.
 */
function fb_pruefzeile($frage, $zustand = null, $antwort = '')
{
    static $zahl = array(0, 0, 0);
    if ($frage === null) {
        $stand = $zahl;
        $zahl = array(0, 0, 0);
        return $stand;
    }
    if ($zustand === true)       { $z = '[ ok ]'; $zahl[0]++; }
    elseif ($zustand === false)  { $z = '[ !! ]'; $zahl[1]++; }
    else                         { $z = '[ -- ]'; $zahl[2]++; }
    return $z . ' ' . $frage . "\n         " . $antwort;
}

/**
 * Nimmt dieses PHP eine Loxone-Projektdatei ueberhaupt entgegen?
 *
 * Die Frage ist nicht theoretisch: die Vorgabe von PHP sind 2 MB je Datei,
 * eine Projektdatei ist 3 bis 4 MB gross. Beantwortet wird sie mit den
 * Werten, die WIRKLICH gelten - und mit der Auskunft, ob die .user.ini des
 * Plugins dabei etwas bewirkt hat.
 *
 * Nicht bestanden heisst hier NICHT "kaputt": der Weg ueber den
 * Ablageordner steht davon unberuehrt offen und kennt keine Grenze.
 */
function fb_probe_upload()
{
    $g = fb_grenzen();
    list($liegt, $greift, $pfad) = fb_user_ini();
    $reicht = $g['grenze'] >= 3 * 1048576;
    $wie = $liegt
        ? ($greift ? fb_klartext('TEST.A_UPLOAD_INI_WIRKT')
                   : fb_klartext('TEST.A_UPLOAD_INI_STUMM'))
        : fb_klartext('TEST.A_UPLOAD_INI_FEHLT');
    return array($reicht, sprintf(
        fb_klartext($reicht ? 'TEST.A_UPLOAD_JA' : 'TEST.A_UPLOAD_NEIN'),
        $g['upload_max_filesize'][0], $g['post_max_size'][0], $wie));
}

/**
 * Tragen Meldungstexte Auszeichnung, obwohl sie maskiert ausgegeben werden?
 *
 * $fb_meldungen und $fb_fehler gehen durch fb_e(). Das ist richtig: in
 * ihnen stehen Dateinamen, Raumschluessel und andere fremde Zeichenketten,
 * und die gehoeren maskiert. Die Folge ist aber, dass ein Text MIT
 * Auszeichnung dort woertlich auf dem Schirm landet - gemeldet wurde genau
 * das: "<b>Das Plugin kann daran nichts aendern</b>" als sichtbarer Text.
 *
 * Gelesen wird die index.php, nicht das gerenderte HTML: welche Schluessel
 * in eine Meldung gehen, steht dort ausgeschrieben, und eine zweite,
 * gepflegte Liste liefe frueher oder spaeter davon weg. Sieben Texte waren
 * betroffen, zwei davon seit der ersten Fassung - beide auf Wegen, die man
 * selten geht.
 *
 * Rueckgabe: array(ok, Text). null heisst "nicht feststellbar" - die
 * index.php liegt im installierten Zustand in einem anderen Baum.
 */
function fb_probe_meldungstexte()
{
    $datei = __DIR__ . '/index.php';
    if (!is_file($datei) || !is_readable($datei)) {
        return array(null, fb_klartext('TEST.A_MELDUNG_KEINE'));
    }
    $quelle = fb_holen_datei($datei);
    if ($quelle === false) {
        return array(null, fb_klartext('TEST.A_MELDUNG_KEINE'));
    }
    /* Jede Anweisung, die etwas an eine der beiden Listen anhaengt - und
     * daraus die Sprachschluessel. Der Ausdruck ist absichtlich genuegsam:
     * lieber ein Schluessel zu viel geprueft als einer zu wenig. */
    $schluessel = array();
    if (preg_match_all('/\$fb_(?:fehler|meldungen)\[\]\s*=(.{0,400}?);/s', $quelle, $mm)) {
        foreach ($mm[1] as $stueck) {
            if (preg_match_all("/fb_t\\('([A-Z0-9_]+\\.[A-Z0-9_]+)'\\)/", $stueck, $kk)) {
                foreach ($kk[1] as $k) { $schluessel[$k] = true; }
            }
        }
    }
    if (!$schluessel) {
        return array(null, fb_klartext('TEST.A_MELDUNG_KEINE'));
    }
    $roh = array();
    foreach (array_keys($schluessel) as $k) {
        if (strpos(fb_t($k), '<') !== false) { $roh[] = $k; }
    }
    if (!$roh) {
        return array(true, sprintf(fb_klartext('TEST.A_MELDUNG_JA'), count($schluessel)));
    }
    return array(false, sprintf(fb_klartext('TEST.A_MELDUNG_NEIN'),
                                count($roh), fb_liste_kurz($roh)));
}

/**
 * Stimmen Reiterleiste, Bereiche und Positivliste ueberein?
 *
 * Alle drei stehen in der index.php ausgeschrieben - genau deshalb koennen
 * sie auseinanderlaufen, und genau dafuer gibt es diese Zeile. Gelesen wird
 * die Datei, nicht das gerenderte HTML: was statisch geschrieben ist, wird
 * statisch verglichen.
 */
function fb_probe_reiter()
{
    $d = __DIR__ . '/index.php';
    if (!is_file($d)) { return array(null, fb_klartext('TEST.P_KEINE_DATEI')); }
    $s = (string) @file_get_contents($d);
    preg_match_all('/data-ziel="(tab-[a-z0-9]+)"/', $s, $a);
    preg_match_all('/class="sm-seite[^"]*"[^>]*id="(tab-[a-z0-9]+)"/', $s, $b);
    preg_match_all("/'(tab-[a-z0-9]+)'/", $s, $c);
    $leiste = array_values(array_unique($a[1]));
    $bereiche = array_values(array_unique($b[1]));
    $liste = array_values(array_unique($c[1]));
    sort($leiste); sort($bereiche); sort($liste);
    $ok = ($leiste === $bereiche && $leiste === $liste && count($leiste) > 0);
    return array($ok, sprintf(fb_klartext('TEST.P_REITER'),
        count($leiste), count($bereiche), count($liste), implode(', ', $leiste)));
}

/**
 * Setzt der Server die Klasse sm-active selbst?
 * Ohne sie ist die Seite leer, sobald das Skript nicht laeuft -
 * .sm-seite steht auf display:none.
 */
function fb_probe_smactive()
{
    $d = __DIR__ . '/index.php';
    if (!is_file($d)) { return array(null, fb_klartext('TEST.P_KEINE_DATEI')); }
    $s = (string) @file_get_contents($d);
    $anzahl   = preg_match_all('/data-ziel="tab-[a-z0-9]+"/', $s);
    $leiste   = preg_match_all('/class="sm-tab<\?=[^>]*sm-active/', $s);
    $bereiche = preg_match_all('/class="sm-seite<\?=[^>]*sm-active/', $s);
    $ok = ($anzahl > 0 && $leiste >= $anzahl && $bereiche >= $anzahl);
    return array($ok, sprintf(fb_klartext('TEST.P_SMACTIVE'), $leiste, $bereiche, $anzahl));
}

/**
 * Traegt jedes Formular das Merkmal gegen fremde Seiten?
 *
 * Der Wachposten am Eingang nuetzt nichts, wenn ein Formular das Merkmal
 * nicht mitschickt - dann tut es einfach nichts mehr, und der Anwender
 * sucht den Fehler bei sich.
 */
function fb_probe_formulare()
{
    $d = __DIR__ . '/index.php';
    if (!is_file($d)) { return array(null, fb_klartext('TEST.P_KEINE_DATEI')); }
    $s = (string) @file_get_contents($d);
    $gesamt = 0; $ohne = 0;
    if (preg_match_all('/<form\s/', $s, $y, PREG_OFFSET_CAPTURE)) {
        foreach ($y[0] as $f) {
            $gesamt++;
            $ende = strpos($s, '</form>', $f[1]);
            $blk  = substr($s, $f[1], ($ende === false ? 400 : $ende - $f[1]));
            if (strpos($blk, 'name="fmt"') === false) { $ohne++; }
        }
    }
    /* Die leere Menge zuerst: "alle 0 von 0 sind in Ordnung" ist kein Haken. */
    if ($gesamt === 0) { return array(false, fb_klartext('TEST.P_KEIN_FORMULAR')); }
    if ($ohne > 0) {
        return array(false, sprintf(fb_klartext('TEST.P_FORM_OHNE'), $ohne, $gesamt));
    }
    return array(true, sprintf(fb_klartext('TEST.P_FORM_OK'), $gesamt));
}

/** Sind alle Kuerzel eindeutig? Zwei gleiche ueberschreiben einander lautlos. */
function fb_probe_kuerzel()
{
    $gesehen = array();
    $doppelt = array();
    foreach (fb_fenster() as $f) {
        $k = strtoupper($f['kuerzel']);
        if (isset($gesehen[$k])) { $doppelt[] = $f['kuerzel']; }
        $gesehen[$k] = true;
    }
    if (!$gesehen) { return array(null, fb_klartext('TEST.P_KEIN_FENSTER')); }
    if ($doppelt) {
        return array(false, sprintf(fb_klartext('TEST.P_KUERZEL_DOPPELT'),
                                    fb_liste_kurz(array_unique($doppelt))));
    }
    return array(true, sprintf(fb_klartext('TEST.P_KUERZEL_OK'), count($gesehen)));
}

/** Ist die Konfiguration vollstaendig - und wenn nicht, welche Schluessel fehlen? */
function fb_probe_konfig()
{
    $p = fb_paths();
    list($roh, $zustand) = fb_json_lesen_geprueft($p['config']);
    if ($zustand === 'kaputt') { return array(false, fb_klartext('TEST.P_CFG_KAPUTT')); }
    if ($zustand === 'fehlt') { $roh = array(); }
    $soll = array_keys(fb_vorgaben());
    $fehlen = array();
    foreach ($soll as $k) {
        if (!array_key_exists($k, $roh)) { $fehlen[] = $k; }
    }
    if (!$fehlen) {
        return array(true, sprintf(fb_klartext('TEST.P_CFG_OK'), count($soll), count($soll)));
    }
    return array(false, sprintf(fb_klartext('TEST.P_CFG_FEHLT'),
        count($soll) - count($fehlen), count($soll), fb_liste_kurz($fehlen)));
}

/** Sind die Messwerte da und frisch genug? */
function fb_probe_messwerte()
{
    $cfg = fb_config();
    $m = fb_messwerte();
    $jetzt = time();
    $fehlen = array();
    $alt = array();
    $gut = 0;
    $noetig = array();
    foreach (fb_messgroessen() as $name => $info) {
        if ($info[0]) { $noetig[] = $name; }
    }
    foreach (fb_fenster() as $f) {
        if (empty($f['raumwerte']) || $f['raum'] === '') { continue; }
        $noetig[] = 'ist.' . $f['raum'];
        $noetig[] = 'grenze.' . $f['raum'];
    }
    $noetig = array_values(array_unique($noetig));
    foreach ($noetig as $name) {
        if (!isset($m[$name])) { $fehlen[] = $name; continue; }
        $a = $jetzt - (int) $m[$name]['t'];
        if ($a > (int) $cfg['hoechstalter']) { $alt[] = $name . ' (' . $a . ' s)'; continue; }
        $gut++;
    }
    if (!$noetig) { return array(null, fb_klartext('TEST.P_KEIN_FENSTER')); }
    if (!$fehlen && !$alt) {
        return array(true, sprintf(fb_klartext('TEST.P_MESS_OK'), $gut, count($noetig)));
    }
    return array(false, sprintf(fb_klartext('TEST.P_MESS_FEHLT'),
        $gut, count($noetig),
        fb_liste_kurz($fehlen), fb_liste_kurz($alt)));
}

/** Ist die erzeugte Loxone-Vorlage wohlgeformtes XML? */
function fb_probe_vorlage()
{
    $fenster = fb_fenster();
    if (!$fenster) { return array(null, fb_klartext('TEST.P_KEIN_FENSTER')); }
    $ergebnis = array();
    foreach (array('vi' => 'fb_vorlage', 'vq' => 'fb_vorlage_out') as $art => $fn) {
        list($name, $inhalt) = $fn();
        $vorher = libxml_use_internal_errors(true);
        $x = simplexml_load_string($inhalt);
        $meldung = '';
        if ($x === false) {
            $e = libxml_get_errors();
            $meldung = $e ? trim($e[0]->message) : 'unbekannt';
        }
        libxml_clear_errors();
        libxml_use_internal_errors($vorher);
        if ($x === false) {
            return array(false, sprintf(fb_klartext('TEST.P_XML_KAPUTT'), $name, $meldung));
        }
        $ergebnis[] = $name . ' (' . count($x->children()) . ')';
    }
    return array(true, sprintf(fb_klartext('TEST.P_XML_OK'), implode(', ', $ergebnis)));
}

function fb_test_selbstpruefung()
{
    $cfg = fb_config();
    $p = fb_paths();
    $stand = fb_stand();
    $mq = fb_mqtt_zustand();
    $fenster = fb_fenster();

    $z = array();
    $z[] = fb_klartext('TEST.KOPF');
    $z[] = str_repeat('-', 68);

    $z[] = fb_pruefzeile(fb_klartext('TEST.F_SPRACHE'), !fb_sprache_fehlt(),
        fb_sprache_fehlt() ? fb_klartext('TEST.A_SPRACHE_NEIN')
                           : sprintf(fb_klartext('TEST.A_SPRACHE_JA'), fb_langdir(), fb_sprache()));

    $ohne_ort = (abs((float) $cfg['breite']) < 0.001 && abs((float) $cfg['laenge']) < 0.001);
    $z[] = fb_pruefzeile(fb_klartext('TEST.F_STANDORT'), !$ohne_ort,
        $ohne_ort ? fb_klartext('TEST.A_STANDORT_NEIN')
                  : sprintf(fb_klartext('TEST.A_STANDORT_JA'), $cfg['breite'], $cfg['laenge']));

    $z[] = fb_pruefzeile(fb_klartext('TEST.F_FENSTER'), count($fenster) > 0,
        sprintf(fb_klartext('TEST.A_FENSTER'), count($fenster), FB_FENSTER));

    list($ok, $txt) = fb_probe_kuerzel();
    $z[] = fb_pruefzeile(fb_klartext('TEST.F_KUERZEL'), $ok, $txt);

    list($ok, $txt) = fb_probe_konfig();
    $z[] = fb_pruefzeile(fb_klartext('TEST.F_KONFIG'), $ok, $txt);

    list($ok, $txt) = fb_probe_messwerte();
    $z[] = fb_pruefzeile(fb_klartext('TEST.F_MESSWERTE'), $ok, $txt);

    list($ok, $txt) = fb_probe_meldungstexte();
    $z[] = fb_pruefzeile(fb_klartext('TEST.F_MELDUNG'), $ok, $txt);

    list($ok, $txt) = fb_probe_upload();
    $z[] = fb_pruefzeile(fb_klartext('TEST.F_UPLOAD'), $ok, $txt);

    $alter = fb_alter();
    $z[] = fb_pruefzeile(fb_klartext('TEST.F_LAUF'),
        $alter >= 0 ? ($alter < 900) : false,
        $alter < 0 ? fb_klartext('TEST.A_LAUF_NIE')
                   : sprintf(fb_klartext('TEST.A_LAUF'), $alter));

    /* Der Cron ist die einzige Stelle, die von selbst rechnet, wenn keine
     * Messwerte hereinkommen. Fehlt er, faellt das erst auf, wenn jemand
     * merkt, dass sich nichts mehr bewegt - und das kann Wochen dauern. */
    $cron = '';
    foreach (array($p['home'] . '/system/cron/cron.05min/' . $p['plugin'],
                   dirname(dirname(__DIR__)) . '/cron/cron.05min') as $k) {
        if ($k !== '' && is_file($k)) { $cron = $k; break; }
    }
    $z[] = fb_pruefzeile(fb_klartext('TEST.F_CRON'), $cron !== '',
        $cron !== '' ? sprintf(fb_klartext('TEST.A_CRON_JA'), $cron)
                     : fb_klartext('TEST.A_CRON_NEIN'));

    $lauf = fb_lauf_datei();
    $z[] = fb_pruefzeile(fb_klartext('TEST.F_LAUFDATEI'), $lauf !== '',
        $lauf !== '' ? $lauf : fb_klartext('TEST.A_LAUFDATEI_NEIN'));

    $token = trim((string) $cfg['aktionstoken']);
    $z[] = fb_pruefzeile(fb_klartext('TEST.F_TOKEN'), $token !== '',
        $token !== '' ? sprintf(fb_klartext('TEST.A_TOKEN_JA'), strlen($token))
                      : fb_klartext('TEST.A_TOKEN_NEIN'));

    list($ok, $txt) = fb_probe_endpunkt();
    $z[] = fb_pruefzeile(fb_klartext('TEST.F_ENDPUNKT'), $ok, $txt);

    list($ok, $txt) = fb_probe_vorlage();
    $z[] = fb_pruefzeile(fb_klartext('TEST.F_XML'), $ok, $txt);

    list($ok, $txt) = fb_felder_kongruent();
    $z[] = fb_pruefzeile(fb_klartext('TEST.F_FELDER'), $ok, $txt);

    list($ok, $txt) = fb_mqtt_kongruent();
    $z[] = fb_pruefzeile(fb_klartext('TEST.F_MQTT_THEMEN'), $ok, $txt);

    /* --- Die Neuerungen der 0.10.0, jede mit ihrem eigenen Zustand --- */
    $z[] = fb_pruefzeile(fb_klartext('TEST.F_VORSCHAU'),
        (int) $cfg['vorschau'] > 0 ? true : null,
        (int) $cfg['vorschau'] > 0
            ? sprintf(fb_klartext('TEST.A_VORSCHAU_JA'), (int) round($cfg['vorschau'] / 60))
            : fb_klartext('TEST.A_VORSCHAU_AUS'));

    $bilanz_t = fb_json_lesen($p['bilanz']);
    $z[] = fb_pruefzeile(fb_klartext('TEST.F_BILANZ'),
        !empty($bilanz_t['letzte']),
        empty($bilanz_t['letzte'])
            ? fb_klartext('TEST.A_BILANZ_NIE')
            : sprintf(fb_klartext('TEST.A_BILANZ'),
                      (string) $bilanz_t['datum'],
                      array_sum($bilanz_t['fenster']) / 1000.0,
                      count($bilanz_t['raeume'])));

    if (!empty($cfg['lernen_ein'])) {
        $lern_t = fb_json_lesen($p['lernen']);
        $tage_min = 0;
        foreach (array_keys($lern_t) as $r) {
            $x = fb_lernkurve($r, $lern_t);
            if ($tage_min === 0 || $x[0] < $tage_min) { $tage_min = $x[0]; }
        }
        $z[] = fb_pruefzeile(fb_klartext('TEST.F_LERNEN'),
            $tage_min >= 20 ? true : null,
            $lern_t ? sprintf(fb_klartext('TEST.A_LERNEN'), count($lern_t), $tage_min)
                    : fb_klartext('TEST.LERN_LEER'));
    }

    if (!empty($cfg['pv_gegenprobe'])) {
        $pv_t = fb_pv_pruefen($cfg);
        $z[] = fb_pruefzeile(fb_klartext('TEST.F_PV'),
            $pv_t['tage'] < 10 ? null : ($pv_t['warnt'] ? false : true),
            $pv_t['tage'] < 10
                ? sprintf(fb_klartext('TEST.A_PV_WENIG'), $pv_t['tage'])
                : sprintf(fb_klartext('TEST.A_PV'), $pv_t['abweichung'], $pv_t['tage']));
    }

    if (!empty($cfg['stellung_ein'])) {
        $st_t = isset($stand['nicht_gefahren']) ? (int) $stand['nicht_gefahren'] : 0;
        $z[] = fb_pruefzeile(fb_klartext('TEST.F_STELLUNG'), $st_t === 0,
            $st_t === 0 ? fb_klartext('TEST.A_STELLUNG_OK')
                        : sprintf(fb_klartext('TEST.A_STELLUNG_ABW'), $st_t));
    }

    list($ok, $txt) = fb_probe_reiter();
    $z[] = fb_pruefzeile(fb_klartext('TEST.F_REITER'), $ok, $txt);

    list($ok, $txt) = fb_probe_smactive();
    $z[] = fb_pruefzeile(fb_klartext('TEST.F_SMACTIVE'), $ok, $txt);

    list($ok, $txt) = fb_probe_formulare();
    $z[] = fb_pruefzeile(fb_klartext('TEST.F_FORMULARE'), $ok, $txt);

    /* MQTT: drei getrennte Aussagen, nicht eine. */
    if (empty($cfg['mqtt_ein'])) {
        $z[] = fb_pruefzeile(fb_klartext('TEST.F_MQTT'), null, fb_klartext('TEST.A_MQTT_AUS'));
    } else {
        $z[] = fb_pruefzeile(fb_klartext('TEST.F_MQTT'), (bool) $mq['gefunden'],
            $mq['gefunden'] ? sprintf(fb_klartext('TEST.A_MQTT_JA'), $mq['udpport'],
                                      $mq['fassung'] ? (string) $mq['fassung']
                                                     : fb_klartext('ALLG.UNBEKANNT'))
                            : fb_klartext('TEST.A_MQTT_NEIN'));
        $z[] = fb_pruefzeile(fb_klartext('TEST.F_AUTOSTART'), (bool) $mq['autostart'],
            $mq['autostart'] ? fb_klartext('TEST.A_AUTOSTART_JA')
                             : fb_klartext('TEST.A_AUTOSTART_NEIN'));
        $z[] = fb_pruefzeile(fb_klartext('TEST.F_SOCKETS'), function_exists('socket_create'),
            function_exists('socket_create') ? fb_klartext('TEST.A_SOCKETS_JA')
                                             : fb_klartext('TEST.A_SOCKETS_NEIN'));
    }

    /* Der Rechenkern zuletzt - er ist die teuerste Zeile, weil sie einen
     * eigenen PHP-Prozess kostet. Ausgewertet wird der RUECKGABEWERT, nicht
     * der Text: ein PHP, das mit einem toedlichen Fehler abbricht, schreibt
     * unter Umstaenden gar nichts, und "keine Ausgabe" saehe dann aus wie
     * "nichts zu beanstanden". */
    if ($lauf !== '') {
        $aus = array(); $rc = 0;
        @exec('php ' . escapeshellarg($lauf) . ' --selbsttest 2>&1', $aus, $rc);
        $letzte = $aus ? trim(end($aus)) : '';
        $z[] = fb_pruefzeile(fb_klartext('TEST.F_KERN'), $rc === 0,
            $letzte !== '' ? $letzte : sprintf(fb_klartext('TEST.A_KERN_STUMM'), $rc));
    } else {
        $z[] = fb_pruefzeile(fb_klartext('TEST.F_KERN'), null,
                             fb_klartext('TEST.A_LAUFDATEI_NEIN'));
    }

    $stand_zahl = fb_pruefzeile(null);
    $z[] = str_repeat('-', 68);
    $z[] = sprintf(fb_klartext('TEST.SUMME'), $stand_zahl[0], $stand_zahl[1], $stand_zahl[2]);
    return implode("\n", $z) . "\n";
}

/* ==================================================================
 * Die einzelnen Knoepfe
 * ================================================================== */

function fb_test_sonne()
{
    $cfg = fb_config();
    if (abs((float) $cfg['breite']) < 0.001 && abs((float) $cfg['laenge']) < 0.001) {
        return fb_klartext('TEST.A_STANDORT_NEIN');
    }
    $jetzt = time();
    $s = fb_sonnenstand($jetzt, $cfg['breite'], $cfg['laenge']);
    $z = array();
    $z[] = sprintf(fb_klartext('TEST.SONNE_KOPF'), date('d.m.Y H:i:s', $jetzt),
                   $cfg['breite'], $cfg['laenge']);
    $z[] = sprintf(fb_klartext('TEST.SONNE_STAND'), $s['hoehe'], $s['azimut'],
                   $s['deklination'], $s['zeitgleichung']);
    $z[] = '';
    $z[] = sprintf('%-10s %6s %8s %8s %8s %8s %7s', 'Kuerzel', 'Dir', 'Einfall',
                   'Horizont', 'W/m2', 'Watt', 'Urteil');
    $z[] = str_repeat('-', 64);
    /* JETZT rechnen, nicht den abgelegten Stand anzeigen.
     *
     * Hier stand zuerst der Stand aus stand.json - und darueber ein
     * Sonnenstand von diesem Augenblick. Beim ersten Durchlauf zeigte die
     * Tabelle deshalb Watt-Werte von 10:00 Uhr neben einem Sonnenstand von
     * 12:44. Zwei Zeitpunkte in einer Tabelle sind schlimmer als einer:
     * beide Zahlen sind fuer sich richtig, und zusammen ergeben sie eine
     * falsche Aussage. Geschrieben wird dabei nichts - fb_rechnen() fasst
     * keine Datei an. */
    $stand = fb_rechnen($cfg, fb_messwerte(), $jetzt, fb_stand());
    foreach (fb_fenster() as $nr => $f) {
        $ct = fb_cos_einfall($s['azimut'], $s['hoehe_geo'], $f['azimut'], $f['neigung']);
        list($punkte, ) = fb_horizont_lesen($f['horizont']);
        $hind = count($punkte) > 0 ? fb_horizont_hoehe($punkte, $s['azimut']) : -1.0;
        $e = isset($stand['fenster'][(string) $nr]) ? $stand['fenster'][(string) $nr] : array();
        $z[] = sprintf('%-10s %6d %8s %8s %8s %8s %7s',
            $f['kuerzel'], $f['azimut'],
            $ct > 0 ? sprintf('%.0f', rad2deg(acos(min(1.0, $ct)))) : '-',
            $hind >= 0 ? sprintf('%.0f', $hind) : '-',
            isset($e['glas']) ? (string) (int) $e['glas'] : '-',
            isset($e['watt']) ? (string) (int) $e['watt'] : '-',
            isset($e['urteil'])
                ? sprintf('%+d%s', (int) $e['urteil'], !empty($e['beschatten']) ? '*' : '')
                : '-');
    }
    if (!empty($stand['fehlend'])) {
        $z[] = '';
        $z[] = sprintf(fb_klartext('TEST.RECHNEN_FEHLT'), fb_liste_kurz($stand['fehlend']));
    }
    $z[] = '';
    $z[] = fb_klartext('TEST.TAGESGANG_FUSS');
    return implode("\n", $z) . "\n";
}

/**
 * Der Tagesgang: was haette das Plugin heute stuendlich geurteilt?
 *
 * Gerechnet wird mit den GERADE geltenden Messwerten und nur die Zeit
 * verschoben. Das ist ausdruecklich KEINE Vorhersage - die Wolken von
 * heute Nachmittag kennt niemand. Es beantwortet die Frage, die beim
 * Einrichten wirklich zaehlt: wann steht die Sonne an welchem Fenster.
 */
function fb_test_tagesgang()
{
    $cfg = fb_config();
    if (abs((float) $cfg['breite']) < 0.001 && abs((float) $cfg['laenge']) < 0.001) {
        return fb_klartext('TEST.A_STANDORT_NEIN');
    }
    $fenster = fb_fenster();
    if (!$fenster) { return fb_klartext('TEST.P_KEIN_FENSTER'); }
    $messwerte = fb_messwerte();
    $heute = mktime(0, 0, 0);
    $z = array();
    $z[] = fb_klartext('TEST.TAGESGANG_KOPF');
    $z[] = '';
    $kopf = sprintf('%5s %6s %6s', 'Zeit', 'Hoehe', 'Azim');
    foreach ($fenster as $f) { $kopf .= sprintf(' %8s', substr($f['kuerzel'], 0, 8)); }
    $z[] = $kopf;
    $z[] = str_repeat('-', strlen($kopf));
    for ($stunde = 4; $stunde <= 21; $stunde++) {
        $t = $heute + $stunde * 3600;
        /* Die Messwerte auf den gerechneten Zeitpunkt umdatieren, sonst
         * gelten sie als zu alt und der Lauf meldet ueberall "keine Daten".
         * Das ist eine SICHT, keine Messung - und es steht in der Kopfzeile. */
        $m = array();
        foreach ($messwerte as $k => $v) { $m[$k] = array('v' => $v['v'], 't' => $t); }
        $s = fb_rechnen($cfg, $m, $t, array());
        $zeile = sprintf('%5s %6.1f %6.1f', date('H:i', $t),
                         $s['sonne_hoehe'], $s['sonne_azimut']);
        foreach ($fenster as $nr => $f) {
            $e = isset($s['fenster'][(string) $nr]) ? $s['fenster'][(string) $nr] : null;
            $zeile .= sprintf(' %8s', $e === null ? '-'
                : sprintf('%+d%s', (int) $e['urteil'], $e['beschatten'] ? '*' : ' '));
        }
        $z[] = $zeile;
    }
    $z[] = '';
    $z[] = fb_klartext('TEST.TAGESGANG_FUSS');
    return implode("\n", $z) . "\n";
}

function fb_test_selbsttest()
{
    $lauf = fb_lauf_datei();
    if ($lauf === '') { return fb_klartext('TEST.A_LAUFDATEI_NEIN'); }
    $aus = array(); $rc = 0;
    @exec('php ' . escapeshellarg($lauf) . ' --selbsttest 2>&1', $aus, $rc);
    return sprintf(fb_klartext('TEST.SELBSTTEST_KOPF'), $lauf, $rc) . "\n\n"
         . implode("\n", $aus) . "\n";
}

function fb_test_zeile()
{
    $zeile = fb_zeile(fb_stand());
    return fb_klartext('TEST.ZEILE_KOPF') . "\n\n" . $zeile . "\n"
         . sprintf(fb_klartext('TEST.ZEILE_FUSS'), strlen($zeile),
                   substr_count($zeile, "\n")) . "\n";
}

function fb_test_messwerte()
{
    $cfg = fb_config();
    $m = fb_messwerte();
    if (!$m) { return fb_klartext('TEST.MESS_LEER'); }
    ksort($m);
    $jetzt = time();
    $z = array();
    $z[] = sprintf(fb_klartext('TEST.MESS_KOPF'), (int) $cfg['hoechstalter']);
    $z[] = '';
    $z[] = sprintf('%-22s %10s %10s  %s', 'Name', 'Wert', 'Alter/s', 'Zustand');
    $z[] = str_repeat('-', 60);
    foreach ($m as $name => $v) {
        $a = $jetzt - (int) $v['t'];
        $z[] = sprintf('%-22s %10s %10d  %s', $name, (string) $v['v'], $a,
            $a > (int) $cfg['hoechstalter'] ? fb_klartext('TEST.MESS_ZU_ALT')
                                            : fb_klartext('TEST.MESS_FRISCH'));
    }
    return implode("\n", $z) . "\n";
}

function fb_test_vorlage()
{
    $fenster = fb_fenster();
    if (!$fenster) { return fb_klartext('TEST.P_KEIN_FENSTER'); }
    $z = array();
    foreach (array('fb_vorlage', 'fb_vorlage_out') as $fn) {
        list($name, $inhalt) = $fn();
        $vorher = libxml_use_internal_errors(true);
        $x = simplexml_load_string($inhalt);
        $meldung = '';
        if ($x === false) {
            $e = libxml_get_errors();
            $meldung = $e ? trim($e[0]->message) : 'unbekannt';
        }
        libxml_clear_errors();
        libxml_use_internal_errors($vorher);
        $crlf = substr_count($inhalt, "\r\n");
        $lf = substr_count($inhalt, "\n");
        $z[] = sprintf(fb_klartext('TEST.VORLAGE_ZEILE'), $name,
            $x === false ? fb_klartext('ALLG.NEIN') . ' - ' . $meldung : fb_klartext('ALLG.JA'),
            $x === false ? 0 : count($x->children()),
            $crlf, $lf, strlen($inhalt));
    }
    $z[] = '';
    $z[] = fb_klartext('TEST.VORLAGE_FUSS');
    return implode("\n", $z) . "\n";
}

/**
 * DER EIGENE ENDPUNKT, ueber HTTP und wirklich abgerufen.
 *
 * Das ist die eine Zeile dieser Seite, die den getrennten Baeumen auf die
 * Finger sieht. Im entpackten Archiv liegen html/ und htmlauth/
 * nebeneinander, installiert in zwei verschiedenen Baeumen; ein require, das
 * im Archiv aufgeht, endet dort mit einem leeren HTTP 500. Und den sieht
 * niemand: der einzige Aufrufer ist der Miniserver, und der liest kein
 * Fehlerprotokoll. In diesem Haus ist genau daran ein Plugin ueber acht
 * Fassungen vorbeigelaufen.
 *
 * Keine Leseprüfung findet das. Nur ein echter Abruf.
 *
 * Dazu die GEGENPROBE mit einem falschen Wortzeichen: wird sie nicht
 * abgewiesen, steht der Endpunkt offen - und das ist wichtiger als jedes
 * andere Ergebnis auf dieser Seite. Ohne sie bestuende auch ein Endpunkt,
 * der auf alles mit derselben Zeile antwortet.
 */
function fb_test_endpunkt()
{
    $adresse = fb_endpunkt() . '?token=' . fb_token() . '&aktion=status';
    $o = array(sprintf(fb_klartext('TEST.EP_AUFRUF'), $adresse), '');
    /* Jeder Netzabruf traegt eine Zeitschranke, und Weiterleitungen werden
     * abgeschaltet: file_get_contents folgt von sich aus bis zu zwanzigmal
     * und schickt dabei mitgegebene Kopfzeilen erneut. */
    $ctx = stream_context_create(array('http' => array(
        'timeout' => 10, 'ignore_errors' => true,
        'follow_location' => 0, 'max_redirects' => 1)));
    /* DAS @ GENUEGT HIER NICHT.
     *
     * Es schaltet die AUSGABE einer Warnung ab, nicht den Fehler - ein
     * gesetzter Fehlerbehandler sieht sie trotzdem. Genau das ist der Fall,
     * fuer den es diese Zeile gibt: ist der Endpunkt nicht erreichbar,
     * warnt file_get_contents, und im Pruefstand stand die Warnung danach
     * als Befund da, obwohl das Plugin den Fall sauber behandelt.
     *
     * Deshalb wird der Behandler fuer die Dauer des Abrufs durch einen
     * ersetzt, der schweigt, und danach wiederhergestellt. */
    $text = fb_holen($adresse, $ctx);
    if ($text === false) {
        $o[] = fb_klartext('TEST.EP_FEHL');
        return implode("\n", $o);
    }
    $o[] = $text;
    $o[] = '';
    $o[] = fb_klartext('TEST.EP_GEGENPROBE');
    $falsch = fb_holen(fb_endpunkt() . '?token=falsch&aktion=status', $ctx);
    $o[] = ($falsch !== false && strpos((string) $falsch, 'GRUND=TOKEN') !== false)
        ? fb_klartext('TEST.EP_ABGEWIESEN')
        : sprintf(fb_klartext('TEST.EP_OFFEN'), substr((string) $falsch, 0, 200));
    return implode("\n", $o);
}

/**
 * Antwortet der eigene Endpunkt - als PRUEFZEILE, kurz und mit Urteil.
 *
 * Rueckgabe: array(ok, Text). Der Strich (null) steht fuer "hier nicht
 * feststellbar": ohne Wortzeichen gibt es nichts abzurufen.
 */
function fb_probe_endpunkt()
{
    $token = trim((string) fb_config()['aktionstoken']);
    if ($token === '') { return array(null, fb_klartext('TEST.A_TOKEN_NEIN')); }
    $ctx = stream_context_create(array('http' => array(
        'timeout' => 10, 'ignore_errors' => true,
        'follow_location' => 0, 'max_redirects' => 1)));
    $adresse = fb_endpunkt() . '?token=' . $token . '&selftest=1';
    $text = fb_holen($adresse, $ctx);
    if ($text === false || strpos((string) $text, 'SELBSTTEST;OK=1') === false) {
        return array(false, sprintf(fb_klartext('TEST.P_EP_FEHL'), $adresse,
                     $text === false ? '-' : substr(trim((string) $text), 0, 120)));
    }
    /* Die Gegenprobe gehoert in dieselbe Zeile: ein Endpunkt, der antwortet,
     * aber jedem antwortet, ist schlimmer als einer, der schweigt. */
    $falsch = fb_holen(fb_endpunkt() . '?token=falsch&selftest=1', $ctx);
    if ($falsch === false || strpos((string) $falsch, 'GRUND=TOKEN') === false) {
        return array(false, fb_klartext('TEST.P_EP_OFFEN'));
    }
    return array(true, fb_klartext('TEST.P_EP_OK'));
}

function fb_test_rechnen()
{
    list($gerechnet, $stand) = fb_lauf(true);
    $z = array();
    $z[] = sprintf(fb_klartext('TEST.RECHNEN_KOPF'), date('d.m.Y H:i:s', (int) $stand['ts']));
    if ($stand['meldung'] === 'KEIN_STANDORT') {
        $z[] = fb_klartext('TEST.A_STANDORT_NEIN');
        return implode("\n", $z) . "\n";
    }
    $z[] = sprintf(fb_klartext('TEST.RECHNEN_SONNE'),
        $stand['sonne_hoehe'], $stand['sonne_azimut'], $stand['saison']);
    if (!empty($stand['fehlend'])) {
        $z[] = sprintf(fb_klartext('TEST.RECHNEN_FEHLT'), implode(', ', $stand['fehlend']));
    }
    $z[] = '';
    foreach ((isset($stand['fenster']) ? $stand['fenster'] : array()) as $e) {
        $z[] = sprintf('%-10s %+5d %s  %s', $e['kuerzel'], (int) $e['urteil'],
            $e['beschatten'] ? '[beschatten]' : '[           ]',
            fb_klartext('GRUND.' . strtoupper($e['grund'])));
        if ($e['begruendung'] !== '') { $z[] = '           ' . $e['begruendung']; }
    }
    return implode("\n", $z) . "\n";
}
