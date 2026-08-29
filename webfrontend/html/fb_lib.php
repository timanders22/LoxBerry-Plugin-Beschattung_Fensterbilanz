<?php
/**
 * Fensterbilanz - gemeinsame Bibliothek
 *
 * Liegt unter webfrontend/html/, weil der Miniserver-Endpunkt sie ebenso
 * braucht wie die Oberflaeche. html/ und htmlauth/ liegen auf dem
 * installierten LoxBerry in GETRENNTEN Baeumen; ein require aus html/ nach
 * htmlauth/ zeigt dort ins Leere und endet mit einem leeren HTTP 500, den
 * ausser dem Miniserver niemand sieht.
 *
 * Praefix 'fb_', weil LBWeb::lbheader() SDK-Globale setzt.
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 *
 * WAS DIESES PLUGIN TUT UND WAS NICHT
 * -----------------------------------
 * Es faellt je Fenster ein Urteil darueber, ob der Sonneneintrag durch das
 * Glas gerade erwuenscht ist - eine Zahl von -100 (unbedingt beschatten) bis
 * +100 (Sonne hereinlassen) und ein Digitalwert daneben.
 *
 * ES SCHALTET NICHTS. Kein Rollladen wird von hier gefahren. Der Wert geht
 * nach Loxone, und dort entscheidet der AutoJalousie-Baustein, der die
 * Winkel- und Lamellenrechnung ohnehin besser kann. Ein Plugin, das
 * Rolllaeden faehrt, ist bei einem Netzausfall eine Gefahr und bei einem
 * eigenen Fehler nicht abschaltbar.
 *
 * ES MISST NICHTS SELBST. Strahlung, Aussentemperatur, Raumtemperaturen und
 * die Tagesprognose kommen aus dem Miniserver herein, ueber den
 * tokengeschuetzten Endpunkt. Eine zweite Wetterquelle waere nur eine dritte
 * Meinung.
 *
 * FAIL CLOSED HEISST HIER: KEIN URTEIL. Fehlt ein Messwert oder ist er zu
 * alt, wird nicht geraten. Dann steht OK auf 0, das Urteil auf 0 und
 * BESCHATTEN auf 0 - und in Loxone greift wieder die eigene Freigabe des
 * AutoJalousie. Ein Plugin, das bei fehlenden Daten "beschatten" sagt,
 * verdunkelt das Haus, sobald ein Kabel wackelt.
 */

/* Den LoxBerry-Wurzelordner ohne festen Systempfad bestimmen. */
if (!function_exists('lb_wurzel_ermitteln')) {
    function lb_wurzel_ermitteln()
    {
        $d = __DIR__;
        for ($i = 0; $i < 8; $i++) {
            if (is_dir($d . '/config/plugins') && is_dir($d . '/webfrontend')) {
                return $d;
            }
            $eltern = dirname($d);
            if ($eltern === $d) { break; }
            $d = $eltern;
        }
        return '';
    }
}

require_once __DIR__ . '/fb_sonne.php';

if (!function_exists('fb_e')) {
    function fb_e($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
}
function fb_x($s) { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8'); }

/* So viele Fensterzeilen fuehrt die Oberflaeche. Die Anlage, fuer die dieses
 * Plugin entstanden ist, hat 25 beschattbare Fenster; 30 laesst Luft, ohne
 * die Seite unbedienbar lang zu machen. Wer mehr braucht, erhoeht die Zahl -
 * bestehende Zeilen behalten dabei ihre Nummer, denn gezaehlt wird nach der
 * ZEILE und nicht nach den belegten Zeilen. */
define('FB_FENSTER', 30);

/**
 * Die Pfade. $neu = true wirft den gemerkten Stand weg.
 *
 * Das Geruest in REGELN_2 holt die Pfade nach dem Einbinden des SDK ein
 * zweites Mal, weil loxberry_system.php die Umgebung veraendern kann. Ohne
 * den Schalter waere dieser zweite Aufruf wirkungslos.
 */
function fb_paths($neu = false)
{
    static $p = null;
    if ($p !== null && !$neu) { return $p; }
    $home = getenv('LBHOMEDIR');
    if (!$home || !is_dir($home)) { $home = lb_wurzel_ermitteln(); }
    $dir = getenv('LBPPLUGINDIR');
    if (!$dir) { $dir = basename(dirname(__FILE__)); }
    if ($dir === '' || $dir === '.' || $dir === '/' || $dir === 'html' || $dir === 'plugins') {
        $dir = 'fensterbilanz';
    }
    $basis = $home !== '' ? $home : dirname(dirname(__DIR__));
    $p = array(
        'home'      => $home,
        'plugin'    => $dir,
        'configdir' => $basis . '/config/plugins/' . $dir,
        'config'    => $basis . '/config/plugins/' . $dir . '/fensterbilanz.json',
        'sicherung' => $basis . '/config/plugins/' . $dir . '.backup.json',
        'datadir'   => $basis . '/data/plugins/' . $dir,
        'stand'     => $basis . '/data/plugins/' . $dir . '/stand.json',
        'messwerte' => $basis . '/data/plugins/' . $dir . '/messwerte.json',
        /* Diese drei ueberleben ein Update mit Absicht (postupgrade.sh
         * loescht sie NICHT): in ihnen steckt alles, was ueber Tage und
         * Wochen zusammengetragen wurde und sich nicht nachrechnen laesst. */
        'bilanz'    => $basis . '/data/plugins/' . $dir . '/bilanz.json',
        'lernen'    => $basis . '/data/plugins/' . $dir . '/lernen.json',
        'pv'        => $basis . '/data/plugins/' . $dir . '/pv.json',
        'logdir'    => $basis . '/log/plugins/' . $dir,
        'log'       => $basis . '/log/plugins/' . $dir . '/fensterbilanz.log',
        'lauflog'   => $basis . '/log/plugins/' . $dir . '/lauf.out',
        'bindir'    => $basis . '/bin/plugins/' . $dir,
    );
    return $p;
}

/* ==================================================================
 * Konfiguration
 * ================================================================== */

function fb_fenster_vorgabe()
{
    return array(
        'kuerzel'   => '',
        'name'      => '',
        'aktiv'     => 1,
        'azimut'    => 180,     // Grad von Nord im Uhrzeigersinn, wie Loxone Dir
        'neigung'   => 90,      // 90 = senkrechtes Fenster
        'flaeche'   => 1.5,     // Glasflaeche in m2
        'gwert'     => 60,      // g-Wert der Verglasung in Prozent
        'horizont'  => '',      // Stuetzpunkte "80:22, 110:14"
        'raum'      => '',      // Schluessel der Raumwerte
        'raumwerte' => 1,       // Raumwerte ueberhaupt verwenden?
        'traegheit' => 0,       // Zehntelkelvin Zuschlag wegen Speichermasse
        /* Blendschutz - eine EIGENE Aussage, die nicht ins Urteil einfliesst.
         * Tief stehende Sonne blendet auch im Januar, wenn die Waerme
         * hochwillkommen ist. Wer beides in eine Zahl mischt, kann hinterher
         * nicht mehr sagen, warum ein Fenster zu ist. 0 = abgeschaltet. */
        /* ---- Dachueberstand ----
         * Das haeufigste Verschattungselement am Haus - und eines, das der
         * Verschattungshorizont nicht abbilden kann: der Horizont nimmt die
         * Sonne weg, wenn sie TIEF steht, ein Ueberstand, wenn sie HOCH
         * steht. Alles in Zentimetern, weil ein Zollstock keine Meter mit
         * Nachkommastellen anzeigt.
         *
         * 0 fuer die Auskragung heisst "kein Ueberstand" und schaltet die
         * ganze Rechnung ab. */
        'dach_tiefe'   => 0,      // Auskragung in cm
        'dach_hoehe'   => 30,     // cm zwischen Ueberstand und Fensteroberkante
        'fenster_hoehe' => 140,   // Hoehe des Fensters in cm
        'blend_hoehe'  => 0,    // Grad Sonnenhoehe, darunter blendet es
        'blend_winkel' => 40,   // Grad Einfallswinkel, darunter blendet es
        /* Nachtdaemmung: soll dieses Fenster nachts geschlossen werden? */
        'daemmen'      => 1,
    );
}

/**
 * Die Vorgabewerte. Diese Liste ist die EINZIGE Quelle - fb_config() merkt
 * sie ein, fb_cfg_vervollstaendigen() schreibt sie einmalig in die Datei,
 * und der Reiter Test zaehlt beide gegeneinander.
 *
 * Standort: die Vorgabe ist bewusst der Nullpunkt und nicht ein Ort. Ein
 * eingetragener Ort, den niemand gewaehlt hat, waere von einem gewaehlten
 * nicht zu unterscheiden - und ein Sonnenstand fuer den falschen Ort sieht
 * genauso richtig aus wie einer fuer den richtigen. Ohne Standort rechnet
 * das Plugin nicht und sagt das.
 */
function fb_vorgaben()
{
    return array(
        'fenster'        => array(),
        'breite'         => 0.0,   // Grad Nord
        'laenge'         => 0.0,   // Grad Ost
        'albedo'         => 20,    // Bodenreflex in Prozent
        'iam_b0'         => 10,    // ASHRAE-Beiwert * 100 (0,10 = klares Zweischeibenglas)
        'e_ref'          => 250,   // W/m2 am Glas, ab hier zaehlt das Urteil voll
        /* Tagesgrenze: der ERWARTETE TAGESHOECHSTWERT, ab dem der
         * Sonneneintrag Last statt Gewinn ist.
         *
         * Nicht zu verwechseln mit der Heizgrenztemperatur der Bauphysik
         * (ueblich 15 Grad C). Die bezieht sich auf das TAGESMITTEL; wir
         * bekommen aus Weather4Loxone aber den Hoechstwert, und der liegt
         * in der Uebergangszeit rund 5 K darueber. 20 ist also dieselbe
         * Aussage, nur in der Groesse gemessen, die wirklich vorliegt.
         *
         * WARUM DIESE GROESSE UND NICHT DIE AUSSENLUFT: am 23.08.2026
         * standen 19,5 Grad draussen und 32,7 Grad im Wohnzimmer. Die
         * Aussenluft haette "hereinlassen" gesagt, der Tag wurde 28 Grad.
         * Genau daran ist die Freigabe in Loxone gescheitert - nicht weil
         * sie kaputt war, sondern weil sie die falsche Groesse fragte. */
        'tagesgrenze'    => 20,    // Grad C erwarteter Tageshoechstwert
        'spreizung_tag'  => 6,     // Kelvin, ueber die der Tagesteil von +1 auf -1 laeuft
        'spreizung_raum' => 20,    // ZEHNTELkelvin, dasselbe fuer den Raumteil
        'gewicht_raum'   => 40,    // Prozent
        'gewicht_tag'    => 60,    // Prozent
        'schwelle_ein'   => 30,    // Urteil <= -30  ->  beschatten
        'schwelle_aus'   => 15,    // Urteil >= -15  ->  wieder freigeben
        'hoechstalter'   => 900,   // Sekunden, aeltere Messwerte gelten nicht mehr
        'rechentakt'     => 60,    // Sekunden Mindestabstand zweier Rechnungen

        /* ---- Vorausschau ----
         * Der AutoJalousie-Baustein hat eine Verzoegerung (AutoShadeTime),
         * damit eine einzelne Wolke nichts ausloest. Ein Plugin, das nur den
         * Augenblick meldet, beschattet dadurch systematisch ZU SPAET. Der
         * Sonnenstand ist reine Rechnung - eine zweite Rechnung fuer den
         * Zeitpunkt in einer halben Stunde kostet nichts und braucht KEINE
         * Wettervorhersage. 0 schaltet die Vorausschau ab. */
        'vorschau'       => 1800,  // Sekunden

        /* ---- Himmelsmodell ----
         * 'isotrop' oder 'hdkr'. Ab Werk das einfachere: es ist das, gegen
         * das alles andere geeicht ist. HDKR bringt den Sonnenkranz und die
         * Horizontaufhellung und lohnt erst, wenn die Aufheizkonstante
         * gemessen ist - vorher misst man Modell gegen Modell. */
        'himmelsmodell'  => 'isotrop',

        /* ---- Glaettung der Strahlung ----
         * Der Klarheitsindex springt bei durchziehenden Wolken erheblich.
         * Geglaettet wird die STRAHLUNG und nicht das Urteil: eine Wolke
         * soll die Rechnung nicht durcheinanderbringen, aber ein echter
         * Wetterwechsel soll ankommen. 0 schaltet ab. */
        'glaettung'      => 300,   // Sekunden gleitendes Mittel

        /* ---- Tagesbilanz je Raum ----
         * Wie viel Waerme hat dieser Raum heute schon geschluckt? Ein
         * Zimmer, das seit acht Uhr 3 kWh aufgenommen hat, ist voll, auch
         * wenn das Thermometer erst 24 Grad zeigt.
         *
         * AB WERK AUS (Gewicht 0). Der Anwender soll die Wattstundenzahl
         * erst eine Woche lang ANSEHEN, bevor er sie ins Urteil laesst -
         * sonst aendert eine Groesse das Verhalten, die er noch nie
         * gesehen hat. */
        'gewicht_bilanz' => 0,     // Prozent

        /* WATTSTUNDEN JE QUADRATMETER, NICHT JE RAUM.
         *
         * Bis 0.10.0 stand hier eine feste Zahl je Raum - 3000 Wh, ob
         * 5-Quadratmeter-Duschbad oder 25-Quadratmeter-Wohnzimmer. Das ist
         * physikalisch unsinnig: was einen Raum aufheizt, haengt an seiner
         * Masse, und die waechst mit der Grundflaeche. Das kleine Bad war
         * damit viel zu spaet voll und das grosse Zimmer viel zu frueh.
         *
         * 150 Wh/m2 mal 20 m2 sind dieselben 3000 Wh wie bisher - fuer den
         * mittelgrossen Raum aendert sich also nichts. Der Schluessel heisst
         * bewusst ANDERS als vorher: eine alte Zahl wie 3000 wuerde hier als
         * 3000 Wh/m2 gelesen und damit den Bilanzteil stillschweigend
         * abschalten. Ein umbenannter Schluessel faellt auf die Vorgabe
         * zurueck, und das ist die harmlose Richtung. */
        'bilanz_voll_qm' => 150,   // Wh je m2 Grundflaeche, ab denen der Raum voll ist

        /* Die Grundflaechen je Raum, in m2. Sie kommen aus der Projektdatei
         * (dort <C Type="Place" ... Sqm="25">) oder von Hand. */
        'raumflaechen'   => array(),

        /* Und was gilt fuer einen Raum, dessen Flaeche niemand kennt? NICHT
         * null - dann waere der Raum sofort voll und der Bilanzteil stuende
         * dauerhaft auf -1, ohne dass es jemand merkt. Ein mittlerer Wert
         * ist die ehrlichere Annahme, und der Begruendungssatz sagt dazu,
         * dass geschaetzt wurde. */
        'raumflaeche_vorgabe' => 20,   // m2

        /* ---- Vorabend: die Prognose fuer MORGEN ----
         * Wird morgen kuehler, darf die letzte Sonnenstunde heute herein.
         * Wird morgen heisser, ist jedes Watt von heute Abend eines zu
         * viel. Greift erst ab der eingestellten Stunde. AB WERK AUS. */
        'gewicht_morgen' => 0,     // Prozent
        'vorabend_ab'    => 16,    // Stunde

        /* ---- Nachtdaemmung ----
         * Ein geschlossener Rollladen ist nachts ein Waermeschutz. Dieselbe
         * Energiebilanz, nur mit umgekehrtem Vorzeichen und ohne Sonne.
         * AB WERK AUS - sie faehrt Rolllaeden, und das gehoert bewusst
         * eingeschaltet. */
        'daemmen_ein'    => 0,
        'daemm_grenze'   => 5,     // Grad C Aussenluft

        /* ---- Rueckmeldung der Stellung ----
         * Das Plugin liefert ein Urteil und sieht sonst nie, was Loxone
         * daraus gemacht hat. Genau daran ist der Vorfall entstanden, aus
         * dem dieses Plugin kommt. AB WERK AUS, weil es je Fenster einen
         * weiteren virtuellen Ausgang verlangt. */
        'stellung_ein'   => 0,
        'stellung_zu'    => 70,    // Prozent, ab hier gilt der Rollladen als gefahren
        'stellung_frist' => 900,   // Sekunden, bevor eine Abweichung gemeldet wird

        /* ---- Tagesbericht ----
         * Einmal am Abend eine Zeile, die das Plugin ueber Wochen bewertbar
         * macht. Kostet nichts und aendert nichts - deshalb ab Werk an. */
        'bericht_ein'    => 1,
        'bericht_stunde' => 22,

        /* ---- Gegenprobe gegen die PV-Ertragsprognose ----
         * KEINE zweite Wetterquelle: der gerechnete Wert bleibt der
         * gemessene. Weicht die gemeldete Strahlung ueber Tage systematisch
         * von der Prognose ab, ist wahrscheinlich der Geber verschmutzt -
         * dann wird gewarnt und nichts geaendert. AB WERK AUS. */
        'pv_gegenprobe'  => 0,
        'pv_abweichung'  => 25,    // Prozent

        /* ---- Aufheizkonstante lernen ----
         * Je Raum den gerechneten Tageseintrag gegen die gemessene
         * Temperaturaenderung halten. Sammelt nur, greift nie ins Urteil
         * ein. AB WERK AUS. */
        'lernen_ein'     => 0,

        'mqtt_ein'       => 1,
        'mqtt_topic'     => 'fenster',
        'aktionstoken'   => '',
    );
}

/**
 * Die Messwerte, die von aussen hereinkommen muessen.
 *
 * Schluessel => array(Pflicht, Einheit, Sprachschluessel).
 * Raumwerte kommen zusaetzlich unter 'ist.<raum>' und 'grenze.<raum>' herein;
 * sie stehen nicht in dieser Liste, weil ihre Namen aus der
 * Fensterkonfiguration stammen.
 */
function fb_messgroessen()
{
    return array(
        /* PFLICHT heisst: ohne diesen Wert gibt es KEIN Urteil. Die Spalte
         * ist die einzige Quelle dafuer - fb_rechnen() leitet die Sperre
         * daraus ab und fuehrt keine zweite Liste. Bis zum ersten Prueflauf
         * gab es zwei, und sie liefen auseinander: 'aussen' stand hier als
         * Pflicht, fehlte aber in der Sperre. Ergebnis war ein Fenster mit
         * OK=0 und trotzdem BESCHATTEN=1 - genau der Zustand, den der
         * Dateikopf ausschliesst.
         *
         * 'aussen' ist deshalb jetzt KEINE Pflicht mehr: die Groesse geht
         * nicht in das Urteil ein, sie steht nur in der Begruendung. Wer
         * einen ausgefallenen Aussenfuehler hat, soll deswegen nicht die
         * Beschattung verlieren.
         *
         * 'prognose1' (Hoechstwert von morgen) stand in der Fassung 0.9.0
         * schon einmal hier und wurde WIEDER ENTFERNT: der Wert wurde
         * entgegengenommen, abgelegt und nie benutzt, und ein Bedienelement
         * ohne Wirkung ist schlimmer als keines. Jetzt steht er wieder da,
         * aber erst, seit der Vorabend-Term ihn tatsaechlich liest. Die
         * Reihenfolge war Absicht: erst die Wirkung, dann die Eingabe. */
        /* Die vierte und fuenfte Spalte sind die GRENZEN, in denen ein
         * hereingereichter Wert ueberhaupt moeglich ist. Was darueber
         * hinausgeht, wird abgewiesen und gemeldet, nicht zurechtgebogen -
         * dieselbe Haltung wie bei jeder anderen Eingabe dieses Plugins.
         * Sie sind GEWAEHLT und nicht gemessen: 1600 W/m2 liegt ueber allem,
         * was auf der Erdoberflaeche waagerecht ankommt, und -60 bis +80
         * Grad umschliesst jede bewohnte Stelle mit reichlich Luft. */
        'strahlung'   => array(1, 'W/m2', 'FB_MESS.STRAHLUNG', 0.0, 1600.0),
        'prognose'    => array(1, 'C',    'FB_MESS.PROGNOSE', -60.0, 80.0),
        'aussen'      => array(0, 'C',    'FB_MESS.AUSSEN', -60.0, 80.0),
        /* Freiwillig, solange die zugehoerige Funktion abgeschaltet ist -
         * fb_messgroessen_pflicht() sieht nach, was WIRKLICH gebraucht wird.
         * Eine feste Pflichtspalte waere hier falsch: sie haengt an der
         * Konfiguration, nicht an der Groesse. */
        'prognose1'   => array(0, 'C',    'FB_MESS.PROGNOSE1', -60.0, 80.0),
        'pv_prognose' => array(0, 'W/m2', 'FB_MESS.PV', 0.0, 1600.0),
    );
}

/**
 * Welche Messgroessen sind bei DIESER Konfiguration Pflicht?
 *
 * 'prognose1' wird erst gebraucht, wenn der Vorabend-Term eingeschaltet ist;
 * 'aussen' erst, wenn die Nachtdaemmung laeuft; 'pv_prognose' erst bei
 * eingeschalteter Gegenprobe. Eine feste Spalte in fb_messgroessen() haette
 * entweder zu viel verlangt oder zu wenig - und beides faellt erst auf, wenn
 * jemand eine Funktion einschaltet.
 */
function fb_messgroessen_pflicht($cfg)
{
    $pflicht = array();
    foreach (fb_messgroessen() as $name => $info) {
        if ($info[0]) { $pflicht[] = $name; }
    }
    if ((int) $cfg['gewicht_morgen'] > 0) { $pflicht[] = 'prognose1'; }
    if (!empty($cfg['daemmen_ein']))      { $pflicht[] = 'aussen'; }
    if (!empty($cfg['pv_gegenprobe']))    { $pflicht[] = 'pv_prognose'; }
    return array_values(array_unique($pflicht));
}

/**
 * Die Grundflaeche eines Raumes in m2 - und ob sie bekannt ist.
 *
 * Rueckgabe: array(Flaeche, geschaetzt). Das zweite Feld ist der Grund,
 * warum diese Funktion ueberhaupt existiert und nicht einfach ein
 * Array-Zugriff ist: eine geschaetzte Flaeche und eine gemessene sehen im
 * Ergebnis gleich aus, und der Begruendungssatz soll sie unterscheiden
 * koennen. Wer das nicht trennt, baut genau die stille Annahme ein, gegen
 * die dieses Plugin sonst durchgehend gebaut ist.
 */
function fb_raumflaeche($cfg, $raum)
{
    $raum = (string) $raum;
    if ($raum !== '' && isset($cfg['raumflaechen'][$raum])
        && (float) $cfg['raumflaechen'][$raum] > 0.0) {
        return array((float) $cfg['raumflaechen'][$raum], false);
    }
    return array(max(1.0, (float) $cfg['raumflaeche_vorgabe']), true);
}

/**
 * Welche Fensterzeilen stehen noch auf der VORGABEFLAECHE?
 *
 * Die Glasflaeche geht nicht in das Urteil ein - deshalb faellt eine
 * vergessene Flaeche im Betrieb nie auf. Sie bestimmt aber jede Wattzahl,
 * jede Wattstunde und damit die gemessene Aufheizkonstante. Ein Haus voller
 * 1,5-m2-Fenster liefert eine Konstante, die aussieht wie eine Messung und
 * keine ist.
 *
 * Verglichen wird gegen fb_fenster_vorgabe() und nicht gegen eine hier
 * hingeschriebene 1.5 - sonst waeren es zwei Wahrheiten, sobald jemand die
 * Vorgabe aendert.
 *
 * Rueckgabe: Liste der Kuerzel, aufsteigend nach Zeilennummer.
 */
function fb_flaeche_vorgabe($cfg = null)
{
    if ($cfg === null) { $cfg = fb_config(false); }
    $vorgabe = fb_fenster_vorgabe();
    $offen = array();
    foreach ((isset($cfg['fenster']) ? $cfg['fenster'] : array()) as $f) {
        if ((string) $f['kuerzel'] === '' || empty($f['aktiv'])) { continue; }
        if (abs((float) $f['flaeche'] - (float) $vorgabe['flaeche']) < 0.001) {
            $offen[] = (string) $f['kuerzel'];
        }
    }
    return $offen;
}

/** Die Grenzen fuer Raumwerte - sie stehen nicht in fb_messgroessen(), weil
 *  ihre Namen aus der Fensterkonfiguration stammen. */
function fb_raumgrenzen() { return array(-60.0, 80.0); }

/** Zahlencodes fuer Loxone. Dieselbe Zuordnung wie in der Sprachdatei. */
function fb_grund_nr()
{
    return array(
        'zu_wenig_sonne' => 0,
        'nicht_am_glas'  => 1,
        'verschattet'    => 2,
        'erwuenscht'     => 3,
        'raum_zu_warm'   => 4,
        'tag_zu_warm'    => 5,
        'raum_und_tag'   => 6,
        'abgeschaltet'   => 7,
        'raum_voll'      => 10,
        'morgen_zu_warm' => 11,
        'keine_daten'    => 9,
    );
}

/**
 * Eine JSON-Datei lesen. Rueckgabe: array(Daten, Zustand).
 *
 * Zustand ist 'ok', 'fehlt' oder 'kaputt'. Die Unterscheidung ist wichtig:
 * eine abgeschnittene Datei - Stromausfall mitten im Schreiben - ergibt
 * json_decode() === null. Wer daraus ein leeres Feld macht, schreibt
 * stillschweigend die Werkseinstellung zurueck und nimmt die noch heile
 * Zweitschrift gleich mit.
 */
function fb_json_lesen_geprueft($pfad)
{
    if (!is_file($pfad)) { return array(array(), 'fehlt'); }
    $roh = @file_get_contents($pfad);
    if ($roh === false) { return array(array(), 'kaputt'); }
    if (trim($roh) === '') { return array(array(), 'fehlt'); }
    $d = json_decode($roh, true);
    if (!is_array($d)) { return array(array(), 'kaputt'); }
    return array($d, 'ok');
}

/** Die bequeme Form fuer alles, wo ein leerer Stand kein Fehler ist. */
function fb_json_lesen($pfad)
{
    list($d, $z) = fb_json_lesen_geprueft($pfad);
    return $z === 'ok' ? $d : array();
}

/**
 * Erst in eine Nebendatei, dann umbenennen.
 *
 * Die Nebendatei traegt Prozessnummer und einen Zufallsanteil: Cron-Lauf,
 * Endpunkt und Oberflaeche koennen im selben Augenblick schreiben, und zwei
 * Schreiber in derselben .tmp ergaeben eine Mischung aus zwei Dokumenten,
 * also keines.
 *
 * Die Rechte werden VOR dem Inhalt gesetzt: sonst steht die Konfiguration
 * samt Wortzeichen fuer die Dauer des Schreibens mit den Rechten der umask
 * da. Verglichen wird die geschriebene Laenge, nicht gegen false - eine
 * abgebrochene Schreibung ist genauso kaputt wie gar keine, meldet sich aber
 * nicht als Fehler.
 */
function fb_json_schreiben($pfad, $daten, $rechte = null)
{
    $ordner = dirname($pfad);
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) { return false; }
    $json = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) { return false; }
    $tmp = $pfad . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(3));
    $fh = @fopen($tmp, 'c');
    if ($fh === false) { return false; }
    if ($rechte !== null) { @chmod($tmp, $rechte); }
    $geschrieben = ftruncate($fh, 0) ? fwrite($fh, $json) : false;
    fflush($fh);
    fclose($fh);
    if ($geschrieben === false || $geschrieben !== strlen($json)) { @unlink($tmp); return false; }
    if (!@rename($tmp, $pfad)) { @unlink($tmp); return false; }
    return true;
}

/**
 * Die Konfiguration lesen.
 *
 * $erzeugen = false bedeutet: NUR lesen. Kein mkdir, kein Zurueckschreiben,
 * kein Beiseitelegen. Der unangemeldete Endpunkt ruft ausschliesslich so
 * auf - wer sich nicht ausweisen kann, legt nichts an, auch nichts
 * Harmloses.
 */
function fb_config($erzeugen = true)
{
    $p = fb_paths();
    list($roh, $zustand) = fb_json_lesen_geprueft($p['config']);

    if ($zustand === 'kaputt') {
        if ($erzeugen) {
            @rename($p['config'], $p['config'] . '.kaputt');
            fb_log('Die Konfiguration war unlesbar und liegt jetzt als '
                 . basename($p['config']) . '.kaputt daneben.');
        }
        $zustand = 'fehlt';
        $roh = array();
    }

    if ($zustand === 'fehlt') {
        list($sicher, $zs) = fb_json_lesen_geprueft($p['sicherung']);
        if ($zs === 'ok') {
            $roh = $sicher;
            if ($erzeugen) {
                if (!is_dir($p['configdir'])) { @mkdir($p['configdir'], 0775, true); }
                fb_json_schreiben($p['config'], $roh, 0600);
                fb_log('Konfiguration aus der Zweitschrift wiederhergestellt.');
            }
        }
    }

    $cfg = array_merge(fb_vorgaben(), is_array($roh) ? $roh : array());
    return fb_config_richten($cfg);
}

/**
 * Die Werte in ihre Grenzen bringen.
 *
 * Steht getrennt vom Lesen, weil der Speicher-Handler dieselbe Begrenzung
 * braucht - zwei Kopien waeren zwei Wahrheiten.
 */
function fb_config_richten($cfg)
{
    if (!isset($cfg['fenster']) || !is_array($cfg['fenster'])) { $cfg['fenster'] = array(); }
    for ($i = 0; $i < FB_FENSTER; $i++) {
        $f = isset($cfg['fenster'][$i]) && is_array($cfg['fenster'][$i])
             ? $cfg['fenster'][$i] : array();
        $f += fb_fenster_vorgabe();
        $f['kuerzel']   = fb_kuerzel_richten($f['kuerzel']);
        $f['name']      = trim((string) $f['name']);
        $f['raum']      = fb_raumschluessel_richten($f['raum']);
        $f['horizont']  = trim((string) $f['horizont']);
        $f['aktiv']     = empty($f['aktiv']) ? 0 : 1;
        $f['raumwerte'] = empty($f['raumwerte']) ? 0 : 1;
        /* Raumwerte ohne Raumschluessel sind ein Haken ohne Wirkung. Bis
         * zum ersten Prueflauf blieb er stehen: der Speicher-Handler meldete
         * ihn zwar, blockierte aber nicht, und danach rechnete das Plugin
         * dauerhaft OHNE Raumteil - mit OK=1 und ohne dass es irgendwo
         * ausser im Begruendungssatz stand. Jetzt ist die Konfiguration
         * eindeutig: kein Raum, kein Haken. */
        if ($f['raum'] === '') { $f['raumwerte'] = 0; }
        $f['azimut']    = (int) fb_klemme((int) $f['azimut'], 0, 359);
        $f['neigung']   = (int) fb_klemme((int) $f['neigung'], 0, 90);
        $f['flaeche']   = round(fb_klemme((float) $f['flaeche'], 0.1, 30.0), 2);
        $f['gwert']     = (int) fb_klemme((int) $f['gwert'], 5, 95);
        $f['traegheit'] = (int) fb_klemme((int) $f['traegheit'], 0, 50);
        $f['dach_tiefe']    = (int) fb_klemme((int) $f['dach_tiefe'], 0, 300);
        $f['dach_hoehe']    = (int) fb_klemme((int) $f['dach_hoehe'], 0, 300);
        $f['fenster_hoehe'] = (int) fb_klemme((int) $f['fenster_hoehe'], 20, 400);
        $f['blend_hoehe']  = (int) fb_klemme((int) $f['blend_hoehe'], 0, 60);
        $f['blend_winkel'] = (int) fb_klemme((int) $f['blend_winkel'], 5, 89);
        $f['daemmen']      = empty($f['daemmen']) ? 0 : 1;
        $cfg['fenster'][$i] = $f;
    }
    $cfg['breite']         = round(fb_klemme((float) $cfg['breite'], -90.0, 90.0), 5);
    $cfg['laenge']         = round(fb_klemme((float) $cfg['laenge'], -180.0, 180.0), 5);
    $cfg['albedo']         = (int) fb_klemme((int) $cfg['albedo'], 0, 90);
    $cfg['iam_b0']         = (int) fb_klemme((int) $cfg['iam_b0'], 0, 50);
    $cfg['e_ref']          = (int) fb_klemme((int) $cfg['e_ref'], 50, 1000);
    $cfg['tagesgrenze']    = (int) fb_klemme((int) $cfg['tagesgrenze'], 10, 35);
    $cfg['spreizung_tag']  = (int) fb_klemme((int) $cfg['spreizung_tag'], 1, 20);
    $cfg['spreizung_raum'] = (int) fb_klemme((int) $cfg['spreizung_raum'], 5, 100);
    $cfg['gewicht_raum']   = (int) fb_klemme((int) $cfg['gewicht_raum'], 0, 100);
    $cfg['gewicht_tag']    = (int) fb_klemme((int) $cfg['gewicht_tag'], 0, 100);
    $cfg['schwelle_ein']   = (int) fb_klemme((int) $cfg['schwelle_ein'], 1, 100);
    $cfg['schwelle_aus']   = (int) fb_klemme((int) $cfg['schwelle_aus'], 0, 99);
    $cfg['hoechstalter']   = (int) fb_klemme((int) $cfg['hoechstalter'], 60, 86400);
    $cfg['rechentakt']     = (int) fb_klemme((int) $cfg['rechentakt'], 10, 3600);
    $cfg['vorschau']       = (int) fb_klemme((int) $cfg['vorschau'], 0, 10800);
    $cfg['glaettung']      = (int) fb_klemme((int) $cfg['glaettung'], 0, 3600);
    $cfg['gewicht_bilanz'] = (int) fb_klemme((int) $cfg['gewicht_bilanz'], 0, 100);
    $cfg['bilanz_voll_qm'] = (int) fb_klemme((int) $cfg['bilanz_voll_qm'], 10, 2000);
    $cfg['raumflaeche_vorgabe'] = (int) fb_klemme((int) $cfg['raumflaeche_vorgabe'], 1, 1000);
    /* Die Raumflaechen werden hier genauso gerichtet wie jede andere
     * Eingabe: Schluessel durch dieselbe Siebfunktion wie der Raumschluessel
     * am Fenster - sonst zeigt eine Flaeche auf einen Raum, den es nicht
     * gibt, und niemand sieht es. Eine Flaeche 0 heisst "unbekannt" und
     * wird geloescht statt gespeichert. */
    $rf = array();
    if (isset($cfg['raumflaechen']) && is_array($cfg['raumflaechen'])) {
        foreach ($cfg['raumflaechen'] as $r => $q) {
            $r = fb_raumschluessel_richten($r);
            if ($r === '') { continue; }
            $q = round(fb_klemme((float) $q, 0.0, 1000.0), 1);
            if ($q <= 0.0) { continue; }
            $rf[$r] = $q;
        }
    }
    ksort($rf);
    $cfg['raumflaechen'] = $rf;
    $cfg['gewicht_morgen'] = (int) fb_klemme((int) $cfg['gewicht_morgen'], 0, 100);
    $cfg['vorabend_ab']    = (int) fb_klemme((int) $cfg['vorabend_ab'], 0, 23);
    $cfg['daemmen_ein']    = empty($cfg['daemmen_ein']) ? 0 : 1;
    $cfg['daemm_grenze']   = (int) fb_klemme((int) $cfg['daemm_grenze'], -30, 25);
    $cfg['stellung_ein']   = empty($cfg['stellung_ein']) ? 0 : 1;
    $cfg['stellung_zu']    = (int) fb_klemme((int) $cfg['stellung_zu'], 1, 100);
    $cfg['stellung_frist'] = (int) fb_klemme((int) $cfg['stellung_frist'], 60, 86400);
    $cfg['bericht_ein']    = empty($cfg['bericht_ein']) ? 0 : 1;
    $cfg['bericht_stunde'] = (int) fb_klemme((int) $cfg['bericht_stunde'], 0, 23);
    $cfg['pv_gegenprobe']  = empty($cfg['pv_gegenprobe']) ? 0 : 1;
    $cfg['pv_abweichung']  = (int) fb_klemme((int) $cfg['pv_abweichung'], 5, 90);
    $cfg['lernen_ein']     = empty($cfg['lernen_ein']) ? 0 : 1;
    $cfg['himmelsmodell']  = in_array((string) $cfg['himmelsmodell'],
                                      array('isotrop', 'hdkr'), true)
                             ? (string) $cfg['himmelsmodell'] : 'isotrop';
    $cfg['mqtt_ein']       = empty($cfg['mqtt_ein']) ? 0 : 1;
    $t = preg_replace('#[^a-z0-9_/\-]#', '', strtolower((string) $cfg['mqtt_topic']));
    $cfg['mqtt_topic'] = trim($t, '/') !== '' ? trim($t, '/') : 'fenster';
    /* Die Ausschaltschwelle muss unter der Einschaltschwelle liegen, sonst
     * gibt es keine Hysterese, sondern ein Flattern am Schwellwert. Hier
     * wird NICHT zurechtgebogen, sondern angeglichen und im Reiter Test
     * gemeldet - der Speicher-Handler weist die Eingabe schon vorher ab. */
    if ($cfg['schwelle_aus'] >= $cfg['schwelle_ein']) {
        $cfg['schwelle_aus'] = max(0, $cfg['schwelle_ein'] - 1);
    }
    return $cfg;
}

/**
 * Das Kuerzel eines Fensters - es wird zum Namen des virtuellen Eingangs
 * und zum MQTT-Zweig. Erlaubt sind Buchstaben, Ziffern und der
 * Unterstrich; alles andere wird ENTFERNT und nicht ersetzt, damit aus
 * "EG Wohnzimmer" nicht "EG_Wohnzimmer" mit stillschweigend anderer
 * Bedeutung wird. Der Handler meldet, wenn dabei etwas wegfaellt.
 */
function fb_kuerzel_richten($s)
{
    return substr(preg_replace('/[^A-Za-z0-9_]/', '', (string) $s), 0, 16);
}

/** Der Raumschluessel steht in Messwertnamen wie 'ist.wohnen'. */
function fb_raumschluessel_richten($s)
{
    return substr(preg_replace('/[^a-z0-9_]/', '', strtolower((string) $s)), 0, 20);
}

/**
 * Speichern - und die Zweitschrift aus DENSELBEN Daten schreiben.
 *
 * Zuerst schreiben, dann zuruecklesen - und nur, wenn das gelingt, die
 * Zweitschrift erneuern. Wer die frisch geschriebene Datei ueber die
 * Sicherung kopiert, reisst bei einer beschaedigten Konfiguration die noch
 * heile Sicherung mit.
 */
/**
 * Die Sperre um LESEN, AENDERN und SCHREIBEN der Konfiguration.
 *
 * fb_config_speichern() allein genuegt nicht. Der Ablauf in jedem
 * Speicher-Handler ist: Konfiguration lesen, einen Teil aendern, ganz
 * zurueckschreiben. Laufen zwei Handler gleichzeitig - zwei Reiter im
 * Browser, zwei Bediener -, liest der zweite den Stand VOR der Aenderung des
 * ersten und schreibt sie damit weg. Gemessen an zwei parallelen Formularen
 * (Modell und MQTT): in sechs von acht Durchgaengen ging eine der beiden
 * Aenderungen verloren, und BEIDE Seiten meldeten "gespeichert".
 *
 * Die Sperre gehoert deshalb um den ganzen Vorgang und nicht nur um das
 * Schreiben. Der Aufrufer holt sie vor fb_config() und gibt sie nach
 * fb_config_speichern() zurueck.
 *
 * NICHT verschachteln: flock() auf zwei Griffen derselben Datei im selben
 * Prozess blockiert sich selbst. Deshalb benutzt auch fb_token() genau
 * diese Sperre und keine zweite.
 */
function fb_config_sperre()
{
    $p = fb_paths();
    if (!is_dir($p['datadir']) && !@mkdir($p['datadir'], 0775, true)
        && !is_dir($p['datadir'])) {
        return null;
    }
    $fp = @fopen($p['datadir'] . '/config.lock', 'c+');
    if ($fp === false) { return null; }
    if (!@flock($fp, LOCK_EX)) { fclose($fp); return null; }
    return $fp;
}

/** Die Gegenstelle zu fb_config_sperre(). Vertraegt auch null. */
function fb_config_freigeben($fp)
{
    if ($fp) { @flock($fp, LOCK_UN); fclose($fp); }
}

function fb_config_speichern($cfg)
{
    $p = fb_paths();
    if (!is_dir($p['configdir'])) { @mkdir($p['configdir'], 0700, true); }
    if (!fb_json_schreiben($p['config'], $cfg, 0600)) { return false; }
    list($zurueck, $zustand) = fb_json_lesen_geprueft($p['config']);
    if ($zustand !== 'ok') {
        fb_log('Die Konfiguration liess sich nach dem Schreiben nicht zuruecklesen. '
             . 'Die Zweitschrift bleibt unangetastet.');
        return false;
    }
    fb_json_schreiben($p['sicherung'], $zurueck, 0600);
    return true;
}

/**
 * Die Konfiguration VERVOLLSTAENDIGEN, nicht nur ergaenzen.
 *
 * Ergaenzen heisst: beim Lesen tritt fuer einen fehlenden Schluessel seine
 * Vorgabe ein - die Datei bleibt lueckenhaft, und "fehlt" ist von "steht
 * auf dem Vorgabewert" nicht mehr zu unterscheiden. Vervollstaendigen
 * heisst: der fehlende Schluessel wird EINMAL mit seiner Vorgabe
 * hineingeschrieben.
 *
 * Verglichen wird gegen die ROHE Datei, nicht gegen die schon gemergte
 * Konfiguration - sonst faende die Pruefung nie etwas. array_key_exists()
 * und nicht isset(): isset() haelt einen leeren Wert fuer nicht vorhanden
 * und wuerde eine bewusst geleerte Angabe bei jedem Lauf zurueckschreiben.
 *
 * NUR aus dem angemeldeten Bereich aufrufen - die Funktion schreibt.
 * Rueckgabe: die Namen der Schluessel, die gefehlt haben.
 */
function fb_cfg_vervollstaendigen()
{
    $p = fb_paths();
    list($roh, $zustand) = fb_json_lesen_geprueft($p['config']);
    if ($zustand !== 'ok') { $roh = array(); }
    $fehlten = array();
    foreach (fb_vorgaben() as $k => $v) {
        if (!array_key_exists($k, $roh)) { $fehlten[] = $k; }
    }
    if ($fehlten) {
        $cfg = fb_config();
        fb_config_speichern($cfg);
        fb_log('Konfiguration vervollstaendigt: ' . implode(', ', $fehlten));
    }
    return $fehlten;
}

/**
 * Die belegten Fenster - Schluessel ist die ZEILENNUMMER, nicht die
 * laufende Nummer der belegten Zeilen.
 *
 * Warum das wichtig ist: der Rechenkern und die Oberflaeche muessen
 * dieselbe Nummer meinen. Zaehlt eine Seite nach der Zeile und die andere
 * nach den belegten Zeilen, genuegt eine leere Zeile dazwischen, und der
 * virtuelle Eingang eines Fensters zeigt den Wert eines anderen - ohne
 * Fehler, ohne Meldung, nur mit falschen Werten.
 */
function fb_fenster($erzeugen = true)
{
    $out = array();
    $nr = 0;
    foreach (fb_config($erzeugen)['fenster'] as $f) {
        $nr++;
        if ($f['kuerzel'] === '') { continue; }
        $f['nr'] = $nr;
        $out[$nr] = $f;
    }
    return $out;
}

function fb_token_erzeugen($laenge = 24)
{
    $z = 'abcdefghijkmnpqrstuvwxyz23456789';
    $t = '';
    for ($i = 0; $i < $laenge; $i++) { $t .= $z[random_int(0, strlen($z) - 1)]; }
    return $t;
}

/**
 * Das Aktionstoken holen, bei Bedarf erzeugen - hinter einer Dateisperre.
 *
 * NUR aus dem angemeldeten Bereich aufrufen: die Funktion schreibt. Der
 * Endpunkt liest das Token aus fb_config(false) und erzeugt keines.
 *
 * Ohne Sperre koennen zwei gleichzeitige Aufrufe je ein eigenes Token
 * erzeugen und nacheinander speichern. Der zuerst angezeigte Wert waere
 * dann schon ueberholt, und die daraus gebaute Loxone-Vorlage truege ein
 * Token, das nicht mehr gilt.
 */
function fb_token()
{
    $cfg = fb_config();
    if (trim((string) $cfg['aktionstoken']) !== '') { return (string) $cfg['aktionstoken']; }
    $fp = fb_config_sperre();
    if ($fp === null) {
        $cfg['aktionstoken'] = fb_token_erzeugen();
        fb_config_speichern($cfg);
        return (string) $cfg['aktionstoken'];
    }
    $cfg = fb_config();                     // zweiter Blick unter der Sperre
    if (trim((string) $cfg['aktionstoken']) === '') {
        $cfg['aktionstoken'] = fb_token_erzeugen();
        fb_config_speichern($cfg);
    }
    fb_config_freigeben($fp);
    return (string) $cfg['aktionstoken'];
}

/**
 * Das Merkmal gegen Formulare, die auf fremden Seiten stehen.
 *
 * htmlauth/ schuetzt gegen den unangemeldeten Aufruf - NICHT dagegen, dass
 * der Browser eines angemeldeten Bedieners ein Formular abschickt, das
 * woanders steht; die Anmeldung geht dabei automatisch mit, SameSite greift
 * nicht. Abgeleitet, nicht gespeichert: es gibt damit keinen zweiten Wert,
 * der auseinanderlaufen kann. Fail closed - ohne Aktionstoken kein Merkmal.
 */
function fb_formtoken()
{
    $t = trim((string) fb_config()['aktionstoken']);
    if ($t === '') { return ''; }
    return hash_hmac('sha256', 'formular-v1', $t);
}

/* ==================================================================
 * Messwerte
 * ================================================================== */

function fb_messwerte()
{
    /* GEPRUEFT lesen, nicht bequem.
     *
     * Hier stand fb_json_lesen(), das eine beschaedigte Datei stillschweigend
     * als leer zurueckgibt. Gemessen: zwoelf abgelegte Messwerte, Datei mit
     * Muell ueberschrieben, ein einziger melden-Aufruf - danach stand nur
     * noch der eine gerade gemeldete Wert darin, ohne einen einzigen
     * Protokolleintrag darueber. Bei der Konfiguration macht dieses Plugin
     * es an derselben Stelle richtig; hier fehlte es. */
    $p = fb_paths();
    list($d, $zustand) = fb_json_lesen_geprueft($p['messwerte']);
    if ($zustand === 'kaputt') {
        @rename($p['messwerte'], $p['messwerte'] . '.kaputt');
        fb_log('Die Messwertdatei war unlesbar und liegt jetzt als '
             . basename($p['messwerte']) . '.kaputt daneben. Die Werte treffen '
             . 'wieder ein, sobald Loxone das naechste Mal sendet.');
        return array();
    }
    return $d;
}

/**
 * Die Messwertnamen, die dieses Plugin ueberhaupt gebrauchen kann.
 *
 * Alles andere wird abgewiesen. Das ist nicht Pedanterie, sondern eine
 * Grenze: bis zum ersten Prueflauf nahm der Endpunkt JEDEN Namen an, der
 * ins Muster passte - auch einen aus 5000 Buchstaben und auch frei
 * erfundene. Zwanzig solche Meldungen blaehten die Datei von 716 Byte auf
 * 101 707 Byte auf, und jede weitere Meldung liest und schreibt sie ganz.
 *
 * Zweitens macht die Liste einen Tippfehler sichtbar: wer in Loxone
 * 'ist.wohnzimer' schreibt, bekommt eine Fehlermeldung statt eines
 * stillen OK und einer Beschattung, die nie kommt.
 */
function fb_messwertnamen($cfg)
{
    $namen = array_keys(fb_messgroessen());
    foreach ((isset($cfg['fenster']) ? $cfg['fenster'] : array()) as $f) {
        if (isset($f['raum']) && $f['raum'] !== '' && !empty($f['raumwerte'])) {
            $namen[] = 'ist.' . $f['raum'];
            $namen[] = 'grenze.' . $f['raum'];
        }
        /* Die Rueckmeldung der Rollladenstellung - je Fenster eine, und nur
         * wenn die Auswertung ueberhaupt eingeschaltet ist. */
        if (!empty($cfg['stellung_ein']) && isset($f['kuerzel']) && $f['kuerzel'] !== '') {
            $namen[] = 'stellung.' . strtolower($f['kuerzel']);
        }
    }
    return array_values(array_unique($namen));
}

/**
 * Einen hereingereichten Messwert ablegen.
 *
 * Jeder Wert traegt seinen EIGENEN Zeitstempel. Eine gemeinsame Zeit fuer
 * alle waere die Behauptung, alles sei gleich frisch - und ein seit einer
 * Stunde stehengebliebener Raumfuehler saehe dann genauso aus wie ein
 * gerade gemeldeter.
 *
 * Rueckgabe: array(ok, Meldung). Ein Name, der nicht ins Muster passt, wird
 * ABGEWIESEN und nicht zurechtgebogen.
 */
function fb_messwert_setzen($name, $wert, $cfg = null, $ts = null)
{
    /* is_string() VOR dem Cast. Ein Feldparameter (wert[]=a) wird von
     * (string) zu "array" - unter PHP 8 mit einer Warnung, die VOR den
     * Kopfzeilen steht und http_response_code() unwirksam macht, unter 7.4
     * lautlos. Gemessen unter beiden Fassungen: der Endpunkt antwortete mit
     * OK=1 und legte einen Messwert namens "array" ab. */
    if (!is_string($name) || !is_string($wert)) {
        return array(false, 'PARAMETER_UNGUELTIG');
    }
    /* Der Modifier D ist der ganze Unterschied.
     *
     * OHNE ihn passt $ in PHP auch VOR einem abschliessenden Zeilenumbruch.
     * Gemessen: "wert=aussen%0A" wurde angenommen, der Wert landete unter
     * dem Schluessel "aussen\n", den der Rechenkern nie liest - und die
     * Antwortzeile an den Miniserver zerfiel in zwei Zeilen. Quittiert
     * wurde das mit OK=1, also genau mit der Erfolgsmeldung, vor der der
     * Kommentar im Endpunkt warnt. */
    if (!preg_match('/^[a-z][a-z0-9_]*(\.[a-z0-9_]+)?$/D', $name)) {
        return array(false, 'NAME_UNGUELTIG');
    }
    if ($cfg === null) { $cfg = fb_config(false); }
    if (!in_array($name, fb_messwertnamen($cfg), true)) {
        return array(false, 'NAME_UNBEKANNT');
    }
    $roh = str_replace(',', '.', trim($wert));
    if ($roh === '' || !is_numeric($roh)) {
        return array(false, 'WERT_KEINE_ZAHL');
    }
    /* is_finite() nach der Umwandlung: "1e999" ist numerisch, ergibt aber
     * INF, und daran scheitert json_encode(). Vorher meldete der Endpunkt
     * dafuer GRUND=SCHREIBEN - und schickte den Anwender ins Dateisystem,
     * wo alles in Ordnung war. */
    if (!is_finite((float) $roh)) {
        return array(false, 'WERT_UNMOEGLICH');
    }
    /* Und in die Grenzen der Groesse. Ein Strahlungswert von 99999 W/m2 oder
     * eine Raumtemperatur von 900 Grad ist keine Messung, sondern ein
     * falsch verdrahteter Ausgang - und er wuerde hier ein Urteil erzeugen,
     * das richtig aussieht. */
    $gr = fb_messgroessen();
    if (isset($gr[$name][3])) { $von = $gr[$name][3]; $bis = $gr[$name][4]; }
    elseif (strpos($name, 'stellung.') === 0) { $von = 0.0; $bis = 100.0; }
    else { list($von, $bis) = fb_raumgrenzen(); }
    if ((float) $roh < $von || (float) $roh > $bis) {
        return array(false, 'WERT_AUSSERHALB');
    }
    $p = fb_paths();
    if (!is_dir($p['datadir']) && !@mkdir($p['datadir'], 0775, true) && !is_dir($p['datadir'])) {
        return array(false, 'ORDNER');
    }
    /* Unter einer Dateisperre lesen, aendern, schreiben. Ohne sie
     * ueberschreiben zwei gleichzeitig gemeldete Werte einander: Loxone
     * schickt bei einem Wetterwechsel mehrere virtuelle Ausgaenge in
     * derselben Sekunde los, und der zweite Schreiber haette den ersten
     * noch nicht gesehen. */
    $sperre = @fopen($p['datadir'] . '/messwerte.lock', 'c+');
    if ($sperre !== false) { @flock($sperre, LOCK_EX); }
    $m = fb_messwerte();
    $zeit = $ts === null ? time() : (int) $ts;
    $neu = array('v' => (float) $roh, 't' => $zeit);
    /* Fuer die Strahlung wird eine kurze Reihe mitgefuehrt - sie ist die
     * Grundlage der Glaettung. Zwanzig Punkte reichen: bei einem
     * Meldeabstand von einer Minute sind das zwanzig Minuten, und laenger
     * glaettet niemand. Aeltere fallen heraus, die Datei waechst also
     * nicht. */
    if ($name === 'strahlung') {
        $reihe = array();
        if (isset($m[$name]['reihe']) && is_array($m[$name]['reihe'])) {
            $reihe = $m[$name]['reihe'];
        }
        $reihe[] = array($zeit, (float) $roh);
        if (count($reihe) > 20) { $reihe = array_slice($reihe, -20); }
        $neu['reihe'] = $reihe;
    }
    $m[$name] = $neu;
    /* Beim Schreiben aufraeumen: Namen, die zur heutigen Konfiguration nicht
     * mehr gehoeren, fliegen hinaus. Sonst bleibt ein umbenannter
     * Raumschluessel fuer immer in der Datei stehen, und die Selbstpruefung
     * zaehlt Werte mit, die niemand mehr liest. */
    $erlaubt = fb_messwertnamen($cfg);
    $weg = array();
    foreach (array_keys($m) as $k) {
        if (!in_array($k, $erlaubt, true)) { unset($m[$k]); $weg[] = $k; }
    }
    if ($weg) {
        fb_log_wenn_neu('messwerte_aufgeraeumt', count($weg)
            . ' Messwert(e) gehoeren nicht mehr zur Konfiguration und wurden '
            . 'entfernt: ' . fb_liste_kurz($weg));
    }
    $ok = fb_json_schreiben($p['messwerte'], $m, 0644);
    if ($sperre !== false) { @flock($sperre, LOCK_UN); fclose($sperre); }
    return array($ok, $ok ? 'OK' : 'SCHREIBEN');
}

/**
 * Einen Messwert holen, wenn er frisch genug ist.
 *
 * Rueckgabe: array(wert, alter) - wert === null heisst "nicht vorhanden
 * oder zu alt". Es gibt bewusst keinen Rueckfallwert: eine Zahl, die
 * niemand gemessen hat, ist von einer gemessenen nicht zu unterscheiden,
 * sobald sie erst einmal in Loxone steht.
 */
function fb_messwert($m, $name, $hoechstalter, $jetzt)
{
    if (!isset($m[$name]) || !is_array($m[$name]) || !isset($m[$name]['v'])) {
        return array(null, -1);
    }
    $alter = (int) $jetzt - (int) (isset($m[$name]['t']) ? $m[$name]['t'] : 0);
    /* EIN STEMPEL AUS DER ZUKUNFT IST KEIN TAUFRISCHER WERT.
     *
     * Hier stand zuerst nur "if ($alter < 0) { $alter = 0; }". Damit galt
     * jeder Wert mit einem Stempel in der Zukunft als null Sekunden alt -
     * unbegrenzt lange. Der praktische Fall ist kein Angriff, sondern ein
     * LoxBerry ohne Batterieuhr: er startet mit einer alten Systemzeit,
     * bevor die Netzzeit greift, und alle abgelegten Werte sehen dann
     * beliebig frisch aus. Gemessen mit einem Stempel 100 Millionen
     * Sekunden voraus: Alter 0, Wert gueltig.
     *
     * Ein paar Sekunden Vorlauf sind normal (zwei Uhren, ein Netz); mehr
     * als fuenf Minuten sind es nicht. Darueber wird der Wert verworfen
     * statt geglaubt - fail closed. */
    if ($alter < -300) { return array(null, $alter); }
    if ($alter < 0) { $alter = 0; }
    if ($alter > (int) $hoechstalter) { return array(null, $alter); }
    return array((float) $m[$name]['v'], $alter);
}

/* ==================================================================
 * Zustand und Protokoll
 * ================================================================== */

function fb_stand() { return fb_json_lesen(fb_paths()['stand']); }

function fb_alter()
{
    $s = fb_stand();
    return isset($s['ts']) && (int) $s['ts'] > 0 ? max(0, time() - (int) $s['ts']) : -1;
}

function fb_log($text)
{
    $p = fb_paths();
    if (!is_dir($p['logdir'])) { @mkdir($p['logdir'], 0775, true); }
    /* log/plugins liegt auf einer Ramdisk - eine unbegrenzt wachsende Datei
     * frisst Arbeitsspeicher, nicht Plattenplatz. */
    clearstatcache(true, $p['log']);
    if (is_file($p['log']) && filesize($p['log']) > 512000) {
        $rest = array_slice(file($p['log'], FILE_IGNORE_NEW_LINES) ?: array(), -400);
        @file_put_contents($p['log'], implode("\n", $rest) . "\n");
    }
    @file_put_contents($p['log'], '[' . date('Y-m-d H:i:s') . '] ' . $text . "\n", FILE_APPEND);
}

/**
 * Nur schreiben, wenn sich die Aussage geaendert hat.
 *
 * Der Cron laeuft alle fuenf Minuten. Eine Zeile je Lauf ergaebe 288
 * gleichlautende Zeilen am Tag, und die eine, die etwas bedeutet, ginge
 * darin unter.
 */
function fb_log_wenn_neu($schluessel, $text)
{
    $p = fb_paths();
    if (!is_dir($p['datadir'])) { @mkdir($p['datadir'], 0775, true); }
    $merk = $p['datadir'] . '/letzte_meldung.json';
    /* DER MERKER GEHOERT ZURUECKGESETZT, SOBALD DAS PROTOKOLL FORT IST.
     *
     * Er liegt unter data/ und ueberlebt damit alles; die Protokolldatei
     * liegt unter log/plugins und damit auf einer Ramdisk. Nach "Protokoll
     * leeren" oder einem Neustart unterdrueckte die Bremse deshalb
     * ausgerechnet die ERSTE Zeile in der leeren Datei - also die eine
     * Meldung, die den Anwender interessiert. Gemessen: dritter Lauf nach
     * dem Leeren, Protokoll blieb bei null Zeilen. */
    /* Der Zwischenspeicher von stat() muss vorher weg, sonst kehrt sich die
     * Absicht um. fb_log_wenn_neu() wird in EINEM Lauf von fb_lauf.php an
     * zwoelf Stellen gerufen. Sieht filesize() beim ersten Mal die leere
     * Datei, merkt PHP sich diese Null fuer den ganzen Prozess - auch nachdem
     * fb_log() geschrieben hat. Der Merker wuerde dann bei JEDEM weiteren
     * Aufruf geloescht, und die Wiederholungssperre, um die es dieser
     * Funktion einzig geht, waere fuer den ganzen Lauf ausser Kraft. */
    clearstatcache(true, $p['log']);
    if (!is_file($p['log']) || filesize($p['log']) === 0) {
        @unlink($merk);
    }
    $alt = fb_json_lesen($merk);
    if (isset($alt[$schluessel]) && $alt[$schluessel] === $text) { return; }
    $alt[$schluessel] = $text;
    fb_json_schreiben($merk, $alt, 0644);
    fb_log($text);
}

/**
 * Die letzten Zeilen einer Datei, neueste zuerst - rueckwaerts mit fseek.
 * Gemessen an 12.000 Zeilen: file() 0,37 ms und 2 MB, exec("tail") 2,17 ms,
 * fseek 0,05 ms und 0 kB. Ein Prozessstart kostet mehr, als das Einlesen je
 * gespart hat.
 *
 * Erst fragen, dann oeffnen: vor dem ersten Lauf gibt es die Datei nicht,
 * und ein @fopen darauf erzeugt trotzdem einen Fehler - das @ schaltet die
 * Ausgabe ab, nicht den Fehler-Aufnehmer.
 */
function fb_log_ende($datei, $anzahl = 400, $block = 8192)
{
    if (!is_file($datei)) { return array(); }
    $fp = @fopen($datei, 'rb');
    if ($fp === false) { return array(); }
    fseek($fp, 0, SEEK_END);
    $pos = ftell($fp);
    $puffer = '';
    $zeilen = array();
    while ($pos > 0 && count($zeilen) <= $anzahl) {
        $lese = (int) min($block, $pos);
        $pos -= $lese;
        fseek($fp, $pos, SEEK_SET);
        $puffer = fread($fp, $lese) . $puffer;
        $zeilen = explode("\n", $puffer);
    }
    fclose($fp);
    $zeilen = array_values(array_filter(array_map('rtrim', $zeilen), 'strlen'));
    return array_slice(array_reverse($zeilen), 0, $anzahl);
}

/* ==================================================================
 * Der Rechenkern
 * ================================================================== */

/**
 * Das Urteil fuer alle Fenster rechnen.
 *
 * Diese Funktion fasst KEINE Datei an. Sie bekommt Konfiguration,
 * Messwerte, Zeitpunkt und den vorherigen Stand herein und gibt den neuen
 * Stand zurueck. Genau deshalb laesst sie sich im Reiter Test mit
 * ausgedachten Werten fahren, ohne dass irgendwo etwas kaputtgeht - und
 * genau deshalb misst der Selbsttest wirklich die Rechnung und nicht die
 * Umgebung.
 *
 * DIE RECHNUNG IN VIER SCHRITTEN
 *
 * 1. Geometrie. Sonnenstand aus Ort und Zeit, daraus der Einfallswinkel auf
 *    das Glas. Bei streifendem Einfall spiegelt das Glas den groessten Teil
 *    weg - ein Winkelbereich allein bildet das nicht ab.
 * 2. Verschattung. Steht die Sonne unter dem eingetragenen Horizont, faellt
 *    der DIREKTE Anteil weg. Der Himmel scheint trotzdem herein.
 * 3. Waermebedarf. Zwei Teile: will der RAUM Waerme (Soll gegen Ist), und
 *    will der TAG Waerme (Heizgrenze gegen Tageshoechstwert). Beide laufen
 *    stetig von +1 nach -1 und werden gewichtet addiert.
 *
 *    Das ist der Kern der Sache. Im Juli ist der Eintrag Last, selbst bei
 *    19 Grad Aussenluft am Morgen - denn der Tag wird 28. Im September ist
 *    er willkommen, auch wenn der Raum noch warm ist - denn der Tag wird
 *    16. Die Aussenluft im Augenblick beantwortet diese Frage NICHT; das
 *    ist der Grund, aus dem eine reine Schaltbedingung hier scheitert.
 * 4. Urteil. Der Waermebedarf mal dem Gewicht des Eintrags: wo keine Sonne
 *    aufs Glas faellt, gibt es nichts zu entscheiden, und das Urteil ist 0.
 */
function fb_rechnen($cfg, $messwerte, $jetzt, $vorher = array(), $bilanz = array())
{
    /* DIE ZAEHLER STEHEN HIER OBEN UND NICHT ERST WEITER UNTEN.
     *
     * Sonst fehlen sie auf jedem vorzeitigen Rueckweg - und den gibt es:
     * ohne Standort steigt die Funktion gleich hier aus. Ein Aufrufer, der
     * $stand['anzahl'] liest, bekam dann eine PHP-Meldung statt einer Zahl.
     * Gefunden hat das der Endpunkttest auf einer FRISCHEN Anlage, also
     * genau in der Lage, in der jeder Anwender einmal ist: Plugin
     * installiert, Standort noch nicht eingetragen, erster Messwert kommt
     * schon herein.
     *
     * Null ist hier keine Notluege, sondern die Wahrheit: es wurde kein
     * Fenster gerechnet, also wollen null Fenster Beschattung. Anders als
     * beim Sonnenstand - der ist ohne Standort nicht null, sondern
     * unbekannt, und deshalb steht er auch weiterhin nicht hier. */
    $stand = array(
        'ts'                => (int) $jetzt,
        'ok'                => 0,
        'fenster'           => array(),
        'fehlend'           => array(),
        'meldung'           => '',
        'anzahl'            => 0,
        'beschatten_anzahl' => 0,
    );

    /* --- Standort. Ohne ihn wird nicht gerechnet und nicht geraten. --- */
    if (abs((float) $cfg['breite']) < 0.001 && abs((float) $cfg['laenge']) < 0.001) {
        $stand['meldung'] = 'KEIN_STANDORT';
        return $stand;
    }

    $sonne = fb_sonnenstand($jetzt, $cfg['breite'], $cfg['laenge']);
    $stand['sonne_hoehe']  = round($sonne['hoehe'], 2);
    $stand['sonne_azimut'] = round($sonne['azimut'], 2);

    /* --- Messwerte einsammeln. Fehlt einer, wird er benannt. --- */
    /* --- Die Strahlung glaetten, BEVOR irgendetwas mit ihr gerechnet wird.
     *
     * Der Klarheitsindex springt bei durchziehenden Wolken erheblich; ohne
     * Glaettung nimmt eine einzelne Wolke die Beschattung zurueck und die
     * naechste bringt sie wieder. Geglaettet wird die STRAHLUNG und nicht
     * das Urteil - die Hysterese weiter unten ist etwas anderes: sie
     * verhindert das Flattern an der Schwelle, nicht das Flattern der
     * Messgroesse.
     *
     * Es steht hier und nicht in fb_lauf(), damit jeder Weg dieselbe Zahl
     * sieht: der Cron, der Endpunkt und jede Probe im Reiter Test. */
    $messwerte = fb_messwerte_glaetten($cfg, $messwerte, $jetzt);

    $ha = (int) $cfg['hoechstalter'];
    $fehlend = array();
    $werte = array();
    foreach (fb_messgroessen() as $name => $info) {
        list($w, $alter) = fb_messwert($messwerte, $name, $ha, $jetzt);
        $werte[$name] = $w;
        if ($w === null && $info[0]) { $fehlend[] = $name; }
    }
    $stand['aussen']    = $werte['aussen'] === null ? -99 : round($werte['aussen'], 1);
    $stand['prognose']  = $werte['prognose'] === null ? -99 : round($werte['prognose'], 1);

    /* --- Der Tagesteil des Waermebedarfs. --- */
    $n_tag = null;
    if ($werte['prognose'] !== null) {
        $n_tag = fb_klemme(((float) $cfg['tagesgrenze'] - $werte['prognose'])
                           / max(1.0, (float) $cfg['spreizung_tag']), -1.0, 1.0);
    }
    $stand['saison'] = $n_tag === null ? 0 : (int) round(100 * $n_tag);

    /* --- Der Vorabendteil: was wird MORGEN? ---
     *
     * Er greift erst ab der eingestellten Stunde, und das ist der ganze
     * Witz daran. Am Vormittag sagt die Prognose fuer morgen nichts ueber
     * die Frage, ob diese Sonne jetzt erwuenscht ist - das Haus hat bis
     * dahin eine Nacht Zeit. Am spaeten Nachmittag dagegen bleibt die
     * Waerme bis morgen frueh im Haus: wird es kuehler, ist sie willkommen;
     * wird es heisser, ist sie eine Last, weil das Haus ueber Nacht nicht
     * mehr auskuehlt.
     *
     * Die Stunde wird aus der ORTSZEIT genommen und nicht aus UTC - hier
     * geht es um den Feierabend des Bewohners, nicht um Astronomie. */
    $n_morgen = null;
    $stunde = (int) date('G', (int) $jetzt);
    $vorabend = ($stunde >= (int) $cfg['vorabend_ab']);
    if ($vorabend && $werte['prognose1'] !== null) {
        $n_morgen = fb_klemme(((float) $cfg['tagesgrenze'] - $werte['prognose1'])
                              / max(1.0, (float) $cfg['spreizung_tag']), -1.0, 1.0);
    }
    $stand['vorabend'] = $vorabend ? 1 : 0;
    $stand['morgen']   = $n_morgen === null ? 0 : (int) round(100 * $n_morgen);

    /* --- Strahlung aufteilen - und einen unmoeglichen Wert VERWERFEN. ---
     *
     * fb_strahlungsteilung() sagt im dritten Rueckgabewert, ob der Messwert
     * ueberhaupt moeglich war. Ist er es nicht, wird er behandelt wie ein
     * fehlender: kein Urteil, OK auf 0, und der Grund steht im Protokoll.
     * Vorher wurde ein solcher Wert stillschweigend auf das physikalische
     * Maximum geklemmt - und ergab damit das denkbar sicherste Urteil aus
     * der denkbar schlechtesten Angabe. */
    $dni = 0.0; $diffus = 0.0; $global = 0.0;
    if ($werte['strahlung'] !== null) {
        $global = max(0.0, $werte['strahlung']);
        list($dni, $diffus, $strahlung_moeglich)
            = fb_strahlungsteilung($global, $sonne['hoehe_geo'], $jetzt);
        if (!$strahlung_moeglich) {
            $stand['verworfen'] = array('strahlung' => round($global, 1),
                'hoehe' => round($sonne['hoehe_geo'], 2),
                'grenze' => round(fb_extraterrestrisch($jetzt, $sonne['hoehe_geo']), 1));
            $werte['strahlung'] = null;
            $global = 0.0; $dni = 0.0; $diffus = 0.0;
            $fehlend[] = 'strahlung';
        }
    }
    $stand['strahlung'] = $werte['strahlung'] === null ? -1 : round($werte['strahlung'], 1);
    $stand['dni']    = round($dni, 1);
    $stand['diffus'] = round($diffus, 1);

    /* Die Sperre wird aus der PFLICHTSPALTE von fb_messgroessen() gebildet,
     * nicht aus einer zweiten Aufzaehlung. Wer dort eine Groesse auf Pflicht
     * setzt, bekommt die Sperre ohne weiteres Zutun - und die beiden Listen
     * koennen nicht mehr auseinanderlaufen. */
    $pflicht_fehlt = false;
    foreach (fb_messgroessen_pflicht($cfg) as $fb_n) {
        if (!isset($werte[$fb_n]) || $werte[$fb_n] === null) { $pflicht_fehlt = true; }
    }

    /* Vier Teile, vier Gewichte. Ein Teil, fuer den keine Zahl vorliegt -
     * etwa der Vorabend am Vormittag -, zaehlt NICHT mit; sein Gewicht faellt
     * aus der Summe heraus. Sonst zoege eine fehlende Groesse das Urteil
     * stillschweigend gegen null, und das saehe aus wie "ausgeglichen"
     * statt wie "nicht bekannt". */
    $gr = (float) $cfg['gewicht_raum'];
    $gt = (float) $cfg['gewicht_tag'];
    $gb = (float) $cfg['gewicht_bilanz'];
    $gm = (float) $cfg['gewicht_morgen'];

    $grund_nr = fb_grund_nr();
    $anzahl_beschatten = 0;
    $anzahl_fenster = 0;
    $anzahl_blendung = 0;
    $anzahl_daemmen = 0;
    $anzahl_nicht_gefahren = 0;
    $wh_gesamt = 0.0;
    $modell = (string) $cfg['himmelsmodell'];

    $nr = 0;
    foreach ($cfg['fenster'] as $f) {
        $nr++;
        if ($f['kuerzel'] === '') { continue; }
        $anzahl_fenster++;

        $vor = isset($vorher['fenster'][(string) $nr]) && is_array($vorher['fenster'][(string) $nr])
             ? $vorher['fenster'][(string) $nr] : array();
        $war_beschattet = !empty($vor['beschatten']);

        $e = array(
            'nr'       => $nr,
            'kuerzel'  => $f['kuerzel'],
            'name'     => $f['name'],
            'urteil'   => 0,
            'beschatten' => 0,
            'grundnr'  => $grund_nr['zu_wenig_sonne'],
            'grund'    => 'zu_wenig_sonne',
            'watt'     => 0,
            'glas'     => 0,
            'kranz'    => 0,
            'einfall'  => -1,
            'blendung' => 0,
            'daemmen'  => 0,
            'wh'       => isset($bilanz['fenster'][(string) $nr])
                          ? (int) round($bilanz['fenster'][(string) $nr]) : 0,
            'stellung' => -1,
            'gefahren' => -1,
            'begruendung' => '',
        );
        $wh_gesamt += $e['wh'];

        if (empty($f['aktiv'])) {
            $e['grund'] = 'abgeschaltet';
            $e['grundnr'] = $grund_nr['abgeschaltet'];
            $stand['fenster'][(string) $nr] = $e;
            continue;
        }

        /* --- Geometrie --- */
        $cos_theta = fb_cos_einfall($sonne['azimut'], $sonne['hoehe_geo'],
                                    $f['azimut'], $f['neigung']);
        $e['einfall'] = $cos_theta > 0 ? (int) round(rad2deg(acos(min(1.0, $cos_theta)))) : -1;

        list($punkte, $unlesbar) = fb_horizont_lesen($f['horizont']);
        $hindernis = count($punkte) > 0 ? fb_horizont_hoehe($punkte, $sonne['azimut']) : -90.0;
        $verschattet = ($sonne['hoehe_geo'] <= $hindernis);

        /* DER DACHUEBERSTAND.
         *
         * Er verschattet von OBEN und damit genau umgekehrt zum Horizont:
         * die hohe Sommersonne bleibt draussen, die tiefe Wintersonne kommt
         * herein. Genau dafuer baut man ihn. Zurueck kommt der Anteil des
         * Fensters, der im Schatten liegt - der Rest der direkten Strahlung
         * geht durch. */
        $dach_anteil = fb_dach_anteil($sonne['hoehe_geo'], $sonne['azimut'],
                                      $f['azimut'], $f['dach_tiefe'],
                                      $f['dach_hoehe'], $f['fenster_hoehe']);
        $e['dach'] = (int) round($dach_anteil * 100.0);
        $strahlen = fb_fensterstrahlung($dni, $diffus, $global, $cos_theta, $f['neigung'],
                                        $cfg['albedo'] / 100.0, $cfg['iam_b0'] / 100.0,
                                        $verschattet, $modell, $sonne['hoehe_geo'], $jetzt,
                                        1.0 - $dach_anteil);
        $glas = $strahlen['gesamt'];
        $e['glas'] = (int) round($glas);
        $e['kranz'] = (int) round($strahlen['kranz']);
        $e['watt'] = (int) round($glas * (float) $f['flaeche'] * ((int) $f['gwert'] / 100.0));

        /* --- Blendschutz: eine EIGENE Aussage ---
         *
         * Sie geht mit Absicht NICHT in das Urteil ein. Tief stehende Sonne
         * blendet auch im Januar, wenn die Waerme hochwillkommen ist; wer
         * beides in eine Zahl mischt, kann hinterher nicht mehr sagen, warum
         * ein Fenster zu ist. In Loxone gehoert dieser Wert deshalb an einen
         * ANDEREN Eingang als die Waermefreigabe.
         *
         * Gerechnet wird sie auch dann, wenn Messwerte fehlen - sie braucht
         * nur die Geometrie und die Strahlung. */
        if ((int) $f['blend_hoehe'] > 0 && !$verschattet && $cos_theta > 0.0
            && $sonne['hoehe'] > 0.0
            && $sonne['hoehe'] <= (float) $f['blend_hoehe']
            && $e['einfall'] >= 0 && $e['einfall'] <= (int) $f['blend_winkel']
            && ($strahlen['direkt'] + $strahlen['kranz']) > 50.0) {
            $e['blendung'] = 1;
            $anzahl_blendung++;
        }

        /* --- Nachtdaemmung: dieselbe Bilanz, nachts und ohne Sonne ---
         *
         * Ein geschlossener Rollladen ist nachts ein Waermeschutz. Verlangt
         * wird dreierlei: die Sonne ist unten, es ist kalt genug, und der
         * Tag will ueberhaupt Waerme. Der dritte Punkt ist der wichtige - in
         * einer lauen Augustnacht bringt ein geschlossener Rollladen nichts
         * ausser einem dunklen Zimmer. */
        if (!empty($cfg['daemmen_ein']) && !empty($f['daemmen'])
            && $sonne['hoehe'] <= 0.0
            && $werte['aussen'] !== null
            && $werte['aussen'] <= (float) $cfg['daemm_grenze']
            && $n_tag !== null && $n_tag > 0.0) {
            $e['daemmen'] = 1;
            $anzahl_daemmen++;
        }

        /* --- Was hat Loxone daraus gemacht? ---
         * Die Rueckmeldung wird HIER nur eingesammelt; verglichen wird sie
         * weiter unten, wenn das Urteil feststeht. */
        if (!empty($cfg['stellung_ein'])) {
            list($st, $st_alter) = fb_messwert($messwerte,
                'stellung.' . strtolower($f['kuerzel']), $ha, $jetzt);
            if ($st !== null) { $e['stellung'] = (int) round($st); }
        }

        /* --- Der Raumteil des Waermebedarfs ---
         *
         * Bezugsgroesse ist die BESCHATTUNGSGRENZE des Raumes, nicht sein
         * Heizsollwert. In Loxone ist das der Parameter TShadeHeat des
         * Raumreglers - in dieser Anlage 25 Grad -, also die Temperatur, ab
         * der der Raum keine weitere Sonne mehr will.
         *
         * Der Unterschied ist nicht akademisch. Ein Schlafzimmer mit 22 Grad
         * Heizsollwert waere im Sommer dauernd "zu warm" und wuerde jede
         * Sonne abweisen, auch im Oktober. Deshalb heisst der Messwert
         * 'grenze.<raum>' und nicht 'soll.<raum>': ein Name, den man
         * verwechseln kann, wird verwechselt.
         */
        $n_raum = null;
        $raum_ist = null; $raum_grenze = null;
        if (!empty($f['raumwerte']) && $f['raum'] !== '') {
            list($raum_ist, $a1)    = fb_messwert($messwerte, 'ist.' . $f['raum'], $ha, $jetzt);
            list($raum_grenze, $a2) = fb_messwert($messwerte, 'grenze.' . $f['raum'], $ha, $jetzt);
            if ($raum_ist === null) { $fehlend[] = 'ist.' . $f['raum']; }
            if ($raum_grenze === null) { $fehlend[] = 'grenze.' . $f['raum']; }
            if ($raum_ist !== null && $raum_grenze !== null) {
                /* Die Traegheit hebt die Grenze an: ein massives Zimmer
                 * vertraegt eine Stunde Sonne, ein Dachzimmer nicht. Sie
                 * verschiebt also den Punkt, ab dem es dem Raum zu warm
                 * wird - sie erfindet keine Temperatur. */
                $grenze = $raum_grenze + ((int) $f['traegheit']) / 10.0;
                $n_raum = fb_klemme(($grenze - $raum_ist)
                                    / max(0.1, (float) $cfg['spreizung_raum'] / 10.0), -1.0, 1.0);
            }
        }

        /* --- Fehlt etwas Notwendiges, gibt es kein Urteil --- */
        $daten_fehlen = ($pflicht_fehlt || $n_tag === null
                         || (!empty($f['raumwerte']) && $f['raum'] !== '' && $n_raum === null));
        if ($daten_fehlen) {
            $e['grund'] = 'keine_daten';
            $e['grundnr'] = $grund_nr['keine_daten'];
            $e['begruendung'] = 'Es fehlen gueltige Messwerte - es wird nicht geraten.';
            $stand['fenster'][(string) $nr] = $e;
            continue;
        }

        /* --- Der Waermebedarf ---
         *
         * Zwei Teile, gewichtet addiert und nicht miteinander verundet. Ein
         * UND liesse den Raum die Jahreszeit ueberstimmen (dann waere im
         * September alles zu), ein ODER umgekehrt (dann bliebe im August
         * alles offen). Die Summe ist stetig, und der Anwender kann die
         * Gewichtung verschieben, ohne den Bauplan zu aendern. */
        /* --- Der Tagesbilanzteil: wie viel hat der Raum heute schon? ---
         *
         * Nicht die Temperatur, sondern die WAERMEMENGE. Ein Zimmer, das
         * seit acht Uhr drei Kilowattstunden aufgenommen hat, ist voll -
         * auch wenn das Thermometer noch 24 Grad zeigt, denn die Wand ist
         * warm und gibt das abends wieder ab. Genau diese Groesse fehlt
         * einer Momentaufnahme.
         *
         * Bei einem leeren Raum ist der Teil +1 (nimm ruhig), bei einem
         * vollen -1. */
        $n_bilanz = null;
        if ($gb > 0.0 && !empty($f['raumwerte']) && $f['raum'] !== ''
            && isset($bilanz['raeume'][$f['raum']])) {
            /* DIE SCHWELLE HAENGT AN DER RAUMGROESSE.
             *
             * 3000 Wh sind fuer ein 5-Quadratmeter-Duschbad eine andere
             * Aussage als fuer ein 25-Quadratmeter-Wohnzimmer - im ersten
             * Fall laengst zu viel, im zweiten kaum der halbe Tag. Deshalb
             * steht in der Einstellung jetzt eine Zahl JE QUADRATMETER, und
             * die Flaeche kommt aus der Projektdatei. */
            list($qm, $geschaetzt) = fb_raumflaeche($cfg, $f['raum']);
            $voll = max(1.0, (float) $cfg['bilanz_voll_qm'] * $qm);
            $n_bilanz = fb_klemme(1.0 - 2.0 * ((float) $bilanz['raeume'][$f['raum']] / $voll),
                                  -1.0, 1.0);
            $e['raum_qm'] = round($qm, 1);
            $e['raum_qm_geschaetzt'] = $geschaetzt ? 1 : 0;
        }

        /* Vier Teile, gewichtet addiert. Wer keine Zahl hat, faellt samt
         * seinem Gewicht heraus - siehe die Begruendung weiter oben. */
        $teile = array();
        if ($n_raum   !== null) { $teile[] = array($gr, $n_raum); }
        if ($n_tag    !== null) { $teile[] = array($gt, $n_tag); }
        if ($n_bilanz !== null) { $teile[] = array($gb, $n_bilanz); }
        if ($n_morgen !== null) { $teile[] = array($gm, $n_morgen); }
        $summe = 0.0; $gsumme = 0.0;
        foreach ($teile as $t) { $summe += $t[0] * $t[1]; $gsumme += $t[0]; }
        if ($gsumme <= 0.0) {
            /* Alle Gewichte auf null: dann bleibt der Tagesteil als
             * Rueckfall, und wenn auch der fehlt, gibt es kein Urteil. */
            $n = $n_tag === null ? 0.0 : $n_tag;
        } else {
            $n = fb_klemme($summe / $gsumme, -1.0, 1.0);
        }
        /* EINE HARTE OBERGRENZE: ein Raum, der ueber seiner
         * Beschattungsgrenze liegt, bekommt nie ein positives Urteil.
         * "Lass Sonne in das 26 Grad warme Zimmer, es wird ja ein kuehler
         * Tag" ist ein Satz, den niemand hoeren will - und die gewichtete
         * Summe kann ihn sonst sagen. Beschattung folgt daraus NICHT; das
         * Urteil steht dann auf 0, also auf "keine Meinung". */
        if ($n_raum !== null && $n_raum < 0.0 && $n > 0.0) { $n = 0.0; }

        /* --- Das Gewicht des Eintrags ---
         *
         * Gewogen wird der DIREKTE Anteil, nicht die gesamte Strahlung am
         * Glas. Das ist eine Korrektur aus dem ersten Vollversuch mit 25
         * Fenstern, und sie ist wichtig genug, um sie hier auszuschreiben:
         *
         * Himmelsdiffus und Bodenreflex stehen an JEDEM Fenster, den ganzen
         * Tag, und sie sind an allen Fenstern fast gleich gross. Am
         * 23.08.2026 waren das 121 W/m2. Bei einer Bezugsstrahlung von 250
         * ergab das allein schon ein halbes Gewicht - und damit meldeten
         * die fuenf NORDfenster, auf die um zehn Uhr keine Sonne faellt,
         * ein Urteil von -39 und verlangten Beschattung. Das ist keine
         * Kleinigkeit: der Anwender liest daran ab, dass das Plugin nicht
         * versteht, wo die Sonne steht.
         *
         * Der direkte Anteil dagegen ist genau das, was dieses Fenster von
         * den anderen unterscheidet - und genau das, was der
         * AutoJalousie-Baustein hinter uns wegnimmt. Steht die Sonne nicht
         * am Glas oder ist sie verschattet, ist er null, und das Urteil ist
         * 0: keine Meinung.
         *
         * Was dabei NICHT verlorengeht: die Anzeige. 'glas' und 'watt'
         * fuehren weiter die gesamte Strahlung samt Diffusanteil - das ist
         * die ehrliche physikalische Zahl, und sie steht auch in der
         * Begruendung. Nur das GEWICHT des Urteils haengt am direkten Teil.
         */
        $s = fb_klemme($strahlen['direkt'] / max(1.0, (float) $cfg['e_ref']), 0.0, 1.0);
        $e['urteil'] = (int) round(100.0 * $n * $s);

        /* --- Hysterese. Ohne sie flattert der Rollladen am Schwellwert. --- */
        if ($war_beschattet) {
            $e['beschatten'] = ($e['urteil'] <= -(int) $cfg['schwelle_aus']) ? 1 : 0;
        } else {
            $e['beschatten'] = ($e['urteil'] <= -(int) $cfg['schwelle_ein']) ? 1 : 0;
        }
        /* URTEIL 0 HEISST "KEINE MEINUNG" UND NIE "BESCHATTEN".
         *
         * Steht die Ausschaltschwelle auf 0 - und das ist ein zulaessiger
         * Wert -, lautet die Haltebedingung oben "urteil <= 0". Damit rastet
         * eine einmal begonnene Beschattung dauerhaft ein: nachts ist das
         * Urteil 0, weil die Sonne weg ist, und der Rollladen bliebe die
         * ganze Nacht und alle Folgetage unten. Gemessen ueber zwei Tage in
         * Fuenfminutenschritten: eingeschaltet nach 280 Minuten, wieder
         * ausgeschaltet nie.
         *
         * Die eine Zeile ist der robuste Weg: bei Urteil 0 lautet der Grund
         * ohnehin immer 'nicht_am_glas', 'zu_wenig_sonne' oder
         * 'keine_daten'. Aus keinem davon folgt eine Beschattung. */
        if ($e['urteil'] === 0) { $e['beschatten'] = 0; }
        if ($e['beschatten']) { $anzahl_beschatten++; }

        /* --- Der Grund, und zwar der beherrschende ---
         * Die Reihenfolge ist die der Ausschlusskraft: was gar nicht am
         * Glas ankommt, braucht keine Bewertung. Erst danach wird gefragt,
         * WER dagegen ist. */
        $raum_dagegen = ($n_raum !== null && $n_raum < 0.0);
        $tag_dagegen  = ($n_tag !== null && $n_tag < 0.0);
        $bilanz_dagegen = ($n_bilanz !== null && $n_bilanz < 0.0);
        if ($cos_theta <= 0.0 || $sonne['hoehe'] <= 0.0) {
            $e['grund'] = 'nicht_am_glas';
        } elseif ($verschattet && $strahlen['direkt'] <= 0.0) {
            $e['grund'] = 'verschattet';
        } elseif ($s < 0.05) {
            $e['grund'] = 'zu_wenig_sonne';
        } elseif ($e['urteil'] > 0) {
            $e['grund'] = 'erwuenscht';
        } elseif ($raum_dagegen && $tag_dagegen) {
            $e['grund'] = 'raum_und_tag';
        } elseif ($raum_dagegen) {
            $e['grund'] = 'raum_zu_warm';
        } elseif ($tag_dagegen) {
            $e['grund'] = 'tag_zu_warm';
        } elseif ($bilanz_dagegen) {
            $e['grund'] = 'raum_voll';
        } elseif ($n_morgen !== null && $n_morgen < 0.0) {
            $e['grund'] = 'morgen_zu_warm';
        } else {
            /* Urteil 0, und niemand ist dagegen: dann ist der Eintrag zu
             * klein, um eine Meinung zu rechtfertigen. */
            $e['grund'] = 'zu_wenig_sonne';
        }
        $e['grundnr'] = $grund_nr[$e['grund']];

        /* --- Die Zeile, die das Urteil in einem Satz begruendet ---
         * Wer nicht sieht, WARUM ein Fenster beschattet wird, schaltet das
         * Plugin nach der ersten Ueberraschung ab. Deshalb steht sie nicht
         * in der Hilfe, sondern neben dem Wert. */
        /* --- Forderung gegen Wirklichkeit ---
         *
         * Erst jetzt, wo das Urteil feststeht, laesst sich sagen, ob Loxone
         * getan hat, was das Plugin verlangt. Gemeldet wird erst nach einer
         * Frist: ein Rollladen braucht seine Zeit, und der AutoJalousie hat
         * seine eigene Verzoegerung. -1 heisst "keine Rueckmeldung". */
        if (!empty($cfg['stellung_ein']) && $e['stellung'] >= 0) {
            $zu = ((int) $e['stellung'] >= (int) $cfg['stellung_zu']) ? 1 : 0;
            $e['gefahren'] = ($e['beschatten'] === $zu) ? 1 : 0;
            if ($e['gefahren'] === 0) {
                /* Seit wann weicht es ab? Der Zeitpunkt kommt aus dem
                 * vorigen Stand und wandert mit, solange die Abweichung
                 * besteht. */
                $seit = isset($vor['abweicht_seit']) && (int) $vor['abweicht_seit'] > 0
                        && isset($vor['gefahren']) && (int) $vor['gefahren'] === 0
                      ? (int) $vor['abweicht_seit'] : (int) $jetzt;
                $e['abweicht_seit'] = $seit;
                if (($jetzt - $seit) >= (int) $cfg['stellung_frist']) {
                    $anzahl_nicht_gefahren++;
                    $e['gemeldet'] = 1;
                }
            }
        }

        $e['begruendung'] = fb_begruendung($e, $sonne, $n_raum, $n_tag,
                                           $raum_ist, $raum_grenze, $werte, $verschattet,
                                           count($punkte) > 0, $n_bilanz, $n_morgen);
        if ($unlesbar) {
            $e['begruendung'] .= ' Achtung: ' . count($unlesbar)
                              . ' Stuetzpunkt(e) des Horizonts sind unlesbar und bleiben unberuecksichtigt.';
        }
        $stand['fenster'][(string) $nr] = $e;
    }

    $stand['fehlend'] = array_values(array_unique($fehlend));
    $stand['anzahl']  = $anzahl_fenster;
    $stand['beschatten_anzahl'] = $anzahl_beschatten;
    $stand['blendung_anzahl']   = $anzahl_blendung;
    $stand['daemmen_anzahl']    = $anzahl_daemmen;
    $stand['nicht_gefahren']    = $anzahl_nicht_gefahren;
    $stand['wh_tag']            = (int) round($wh_gesamt);
    $stand['ok'] = (count($stand['fehlend']) === 0 && $anzahl_fenster > 0) ? 1 : 0;
    if ($stand['fehlend']) { $stand['meldung'] = 'MESSWERTE_FEHLEN'; }
    elseif ($anzahl_fenster === 0) { $stand['meldung'] = 'KEIN_FENSTER'; }
    return $stand;
}

/**
 * Ein Satz, der das Urteil erklaert. Deutsch, ohne Sprachdatei.
 *
 * Warum fest im Quelltext und nicht uebersetzt: der Satz wird aus Zahlen
 * zusammengesetzt, die je Fenster verschieden sind, und er wandert
 * unveraendert nach MQTT. Ein Satz aus acht Bruchstuecken in zwei Sprachen
 * waere in beiden schlechter als einer in einer. Die Oberflaeche zeigt ihn
 * zusaetzlich zu den uebersetzten Beschriftungen; wer ihn uebersetzt haben
 * will, bekommt eine ehrliche Antwort in der Hilfe statt einer schlechten
 * Uebersetzung.
 */
function fb_begruendung($e, $sonne, $n_raum, $n_tag, $raum_ist, $raum_grenze,
                        $werte, $verschattet, $horizont_da,
                        $n_bilanz = null, $n_morgen = null)
{
    $t = array();
    $t[] = sprintf('Sonne %d Grad hoch, Azimut %d Grad',
                   (int) round($sonne['hoehe']), (int) round($sonne['azimut']));
    if ($e['einfall'] >= 0) {
        $t[] = sprintf('Einfall %d Grad zur Senkrechten', (int) $e['einfall']);
    } else {
        $t[] = 'Sonne steht hinter dem Fenster';
    }
    if (!empty($e['dach'])) {
        /* Nur nennen, wenn er auch wirkt - ein Satzteil "0 Prozent
         * verschattet" bei jedem Fenster den ganzen Winter waere Laerm. */
        $t[] = sprintf('Dachueberstand verschattet %d Prozent des Glases', (int) $e['dach']);
    }
    if ($verschattet) {
        $t[] = 'vom eingetragenen Horizont verdeckt, nur Himmelslicht';
    } elseif (!$horizont_da) {
        $t[] = 'kein Horizont hinterlegt, freie Sicht angenommen';
    }
    $t[] = sprintf('%d W/m2 am Glas, %d W durchs Fenster', (int) $e['glas'], (int) $e['watt']);
    if ($raum_ist !== null && $raum_grenze !== null) {
        $t[] = sprintf('Raum %.1f Grad, Beschattungsgrenze %.1f Grad',
                       $raum_ist, $raum_grenze);
    } else {
        $t[] = 'ohne Raumwerte gerechnet';
    }
    if ($werte['prognose'] !== null) {
        $t[] = sprintf('Tageshoechstwert %.0f Grad erwartet', $werte['prognose']);
    }
    if ($n_bilanz !== null) {
        $t[] = sprintf('%d Wh heute schon durch dieses Glas', (int) $e['wh']);
        /* DIE RAUMFLAECHE GEHOERT IN DEN SATZ.
         *
         * Der Bilanzteil misst Wattstunden je Quadratmeter Grundflaeche.
         * Steht dort eine geschaetzte Flaeche, ist das Urteil um genau den
         * Faktor daneben, um den die Schaetzung danebenliegt - und ohne
         * diesen Halbsatz saehe eine geschaetzte Flaeche im Ergebnis
         * genauso aus wie eine aus der Projektdatei gelesene. */
        if (isset($e['raum_qm'])) {
            $t[] = sprintf('Raum %.1f m2%s', (float) $e['raum_qm'],
                           empty($e['raum_qm_geschaetzt']) ? '' : ' (geschaetzt)');
        }
    }
    if ($n_morgen !== null && $werte['prognose1'] !== null) {
        $t[] = sprintf('morgen %.0f Grad erwartet', $werte['prognose1']);
    }
    if (!empty($e['blendung'])) { $t[] = 'blendet'; }
    if (!empty($e['daemmen']))  { $t[] = 'Nachtdaemmung'; }
    if (isset($e['gefahren']) && (int) $e['gefahren'] === 0 && !empty($e['gemeldet'])) {
        $t[] = sprintf('Loxone meldet Stellung %d Prozent - das passt nicht zur Forderung',
                       (int) $e['stellung']);
    }
    $t[] = sprintf('Urteil %+d', (int) $e['urteil']);
    return implode('; ', $t) . '.';
}

/**
 * Einen Lauf ausfuehren: rechnen, ablegen, senden.
 *
 * $erzwingen = true uebergeht den Rechentakt (fuer den Knopf im Reiter
 * Test). Rueckgabe: array(gerechnet, Stand).
 *
 * Der Rechentakt ist kein Schoenheitsmittel: Loxone schickt bei einem
 * Wetterwechsel ein Dutzend virtueller Ausgaenge in derselben Sekunde los,
 * und jeder davon landet im Endpunkt. Ohne Mindestabstand rechnete das
 * Plugin ein Dutzend Mal und schickte ein Dutzend MQTT-Salven.
 */
function fb_lauf($erzwingen = false, $erzeugen = true)
{
    /* $erzeugen = false: die Konfiguration wird NUR gelesen. Der Endpunkt
     * ruft ausschliesslich so auf.
     *
     * Der Grund ist gemessen: mit $erzeugen = true legte ein einziger
     * melden-Aufruf bei beschaedigter Konfiguration eine .kaputt-Datei an
     * und stellte aus der Zweitschrift wieder her. Das ist an sich
     * richtiges Verhalten - aber es gehoert in den angemeldeten Bereich und
     * in den Cron, nicht in einen HTTP-Aufruf aus dem Heimnetz. Damit gilt
     * jetzt ohne Ausnahme: der Endpunkt schreibt in data/ und log/, nie in
     * config/. */
    $p = fb_paths();
    $cfg = fb_config($erzeugen);
    $vorher = fb_stand();
    $jetzt = time();

    if (!$erzwingen && isset($vorher['ts'])
        && ($jetzt - (int) $vorher['ts']) < (int) $cfg['rechentakt']) {
        return array(false, $vorher);
    }

    $messwerte = fb_messwerte();

    /* Erst der Tageswechsel, dann die Rechnung: sonst schriebe der erste
     * Lauf nach Mitternacht die Wattstunden von gestern fort. */
    $bilanz = fb_tageswechsel($cfg, fb_json_lesen($p['bilanz']), $jetzt);

    $stand = fb_rechnen($cfg, $messwerte, $jetzt, $vorher, $bilanz);

    /* --- Die Vorausschau ---
     * Dieselbe Rechnung noch einmal, nur mit verschobener Zeit. Die
     * Messwerte bleiben stehen; es wandert allein die Geometrie. Das ist
     * ausdruecklich KEINE Wettervorhersage - die Wolken in einer halben
     * Stunde kennt niemand -, aber es ist genau die Groesse, die dem
     * AutoJalousie-Baustein fehlt: der hat eine Einschaltverzoegerung, und
     * ohne Vorausschau faehrt der Rollladen erst, wenn die Sonne schon eine
     * halbe Stunde im Zimmer steht. */
    if ((int) $cfg['vorschau'] > 0) {
        /* Die Stempel wandern mit - siehe fb_messwerte_verschieben(). */
        $spaeter = fb_rechnen($cfg,
                              fb_messwerte_verschieben($messwerte, (int) $cfg['vorschau']),
                              $jetzt + (int) $cfg['vorschau'], $vorher, $bilanz);
        foreach (array_keys($stand['fenster']) as $nr) {
            $stand['fenster'][$nr]['urteil30'] =
                isset($spaeter['fenster'][$nr]['urteil'])
                ? (int) $spaeter['fenster'][$nr]['urteil'] : 0;
            $stand['fenster'][$nr]['beschatten30'] =
                isset($spaeter['fenster'][$nr]['beschatten'])
                ? (int) $spaeter['fenster'][$nr]['beschatten'] : 0;
        }
        $stand['beschatten30_anzahl'] = (int) $spaeter['beschatten_anzahl'];
        /* Der Sonnenstand wird NUR uebernommen, wenn er wirklich gerechnet
         * wurde. Ohne Standort gibt es keinen - und eine 0 an dieser Stelle
         * waere kein fehlender Wert, sondern ein falscher: 0 Grad Hoehe ist
         * ein Sonnenaufgang. Ein nicht gesetzter Schluessel ist ehrlicher,
         * und jede Stelle, die ihn liest, prueft ohnehin mit isset(). */
        if (isset($spaeter['sonne_hoehe'])) {
            $stand['sonne_hoehe30']  = $spaeter['sonne_hoehe'];
            $stand['sonne_azimut30'] = $spaeter['sonne_azimut'];
        }
    }

    /* Jetzt erst fortschreiben - die Wattzahlen dieses Laufes gelten fuer
     * das Stueck Zeit, das seit dem letzten vergangen ist. */
    $bilanz = fb_bilanz_fortschreiben($cfg, $stand, $bilanz, $messwerte, $jetzt);
    foreach (array_keys($stand['fenster']) as $nr) {
        $stand['fenster'][$nr]['wh'] = isset($bilanz['fenster'][$nr])
            ? (int) round($bilanz['fenster'][$nr]) : 0;
    }
    $stand['wh_tag'] = (int) round(array_sum($bilanz['fenster']));

    $stand['felder'] = fb_felderwerte($stand, $cfg);
    if (!is_dir($p['datadir'])) { @mkdir($p['datadir'], 0775, true); }
    fb_json_schreiben($p['stand'], $stand, 0644);
    fb_json_schreiben($p['bilanz'], $bilanz, 0644);

    fb_tagesbericht($cfg, $bilanz, $stand, $jetzt);
    fb_pv_warnen($cfg);

    if ($stand['meldung'] === 'KEIN_STANDORT') {
        fb_log_wenn_neu('lauf', 'Es ist kein Standort eingetragen - ohne Breite und Laenge '
            . 'laesst sich kein Sonnenstand rechnen. Reiter Einstellungen.');
    } elseif ($stand['meldung'] === 'KEIN_FENSTER') {
        fb_log_wenn_neu('lauf', 'Es ist kein Fenster eingerichtet.');
    } elseif ($stand['fehlend']) {
        fb_log_wenn_neu('lauf', 'Es fehlen gueltige Messwerte: '
            . implode(', ', $stand['fehlend']) . ' - es wird nichts geraten.');
    } else {
        fb_log_wenn_neu('lauf', sprintf('%d von %d Fenstern wollen Beschattung '
            . '(Sonne %d Grad hoch, %d Grad Azimut, %d W/m2).',
            (int) $stand['beschatten_anzahl'], (int) $stand['anzahl'],
            (int) round($stand['sonne_hoehe']), (int) round($stand['sonne_azimut']),
            (int) round($stand['strahlung'])));
    }
    if (!empty($cfg['stellung_ein']) && (int) $stand['nicht_gefahren'] > 0) {
        $namen = array();
        foreach ($stand['fenster'] as $e) {
            if (!empty($e['gemeldet'])) {
                $namen[] = $e['kuerzel'] . ' (' . (int) $e['stellung'] . '%)';
            }
        }
        fb_log_wenn_neu('stellung', sprintf(
            '%d Fenster stehen anders, als das Urteil verlangt: %s. '
            . 'Entweder greift die Freigabe in Loxone nicht, oder der '
            . 'Rollladen kommt nicht durch.',
            (int) $stand['nicht_gefahren'], fb_liste_kurz($namen)));
    }

    fb_mqtt_senden($cfg, $stand);
    return array(true, $stand);
}

/* ==================================================================
 * MQTT
 * ================================================================== */

/**
 * Zustand des LoxBerry-Gateways.
 *
 * Gelesen werden drei Dinge aus DEMSELBEN Block: ob es eingerichtet ist, ob
 * es von selbst startet (Schluessel Gatewayautostart - Mqtt.Autostart gibt
 * es NICHT, das war die Fehlerquelle in vier Plugins dieses Hauses) und in
 * welcher FASSUNG es laeuft. Die Fassung entscheidet, ob der Anwender ein
 * Abonnement von Hand eintragen muss: unter V2 schaltet der LoxBerry-Kern
 * die Knoepfe auf der Abonnement-Seite ab.
 *
 * 0 bei 'fassung' heisst "nicht lesbar" und wird NICHT auf 1 vorbelegt -
 * "unbekannt" und "Fassung 1" sind verschiedene Aussagen.
 */
function fb_mqtt_zustand()
{
    $p = fb_paths();
    $leer = array('gefunden' => 0, 'autostart' => 0, 'udpport' => 0, 'fassung' => 0);
    if ($p['home'] === '') { return $leer; }
    $gen = fb_json_lesen($p['home'] . '/config/system/general.json');
    $m = array();
    if (isset($gen['Mqtt']) && is_array($gen['Mqtt'])) { $m = $gen['Mqtt']; }
    elseif (isset($gen['mqtt']) && is_array($gen['mqtt'])) { $m = $gen['mqtt']; }
    if (!$m) { return $leer; }
    $hol = function ($gross, $klein) use ($m) {
        if (isset($m[$gross])) { return $m[$gross]; }
        return isset($m[$klein]) ? $m[$klein] : '';
    };
    return array(
        'gefunden'  => 1,
        'autostart' => in_array((string) $hol('Gatewayautostart', 'gatewayautostart'),
                                array('1', 'true'), true) ? 1 : 0,
        'udpport'   => (int) $hol('Udpinport', 'udpinport'),
        'fassung'   => (int) $hol('Gatewayversion', 'gatewayversion'),
    );
}

/**
 * Ein Thema fuer das Gateway. Das Gateway liest die UDP-Zeile als drei
 * Teile - Verb, Thema, Rest -, getrennt an Leerzeichen. Ein Leerzeichen IM
 * Thema verschiebt alles dahinter.
 */
function fb_mqtt_thema($thema)
{
    $t = preg_replace('#[^A-Za-z0-9_/\-]#', '_', (string) $thema);
    return trim(preg_replace('#/+#', '/', $t), '/');
}

/**
 * Eine Nutzlast fuer das Gateway. Zeilenumbrueche muessen weg: das Gateway
 * liest zeilenweise, und ein Umbruch macht aus einer Nachricht zwei - die
 * zweite beginnt nicht mit 'publish' und wird verworfen. Das ist hier keine
 * Theorie: die Begruendung je Fenster ist ein zusammengesetzter Satz.
 */
function fb_mqtt_wert_saeubern($v)
{
    $wert = str_replace(array("\r\n", "\r", "\n", "\t"), ' ', (string) $v);
    return trim(preg_replace('/ {2,}/', ' ', $wert));
}

/**
 * Die Themen, die das Plugin veroeffentlicht - EINE Quelle fuer die
 * Tabelle im Reiter MQTT und fuer das Senden. Zwei getrennt gepflegte
 * Listen sind zwei Wahrheiten; genau daran ist das Ferien-Plugin einmal
 * vorbeigelaufen, weil beide Listen zufaellig gleich lang waren.
 */
function fb_mqtt_themen()
{
    $t = array(
        'ok'                 => 'FB_MQTT.OK',
        'herz'               => 'FB_MQTT.HERZ',
        'ts'                 => 'FB_MQTT.TS',
        'fenster_anzahl'     => 'FB_MQTT.FENSTER',
        'beschatten_anzahl'  => 'FB_MQTT.BESCHATTEN_ANZAHL',
        'sonne_hoehe'        => 'FB_MQTT.SONNE_HOEHE',
        'sonne_azimut'       => 'FB_MQTT.SONNE_AZIMUT',
        'saison'             => 'FB_MQTT.SAISON',
        'strahlung'          => 'FB_MQTT.STRAHLUNG',
        'wh_tag'             => 'FB_MQTT.WH_TAG',
        '<kuerzel>/urteil'      => 'FB_MQTT.F_URTEIL',
        '<kuerzel>/beschatten'  => 'FB_MQTT.F_BESCHATTEN',
        '<kuerzel>/grund'       => 'FB_MQTT.F_GRUND',
        '<kuerzel>/watt'        => 'FB_MQTT.F_WATT',
        '<kuerzel>/glas'        => 'FB_MQTT.F_GLAS',
        '<kuerzel>/wh'          => 'FB_MQTT.F_WH',
        '<kuerzel>/begruendung' => 'FB_MQTT.F_BEGRUENDUNG',
    );
    $cfg = fb_config(false);
    if ((int) $cfg['vorschau'] > 0) {
        $t['beschatten30_anzahl']  = 'FB_MQTT.BESCHATTEN30';
        $t['<kuerzel>/urteil30']     = 'FB_MQTT.F_URTEIL30';
        $t['<kuerzel>/beschatten30'] = 'FB_MQTT.F_BESCHATTEN30';
    }
    $blendet = false;
    foreach ($cfg['fenster'] as $fe) {
        if ((int) $fe['blend_hoehe'] > 0) { $blendet = true; break; }
    }
    if ($blendet) {
        $t['blendung_anzahl'] = 'FB_MQTT.BLENDUNG';
        $t['<kuerzel>/blendung'] = 'FB_MQTT.F_BLENDUNG';
    }
    if (!empty($cfg['daemmen_ein'])) {
        $t['daemmen_anzahl'] = 'FB_MQTT.DAEMMEN';
        $t['<kuerzel>/daemmen'] = 'FB_MQTT.F_DAEMMEN';
    }
    if (!empty($cfg['stellung_ein'])) {
        $t['nicht_gefahren'] = 'FB_MQTT.NICHT_GEFAHREN';
        $t['<kuerzel>/gefahren'] = 'FB_MQTT.F_GEFAHREN';
    }
    if (!empty($cfg['pv_gegenprobe'])) { $t['pv_abweichung'] = 'FB_MQTT.PV_ABW'; }
    if (!empty($cfg['bericht_ein']))   { $t['bericht'] = 'FB_MQTT.BERICHT'; }
    return $t;
}

/**
 * Die Nachrichten dieses Standes.
 *
 * ACHTUNG, hier stand einmal "dieselbe Quelle wie die Themenliste" - und das
 * war schlicht unwahr: fb_mqtt_themen() und diese Funktion sind zwei von
 * Hand gefuehrte Listen. Sie decken sich heute, und genau so faengt jedes
 * Auseinanderlaufen an. Ein Satz, der eine Kopplung behauptet, die es nicht
 * gibt, ist schlimmer als gar keiner - er verhindert, dass jemand nachsieht.
 *
 * Zusammengehalten werden die beiden jetzt von fb_mqtt_kongruent(), und die
 * Zeile steht im Reiter Test.
 */
function fb_mqtt_nachrichten($cfg, $stand)
{
    $m = array(
        'ok'                => isset($stand['ok']) ? (int) $stand['ok'] : 0,
        /* herz wird HIER gerechnet und nirgends sonst. Bis zum ersten
         * Durchlauf stand hier eine 0, die der Sender hinterher ersetzte -
         * wer die Liste einzeln aufrief (etwa fuer eine Probe), bekam
         * damit eine Zahl, die nie stimmte. Eine Groesse, zwei Stellen. */
        'herz'              => (isset($stand['ts']) && (int) $stand['ts'] > 0)
                               ? (int) floor((time() - (int) $stand['ts']) / 60) : -1,
        'ts'                => isset($stand['ts']) ? (int) $stand['ts'] : 0,
        'fenster_anzahl'    => isset($stand['anzahl']) ? (int) $stand['anzahl'] : 0,
        'beschatten_anzahl' => isset($stand['beschatten_anzahl']) ? (int) $stand['beschatten_anzahl'] : 0,
        'sonne_hoehe'       => isset($stand['sonne_hoehe']) ? $stand['sonne_hoehe'] : 0,
        'sonne_azimut'      => isset($stand['sonne_azimut']) ? $stand['sonne_azimut'] : 0,
        'saison'            => isset($stand['saison']) ? (int) $stand['saison'] : 0,
        'strahlung'         => isset($stand['strahlung']) ? $stand['strahlung'] : -1,
        'wh_tag'            => isset($stand['wh_tag']) ? (int) $stand['wh_tag'] : 0,
    );
    /* Welche Zweige es gibt, entscheidet DIESELBE Funktion, die auch die
     * Tabelle im Reiter MQTT fuellt - sonst laufen Anleitung und Wirklichkeit
     * auseinander. Die Kongruenzprobe im Reiter Test haelt beide gegeneinander. */
    $themen = fb_mqtt_themen();
    if (isset($themen['beschatten30_anzahl'])) {
        $m['beschatten30_anzahl'] = isset($stand['beschatten30_anzahl'])
            ? (int) $stand['beschatten30_anzahl'] : 0;
    }
    if (isset($themen['blendung_anzahl'])) {
        $m['blendung_anzahl'] = isset($stand['blendung_anzahl'])
            ? (int) $stand['blendung_anzahl'] : 0;
    }
    if (isset($themen['daemmen_anzahl'])) {
        $m['daemmen_anzahl'] = isset($stand['daemmen_anzahl'])
            ? (int) $stand['daemmen_anzahl'] : 0;
    }
    if (isset($themen['nicht_gefahren'])) {
        $m['nicht_gefahren'] = isset($stand['nicht_gefahren'])
            ? (int) $stand['nicht_gefahren'] : 0;
    }
    if (isset($themen['pv_abweichung'])) {
        $pv = fb_pv_pruefen($cfg);
        $m['pv_abweichung'] = (int) $pv['abweichung'];
    }
    if (isset($themen['bericht'])) {
        /* Der Bericht wird von fb_tagesbericht() gesendet, wenn er ENTSTEHT.
         * Hier geht der zuletzt geschriebene mit, damit ein Abonnent, der
         * spaeter dazukommt, ihn ueberhaupt sieht. */
        $b = fb_json_lesen(fb_paths()['bilanz']);
        $m['bericht'] = isset($b['letzter_bericht']) ? (string) $b['letzter_bericht'] : '';
    }
    foreach ((isset($stand['fenster']) ? $stand['fenster'] : array()) as $e) {
        $k = $e['kuerzel'];
        if ($k === '') { continue; }
        $m[$k . '/urteil']      = (int) $e['urteil'];
        $m[$k . '/beschatten']  = (int) $e['beschatten'];
        $m[$k . '/grund']       = (int) $e['grundnr'];
        $m[$k . '/watt']        = (int) $e['watt'];
        $m[$k . '/glas']        = (int) $e['glas'];
        $m[$k . '/wh']          = (int) $e['wh'];
        $m[$k . '/begruendung'] = (string) $e['begruendung'];
        if (isset($themen['<kuerzel>/urteil30'])) {
            $m[$k . '/urteil30']     = isset($e['urteil30']) ? (int) $e['urteil30'] : 0;
            $m[$k . '/beschatten30'] = isset($e['beschatten30']) ? (int) $e['beschatten30'] : 0;
        }
        if (isset($themen['<kuerzel>/blendung'])) { $m[$k . '/blendung'] = (int) $e['blendung']; }
        if (isset($themen['<kuerzel>/daemmen']))  { $m[$k . '/daemmen']  = (int) $e['daemmen']; }
        if (isset($themen['<kuerzel>/gefahren'])) { $m[$k . '/gefahren'] = (int) $e['gefahren']; }
    }
    return $m;
}

/**
 * Ueber den UDP-Eingang des Gateways senden.
 *
 * function_exists() VOR socket_create(): ein vorangestelltes @ unterdrueckt
 * Meldungen, faengt aber keinen "Call to undefined function" - das ist ein
 * toedlicher Fehler, und der Cron-Lauf waere an dieser Zeile ohne Eintrag
 * im Protokoll gestorben. Ein LoxBerry ohne php-sockets ist kein Sonderfall.
 */
function fb_mqtt_senden($cfg, $stand)
{
    if (empty($cfg['mqtt_ein'])) { return 0; }
    $z = fb_mqtt_zustand();
    if ($z['udpport'] < 1 || $z['udpport'] > 65535) {
        fb_log_wenn_neu('mqtt', 'Kein brauchbarer UDP-Eingangsport in der general.json - '
            . 'ist das MQTT-Gateway eingerichtet?');
        return 0;
    }
    if (!function_exists('socket_create')) {
        fb_log_wenn_neu('mqtt', 'Die PHP-Erweiterung sockets fehlt - ohne sie laesst sich '
            . 'das MQTT-Gateway nicht ueber UDP ansprechen. Der HTTP-Weg ist nicht betroffen.');
        return 0;
    }
    $s = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if (!$s) {
        fb_log_wenn_neu('mqtt', 'UDP-Socket liess sich nicht anlegen.');
        return 0;
    }
    $praefix = (string) $cfg['mqtt_topic'];
    $nachrichten = fb_mqtt_nachrichten($cfg, $stand);
    $gesendet = 0;
    foreach ($nachrichten as $k => $v) {
        $msg = 'publish ' . fb_mqtt_thema($praefix . '/' . $k) . ' ' . fb_mqtt_wert_saeubern($v);
        if (@socket_sendto($s, $msg, strlen($msg), 0, '127.0.0.1', $z['udpport']) !== false) {
            $gesendet++;
        }
    }
    socket_close($s);
    fb_log_wenn_neu('mqtt_zahl', $gesendet . ' von ' . count($nachrichten)
        . ' Werten an das Gateway gesendet (Port ' . $z['udpport'] . ').');
    return $gesendet;
}

/* ==================================================================
 * Felder fuer Loxone
 * ================================================================== */

/**
 * Die Felder je Fenster: Einheit, Minimum, Maximum, Sprachschluessel.
 *
 * MINIMUM UND MAXIMUM SIND KEIN SCHMUCK. Loxone kappt einen Wert auf die
 * Grenzen des virtuellen Eingangs, und zwar STILL. Am 22.08.2026 hat diese
 * Anlage genau daran einen Schaden gehabt: ein zu enger Bereich machte aus
 * einer Restzeit eine 0, und 0 hiess dort "sofort losfahren".
 *
 * URTEIL geht bis -100 hinunter. Stuende hier 0 als Untergrenze, kaeme in
 * Loxone von jedem zu beschattenden Fenster eine 0 an - also genau der
 * Wert, der "keine Meinung" bedeutet.
 */
function fb_felder($cfg = null)
{
    /* DIE FELDLISTE HAENGT AN DER KONFIGURATION.
     *
     * Bei 25 Fenstern kostet jedes zusaetzliche Feld 25 virtuelle Eingaenge
     * in Loxone. Wer die Nachtdaemmung nicht benutzt, soll ihre Eingaenge
     * auch nicht anlegen muessen - und vor allem soll die Vorlage nicht
     * Werte versprechen, die dauerhaft auf 0 stehen.
     *
     * Alle Stellen, die Felder aufzaehlen - Werteliste, Statuszeile,
     * Vorlage, Tabelle im Reiter Loxone und die Kongruenzprobe -, gehen
     * durch DIESE Funktion. Zwei Listen waeren zwei Wahrheiten. */
    if ($cfg === null) { $cfg = fb_config(false); }
    $f = array(
        'URTEIL'     => array('',      -100, 100,  'FB_FELD.URTEIL'),
        'BESCHATTEN' => array('',       0,   1,    'FB_FELD.BESCHATTEN'),
        'GRUND'      => array('',       0,   11,   'FB_FELD.GRUND'),
        /* Die Obergrenze passt zur groesstmoeglichen Eingabe: 30 m2 Glas
         * mal 95 Prozent g-Wert mal rund 1400 W/m2 sind knapp 40 000 W.
         * Mit 9999 schnitt Loxone ab etwa zwoelf Quadratmetern lautlos ab -
         * und ein abgeschnittener Wert sieht aus wie ein gemessener. */
        'WATT'       => array('W',      0,   45000, 'FB_FELD.WATT'),
        /* WH ist die Groesse, nach der dieses Plugin heisst. Sie steht
         * immer in der Vorlage, auch wenn der Bilanzterm abgeschaltet ist -
         * ansehen soll man sie von Anfang an, benutzen erst dann, wenn man
         * sie kennt. */
        'WH'         => array('Wh',     0,   99999, 'FB_FELD.WH'),
    );
    if ((int) $cfg['vorschau'] > 0) {
        $f['URTEIL30']     = array('',  -100, 100, 'FB_FELD.URTEIL30');
        $f['BESCHATTEN30'] = array('',   0,   1,   'FB_FELD.BESCHATTEN30');
    }
    $blendet = false;
    foreach ((isset($cfg['fenster']) ? $cfg['fenster'] : array()) as $fe) {
        if ((int) $fe['blend_hoehe'] > 0) { $blendet = true; break; }
    }
    if ($blendet) {
        $f['BLENDUNG'] = array('', 0, 1, 'FB_FELD.BLENDUNG');
    }
    if (!empty($cfg['daemmen_ein'])) {
        $f['DAEMMEN'] = array('', 0, 1, 'FB_FELD.DAEMMEN');
    }
    if (!empty($cfg['stellung_ein'])) {
        /* -1 heisst "keine Rueckmeldung" und ist etwas anderes als 0
         * ("steht falsch"). Deshalb MinVal -1 - sonst machte Loxone aus dem
         * einen stillschweigend das andere. */
        $f['GEFAHREN'] = array('', -1, 1, 'FB_FELD.GEFAHREN');
    }
    return $f;
}

/**
 * Die Summenfelder.
 *
 * HERZ geht bis -1 hinunter: -1 heisst "noch nie gerechnet" und ist etwas
 * anderes als 0 ("gerade eben gerechnet"). Ohne MinVal="-1" machte Loxone
 * aus dem einen stillschweigend das andere, und ein Plugin, das nie
 * gelaufen ist, saehe im Baustein aus wie ein kerngesundes.
 */
function fb_summenfelder($cfg = null)
{
    if ($cfg === null) { $cfg = fb_config(false); }
    $f = array(
        'OK'         => array('',      0,   1,          'FB_FELD.OK'),
        /* 43200 Minuten sind 30 Tage. Mit 1440 haette Loxone einen laenger
         * stehenden Lauf auf "einen Tag" gekappt - und damit ausgerechnet
         * die Zahl beschnitten, an der ein Ausfall zu erkennen ist. */
        'HERZ'       => array('min',  -1,   43200,      'FB_FELD.HERZ'),
        'FENSTER'    => array('',      0,   99,         'FB_FELD.FENSTER'),
        'BESCHATTEN' => array('',      0,   99,         'FB_FELD.S_BESCHATTEN'),
        'SONNE_H'    => array('Grad', -90,  90,         'FB_FELD.SONNE_H'),
        'SONNE_AZ'   => array('Grad',  0,   360,        'FB_FELD.SONNE_AZ'),
        'SAISON'     => array('',     -100, 100,        'FB_FELD.SAISON'),
        'STRAHLUNG'  => array('W/m2', -1,   1400,       'FB_FELD.STRAHLUNG'),
        'TS'         => array('',      0,   4102444800, 'FB_FELD.TS'),
        'WH_TAG'     => array('Wh',    0,   999999,     'FB_FELD.WH_TAG'),
    );
    if ((int) $cfg['vorschau'] > 0) {
        $f['BESCHATTEN30'] = array('', 0, 99, 'FB_FELD.S_BESCHATTEN30');
    }
    $blendet = false;
    foreach ((isset($cfg['fenster']) ? $cfg['fenster'] : array()) as $fe) {
        if ((int) $fe['blend_hoehe'] > 0) { $blendet = true; break; }
    }
    if ($blendet) { $f['BLENDUNG'] = array('', 0, 99, 'FB_FELD.S_BLENDUNG'); }
    if (!empty($cfg['daemmen_ein'])) { $f['DAEMMEN'] = array('', 0, 99, 'FB_FELD.S_DAEMMEN'); }
    if (!empty($cfg['stellung_ein'])) {
        $f['NICHT_GEFAHREN'] = array('', 0, 99, 'FB_FELD.S_NICHT_GEFAHREN');
    }
    if (!empty($cfg['pv_gegenprobe'])) {
        $f['PV_ABW'] = array('%', -100, 100, 'FB_FELD.PV_ABW');
    }
    return $f;
}

/**
 * Die Werteliste, aus der BEIDE Ausgabewege schoepfen.
 *
 * Bis hier hatte jeder Weg seine eigene Rechnung - der HTTP-Weg eine und
 * MQTT eine andere. Sie liefen auseinander, ohne dass es auffiel, weil
 * niemand beide gleichzeitig ansieht. Jetzt gibt es eine Liste; der Reiter
 * Test haelt sie gegen die Vorlage.
 */
function fb_felderwerte($stand, $cfg = null)
{
    if ($cfg === null) { $cfg = fb_config(false); }
    $w = array(
        'OK'         => isset($stand['ok']) ? (int) $stand['ok'] : 0,
        'FENSTER'    => isset($stand['anzahl']) ? (int) $stand['anzahl'] : 0,
        'BESCHATTEN' => isset($stand['beschatten_anzahl']) ? (int) $stand['beschatten_anzahl'] : 0,
        'SONNE_H'    => isset($stand['sonne_hoehe']) ? round($stand['sonne_hoehe'], 1) : 0,
        'SONNE_AZ'   => isset($stand['sonne_azimut']) ? round($stand['sonne_azimut'], 1) : 0,
        'SAISON'     => isset($stand['saison']) ? (int) $stand['saison'] : 0,
        'STRAHLUNG'  => isset($stand['strahlung']) ? $stand['strahlung'] : -1,
        'TS'         => isset($stand['ts']) ? (int) $stand['ts'] : 0,
        'WH_TAG'     => isset($stand['wh_tag']) ? (int) $stand['wh_tag'] : 0,
    );
    $summen = fb_summenfelder($cfg);
    if (isset($summen['BESCHATTEN30'])) {
        $w['BESCHATTEN30'] = isset($stand['beschatten30_anzahl'])
            ? (int) $stand['beschatten30_anzahl'] : 0;
    }
    if (isset($summen['BLENDUNG'])) {
        $w['BLENDUNG'] = isset($stand['blendung_anzahl']) ? (int) $stand['blendung_anzahl'] : 0;
    }
    if (isset($summen['DAEMMEN'])) {
        $w['DAEMMEN'] = isset($stand['daemmen_anzahl']) ? (int) $stand['daemmen_anzahl'] : 0;
    }
    if (isset($summen['NICHT_GEFAHREN'])) {
        $w['NICHT_GEFAHREN'] = isset($stand['nicht_gefahren'])
            ? (int) $stand['nicht_gefahren'] : 0;
    }
    if (isset($summen['PV_ABW'])) {
        $pv = fb_pv_pruefen($cfg);
        $w['PV_ABW'] = (int) $pv['abweichung'];
    }
    $felder = fb_felder($cfg);
    foreach ((isset($stand['fenster']) ? $stand['fenster'] : array()) as $e) {
        $k = strtoupper($e['kuerzel']);
        if ($k === '') { continue; }
        foreach (array_keys($felder) as $feld) {
            $quelle = strtolower($feld);
            $w[$k . $feld] = isset($e[$quelle]) ? (int) $e[$quelle] : 0;
        }
        $w[$k . 'GRUND'] = (int) $e['grundnr'];      // heisst im Stand anders
    }
    return $w;
}

/**
 * Traegt die Themenliste genau die Themen, die auch gesendet werden?
 *
 * Rueckgabe: array(ok, Text). Die Themenliste fuehrt die Fensterzweige mit
 * dem Platzhalter <kuerzel>; hier wird er je eingerichtetem Fenster
 * eingesetzt und beides gegeneinander gehalten.
 */
function fb_mqtt_kongruent()
{
    $stand = fb_stand();
    if (!isset($stand['fenster'])) {
        return array(null, fb_klartext('TEST.P_KEINE_MESSUNG'));
    }
    $soll = array();
    foreach (fb_mqtt_themen() as $thema => $schluessel) {
        if (strpos($thema, '<kuerzel>') === false) { $soll[] = $thema; continue; }
        foreach (fb_fenster() as $f) {
            $soll[] = str_replace('<kuerzel>', $f['kuerzel'], $thema);
        }
    }
    $ist = array_keys(fb_mqtt_nachrichten(fb_config(false), $stand));
    sort($soll); sort($ist);
    $fehlt = array_diff($soll, $ist);
    $zuviel = array_diff($ist, $soll);
    if (!$fehlt && !$zuviel) {
        return array(true, sprintf(fb_klartext('TEST.P_MQTT_OK'), count($soll)));
    }
    return array(false, sprintf(fb_klartext('TEST.P_MQTT_ABW'),
        fb_liste_kurz($fehlt), fb_liste_kurz($zuviel)));
}

function fb_klartext($schluessel)
{
    return trim(strip_tags(html_entity_decode(fb_t($schluessel), ENT_QUOTES, 'UTF-8')));
}

/**
 * Eine Namensliste kurz halten - und die Zahl der Uebrigen NENNEN.
 *
 * Bei 25 Fenstern in 13 Raeumen zaehlt die Selbstpruefung 33 Messwerte auf.
 * Die volle Liste in einer Zeile ist unlesbar, und was man nicht liest, ist
 * so gut wie nicht gemeldet. Abgeschnitten wird deshalb sichtbar, nie still:
 * die Zahl der weggelassenen Namen steht dabei, und die vollstaendige Liste
 * findet sich im Knopf "Eingetroffene Messwerte".
 */
function fb_liste_kurz($namen, $hoechstens = 6)
{
    $namen = array_values($namen);
    if (!$namen) { return '-'; }
    if (count($namen) <= $hoechstens) { return implode(', ', $namen); }
    /* Das Leerzeichen steht HIER und nicht in der Sprachdatei: fb_klartext()
     * trimmt, und ein fuehrendes Leerzeichen im .ini-Wert ueberlebt das
     * nicht. Beim ersten Durchlauf klebte deshalb "und 27 weitere"
     * unmittelbar am letzten Namen. */
    return implode(', ', array_slice($namen, 0, $hoechstens)) . ' '
         . sprintf(fb_klartext('TEST.UND_WEITERE'), count($namen) - $hoechstens);
}

/** Ohne das Attribut Unit steht am virtuellen Eingang eine nackte Zahl. */
function fb_einheit($e)
{
    return $e === '' ? '<v.0>' : ('<v.0> ' . $e);
}

function fb_endpunkt()
{
    $p = fb_paths();
    $host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
        : (gethostname() ?: 'loxberry');
    return 'http://' . $host . '/plugins/' . $p['plugin'] . '/index.php';
}

/** Der Titel eines virtuellen Eingangs - je Fenster, nicht als Platzhalter. */
function fb_titel($f, $feld)
{
    return 'FB_' . strtoupper($f['kuerzel']) . '_' . $feld;
}

/**
 * Das Suchmuster eines Feldes.
 *
 * Mit fuehrendem Semikolon: ohne es faende das Muster OK= auch die Stelle
 * WOHNZIMMEROK= in einer spaeteren Zeile. Heute ginge das gut, weil die
 * Summenzeile zuerst kommt - aber das ist eine Wette auf die Reihenfolge,
 * und es kostet nichts, sie zu vermeiden.
 */
function fb_muster($feld)
{
    return '\i;' . $feld . '=\i\v';
}

/**
 * Der geprueefte PHP-Nachbau des LoxoneTemplateBuilder.
 *
 * Attributreihenfolge, CRLF als Zeilenende und der Tabulator vor den
 * Kindelementen entsprechen den Ausfuhren aus Loxone Config vom 12.08.2026.
 * templateType 2 ist der HTTP-Eingang.
 */
function fb_xml_virtual_in_http($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualInHttp ';
    $o .= 'HintText="" ';
    $o .= 'Title="' . fb_x($kopf['title']) . '" ';
    $o .= 'Comment="' . fb_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . fb_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'PollingTime="' . fb_x(isset($kopf['polling']) ? $kopf['polling'] : '60') . '"';
    $o .= '>' . $crlf;
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualInHttpCmd ';
        $o .= 'Title="' . fb_x($c['title']) . '" ';
        $o .= 'Comment="' . fb_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'Check="' . fb_x(isset($c['check']) ? $c['check'] : ' ') . '" ';
        $o .= 'Signed="true" ';
        $o .= 'Analog="true" ';
        $o .= 'SourceValLow="0" ';
        $o .= 'DestValLow="0" ';
        $o .= 'SourceValHigh="1" ';
        $o .= 'DestValHigh="1" ';
        $o .= 'DefVal="0" ';
        $o .= 'MinVal="' . fb_x(isset($c['min']) ? $c['min'] : '0') . '" ';
        $o .= 'MaxVal="' . fb_x(isset($c['max']) ? $c['max'] : '100') . '" ';
        $o .= 'Unit="' . fb_x(isset($c['unit']) ? $c['unit'] : '<v.0>') . '" ';
        $o .= 'HintText=""';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualInHttp>' . $crlf;
    return $o;
}

/**
 * Der virtuelle AUSGANG - damit Loxone die Messwerte hereinreicht.
 *
 * templateType 3. Die lange Attributreihenfolge stammt aus einer echten
 * Ausfuhr; ein ANALOGER Befehl traegt vier Attribute mehr als ein
 * digitaler (SourceValLow bis DestValHigh), und CmdOnMethod="GET" steht
 * auch dann da, wenn die Adresse gar kein HTTP spricht. Wer daraus zwei
 * Bauformen macht, weicht vom Original ab - das hat dieses Haus schon eine
 * Ausfuhr gekostet.
 */
function fb_xml_virtual_out($kopf, $cmds)
{
    $crlf = "\r\n";
    $o = '<?xml version="1.0" encoding="utf-8"?>' . $crlf;
    $o .= '<VirtualOut ';
    $o .= 'HintText="" ';
    $o .= 'Title="' . fb_x($kopf['title']) . '" ';
    $o .= 'Comment="' . fb_x(isset($kopf['comment']) ? $kopf['comment'] : '') . '" ';
    $o .= 'Address="' . fb_x(isset($kopf['address']) ? $kopf['address'] : '') . '" ';
    $o .= 'CmdInit="" ';
    $o .= 'CloseAfterSend="false" ';
    $o .= 'CmdSep=""';
    $o .= '>' . $crlf;
    $o .= "\t" . '<Info templateType="3" minVersion="17010727"/>' . $crlf;
    foreach ($cmds as $c) {
        $o .= "\t" . '<VirtualOutCmd ';
        $o .= 'Title="' . fb_x($c['title']) . '" ';
        $o .= 'Comment="' . fb_x(isset($c['comment']) ? $c['comment'] : '') . '" ';
        $o .= 'CmdOnMethod="GET" ';
        $o .= 'CmdOffMethod="GET" ';
        $o .= 'CmdOn="' . fb_x($c['cmd']) . '" ';
        $o .= 'CmdOnHTTP="" ';
        $o .= 'CmdOnPost="" ';
        $o .= 'CmdOff="" ';
        $o .= 'CmdOffHTTP="" ';
        $o .= 'CmdOffPost="" ';
        $o .= 'CmdAnswer="" ';
        $o .= 'Analog="true" ';
        $o .= 'Repeat="0" ';
        $o .= 'RepeatRate="0" ';
        $o .= 'SourceValLow="0" ';
        $o .= 'DestValLow="0" ';
        $o .= 'SourceValHigh="1" ';
        $o .= 'DestValHigh="1" ';
        $o .= 'HintText=""';
        $o .= '/>' . $crlf;
    }
    $o .= '</VirtualOut>' . $crlf;
    return $o;
}

/** Die Vorlage der virtuellen EINGAENGE. Rueckgabe: array(Name, Inhalt). */
function fb_vorlage()
{
    $cfg = fb_config(false);
    $cmds = array();
    foreach (fb_summenfelder($cfg) as $feld => $info) {
        $cmds[] = array(
            'title'   => 'FB_' . $feld,
            'comment' => fb_klartext($info[3]),
            'check'   => fb_muster($feld),
            'min'     => $info[1],
            'max'     => $info[2],
            'unit'    => fb_einheit($info[0]),
        );
    }
    foreach (fb_fenster() as $f) {
        foreach (fb_felder($cfg) as $feld => $info) {
            $cmds[] = array(
                'title'   => fb_titel($f, $feld),
                'comment' => ($f['name'] !== '' ? $f['name'] : $f['kuerzel'])
                           . ': ' . fb_klartext($info[3]),
                'check'   => fb_muster(strtoupper($f['kuerzel']) . $feld),
                'min'     => $info[1],
                'max'     => $info[2],
                'unit'    => fb_einheit($info[0]),
            );
        }
    }
    $adresse = fb_endpunkt() . '?token=' . fb_token() . '&aktion=status';
    return array('VI_FENSTERBILANZ.xml', fb_xml_virtual_in_http(array(
        'title'   => 'Fensterbilanz',
        'address' => $adresse,
        'polling' => '60',
        'comment' => sprintf(fb_klartext('FB_XML.KOPF'), date('d.m.Y')),
    ), $cmds));
}

/**
 * Die Vorlage der virtuellen AUSGAENGE - der Weg, auf dem die Messwerte
 * hereinkommen. Ohne sie muesste der Anwender je Wert einen Ausgang von
 * Hand anlegen; bei 25 Fenstern in 15 Raeumen sind das ueber dreissig.
 */
function fb_vorlage_out()
{
    $basis = '/plugins/' . fb_paths()['plugin'] . '/index.php?token=' . fb_token()
           . '&aktion=melden&wert=';
    $cmds = array();
    foreach (fb_messgroessen() as $name => $info) {
        $cmds[] = array(
            'title'   => 'FB_SET_' . strtoupper($name),
            'comment' => fb_klartext($info[2]) . ' (' . $info[1] . ')',
            'cmd'     => $basis . $name . '&v=<v.0>',
        );
    }
    /* Die Rueckmeldung der Stellung - je Fenster ein Ausgang, und nur wenn
     * die Auswertung eingeschaltet ist. Ohne sie waeren es 25 virtuelle
     * Ausgaenge, die niemand braucht. */
    $cfg_out = fb_config(false);
    if (!empty($cfg_out['stellung_ein'])) {
        foreach (fb_fenster() as $f) {
            $cmds[] = array(
                'title'   => 'FB_SET_STELLUNG_' . strtoupper($f['kuerzel']),
                'comment' => fb_klartext('FB_MESS.STELLUNG') . ': '
                           . ($f['name'] !== '' ? $f['name'] : $f['kuerzel']),
                'cmd'     => $basis . 'stellung.' . strtolower($f['kuerzel']) . '&v=<v.0>',
            );
        }
    }
    $raeume = array();
    foreach (fb_fenster() as $f) {
        if ($f['raum'] === '' || empty($f['raumwerte'])) { continue; }
        $raeume[$f['raum']] = isset($raeume[$f['raum']]) ? $raeume[$f['raum']] : $f['name'];
    }
    ksort($raeume);
    foreach ($raeume as $raum => $bezeichnung) {
        $cmds[] = array(
            'title'   => 'FB_SET_IST_' . strtoupper($raum),
            'comment' => fb_klartext('FB_MESS.RAUM_IST') . ': ' . $raum,
            'cmd'     => $basis . 'ist.' . $raum . '&v=<v.0>',
        );
        $cmds[] = array(
            'title'   => 'FB_SET_GRENZE_' . strtoupper($raum),
            'comment' => fb_klartext('FB_MESS.RAUM_GRENZE') . ': ' . $raum,
            'cmd'     => $basis . 'grenze.' . $raum . '&v=<v.0>',
        );
    }
    $p = fb_paths();
    $host = isset($_SERVER['HTTP_HOST']) && $_SERVER['HTTP_HOST'] !== ''
        ? preg_replace('/[^A-Za-z0-9\.\-:]/', '', (string) $_SERVER['HTTP_HOST'])
        : (gethostname() ?: 'loxberry');
    return array('VQ_FENSTERBILANZ.xml', fb_xml_virtual_out(array(
        'title'   => 'Fensterbilanz Messwerte',
        'address' => 'http://' . $host,
        'comment' => sprintf(fb_klartext('FB_XML.KOPF_OUT'), date('d.m.Y')),
    ), $cmds));
}

/**
 * Die Statuszeile fuer den Miniserver.
 *
 * Sie rechnet NICHTS nach, sondern gibt die Werteliste aus, die der Lauf in
 * stand.json abgelegt hat. Nur HERZ entsteht hier, weil es im Augenblick
 * der Frage gerechnet werden muss - in der Datei stuende sonst dauernd 0.
 *
 * Fehlt die Werteliste, ist noch nie gerechnet worden. Dann geht eine
 * ehrliche Zeile mit OK=0 und HERZ=-1 hinaus und keine zusammengeratene.
 */
function fb_zeile($stand, $hoechstalter = null)
{
    $f = isset($stand['felder']) && is_array($stand['felder']) ? $stand['felder'] : null;
    $alter = isset($stand['ts']) && (int) $stand['ts'] > 0
        ? max(0, time() - (int) $stand['ts']) : -1;
    $herz = $alter < 0 ? -1 : (int) floor($alter / 60);
    if ($f === null) {
        /* Die leere Zeile wird aus DERSELBEN Feldliste gebaut wie die
         * gefuellte. Bis 0.9.0 stand sie hier ausgeschrieben - und waere bei
         * jedem neuen Summenfeld stillschweigend zu kurz geworden. */
        $o = 'FENSTERBILANZ';
        foreach (array_keys(fb_summenfelder(fb_config(false))) as $feld) {
            if ($feld === 'HERZ')           { $o .= ';HERZ=-1'; }
            elseif ($feld === 'STRAHLUNG')  { $o .= ';STRAHLUNG=-1'; }
            else                            { $o .= ';' . $feld . '=0'; }
        }
        return $o . "\n";
    }

    /* EIN ALTER STAND IST KEIN GUELTIGER STAND.
     *
     * Bis zum ersten Prueflauf sah diese Funktion nur nach, OB eine
     * Werteliste da ist - nicht, wie alt sie ist. Steht der Cron still,
     * ging deshalb tagelang dieselbe Zeile mit OK=1 hinaus, samt
     * BESCHATTEN=1. In Loxone sieht das aus wie ein gesundes Plugin, und
     * die Rolllaeden stuenden fuer immer so, wie sie zuletzt standen -
     * gemessen an einem drei Tage alten Stand.
     *
     * Ueberschritten wird dieselbe Grenze, die auch fuer die Messwerte
     * gilt: der Anwender hat dort einmal gesagt, wie lange er einer Zahl
     * traut. Zwei Grenzen fuer dieselbe Frage waeren zwei Wahrheiten.
     * HERZ geht weiterhin mit dem ECHTEN Alter hinaus - sonst waere die
     * Ausfallerkennung in Loxone blind. */
    $cfg = fb_config(false);
    if ($hoechstalter === null) {
        $hoechstalter = (int) $cfg['hoechstalter'];
    }
    if ($alter > (int) $hoechstalter) {
        $o = 'FENSTERBILANZ';
        foreach (fb_summenfelder($cfg) as $feld => $info) {
            if ($feld === 'HERZ')          { $o .= ';HERZ=' . $herz; }
            elseif ($feld === 'TS')        { $o .= ';TS=' . (int) $stand['ts']; }
            elseif ($feld === 'FENSTER')   { $o .= ';FENSTER=' . (isset($f['FENSTER']) ? (int) $f['FENSTER'] : 0); }
            elseif ($feld === 'STRAHLUNG') { $o .= ';STRAHLUNG=-1'; }
            else                           { $o .= ';' . $feld . '=0'; }
        }
        $o .= "\n";
        foreach ((isset($stand['fenster']) ? $stand['fenster'] : array()) as $e) {
            $k = strtoupper($e['kuerzel']);
            if ($k === '') { continue; }
            $o .= 'F' . $k;
            foreach (array_keys(fb_felder($cfg)) as $feld) {
                $o .= ';' . $k . $feld . '=0';
            }
            $o .= "\n";
        }
        return $o;
    }
    $w = function ($k) use ($f) {
        return isset($f[$k]) && is_numeric($f[$k]) ? (string) (0 + $f[$k]) : '-';
    };
    $o = 'FENSTERBILANZ';
    foreach (array_keys(fb_summenfelder($cfg)) as $feld) {
        $o .= ';' . $feld . '=' . ($feld === 'HERZ' ? (string) $herz : $w($feld));
    }
    $o .= "\n";
    foreach ((isset($stand['fenster']) ? $stand['fenster'] : array()) as $e) {
        $k = strtoupper($e['kuerzel']);
        if ($k === '') { continue; }
        $o .= 'F' . $k;
        foreach (array_keys(fb_felder($cfg)) as $feld) {
            $o .= ';' . $k . $feld . '=' . $w($k . $feld);
        }
        $o .= "\n";
    }
    return $o;
}

/**
 * Traegt die Vorlage genau die Felder, die der Lauf auch liefert?
 *
 * Rueckgabe: array(ok, Text). Der Reiter Test haelt damit die beiden
 * Listen gegeneinander, die sonst unabhaengig voneinander gepflegt wuerden.
 */
function fb_felder_kongruent()
{
    $stand = fb_stand();
    if (!isset($stand['felder']) || !is_array($stand['felder'])) {
        return array(null, fb_klartext('TEST.P_KEINE_MESSUNG'));
    }
    $cfg = fb_config(false);
    $soll = array();
    foreach (array_keys(fb_summenfelder($cfg)) as $feld) {
        if ($feld !== 'HERZ') { $soll[] = $feld; }   // HERZ rechnet der Endpunkt
    }
    foreach (fb_fenster() as $f) {
        foreach (array_keys(fb_felder($cfg)) as $feld) {
            $soll[] = strtoupper($f['kuerzel']) . $feld;
        }
    }
    $ist = array_keys($stand['felder']);
    sort($soll);
    sort($ist);
    $fehlt = array_diff($soll, $ist);
    $zuviel = array_diff($ist, $soll);
    if (!$fehlt && !$zuviel) {
        return array(true, sprintf(fb_klartext('TEST.P_FELDER_OK'), count($soll)));
    }
    return array(false, sprintf(fb_klartext('TEST.P_FELDER_ABW'),
        fb_liste_kurz($fehlt), fb_liste_kurz($zuviel)));
}

/* ==================================================================
 * Glaettung, Tagesbilanz, Lernkurve, Gegenprobe, Bericht
 *
 * Alles in diesem Abschnitt ist mit 0.10.0 dazugekommen. Die drei
 * Datendateien bilanz.json, lernen.json und pv.json ueberleben ein Update
 * mit Absicht: in ihnen steckt, was ueber Tage und Wochen zusammengetragen
 * wurde und sich nicht nachrechnen laesst.
 * ================================================================== */

/**
 * Die Strahlung ueber ein Zeitfenster mitteln.
 *
 * Rein: es wird eine KOPIE der Messwerte zurueckgegeben, nichts geschrieben.
 * Gemittelt wird arithmetisch ueber alle Punkte der Reihe, die innerhalb des
 * Fensters liegen - der juengste ist immer dabei, auch wenn das Fenster
 * kuerzer ist als der Meldeabstand. Sonst faellt bei einer Anlage, die nur
 * alle zehn Minuten sendet, die Glaettung stillschweigend auf null zurueck.
 *
 * Der Zeitstempel bleibt der des juengsten Punktes: eine geglaettete Zahl
 * ist nicht aelter als ihre neueste Zutat.
 */
function fb_messwerte_glaetten($cfg, $messwerte, $jetzt)
{
    $fenster = (int) $cfg['glaettung'];
    if ($fenster <= 0) { return $messwerte; }
    if (!isset($messwerte['strahlung']['reihe'])
        || !is_array($messwerte['strahlung']['reihe'])) {
        return $messwerte;
    }
    $summe = 0.0; $anzahl = 0;
    foreach ($messwerte['strahlung']['reihe'] as $punkt) {
        if (!is_array($punkt) || count($punkt) < 2) { continue; }
        if (($jetzt - (int) $punkt[0]) > $fenster) { continue; }
        if ((int) $punkt[0] > $jetzt) { continue; }     // Stempel aus der Zukunft
        $summe += (float) $punkt[1];
        $anzahl++;
    }
    if ($anzahl === 0) { return $messwerte; }
    $messwerte['strahlung']['v'] = $summe / $anzahl;
    $messwerte['strahlung']['geglaettet'] = $anzahl;
    return $messwerte;
}

/**
 * Alle Messwerte um eine Spanne in die Zukunft schieben.
 *
 * Gebraucht fuer die Vorausschau: dort wandert die GEOMETRIE, die Messwerte
 * bleiben, was sie sind. Ohne diese Verschiebung waeren sie beim Blick eine
 * halbe Stunde voraus genau eine halbe Stunde aelter - und damit ueber dem
 * Hoechstalter. Die Vorausschau haette dann durchweg "keine Daten" gemeldet,
 * ohne dass irgendwo ein Fehler auftaucht: ein Urteil 0 sieht aus wie ein
 * Fenster, an dem nichts los ist.
 *
 * Es ist eine Kopie; die abgelegten Messwerte bleiben unangetastet.
 */
function fb_messwerte_verschieben($messwerte, $delta)
{
    $delta = (int) $delta;
    if ($delta === 0) { return $messwerte; }
    foreach ($messwerte as $name => $e) {
        if (!is_array($e)) { continue; }
        if (isset($e['t'])) { $messwerte[$name]['t'] = (int) $e['t'] + $delta; }
        if (isset($e['reihe']) && is_array($e['reihe'])) {
            foreach ($e['reihe'] as $i => $punkt) {
                if (is_array($punkt) && count($punkt) >= 2) {
                    $messwerte[$name]['reihe'][$i][0] = (int) $punkt[0] + $delta;
                }
            }
        }
    }
    return $messwerte;
}

/** Der leere Tagesstand - eine Quelle, damit kein Schluessel fehlt. */
function fb_bilanz_leer($datum)
{
    return array(
        'datum'     => $datum,
        'letzte'    => 0,
        'fenster'   => array(),   // Wh durchs Glas, je Fensternummer
        'raeume'    => array(),   // Wh in den Raum, je Raumschluessel
        'minmax'    => array(),   // je Raum die tiefste und hoechste Temperatur
        'zu_s'      => array(),   // Sekunden Beschattungsforderung, je Fenster
        'spitze'    => array(),   // hoechste Wattzahl des Tages, je Fenster
        'strahlung' => 0.0,       // Wh/m2 waagerecht
        'pv'        => 0.0,       // Wh/m2 aus der Ertragsprognose
        'bericht'   => '',        // Datum des zuletzt geschriebenen Berichts
    );
}

/**
 * Den Tageswechsel abwickeln: archivieren, was der Tag ergeben hat, und
 * zuruecksetzen.
 *
 * Gerechnet wird mit der ORTSZEIT - ein Tag endet dort, wo der Bewohner
 * schlafen geht, nicht um 01:00 oder 02:00 nach Weltzeit.
 */
function fb_tageswechsel($cfg, $bilanz, $jetzt)
{
    $heute = date('Y-m-d', (int) $jetzt);
    if (!is_array($bilanz) || !isset($bilanz['datum'])) {
        return fb_bilanz_leer($heute);
    }
    $bilanz = array_merge(fb_bilanz_leer($heute), $bilanz);
    if ($bilanz['datum'] === $heute) { return $bilanz; }

    /* Der Tag ist vorbei. Was er ergeben hat, wandert in die beiden
     * Langzeitdateien - und zwar NUR hier, einmal am Tag. */
    if (!empty($cfg['lernen_ein'])) { fb_lernen_ablegen($bilanz); }
    if (!empty($cfg['pv_gegenprobe'])) { fb_pv_ablegen($bilanz); }
    fb_log(sprintf('Tageswechsel: %s abgeschlossen mit %d Wh durch alle Fenster.',
                   $bilanz['datum'], (int) round(array_sum($bilanz['fenster']))));
    return fb_bilanz_leer($heute);
}

/**
 * Die Tagesbilanz um das Stueck Zeit seit dem letzten Lauf fortschreiben.
 *
 * Der Abstand wird GEDECKELT. Nach einem Neustart oder einem Ausfall liegt
 * der letzte Lauf womoeglich Stunden zurueck; wer dann die aktuelle
 * Wattzahl mit dieser Spanne multipliziert, traegt eine Kilowattstunde ein,
 * die es nie gegeben hat. Ein Deckel von einer Viertelstunde ist grosszuegig
 * gegenueber dem Fuenfminutentakt und schneidet den Unfug sauber ab.
 */
function fb_bilanz_fortschreiben($cfg, $stand, $bilanz, $messwerte, $jetzt)
{
    $letzte = (int) $bilanz['letzte'];
    $dt = ($letzte > 0 && $jetzt > $letzte) ? min($jetzt - $letzte, 900) : 0;
    $bilanz['letzte'] = (int) $jetzt;
    if ($dt <= 0) { return $bilanz; }

    $stunden = $dt / 3600.0;
    $ha = (int) $cfg['hoechstalter'];

    foreach ((isset($stand['fenster']) ? $stand['fenster'] : array()) as $nr => $e) {
        $wh = (float) $e['watt'] * $stunden;
        $bilanz['fenster'][$nr] = (isset($bilanz['fenster'][$nr])
            ? (float) $bilanz['fenster'][$nr] : 0.0) + $wh;
        if (!empty($e['beschatten'])) {
            $bilanz['zu_s'][$nr] = (isset($bilanz['zu_s'][$nr])
                ? (int) $bilanz['zu_s'][$nr] : 0) + $dt;
        }
        if (!isset($bilanz['spitze'][$nr]) || (int) $e['watt'] > (int) $bilanz['spitze'][$nr]['w']) {
            $bilanz['spitze'][$nr] = array('w' => (int) $e['watt'], 't' => (int) $jetzt);
        }
    }

    /* Je Raum die Summe seiner Fenster - und die Spanne der Temperatur, die
     * spaeter die Lernkurve traegt. */
    $nr = 0;
    foreach ($cfg['fenster'] as $f) {
        $nr++;
        if ($f['kuerzel'] === '' || $f['raum'] === '') { continue; }
        if (!isset($stand['fenster'][(string) $nr])) { continue; }
        $wh = (float) $stand['fenster'][(string) $nr]['watt'] * $stunden;
        $bilanz['raeume'][$f['raum']] = (isset($bilanz['raeume'][$f['raum']])
            ? (float) $bilanz['raeume'][$f['raum']] : 0.0) + $wh;
    }
    foreach ($cfg['fenster'] as $f) {
        if ($f['raum'] === '' || empty($f['raumwerte'])) { continue; }
        list($ist, ) = fb_messwert($messwerte, 'ist.' . $f['raum'], $ha, $jetzt);
        if ($ist === null) { continue; }
        if (!isset($bilanz['minmax'][$f['raum']])) {
            $bilanz['minmax'][$f['raum']] = array('min' => $ist, 'max' => $ist);
        } else {
            $bilanz['minmax'][$f['raum']]['min'] = min($bilanz['minmax'][$f['raum']]['min'], $ist);
            $bilanz['minmax'][$f['raum']]['max'] = max($bilanz['minmax'][$f['raum']]['max'], $ist);
        }
    }

    list($str, ) = fb_messwert($messwerte, 'strahlung', $ha, $jetzt);
    if ($str !== null) { $bilanz['strahlung'] += $str * $stunden; }
    list($pv, ) = fb_messwert($messwerte, 'pv_prognose', $ha, $jetzt);
    if ($pv !== null) { $bilanz['pv'] += $pv * $stunden; }

    return $bilanz;
}

/* ==================================================================
 * Die Aufheizkonstante je Raum
 * ================================================================== */

/**
 * Einen abgeschlossenen Tag in lernen.json ablegen.
 *
 * Aufgeschrieben wird das Wertepaar (Waermeeintrag in Wh, Temperaturspanne
 * des Tages in Kelvin). Tage ohne nennenswerten Eintrag fallen heraus - sie
 * traegen zur Steigung nichts bei und verwaesserten nur die Streuung.
 *
 * WAS HIER NICHT GEFILTERT WIRD, und das gehoert gesagt: geluefteten Tagen,
 * Heiztagen und Tagen mit offener Tuer sieht diese Rechnung nichts an. Sie
 * erhoehen die Streuung, nicht die Steigung. Deshalb steht neben jeder
 * Konstanten die Zahl der Tage UND die Streuung - eine Konstante aus fuenf
 * Tagen ist keine, und das muss man sehen koennen.
 */
function fb_lernen_ablegen($bilanz)
{
    $p = fb_paths();
    $daten = fb_json_lesen($p['lernen']);
    foreach ((isset($bilanz['raeume']) ? $bilanz['raeume'] : array()) as $raum => $wh) {
        if ((float) $wh < 200.0) { continue; }
        if (!isset($bilanz['minmax'][$raum])) { continue; }
        $spanne = (float) $bilanz['minmax'][$raum]['max']
                - (float) $bilanz['minmax'][$raum]['min'];
        if ($spanne < 0.0) { continue; }
        if (!isset($daten[$raum]) || !is_array($daten[$raum])) { $daten[$raum] = array(); }
        $daten[$raum][] = array('d' => $bilanz['datum'],
                                'wh' => round((float) $wh, 1),
                                'dt' => round($spanne, 2));
        /* Ein Jahr reicht: was drei Winter alt ist, sagt ueber dieses Haus
         * nichts mehr, das nicht auch in den letzten 365 Tagen steht. */
        if (count($daten[$raum]) > 365) {
            $daten[$raum] = array_slice($daten[$raum], -365);
        }
    }
    fb_json_schreiben($p['lernen'], $daten, 0644);
}

/**
 * Die Aufheizkonstante eines Raumes aus den gesammelten Tagen.
 *
 * Ausgeglichen wird durch den URSPRUNG: ohne Waerme keine Erwaermung. Das
 * ist nicht nur physikalisch richtig, es spart auch den zweiten Parameter -
 * und mit zwei Parametern braucht man deutlich mehr Tage, bevor die Zahlen
 * etwas bedeuten.
 *
 * Rueckgabe: array(tage, kelvin_je_kwh, streuung, bestimmtheit).
 * tage = 0 heisst: nichts gesammelt.
 */
function fb_lernkurve($raum, $daten = null)
{
    /* $daten laesst sich uebergeben - dann fasst diese Funktion keine Datei
     * an. Nur so laesst sich im Selbsttest eine Zeile darueber schreiben,
     * und eine Rechnung ohne Pruefzeile ist eine Behauptung. */
    if ($daten === null) { $daten = fb_json_lesen(fb_paths()['lernen']); }
    $reihe = isset($daten[$raum]) && is_array($daten[$raum]) ? $daten[$raum] : array();
    $n = count($reihe);
    if ($n === 0) { return array(0, 0.0, 0.0, 0.0); }
    $sxy = 0.0; $sxx = 0.0; $syy = 0.0; $sy = 0.0;
    foreach ($reihe as $t) {
        $x = (float) $t['wh'] / 1000.0;      // kWh
        $y = (float) $t['dt'];               // Kelvin
        $sxy += $x * $y; $sxx += $x * $x; $syy += $y * $y; $sy += $y;
    }
    if ($sxx <= 0.0) { return array($n, 0.0, 0.0, 0.0); }
    $k = $sxy / $sxx;
    $rest = 0.0;
    foreach ($reihe as $t) {
        $x = (float) $t['wh'] / 1000.0;
        $rest += pow((float) $t['dt'] - $k * $x, 2);
    }
    $streuung = $n > 0 ? sqrt($rest / $n) : 0.0;
    $bestimmtheit = $syy > 0.0 ? max(0.0, 1.0 - $rest / $syy) : 0.0;
    return array($n, $k, $streuung, $bestimmtheit);
}

/* ==================================================================
 * Die Gegenprobe gegen die PV-Ertragsprognose
 * ================================================================== */

function fb_pv_ablegen($bilanz)
{
    if ((float) $bilanz['strahlung'] <= 0.0 || (float) $bilanz['pv'] <= 0.0) { return; }
    $p = fb_paths();
    $daten = fb_json_lesen($p['pv']);
    if (!isset($daten['tage']) || !is_array($daten['tage'])) { $daten['tage'] = array(); }
    $daten['tage'][] = array('d' => $bilanz['datum'],
                             's' => round((float) $bilanz['strahlung'], 1),
                             'p' => round((float) $bilanz['pv'], 1));
    if (count($daten['tage']) > 60) { $daten['tage'] = array_slice($daten['tage'], -60); }
    fb_json_schreiben($p['pv'], $daten, 0644);
}

/**
 * Laeuft die gemeldete Strahlung von der Ertragsprognose weg?
 *
 * DAS IST KEINE ZWEITE WETTERQUELLE. Der gerechnete Wert bleibt der
 * gemessene; hier wird nur verglichen. Weicht das Verhaeltnis der letzten
 * fuenf Tage deutlich von dem der Wochen davor ab, ist wahrscheinlich der
 * Strahlungsgeber verschmutzt, verschattet oder verstellt - und das merkt
 * man sonst erst, wenn im Sommer die Beschattung ausbleibt.
 *
 * Verglichen wird ueber den MEDIAN und nicht ueber den Mittelwert: ein
 * einzelner Tag mit Schnee auf dem Geber soll das Ergebnis nicht kippen.
 *
 * Rueckgabe: array(tage, abweichung, warnt, text).
 */
function fb_pv_pruefen($cfg, $daten = null)
{
    $leer = array('tage' => 0, 'abweichung' => 0, 'warnt' => 0, 'text' => '');
    if (empty($cfg['pv_gegenprobe'])) { return $leer; }
    /* Auch hier: uebergebbar, damit der Selbsttest sie fahren kann. */
    if ($daten === null) { $daten = fb_json_lesen(fb_paths()['pv']); }
    $tage = isset($daten['tage']) && is_array($daten['tage']) ? $daten['tage'] : array();
    $q = array();
    foreach ($tage as $t) {
        if ((float) $t['p'] <= 0.0) { continue; }
        $q[] = (float) $t['s'] / (float) $t['p'];
    }
    $n = count($q);
    $leer['tage'] = $n;
    if ($n < 10) { return $leer; }
    $jung = array_slice($q, -5);
    $alt  = array_slice($q, 0, $n - 5);
    $mj = fb_median($jung);
    $ma = fb_median($alt);
    if ($ma <= 0.0) { return $leer; }
    $abw = (int) round(($mj / $ma - 1.0) * 100.0);
    return array('tage' => $n, 'abweichung' => $abw,
                 'warnt' => (abs($abw) > (int) $cfg['pv_abweichung']) ? 1 : 0,
                 'text' => '');
}

/** Der Median einer Zahlenreihe. Bei gerader Anzahl das Mittel der beiden. */
function fb_median($reihe)
{
    $r = array_values($reihe);
    sort($r);
    $n = count($r);
    if ($n === 0) { return 0.0; }
    $m = (int) floor($n / 2);
    return ($n % 2) ? (float) $r[$m] : ((float) $r[$m - 1] + (float) $r[$m]) / 2.0;
}

/** Die Warnung ins Protokoll - hoechstens einmal, solange sie gleich bleibt. */
function fb_pv_warnen($cfg)
{
    if (empty($cfg['pv_gegenprobe'])) { return; }
    $pv = fb_pv_pruefen($cfg);
    if ($pv['tage'] < 10) { return; }
    if (!$pv['warnt']) {
        fb_log_wenn_neu('pv', sprintf('Gegenprobe gegen die Ertragsprognose: %d Prozent '
            . 'Abweichung ueber %d Tage - unauffaellig.', $pv['abweichung'], $pv['tage']));
        return;
    }
    fb_log_wenn_neu('pv', sprintf('Die gemeldete Strahlung weicht seit fuenf Tagen um %d '
        . 'Prozent von der Ertragsprognose ab (%d Tage im Vergleich). Das ist keine '
        . 'Aussage ueber das Wetter, sondern ueber den Geber: verschmutzt, verschattet '
        . 'oder verstellt. Gerechnet wird unveraendert mit dem gemessenen Wert.',
        $pv['abweichung'], $pv['tage']));
}

/* ==================================================================
 * Der Tagesbericht
 * ================================================================== */

/**
 * Einmal am Abend eine Zeile, die den Tag zusammenfasst.
 *
 * Sie kostet nichts und aendert nichts - und sie ist das Einzige, woran
 * sich ueber Wochen ablesen laesst, ob Tagesgrenze und Gewichte passen,
 * ohne dass jemand eine Statistik aufschlaegt.
 *
 * Geschrieben wird HOECHSTENS EINMAL je Tag; der Merker steht in der
 * Tagesbilanz und wird beim Tageswechsel mit zurueckgesetzt.
 */
function fb_tagesbericht($cfg, &$bilanz, $stand, $jetzt)
{
    if (empty($cfg['bericht_ein'])) { return false; }
    $heute = date('Y-m-d', (int) $jetzt);
    if ((string) $bilanz['bericht'] === $heute) { return false; }
    if ((int) date('G', (int) $jetzt) < (int) $cfg['bericht_stunde']) { return false; }

    $text = fb_bericht_text($cfg, $bilanz, $stand);
    fb_log($text);
    $bilanz['bericht'] = $heute;
    $bilanz['letzter_bericht'] = $text;
    fb_json_schreiben(fb_paths()['bilanz'], $bilanz, 0644);
    fb_mqtt_text($cfg, 'bericht', $text);
    return true;
}

/** Den Berichtstext bauen - getrennt, damit der Reiter Test ihn zeigen kann. */
function fb_bericht_text($cfg, $bilanz, $stand)
{
    $wh = array_sum(isset($bilanz['fenster']) ? $bilanz['fenster'] : array());
    $laengste = array('nr' => '', 's' => 0);
    foreach ((isset($bilanz['zu_s']) ? $bilanz['zu_s'] : array()) as $nr => $sek) {
        if ((int) $sek > $laengste['s']) { $laengste = array('nr' => $nr, 's' => (int) $sek); }
    }
    $spitze = array('nr' => '', 'w' => 0, 't' => 0);
    foreach ((isset($bilanz['spitze']) ? $bilanz['spitze'] : array()) as $nr => $sp) {
        if ((int) $sp['w'] > $spitze['w']) {
            $spitze = array('nr' => $nr, 'w' => (int) $sp['w'], 't' => (int) $sp['t']);
        }
    }
    $name = function ($nr) use ($stand) {
        return isset($stand['fenster'][(string) $nr])
            ? $stand['fenster'][(string) $nr]['kuerzel'] : ('#' . $nr);
    };
    $t = sprintf('Tagesbericht %s: %.1f kWh Sonneneintrag ueber %d Fenster.',
                 (string) $bilanz['datum'], $wh / 1000.0, (int) $stand['anzahl']);
    $zu = 0;
    foreach ((isset($bilanz['zu_s']) ? $bilanz['zu_s'] : array()) as $sek) {
        if ((int) $sek > 0) { $zu++; }
    }
    $t .= sprintf(' %d Fenster waren mindestens einmal beschattet.', $zu);
    if ($laengste['s'] > 0) {
        $t .= sprintf(' Am laengsten %s mit %d Stunden %d Minuten.',
                      $name($laengste['nr']), (int) floor($laengste['s'] / 3600),
                      (int) floor(($laengste['s'] % 3600) / 60));
    }
    if ($spitze['w'] > 0) {
        $t .= sprintf(' Hoechster Eintrag: %s mit %d W um %s.',
                      $name($spitze['nr']), $spitze['w'], date('H:i', $spitze['t']));
    }
    if ((float) $bilanz['strahlung'] > 0.0) {
        $t .= sprintf(' Einstrahlung des Tages: %.1f kWh je Quadratmeter.',
                      (float) $bilanz['strahlung'] / 1000.0);
    }
    return $t;
}

/* ==================================================================
 * Die Fensterliste aus einer Loxone-Projektdatei
 * ================================================================== */

/**
 * Alle AutoJalousie-Bausteine einer Projektdatei heraussuchen.
 *
 * Gemessen am 23.08.2026 an der Projektdatei dieser Anlage: die Datei ist
 * reines UTF-8-XML mit BOM und CRLF, und die Ausrichtung steht als
 *
 *     <C Type="AutoJalousie" Title="Rollladen EG Kueche Fenster" ...>
 *         <Co K="Dir" Def="106" .../>
 *         <Co K="DirTol" Def="90" .../>
 *
 * WARUM NICHT MIT simplexml: die Datei ist mehrere Megabyte gross und
 * enthaelt zehntausende Objekte; ein DOM davon kostet ein Vielfaches an
 * Arbeitsspeicher, und der LoxBerry ist ein kleiner Rechner. Gesucht wird
 * deshalb ueber die Bausteingrenzen, aber MIT Tiefenzaehlung - ein
 * AutoJalousie enthaelt eigene Kindelemente, und ein naives Fenster von
 * n Zeichen faende die Parameter des naechsten Bausteins mit.
 *
 * Rueckgabe: array(liste, fehler). Ein Baustein ohne ablesbares Dir kommt
 * MIT dem Vermerk in die Fehlerliste und nicht mit einer geratenen Zahl in
 * die Liste.
 */
function fb_projekt_lesen($inhalt)
{
    $roh = array();
    $fehler = array();
    $inhalt = (string) $inhalt;
    if (strpos($inhalt, '<C Type="AutoJalousie"') === false) {
        return array(array(), array('KEIN_AUTOJALOUSIE'));
    }
    $laenge = strlen($inhalt);
    $pos = 0;
    while (($start = strpos($inhalt, '<C Type="AutoJalousie"', $pos)) !== false) {
        $pos = $start + 20;
        /* Das Ende des Bausteins ueber Tiefenzaehlung finden. */
        $i = $start;
        $tiefe = 0;
        $ende = -1;
        while ($i < $laenge) {
            $auf = strpos($inhalt, '<C ', $i);
            $zu  = strpos($inhalt, '</C>', $i);
            if ($zu === false) { break; }
            if ($auf !== false && $auf < $zu) {
                $tagende = strpos($inhalt, '>', $auf);
                if ($tagende === false) { break; }
                /* Ein selbstschliessendes <C .../> zaehlt nicht mit. */
                if ($inhalt[$tagende - 1] !== '/') { $tiefe++; }
                $i = $tagende + 1;
                continue;
            }
            $tiefe--;
            $i = $zu + 4;
            if ($tiefe <= 0) { $ende = $zu; break; }
        }
        if ($ende < 0) { $fehler[] = 'BAUSTEIN_UNVOLLSTAENDIG'; continue; }
        $blk = substr($inhalt, $start, $ende - $start);

        $titel = '';
        if (preg_match('/\sTitle="([^"]*)"/', substr($blk, 0, 600), $m)) {
            $titel = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        }
        if ($titel === '' && preg_match('/\sDesc="([^"]*)"/', substr($blk, 0, 600), $m)) {
            $titel = html_entity_decode($m[1], ENT_QUOTES, 'UTF-8');
        }
        $dir = null; $dirtol = null;
        if (preg_match('/<Co\s[^>]*K="Dir"[^>]*\sDef="(-?\d+(?:\.\d+)?)"/', $blk, $m)) {
            $dir = (int) round((float) $m[1]);
        }
        if (preg_match('/<Co\s[^>]*K="DirTol"[^>]*\sDef="(-?\d+(?:\.\d+)?)"/', $blk, $m)) {
            $dirtol = (int) round((float) $m[1]);
        }
        /* Ein Baustein, dessen Dir an einem Eingang haengt statt an einer
         * Konstanten, hat hier keine ablesbare Zahl. Er wird mit null
         * weitergereicht und in fb_projekt_liste_bauen() GEMELDET - eine
         * erfundene 180 saehe aus wie eine abgelesene. */
        $roh[] = array($titel, $dir, $dirtol);
    }
    return fb_projekt_liste_bauen($roh, $fehler);
}

/**
 * Was steht in diesem Feld ab Werk?
 *
 * "Was muss ich hier eintragen?" ist die haeufigste Frage an eine
 * Einstellungsseite. Die ehrlichste Antwort steht schon im Programm - der
 * Vorgabewert -, und sie gehoert neben das Feld statt in eine Anleitung,
 * die niemand daneben aufschlaegt.
 *
 * Gelesen wird aus fb_vorgaben(), nicht abgeschrieben: eine abgeschriebene
 * Zahl waere beim naechsten Aendern der Vorgabe still falsch, und "still
 * falsch" ist die schlechteste Sorte.
 */
function fb_abwerk($schluessel)
{
    $v = fb_vorgaben();
    if (!isset($v[$schluessel])) { return ''; }
    $w = $v[$schluessel];
    if (is_array($w) || is_bool($w)) { return ''; }
    /* Kommazahlen ohne Nachkommastellen sehen als "20" besser aus als als
     * "20.0" - gemeint ist dasselbe. */
    if (is_float($w) && $w == (int) $w) { $w = (int) $w; }
    return sprintf(fb_t('EINST.AB_WERK'), (string) $w);
}

/**
 * Der Vorlagenordner des Plugins - dort, wo auch die Sprachdateien liegen.
 *
 * Im Archiv und im installierten Zustand ist das nicht derselbe Pfad,
 * deshalb eine Kandidatenliste statt einer festen Zeile.
 */
function fb_langdir_wurzel()
{
    $p = fb_paths();
    $k = array();
    if ($p['home'] !== '') {
        $k[] = $p['home'] . '/templates/plugins/' . $p['plugin'];
    }
    $k[] = dirname(dirname(__DIR__)) . '/templates';
    $k[] = dirname(__DIR__) . '/templates';
    foreach ($k as $d) {
        if (is_dir($d)) { return $d; }
    }
    return $k[count($k) - 1];
}

/**
 * Einen eingefuegten AUSZUG lesen.
 *
 * DER DRITTE WEG - und der einzige, der ohne die Datei auf dem LoxBerry
 * auskommt.
 *
 * Die Projektdatei ist knapp vier Megabyte gross. Was dieses Plugin daraus
 * braucht, sind ein paar Kilobyte: je Rollladenbaustein ein Titel und eine
 * Himmelsrichtung, je Raum ein Titel und eine Grundflaeche. Wer diesen
 * Auszug auf dem eigenen Rechner erzeugt - das Skript dazu gibt es im
 * Reiter Einstellungen zum Herunterladen - und hier einfuegt, kommt an
 * jeder Absendegrenze und an jedem Dateimanager vorbei.
 *
 * Die NAMENSREGELN bleiben hier: was aus einem Bausteinnamen als Kuerzel
 * und als Raumschluessel wird, entscheidet fb_projekt_liste_bauen() - fuer
 * beide Wege dieselbe Stelle. Der Auszug traegt nur, was in der Datei
 * stand.
 *
 * Rueckgabe: array(liste, fehler, raeume, mehrfach). Ein leerer erster
 * Eintrag heisst: nichts brauchbar, und $fehler sagt warum.
 */
function fb_auszug_lesen($text)
{
    $text = trim((string) $text);
    if ($text === '') { return array(array(), array('AUSZUG_LEER'), array(), array()); }
    $d = json_decode($text, true);
    if (!is_array($d) || !isset($d['fensterbilanz'])) {
        return array(array(), array('AUSZUG_UNBEKANNT'), array(), array());
    }
    /* Die Fassung des Auszugs steht drin, damit ein alter Auszug nicht
     * stillschweigend halb gelesen wird. Groesser als bekannt heisst:
     * melden, nicht raten. */
    if ((int) $d['fensterbilanz'] > 1) {
        return array(array(), array('AUSZUG_ZU_NEU'), array(), array());
    }
    $roh_f = array();
    if (isset($d['fenster']) && is_array($d['fenster'])) {
        foreach ($d['fenster'] as $f) {
            if (!is_array($f) || !isset($f['titel'])) { continue; }
            $dir = (isset($f['dir']) && $f['dir'] !== null && $f['dir'] !== '')
                   ? (int) round((float) $f['dir']) : null;
            $roh_f[] = array((string) $f['titel'], $dir,
                             isset($f['dirtol']) ? (int) $f['dirtol'] : null);
        }
    }
    $roh_r = array();
    if (isset($d['raeume']) && is_array($d['raeume'])) {
        foreach ($d['raeume'] as $r) {
            if (!is_array($r) || !isset($r['titel'])) { continue; }
            $roh_r[] = array((string) $r['titel'],
                             isset($r['qm']) ? (float) $r['qm'] : 0.0);
        }
    }
    if (!$roh_f) { return array(array(), array('AUSZUG_OHNE_FENSTER'), array(), array()); }
    list($liste, $fehler) = fb_projekt_liste_bauen($roh_f);
    list($raeume, $mehrfach) = fb_projekt_raeume_bauen($roh_r);
    return array($liste, $fehler, $raeume, $mehrfach);
}

/**
 * Aus Titel und Himmelsrichtung die Vorschlagsliste bauen.
 *
 * Steht getrennt vom Lesen, weil es ZWEI Wege hierher gibt: die
 * Projektdatei und der eingefuegte Auszug. Die Namensregeln - was aus einem
 * Bausteinnamen als Kuerzel und als Raumschluessel wird - duerfen nur an
 * einer Stelle stehen; zwei Kopien liefen auseinander, und dann hiesse
 * dasselbe Fenster je nach Weg anders. Genau daran haengen die virtuellen
 * Eingaenge in Loxone.
 *
 * $roh: Liste aus array(Titel, Dir, DirTol).
 */
function fb_projekt_liste_bauen($roh, $fehler = array())
{
    $liste = array();
    foreach ($roh as $e) {
        $titel = (string) $e[0];
        $dir = $e[1];
        if ($dir === null) {
            $fehler[] = ($titel !== '' ? $titel : 'ohne Titel');
            continue;
        }
        $dir = (int) $dir;
        $liste[] = array(
            'titel'   => $titel,
            'azimut'  => (($dir % 360) + 360) % 360,
            'dirtol'  => isset($e[2]) ? $e[2] : null,
            'kuerzel' => fb_kuerzel_vorschlag($titel),
            'raum'    => fb_raum_vorschlag($titel),
        );
    }

    /* GLEICHE KUERZEL AUSEINANDERZIEHEN.
     *
     * Zwei Bausteine koennen denselben Namen tragen, und aus zwei Namen
     * kann dieselbe Kurzform werden. Beides ergaebe zwei virtuelle Eingaenge
     * mit demselben Titel und zwei MQTT-Themen, die einander ueberschreiben -
     * die Oberflaeche weist das beim Speichern ab, aber ein Vorschlag, den
     * man nicht speichern kann, ist keiner. Angehaengt wird eine Ziffer, und
     * die Laengengrenze wird dabei eingehalten. */
    $gesehen = array();
    foreach ($liste as $i => $x) {
        $k = $x['kuerzel'];
        if ($k === '') { $k = 'FENSTER'; }
        if (isset($gesehen[$k])) {
            $n = 2;
            while (isset($gesehen[substr($k, 0, 15) . $n]) && $n < 10) { $n++; }
            $k = substr($k, 0, 15) . $n;
        }
        $gesehen[$k] = true;
        $liste[$i]['kuerzel'] = $k;
    }
    return array($liste, $fehler);
}

/**
 * Eine Datei lesen, ohne dass eine Warnung als Befund liegenbleibt.
 *
 * Das vorangestellte @ unterdrueckt die AUSGABE einer Warnung, nicht den
 * Fehler selbst: ein gesetzter Fehlerbehandler bekommt sie weiterhin zu
 * sehen. Genau das ist hier der Regelfall - eine Datei, die inzwischen weg
 * ist oder nicht gelesen werden darf, ist eine Lage, die das Plugin sauber
 * behandelt und ordentlich meldet, und trotzdem stand die Warnung danach im
 * Pruefstand als Befund da.
 *
 * Ein Rueckgabewert false bleibt false - unterdrueckt wird die Meldung,
 * nicht die Aussage.
 */
function fb_holen_datei($pfad)
{
    set_error_handler(function () { return true; });
    $inhalt = file_get_contents($pfad);
    restore_error_handler();
    return $inhalt;
}

/**
 * Eine Groessenangabe aus der php.ini in Byte umrechnen.
 *
 * ini_get() liefert "2M", "8M", "512K" oder "-1" - also gerade NICHT eine
 * Zahl, mit der sich rechnen laesst. Ohne diese Umrechnung koennte die
 * Oberflaeche nur den Text anzeigen und nicht die Frage beantworten, auf
 * die es ankommt: reicht das fuer DIESE Datei?
 *
 * -1 heisst "unbegrenzt" und wird als PHP_INT_MAX zurueckgegeben.
 */
function fb_ini_byte($wert)
{
    $wert = trim((string) $wert);
    if ($wert === '') { return 0; }
    if ($wert === '-1') { return PHP_INT_MAX; }
    $zahl = (float) $wert;
    $letzt = strtolower(substr($wert, -1));
    if ($letzt === 'g') { $zahl *= 1024 * 1024 * 1024; }
    elseif ($letzt === 'm') { $zahl *= 1024 * 1024; }
    elseif ($letzt === 'k') { $zahl *= 1024; }
    return (int) $zahl;
}

/**
 * Was laesst dieses PHP bei einer Absendung durch?
 *
 * WARUM DAS HIER STEHT UND NICHT NUR IN DER FEHLERMELDUNG:
 *
 * Die Grenzen werden erst dann sichtbar, wenn eine Absendung schon
 * gescheitert ist - PHP weist die Datei ab, BEVOR eine Zeile dieses Plugins
 * laeuft. Der Anwender laedt also erst eine Datei hoch, wartet, und
 * erfaehrt danach, dass es nie haette klappen koennen. Diese Werte gehoeren
 * deshalb VOR das Formular.
 *
 * Und das Plugin kann sie nicht selbst anheben: upload_max_filesize und
 * post_max_size sind PHP_INI_PERDIR. Gemessen mit PHP 7.4.33 und 8.4.24
 * gibt ini_set() fuer beide false zurueck und der Wert bleibt stehen.
 * Selbst wenn es ginge, waere es zu spaet - die Absendung ist beim ersten
 * Befehl des Skripts bereits abgewiesen.
 *
 * Rueckgabe je Groesse: array(Text, Byte).
 */
function fb_grenzen()
{
    $g = array();
    foreach (array('upload_max_filesize', 'post_max_size', 'memory_limit') as $k) {
        $t = (string) ini_get($k);
        $g[$k] = array($t, fb_ini_byte($t));
    }
    /* Die kleinere der beiden ersten entscheidet: eine Absendung, die
     * post_max_size sprengt, wird abgewiesen, auch wenn die Datei allein
     * unter upload_max_filesize bliebe. */
    $g['grenze'] = min($g['upload_max_filesize'][1], $g['post_max_size'][1]);
    return $g;
}

/**
 * Liegt die .user.ini des Plugins, und greift sie?
 *
 * Das Plugin legt in seinem htmlauth-Verzeichnis eine .user.ini ab, die
 * upload_max_filesize und post_max_size fuer GENAU DIESES Verzeichnis
 * anhebt. Ob das etwas bewirkt, haengt daran, wie PHP hier laeuft: bei
 * CGI/FastCGI/FPM wird sie gelesen, als Apache-Modul uebergangen. Das laesst
 * sich nicht vorhersagen - also wird es gemessen und gesagt.
 *
 * Rueckgabe: array(liegt_da, greift, Pfad). "greift" ist true, wenn die
 * tatsaechlich geltende Grenze mindestens so gross ist wie die, die in der
 * Datei steht - das ist der einzige Beweis, den es von innen gibt.
 */
function fb_user_ini()
{
    /* Die Datei gehoert neben die Oberflaeche, nicht neben diese
     * Bibliothek: .user.ini wirkt auf das Verzeichnis des AUSGEFUEHRTEN
     * Skripts, und abgesendet wird an htmlauth/index.php. Im installierten
     * Zustand liegen html/ und htmlauth/ in getrennten Baeumen, deshalb die
     * Kandidatenliste statt eines festen Pfades. */
    $kandidaten = array(
        dirname(__DIR__) . '/htmlauth/.user.ini',
        dirname(dirname(__DIR__)) . '/webfrontend/htmlauth/.user.ini',
    );
    $p = fb_paths();
    if ($p['home'] !== '') {
        $kandidaten[] = $p['home'] . '/webfrontend/htmlauth/plugins/'
                      . $p['plugin'] . '/.user.ini';
    }
    $pfad = '';
    foreach ($kandidaten as $k) {
        if (is_file($k)) { $pfad = $k; break; }
    }
    if ($pfad === '') { return array(false, false, ''); }
    /* Was steht drin - und was gilt wirklich? */
    $soll = 0;
    $roh = fb_holen_datei($pfad);
    if ($roh !== false && preg_match('/^\s*upload_max_filesize\s*=\s*(\S+)/mi', $roh, $m)) {
        $soll = fb_ini_byte($m[1]);
    }
    $ist = fb_ini_byte((string) ini_get('upload_max_filesize'));
    return array(true, $soll > 0 && $ist >= $soll, $pfad);
}

/**
 * In welchen Ordnern wird nach einer abgelegten Projektdatei gesucht?
 *
 * DER WEG AN DER ABSENDUNG VORBEI.
 *
 * Eine .Loxone-Datei ist in dieser Anlage 3 bis 4 MB gross, und die Vorgabe
 * von PHP fuer upload_max_filesize ist 2M. Der Weg ueber das Formular
 * scheitert damit auf den meisten Anlagen, und das Plugin kann daran
 * nichts aendern (siehe fb_grenzen()). Wer die Datei stattdessen auf den
 * LoxBerry LEGT - ueber die Windows-Freigabe, mit WinSCP oder scp -, an dem
 * kommt keine dieser Grenzen mehr vor: file_get_contents() liest sie
 * geradewegs.
 *
 * Gesucht wird in einer FESTEN Liste. Ein Pfad aus dem Formular waere
 * bequemer und waere ein Leseloch: die Oberflaeche liegt zwar im
 * angemeldeten Bereich, aber ein Eingabefeld, in das man /etc/shadow
 * schreiben kann, gehoert nicht in ein Beschattungsplugin.
 */
function fb_projekt_ordner()
{
    $p = fb_paths();
    $basis = $p['home'] !== '' ? $p['home'] : dirname(dirname(__DIR__));
    /* MEHRERE WURZELN, weil die Datei auf verschiedenen Wegen hereinkommt
     * und jeder Weg woanders endet:
     *
     *   data/plugins/fensterbilanz  - der Ort, auf den die Anleitung zeigt
     *   data/                       - was ueber die Windows-Freigabe kommt
     *   /home/loxberry              - wo "scp datei loxberry@host:" landet
     *   /tmp und LoxBerrys tmp      - was Werkzeuge dort ablegen
     *   /media                      - USB-Stick
     *
     * Der LoxBerry-Dateimanager scheidet als Weg oft aus: er haengt an
     * denselben PHP-Grenzen wie jede andere Absendung. Gemessen: eine
     * Absendung ueber post_max_size laesst $_FILES und $_POST LEER, und ein
     * Skript, das dort etwas erwartet, tut daraufhin schlicht nichts. */
    /* KEIN BENUTZERVERZEICHNIS.
     *
     * Der erste Entwurf hatte /home/loxberry hingeschrieben - auf der
     * Anlage, fuer die dieses Plugin gebaut ist, gibt es das Verzeichnis
     * gar nicht, und dasselbe galt fuer /opt/loxberry/tmp. Zwei von sechs
     * Wurzeln waren Zierde; aufgefallen ist es erst, als die Seite je
     * Ordner ausgab, was sie dort vorfindet.
     *
     * Der zweite Entwurf hat das Heimatverzeichnis gemessen statt geraten -
     * und war damit noch schlechter dran: auf einem LoxBerry kann das
     * /opt/loxberry sein, also der ganze Baum. Zwei Ebenen tief durch alles
     * zu laufen kostet Zeit, und zwar bei JEDEM Aufruf der
     * Einstellungsseite. Gemessen im Pruefstand: die Vollprobe lief in die
     * Zeitschranke.
     *
     * Die Anleitung nennt ohnehin einen vollstaendigen Zielpfad. Wer ihn
     * abschreibt, landet nicht im Heimatverzeichnis. */
    $o = array($p['datadir'], $basis . '/data',
               (string) sys_get_temp_dir(), '/tmp', '/media', '/mnt');
    /* Doppelte herausnehmen, ohne die Reihenfolge zu verlieren: der eigene
     * Datenordner MUSS oben stehen, weil die Anleitung in der Oberflaeche
     * genau auf den ersten Eintrag zeigt.
     *
     * Verglichen wird der geschriebene Pfad und NICHT realpath(). Der erste
     * Entwurf nahm realpath() und liess damit jeden Ordner fallen, den es
     * noch nicht gibt - und der Datenordner ist vor der ersten Installation
     * genau so einer. Die Folge waere gewesen: die Oberflaeche nennt
     * .../data als Ablageort, gesucht wird aber zuerst woanders. Gefunden
     * hat das der Selbsttest, weil er nachsieht, ob beide dasselbe sagen.
     *
     * Ein Ordner, den es nicht gibt, kostet nichts: fb_projekt_dateien()
     * ueberspringt ihn. */
    $raus = array();
    foreach ($o as $d) {
        $d = (string) $d;
        $k = rtrim(str_replace('\\', '/', $d), '/');
        if ($d === '' || $k === '' || isset($raus[$k])) { continue; }
        $raus[$k] = $d;
    }
    return array_values($raus);
}

/**
 * Welcher Ordner wird zum ABLEGEN empfohlen?
 *
 * Nicht einfach der erste der Suchliste. Der erste ist der Datenordner des
 * Plugins - und der ist der einzige der ganzen Liste, den das Plugin selbst
 * wieder loescht: die Deinstallation raeumt ihn mitsamt Inhalt weg, und
 * viele Anwender deinstallieren vor einer Neuinstallation. Wer seine
 * Projektdatei dorthin gelegt hat, findet sie danach nicht mehr, und die
 * Seite sagt nur noch, es liege nichts da. Genau so ist es passiert.
 *
 * Empfohlen wird deshalb der erste Ordner, der DREI Bedingungen erfuellt:
 * es gibt ihn, es laesst sich hineinschreiben, und ein Update oder eine
 * Deinstallation dieses Plugins fasst ihn nicht an. Gibt es keinen solchen,
 * bleibt der Datenordner - dann aber mit dem Hinweis, was ihm zustoesst.
 *
 * Rueckgabe: array(Pfad, ueberlebt_deinstallation).
 */
function fb_ablageordner($ordner = null, $eigener = null)
{
    /* Beide Argumente sind AUSSCHLIESSLICH fuer den Selbsttest da; die
     * Oberflaeche ruft immer ohne auf. Ohne sie kam der Selbsttest an der
     * entscheidenden Verzweigung gar nicht vorbei - im Pruefstand gibt es
     * weder den Datenordner noch verlaesslich einen zweiten beschreibbaren
     * Ordner, und beide Ruecknahmen blieben deshalb gruen. */
    $p = fb_paths();
    if ($ordner === null || !is_array($ordner)) { $ordner = fb_projekt_ordner(); }
    if ($eigener === null) { $eigener = $p['datadir']; }
    $eigen = rtrim(str_replace('\\', '/', $eigener), '/');
    foreach ($ordner as $d) {
        if (rtrim(str_replace('\\', '/', $d), '/') === $eigen) { continue; }
        if (is_dir($d) && is_writable($d)) { return array($d, true); }
    }
    return array($eigener, false);
}

/**
 * Die abgelegten Projektdateien auflisten.
 *
 * Rueckgabe: Liste aus array(ordner, name, pfad, groesse, zeit), die
 * neueste zuerst. Der PFAD wird hier gebaut und kommt nie aus dem Formular
 * - das Formular nennt nur Ordnernummer und Dateinamen, und beides wird
 * gegen diese Liste gehalten.
 */
function fb_projekt_dateien($hoechstens = 60, $ordner = null)
{
    /* $ordner ist AUSSCHLIESSLICH fuer den Selbsttest da und wird von
     * keinem Handler gesetzt - die Oberflaeche ruft immer ohne auf. Der
     * Grund fuer das Argument: eine Pruefung, die dafuer in den echten
     * Datenordner schreiben muss, prueft die Umgebung mit und legt auf
     * jedem Rechner ohne LoxBerry Ordner an, die dort nichts zu suchen
     * haben. Gemessen: sie hat C:\data\plugins\fensterbilanz erzeugt. */
    $liste = array();
    if ($ordner === null || !is_array($ordner)) { $ordner = fb_projekt_ordner(); }
    /* ZWEI EBENEN TIEF, und nicht weiter.
     *
     * Tief genug fuer die Faelle, die wirklich vorkommen - ein Unterordner
     * "Loxone" im Benutzerverzeichnis, ein USB-Stick unter /media/<name>.
     * Und flach genug, dass die Seite nicht steht: /media und das
     * Datenverzeichnis koennen viel enthalten, und diese Suche laeuft bei
     * JEDEM Aufruf der Einstellungsseite. Die Schranke ist gewaehlt und
     * nicht gemessen; sie begrenzt den Aufwand, nie das Ergebnis einer
     * Rechnung. */
    /* ZWEI SCHRANKEN, und beide begrenzen nur den AUFWAND.
     *
     * Die Zahl der Verzeichnisse allein genuegt nicht: ein einzelnes
     * Verzeichnis mit zehntausend Eintraegen kostet mehr Zeit als
     * vierhundert kleine. Deshalb zusaetzlich eine Uhr. Was danach nicht
     * gefunden ist, wird nicht mehr gesucht - die Seite bleibt bedienbar,
     * und der Weg ueber den Auszug steht ohnehin daneben. */
    $besucht = 0;
    $schluss = microtime(true) + 1.0;
    foreach ($ordner as $nr => $d) {
        $warten = array(array($d, 0));
        while ($warten) {
            list($jetzt_d, $tiefe) = array_shift($warten);
            if ($besucht++ > 400 || microtime(true) > $schluss) { break 2; }
            if (!is_dir($jetzt_d) || !is_readable($jetzt_d)) { continue; }
            $eintraege = @scandir($jetzt_d);
            if (!is_array($eintraege)) { continue; }
            foreach ($eintraege as $name) {
                if ($name === '.' || $name === '..' || $name[0] === '.') { continue; }
                $voll = $jetzt_d . '/' . $name;
                /* Verknuepfungen werden NICHT verfolgt - weder als Ordner
                 * noch als Datei. Eine Verknuepfung koennte aus der Wurzel
                 * herausfuehren, und dann waere die Wurzelliste nur noch
                 * Zierde. */
                if (is_link($voll)) { continue; }
                if (is_dir($voll)) {
                    if ($tiefe < 2) { $warten[] = array($voll, $tiefe + 1); }
                    continue;
                }
                if (!preg_match('/\.loxone$/i', $name)) { continue; }
                if (!is_file($voll) || !is_readable($voll)) { continue; }
                $liste[] = array(
                    'ordner'  => $nr,
                    'ordnerpfad' => $d,
                    /* Der Pfad INNERHALB der Wurzel - das ist es, was das
                     * Formular nennt, und nur er wird wiedergefunden. */
                    'rel'     => ltrim(substr($voll, strlen($d)), '/'),
                    'name'    => $name,
                    'pfad'    => $voll,
                    'groesse' => (int) filesize($voll),
                    'zeit'    => (int) filemtime($voll),
                );
            }
        }
    }
    usort($liste, function ($a, $b) {
        if ($a['zeit'] === $b['zeit']) { return strcmp($a['name'], $b['name']); }
        return $b['zeit'] - $a['zeit'];
    });
    return array_slice($liste, 0, $hoechstens);
}

/**
 * Eine abgelegte Datei anhand von Ordnernummer und Namen wiederfinden.
 *
 * Der Pfad wird NEU aus fb_projekt_dateien() geholt und nicht aus dem
 * Formular uebernommen. Damit ist es gleichgueltig, was im Formular steht:
 * was nicht in der frisch gelesenen Liste vorkommt, gibt es nicht.
 */
function fb_projekt_datei_finden($ordner, $rel, $ordnerliste = null)
{
    foreach (fb_projekt_dateien(60, $ordnerliste) as $d) {
        if ((int) $d['ordner'] === (int) $ordner && $d['rel'] === (string) $rel) {
            return $d;
        }
    }
    return null;
}

/**
 * Die GRUNDFLAECHEN der Raeume aus der Projektdatei lesen.
 *
 * Die Fensterflaeche steht NICHT in der Projektdatei - gemessen an der
 * Ausfuhr vom 23.08.2026 traegt der AutoJalousie-Baustein Dir, DirTol,
 * DirTol2, Width und Space, und Width/Space sind bei allen 25 Rolllaeden
 * derselben Anlage identisch (70 und 60). Das sind Lamellenbreite und
 * -abstand in Millimetern, keine Fenstermasse; 25 verschiedene Fenster
 * koennen keine identischen Masse haben.
 *
 * Was sehr wohl darin steht, ist die Grundflaeche der RAEUME:
 * <C Type="Place" Title="EG Wohnzimmer" Sqm="25"/>. Sie ist die Groesse,
 * die dem Bilanzteil bisher gefehlt hat.
 *
 * Rueckgabe: array(Flaechen, Doppelte). Flaechen ist raumschluessel => m2,
 * Doppelte sind Titel, die auf denselben Schluessel fallen - deren Flaeche
 * wird NICHT uebernommen. "KG Vorrat Ost" und "KG Vorrat Nord" ergeben
 * beide kg_vorrat; eine der beiden Flaechen stillschweigend zu nehmen waere
 * geraten, und geraten wird hier nicht.
 */
function fb_projekt_raeume($inhalt)
{
    $roh = array();
    if (!preg_match_all('/<C\s[^>]*Type="Place"[^>]*>/', (string) $inhalt, $mm)) {
        return array(array(), array());
    }
    foreach ($mm[0] as $tag) {
        if (!preg_match('/\sTitle="([^"]*)"/', $tag, $t)) { continue; }
        /* Ohne Sqm ist der Raum hier nicht vorhanden, nicht null gross.
         * "Zentral" und "Nicht zugeordnet" sind genau solche Faelle. */
        if (!preg_match('/\sSqm="(\d+(?:\.\d+)?)"/', $tag, $q)) { continue; }
        $qm = (float) $q[1];
        if ($qm <= 0.0) { continue; }
        $titel = html_entity_decode($t[1], ENT_QUOTES, 'UTF-8');
        /* DERSELBE WEG wie beim Fenster: fb_raum_vorschlag() macht aus dem
         * Bausteinnamen den Raumschluessel, und nur wenn beide Seiten
         * denselben Weg gehen, treffen sie sich. Gemessen an der echten
         * Datei: 15 von 16 Fensterraeumen finden so ihren Place. */
        $roh[] = array($titel, $qm);
    }
    return fb_projekt_raeume_bauen($roh);
}

/**
 * Aus Titel und Grundflaeche die Raumliste bauen - fuer BEIDE Wege.
 *
 * $roh: Liste aus array(Titel, m2).
 */
function fb_projekt_raeume_bauen($roh)
{
    $flaechen = array();
    $mehrfach = array();
    $titel_zu = array();
    foreach ($roh as $e) {
        $titel = (string) $e[0];
        $qm = (float) $e[1];
        if ($qm <= 0.0) { continue; }
        $r = fb_raum_vorschlag($titel);
        if ($r === '') { continue; }
        if (isset($titel_zu[$r]) && abs($flaechen[$r] - $qm) > 0.05) {
            $mehrfach[$r] = array($titel_zu[$r], $titel);
            continue;
        }
        $titel_zu[$r] = $titel;
        $flaechen[$r] = round($qm, 1);
    }
    foreach (array_keys($mehrfach) as $r) { unset($flaechen[$r]); }
    ksort($flaechen);
    return array($flaechen, $mehrfach);
}

/**
 * Ein Kuerzel aus dem Bausteinnamen VORSCHLAGEN.
 *
 * Vorschlagen, nicht setzen: die Namen in einer Projektdatei sind
 * gewachsen, und was hier herauskommt, gehoert angesehen. Weggeschnitten
 * werden nur die Woerter, die in jedem zweiten Namen stehen und deshalb
 * nichts unterscheiden.
 */
function fb_kuerzel_vorschlag($titel)
{
    /* ZUERST umschreiben, DANN streichen.
     *
     * Die Streichliste stand zuerst auf den Originalwoertern - und traf
     * damit "Rollladen", aber nicht "Rollläden". In der Projektdatei dieser
     * Anlage heissen die Doppelfenster in der Mehrzahl; aus zwei
     * verschiedenen Fenstern wurde dadurch zweimal dasselbe Kuerzel.
     * Umgeschrieben wird deshalb vorher, und die Liste steht in ASCII. */
    $t = strtr((string) $titel, array(
        'ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss',
        'Ä' => 'Ae', 'Ö' => 'Oe', 'Ü' => 'Ue'));
    $t = strtoupper($t);
    $t = preg_replace('/[^A-Z0-9]+/', ' ', $t);
    $streichen = array('ROLLLAEDEN', 'ROLLLADEN', 'ROLLAEDEN', 'ROLLADEN',
                       'JALOUSIEN', 'JALOUSIE', 'RAFFSTORES', 'RAFFSTORE',
                       'BESCHATTUNG', 'MARKISEN', 'MARKISE', 'VERSCHATTUNG');
    $worte = array();
    foreach (preg_split('/\s+/', trim($t)) as $w) {
        if ($w === '') { continue; }
        if (in_array($w, $streichen, true)) { continue; }
        /* Jedes Wort auf sechs Zeichen kuerzen, statt am Ende abzuschneiden.
         * "EG ESSZIMMER TUER" wird so zu EG_ESSZIM_TUER und nicht zu
         * EG_ESSZIMMER_TUE - das unterscheidende Wort steht hinten. */
        $worte[] = substr($w, 0, 6);
    }
    $k = trim(implode('_', $worte), '_');
    return substr($k, 0, 16);
}

/** Ein Raumschluessel aus dem Bausteinnamen - ohne das, was das Fenster
 *  benennt statt den Raum. */
function fb_raum_vorschlag($titel)
{
    /* Vom Kuerzel wird abgeschnitten, was das FENSTER benennt und nicht den
     * Raum. Zwei Fenster desselben Zimmers bekommen dadurch denselben
     * Raumschluessel - und genau so ist es gemeint: sie teilen sich die
     * Temperatur und die Tagesbilanz. */
    $t = fb_kuerzel_vorschlag($titel);
    for ($i = 0; $i < 3; $i++) {
        $vorher = $t;
        $t = preg_replace('/_(FENSTE|FENSTER|TUER|TUERE|TUR|LINKS|RECHTS|MITTE|'
                        . 'NORD|SUED|OST|WEST|GROSS|KLEIN|\d+)$/', '', $t);
        if ($t === $vorher) { break; }
    }
    return fb_raumschluessel_richten(strtolower($t));
}

/* ==================================================================
 * Das Bild des Verschattungshorizonts
 * ================================================================== */

/**
 * Sonnenbahn, Horizont und Fensterrichtung als SVG.
 *
 * Der Horizont wird als Zahlenreihe eingetragen ("80:22, 110:14"). Ob diese
 * Zahlen zu dem passen, was man aus dem Fenster sieht, sagt einem eine
 * Zahlenreihe nicht - ein Bild in einer Sekunde.
 *
 * Gezeichnet wird der Tagesbogen von HEUTE, weil das der Tag ist, an dem
 * der Anwender am Fenster steht und vergleicht.
 *
 * Alles wird ueber fb_x() maskiert; die Beschriftungen kommen aus Zahlen
 * und aus dem Kuerzel, und ein Kuerzel ist bereits auf Buchstaben und
 * Ziffern gefiltert.
 */
function fb_horizont_svg($f, $cfg, $jetzt = null, $breite = 640, $hoehe = 220)
{
    if ($jetzt === null) { $jetzt = time(); }
    $lb = 34; $rb = 8; $ob = 10; $ub = 20;          // Raender
    $iw = $breite - $lb - $rb;
    $ih = $hoehe - $ob - $ub;
    $x = function ($az) use ($lb, $iw) { return $lb + $iw * ($az / 360.0); };
    $y = function ($h) use ($ob, $ih) { return $ob + $ih * (1.0 - fb_klemme($h, 0, 90) / 90.0); };

    $o = '<svg viewBox="0 0 ' . (int) $breite . ' ' . (int) $hoehe . '" width="100%" '
       . 'height="' . (int) $hoehe . '" xmlns="http://www.w3.org/2000/svg" '
       . 'role="img" aria-label="Sonnenbahn und Horizont">';
    $o .= '<rect x="' . $lb . '" y="' . $ob . '" width="' . $iw . '" height="' . $ih
        . '" fill="#f7fbff" stroke="#ccc"/>';

    /* Gitter: alle 45 Grad Azimut, alle 30 Grad Hoehe. */
    foreach (array(0, 45, 90, 135, 180, 225, 270, 315, 360) as $az) {
        $o .= '<line x1="' . round($x($az), 1) . '" y1="' . $ob . '" x2="' . round($x($az), 1)
            . '" y2="' . ($ob + $ih) . '" stroke="#e3e3e3"/>';
    }
    foreach (array(0, 30, 60, 90) as $h) {
        $o .= '<line x1="' . $lb . '" y1="' . round($y($h), 1) . '" x2="' . ($lb + $iw)
            . '" y2="' . round($y($h), 1) . '" stroke="#e3e3e3"/>'
            . '<text x="4" y="' . round($y($h) + 4, 1) . '" font-size="10" fill="#666">'
            . $h . '&#176;</text>';
    }
    foreach (array(0 => 'N', 90 => 'O', 180 => 'S', 270 => 'W', 360 => 'N') as $az => $bez) {
        $o .= '<text x="' . round($x($az) - 4, 1) . '" y="' . ($ob + $ih + 14)
            . '" font-size="10" fill="#666">' . $bez . '</text>';
    }

    /* Der Sichtbereich des Fensters: plus/minus 90 Grad um seine Richtung.
     * Weiter herum sieht ein senkrechtes Fenster nicht. */
    $a0 = ((int) $f['azimut'] - 90 + 360) % 360;
    $a1 = ((int) $f['azimut'] + 90) % 360;
    $bereiche = ($a0 < $a1) ? array(array($a0, $a1))
                            : array(array($a0, 360), array(0, $a1));
    foreach ($bereiche as $b) {
        $o .= '<rect x="' . round($x($b[0]), 1) . '" y="' . $ob . '" width="'
            . round($x($b[1]) - $x($b[0]), 1) . '" height="' . $ih
            . '" fill="#6dac20" opacity="0.08"/>';
    }
    $o .= '<line x1="' . round($x((int) $f['azimut']), 1) . '" y1="' . $ob . '" x2="'
        . round($x((int) $f['azimut']), 1) . '" y2="' . ($ob + $ih)
        . '" stroke="#6dac20" stroke-width="2" stroke-dasharray="4 3"/>';

    /* Der Horizont. */
    list($punkte, ) = fb_horizont_lesen($f['horizont']);
    if (count($punkte) > 0) {
        $pfad = '';
        for ($az = 0; $az <= 360; $az += 3) {
            $pfad .= round($x($az), 1) . ',' . round($y(fb_horizont_hoehe($punkte, $az)), 1) . ' ';
        }
        $o .= '<polygon points="' . trim($pfad) . ' ' . round($x(360), 1) . ','
            . ($ob + $ih) . ' ' . $lb . ',' . ($ob + $ih)
            . '" fill="#546e7a" opacity="0.35"/>';
        $o .= '<polyline points="' . trim($pfad) . '" fill="none" stroke="#546e7a" stroke-width="2"/>';
        foreach ($punkte as $pt) {
            $o .= '<circle cx="' . round($x($pt[0]), 1) . '" cy="' . round($y($pt[1]), 1)
                . '" r="3" fill="#546e7a"/>';
        }
    }

    /* Die Sonnenbahn von heute, alle zehn Minuten. */
    $mitternacht = mktime(0, 0, 0, (int) date('n', $jetzt), (int) date('j', $jetzt),
                          (int) date('Y', $jetzt));
    $bahn = '';
    for ($min = 0; $min <= 1440; $min += 10) {
        $s = fb_sonnenstand($mitternacht + $min * 60, $cfg['breite'], $cfg['laenge']);
        if ($s['hoehe'] < 0) { continue; }
        $bahn .= round($x($s['azimut']), 1) . ',' . round($y($s['hoehe']), 1) . ' ';
    }
    if ($bahn !== '') {
        $o .= '<polyline points="' . trim($bahn) . '" fill="none" stroke="#ffb300" stroke-width="2"/>';
    }

    /* Und wo sie gerade steht. */
    $jetztstand = fb_sonnenstand($jetzt, $cfg['breite'], $cfg['laenge']);
    if ($jetztstand['hoehe'] > 0) {
        $o .= '<circle cx="' . round($x($jetztstand['azimut']), 1) . '" cy="'
            . round($y($jetztstand['hoehe']), 1) . '" r="5" fill="#ffd54f" stroke="#e0620d"/>';
    }
    $o .= '</svg>';
    return $o;
}

/**
 * Einen Text ueber MQTT senden - fuer den Tagesbericht.
 *
 * Getrennt von fb_mqtt_senden(), weil der Bericht hoechstens einmal am Tag
 * kommt und nicht zum Stand gehoert.
 */
function fb_mqtt_text($cfg, $zweig, $text)
{
    if (empty($cfg['mqtt_ein'])) { return false; }
    $z = fb_mqtt_zustand();
    if ($z['udpport'] < 1 || $z['udpport'] > 65535) { return false; }
    if (!function_exists('socket_create')) { return false; }
    $s = @socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
    if (!$s) { return false; }
    $msg = 'publish ' . fb_mqtt_thema($cfg['mqtt_topic'] . '/' . $zweig) . ' '
         . fb_mqtt_wert_saeubern($text);
    $ok = @socket_sendto($s, $msg, strlen($msg), 0, '127.0.0.1', $z['udpport']) !== false;
    socket_close($s);
    return $ok;
}

/* ==================================================================
 * Sprache - Englisch ist die Rueckfallebene, nicht Deutsch
 * ================================================================== */

function fb_sprache()
{
    $s = 'de';
    if (class_exists('LBSystem', false) && method_exists('LBSystem', 'lblanguage')) {
        $s = LBSystem::lblanguage();
    } elseif (getenv('LBLANG')) {
        $s = getenv('LBLANG');
    }
    $s = strtolower(substr((string) $s, 0, 2));
    return in_array($s, array('de', 'en'), true) ? $s : 'en';
}

/**
 * Der Ordner mit den Sprachdateien.
 *
 * Gesucht wird der Ordner, der wirklich eine language_de.ini enthaelt -
 * nicht ein anderer, aus dem man auf ihn schliessen koennte. Genau daran
 * ist ein anderes Plugin gescheitert: dort wurde vom Konfigurations- auf
 * den Vorlagenordner geschlossen, und die ganze Oberflaeche stand
 * unbeschriftet da, ohne dass irgendwo ein Fehler auftauchte.
 */
function fb_langdir()
{
    static $gefunden = null;
    if ($gefunden !== null) { return $gefunden; }
    $p = fb_paths();
    $k = array();
    if ($p['home'] !== '') {
        $k[] = $p['home'] . '/templates/plugins/' . $p['plugin'] . '/lang';
        $k[] = $p['home'] . '/templates/plugins/fensterbilanz/lang';
    }
    $k[] = dirname(dirname(__DIR__)) . '/templates/lang';
    $k[] = dirname(dirname(dirname(__DIR__))) . '/templates/lang';
    foreach ($k as $d) {
        if (is_file($d . '/language_de.ini') || is_file($d . '/language_en.ini')) {
            $gefunden = $d;
            return $gefunden;
        }
    }
    $gefunden = '';
    return $gefunden;
}

function fb_sprache_fehlt() { return fb_langdir() === ''; }

function fb_t($schluessel)
{
    static $texte = null;
    if ($texte === null) {
        $pfad = fb_langdir();
        $texte = $pfad !== ''
            ? @parse_ini_file($pfad . '/language_' . fb_sprache() . '.ini', true, INI_SCANNER_RAW)
            : array();
        if (!is_array($texte)) { $texte = array(); }
        $rueck = $pfad !== ''
            ? @parse_ini_file($pfad . '/language_en.ini', true, INI_SCANNER_RAW) : array();
        if (is_array($rueck)) { $texte = array_replace_recursive($rueck, $texte); }
        foreach ($texte as $ab => $paare) {
            if (!is_array($paare)) { continue; }
            foreach ($paare as $s => $w) { $texte[$ab][$s] = trim((string) $w, '"'); }
        }
    }
    $teile = array_pad(explode('.', $schluessel, 2), 2, '');
    return isset($texte[$teile[0]][$teile[1]]) ? $texte[$teile[0]][$teile[1]] : $schluessel;
}


/**
 * Eine Sicherungsdatei einlesen - und dabei NICHTS durchgehen lassen.
 *
 * Der wichtigste Punkt: eine halb gueltige Datei ueberschreibt GAR NICHTS.
 * Wer eine Sicherung zurueckspielt, will entweder den ganzen Stand oder
 * gar keinen - eine zur Haelfte uebernommene Konfiguration ist schlimmer
 * als die alte, und man sieht es ihr nicht an.
 *
 * Unbekannte Schluessel sind eine Beanstandung, kein stiller Verlust: sie
 * stammen aus einer anderen Fassung oder einem anderen Plugin.
 *
 * Rueckgabe: array(Konfiguration|null, Beanstandungen[], uebernommene Werte).
 */
function fb_sicherung_lesen($roh)
{
    $mangel = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten)) {
        return array(null, array(fb_t('EINST.SICH_KEIN_JSON')), 0);
    }
    $neu = fb_vorgaben();
    $bekannt = array_keys($neu);
    $anzahl = 0;
    foreach ($daten as $k => $w) {
        if (!in_array($k, $bekannt, true)) {
            $mangel[] = sprintf(fb_t('EINST.SICH_FREMD'),
                                 htmlspecialchars((string) $k, ENT_QUOTES, 'UTF-8'));
            continue;
        }
        $neu[$k] = $w;
        $anzahl++;
    }
    if ($anzahl === 0) {
        $mangel[] = fb_t('EINST.SICH_LEER');
    }
    return array($mangel ? null : $neu, $mangel, $anzahl);
}
