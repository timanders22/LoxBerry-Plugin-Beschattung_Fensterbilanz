<?php
/**
 * Fensterbilanz - Bedienoberflaeche
 *
 * Reiter: Einstellungen | MQTT | Einbindung in Loxone | Test | Logdateien
 *
 * Diese Datei ist NUR Oberflaeche. Gerechnet wird in fb_rechnen()
 * (webfrontend/html/fb_lib.php), angestossen aus cron/cron.05min ueber
 * bin/fb_lauf.php und aus dem Endpunkt webfrontend/html/index.php.
 *
 * Praefix 'fb_', weil LBWeb::lbheader() SDK-Globale setzt.
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 *
 * DREI DINGE, DIE HIER BEWUSST SO GEBAUT SIND
 * -------------------------------------------
 * 1. JEDER REITER HAT SEINEN EIGENEN SPEICHER-HANDLER, und im Reiter
 *    Einstellungen sogar zwei - einen fuer Standort und Modell, einen fuer
 *    die Fenstertabelle. Ein gemeinsamer Handler loescht stillschweigend
 *    jeden Schluessel, den das gerade abgeschickte Formular nicht
 *    mitschickt; bei Haken tut das schon isset() von allein.
 * 2. EIN WACHPOSTEN GEGEN FREMDE FORMULARE, vor allen Handlern. htmlauth/
 *    schuetzt gegen den unangemeldeten Aufruf - nicht dagegen, dass der
 *    Browser eines angemeldeten Bedieners ein Formular abschickt, das auf
 *    einer fremden Seite steht.
 * 3. REITERLEISTE, POSITIVLISTE UND KNOPFKLASSEN STEHEN AUSGESCHRIEBEN.
 *    Erzeugt man sie in einer Schleife, findet hausstandard_pruefen.py
 *    nichts und setzt die Spalte auf "-" - und ein Strich sammelt sich beim
 *    Ueberfliegen wie ein Haken ein. Dagegen steht keine Hoffnung, sondern
 *    die Kongruenzprobe im Reiter Test.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/* Die Bibliothek liegt im UNANGEMELDETEN Bereich, weil der Endpunkt sie
 * ebenso braucht. Der Weg dorthin sieht im Archiv anders aus als
 * installiert - deshalb eine Kandidatenliste und keine Rechnung mit einer
 * festen Zahl von "..". Genau daran ist in diesem Haus ein Plugin mit einem
 * leeren HTTP 500 gescheitert, den ausser dem Miniserver niemand sah. */
$fb_gefunden = false;
foreach (array(
    dirname(dirname(__DIR__)) . '/html/plugins/' . basename(__DIR__) . '/fb_lib.php',
    dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . basename(__DIR__) . '/fb_lib.php',
    dirname(__DIR__) . '/html/fb_lib.php',
) as $fb_kandidat) {
    if (is_file($fb_kandidat)) { require_once $fb_kandidat; $fb_gefunden = true; break; }
}
if (!$fb_gefunden) {
    echo '<p><b>Fehler:</b> fb_lib.php wurde nicht gefunden. Bitte das Plugin neu installieren.</p>';
    exit;
}
require_once __DIR__ . '/fb_test.php';

$fb_p = fb_paths();
if ($fb_p['home'] !== '' && is_file($fb_p['home'] . '/libs/phplib/loxberry_system.php')) {
    require_once $fb_p['home'] . '/libs/phplib/loxberry_system.php';
    require_once $fb_p['home'] . '/libs/phplib/loxberry_web.php';
    $fb_p = fb_paths(true);      // nach dem Einbinden neu holen, siehe Geruest
}

/* Die Reiter, ausgeschrieben. Positivliste, Leiste und die id der Bereiche
 * tragen dieselben Namen - dass sie auseinanderlaufen KOENNEN, ist der
 * Preis dafuer, dass das Pruefwerkzeug sie ueberhaupt sieht. */
$fb_reiter = array('tab-settings', 'tab-mqtt', 'tab-loxone', 'tab-test', 'tab-log');
$fb_tab = 'tab-settings';
if (isset($_POST['activetab']) && is_string($_POST['activetab'])
    && in_array($_POST['activetab'], $fb_reiter, true)) {
    $fb_tab = $_POST['activetab'];
} elseif (isset($_GET['form']) && is_string($_GET['form'])
          && in_array('tab-' . $_GET['form'], $fb_reiter, true)) {
    $fb_tab = 'tab-' . $_GET['form'];
}

$fb_meldungen = array();
$fb_fehler = array();
$fb_testausgabe = '';
$fb_post = (isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : '') === 'POST';

/* ---------------- Wachposten gegen fremde Formulare ----------------
 * EINE Pruefung, VOR allen Handlern. Einen einzelnen Handler kann man beim
 * Erweitern vergessen, einen Wachposten am Eingang nicht.
 *
 * fb_token() steht davor, damit auf einer frischen Anlage ueberhaupt ein
 * Merkmal entstehen kann. Das ist der angemeldete Bereich - hier darf
 * geschrieben werden; der Endpunkt tut es ausdruecklich nicht. */
fb_token();
$fb_merkmal = fb_formtoken();
if ($fb_post) {
    /* is_string() VOR dem Cast: ein Feldparameter (fmt[]=x) wuerde von
     * (string) zu "array" - unter PHP 8 mit einer Warnung, die die Seite
     * vor dem Kopf beginnen laesst. */
    $fb_mit = (isset($_POST['fmt']) && is_string($_POST['fmt'])) ? $_POST['fmt'] : '';
    if ($fb_merkmal === '' || !hash_equals($fb_merkmal, $fb_mit)) {
        $fb_post = false;
        $fb_fehler[] = fb_t('FEHLER.FREMDES_FORMULAR');
        fb_log('Ein Formular ohne gueltiges Merkmal wurde abgewiesen.');
    }
}

/* Die Konfiguration einmal vervollstaendigen. Steht hier und nicht im
 * Endpunkt, weil sie schreibt. */
$fb_ergaenzt = fb_cfg_vervollstaendigen();

/* ---------------- Vorlagen herunterladen ---------------- */
if ($fb_post && isset($_POST['vorlage'])) {
    $fb_welche = is_string($_POST['vorlage']) ? $_POST['vorlage'] : '';
    list($fb_name, $fb_inhalt) = ($fb_welche === 'vq') ? fb_vorlage_out() : fb_vorlage();
    if ($fb_inhalt === '') {
        $fb_fehler[] = fb_t('LOX.FEHLER_VORLAGE');
        $fb_tab = 'tab-loxone';
    } else {
        header('Content-Type: application/x-download');
        // Anfuehrungszeichen um den Dateinamen: ohne sie bricht jeder Name
        // mit einem Leerzeichen darin.
        header('Content-Disposition: attachment; filename="' . $fb_name . '"');
        echo $fb_inhalt;
        exit;
    }
}

/* Zwei Helfer, die beide Speicher-Handler brauchen. */
$fb_sauber = function ($s) {
    /* Nur Steuerzeichen und Anfuehrungszeichen entfernen - ein hartes
     * preg_replace auf eine Positivliste zerstoert eingefuegte Werte. */
    return trim(preg_replace('/[\x00-\x1F\x7F"\']/', '', is_string($s) ? $s : ''));
};
/* Der ROHE Wert, nur getrimmt.
 *
 * Er wird gebraucht, um das Zurechtbiegen zu MELDEN. Bis zum ersten
 * Prueflauf wurde gegen den bereits gesaeuberten Wert verglichen - und
 * damit fiel jedes entfernte Anfuehrungszeichen unter den Tisch: aus
 * "EG'Wohn" wurde stillschweigend "EGWohn", waehrend "EG Wohn" ordentlich
 * beanstandet wurde. Dieselbe Eingabeart, zweierlei Verhalten. */
$fb_roh = function ($name, $i) {
    $a = isset($_POST[$name]) ? (array) $_POST[$name] : array();
    return (isset($a[$i]) && is_string($a[$i])) ? trim($a[$i]) : '';
};
$fb_zahl = function ($roh, $von, $bis, $bez, $nachkomma = 0) use (&$fb_fehler) {
    /* Eine Zahl PRUEFEN statt sie stillschweigend zurechtzubiegen. Ein
     * leeres Feld gibt null zurueck - dann bleibt der bisherige Wert
     * stehen, und es gibt keine Beanstandung. */
    $roh = str_replace(',', '.', trim((string) $roh));
    if ($roh === '') { return null; }
    if (!is_numeric($roh)) {
        $fb_fehler[] = sprintf(fb_t('FEHLER.KEINE_ZAHL'), $bez, $roh);
        return null;
    }
    $w = (float) $roh;
    if ($w < $von || $w > $bis) {
        $fb_fehler[] = sprintf(fb_t('FEHLER.AUSSERHALB'), $bez, $roh, $von, $bis);
        return null;
    }
    return $nachkomma > 0 ? round($w, $nachkomma) : (int) round($w);
};

/* ---------------- Speichern: Standort und Modell ---------------- */
if ($fb_post && isset($_POST['speichern_modell'])) {
    /* SPERRE UM LESEN, AENDERN UND SCHREIBEN.
     *
     * Ohne sie geht bei zwei gleichzeitigen Speichervorgaengen eine der
     * beiden Aenderungen verloren, und beide Seiten melden Erfolg - gemessen
     * an zwei parallelen Formularen, sechs Verluste in acht Durchgaengen.
     * Die Sperre wird VOR fb_config() geholt und erst NACH dem Schreiben
     * zurueckgegeben. */
    $fb_sperre = fb_config_sperre();
    $fb_cfg = fb_config();
    $fb_vorher = $fb_cfg;          // fuer den Vergleich, siehe unten
    foreach (array(
        'breite'         => array(-90, 90, 'EINST.L_BREITE', 5),
        'laenge'         => array(-180, 180, 'EINST.L_LAENGE', 5),
        'tagesgrenze'    => array(10, 35, 'EINST.L_TAGESGRENZE', 0),
        'spreizung_tag'  => array(1, 20, 'EINST.L_SPREIZUNG_TAG', 0),
        'spreizung_raum' => array(5, 100, 'EINST.L_SPREIZUNG_RAUM', 0),
        'gewicht_raum'   => array(0, 100, 'EINST.L_GEWICHT_RAUM', 0),
        'gewicht_tag'    => array(0, 100, 'EINST.L_GEWICHT_TAG', 0),
        'schwelle_ein'   => array(1, 100, 'EINST.L_SCHWELLE_EIN', 0),
        'schwelle_aus'   => array(0, 99, 'EINST.L_SCHWELLE_AUS', 0),
        'e_ref'          => array(50, 1000, 'EINST.L_E_REF', 0),
        'albedo'         => array(0, 90, 'EINST.L_ALBEDO', 0),
        'iam_b0'         => array(0, 50, 'EINST.L_IAM', 0),
        'hoechstalter'   => array(60, 86400, 'EINST.L_HOECHSTALTER', 0),
        'rechentakt'     => array(10, 3600, 'EINST.L_RECHENTAKT', 0),
        'vorschau'       => array(0, 10800, 'EINST.L_VORSCHAU', 0),
        'glaettung'      => array(0, 3600, 'EINST.L_GLAETTUNG', 0),
        'gewicht_bilanz' => array(0, 100, 'EINST.L_GEWICHT_BILANZ', 0),
        'bilanz_voll_qm' => array(10, 2000, 'EINST.L_BILANZ_VOLL', 0),
        'raumflaeche_vorgabe' => array(1, 1000, 'EINST.L_RAUMFLAECHE_VORGABE', 0),
        'gewicht_morgen' => array(0, 100, 'EINST.L_GEWICHT_MORGEN', 0),
        'vorabend_ab'    => array(0, 23, 'EINST.L_VORABEND_AB', 0),
        'daemm_grenze'   => array(-30, 25, 'EINST.L_DAEMM_GRENZE', 0),
        'stellung_zu'    => array(1, 100, 'EINST.L_STELLUNG_ZU', 0),
        'stellung_frist' => array(60, 86400, 'EINST.L_STELLUNG_FRIST', 0),
        'bericht_stunde' => array(0, 23, 'EINST.L_BERICHT_STUNDE', 0),
        'pv_abweichung'  => array(5, 90, 'EINST.L_PV_ABWEICHUNG', 0),
    ) as $fb_k => $fb_d) {
        $w = $fb_zahl(isset($_POST[$fb_k]) ? $_POST[$fb_k] : '',
                      $fb_d[0], $fb_d[1], fb_t($fb_d[2]), $fb_d[3]);
        if ($w !== null) { $fb_cfg[$fb_k] = $w; }
    }
    /* Die Haken. isset() genuegt hier, weil sie in DIESEM Formular stehen -
     * jeder Reiter hat seinen eigenen Handler, und ein Haken aus einem
     * fremden Formular kann diesen hier nicht zuruecksetzen. */
    foreach (array('daemmen_ein', 'stellung_ein', 'bericht_ein',
                   'pv_gegenprobe', 'lernen_ein') as $fb_h) {
        $fb_cfg[$fb_h] = !empty($_POST[$fb_h]) ? 1 : 0;
    }
    $fb_modell = (isset($_POST['himmelsmodell']) && is_string($_POST['himmelsmodell']))
        ? $_POST['himmelsmodell'] : '';
    if (in_array($fb_modell, array('isotrop', 'hdkr'), true)) {
        $fb_cfg['himmelsmodell'] = $fb_modell;
    } elseif ($fb_modell !== '') {
        $fb_fehler[] = sprintf(fb_t('FEHLER.MODELL'), $fb_modell);
    }

    /* Beanstanden, nicht zurechtbiegen: eine Ausschaltschwelle ueber der
     * Einschaltschwelle waere keine Hysterese, sondern ein Flattern. */
    if ((int) $fb_cfg['schwelle_aus'] >= (int) $fb_cfg['schwelle_ein']) {
        $fb_fehler[] = sprintf(fb_t('FEHLER.SCHWELLEN'),
                               (int) $fb_cfg['schwelle_aus'], (int) $fb_cfg['schwelle_ein']);
    }
    if ((int) $fb_cfg['gewicht_raum'] + (int) $fb_cfg['gewicht_tag'] === 0) {
        $fb_fehler[] = fb_t('FEHLER.GEWICHTE_NULL');
    }
    /* BEANSTANDEN, ABER SPEICHERN, WAS IN ORDNUNG IST.
     *
     * Hier stand ein "if (!$fb_fehler)" um das Speichern. Wirkung, gemessen:
     * ein einziger Wert ausserhalb seiner Grenzen - etwa der Glas-Beiwert
     * auf 99 statt hoechstens 50 - verwarf ALLE vierzehn Felder dieses
     * Formulars, auch die dreizehn richtigen. Der Meldungstext daneben
     * versprach dabei, dass "der bisherige Wert" stehen bleibe: gemeint war
     * einer, stehen blieben alle.
     *
     * Der Fensterhandler weiter unten macht es seit jeher richtig und
     * schreibt das Prinzip sogar als Kommentar hin. Zwei Handler derselben
     * Seite mit zwei Haltungen sind einer zu viel.
     *
     * Moeglich ist das, weil $fb_zahl() bei einer Beanstandung null
     * zurueckgibt und der bisherige Wert dann unangetastet bleibt - das
     * Feld wird also uebergangen, nicht zurechtgebogen. */
    {
        $fb_neu_cfg = fb_config_richten($fb_cfg);
        if (fb_config_speichern($fb_neu_cfg)) {
            fb_config_freigeben($fb_sperre); $fb_sperre = null;
            if (!$fb_fehler) { $fb_meldungen[] = fb_t('ALLG.GESPEICHERT'); }
            else { $fb_meldungen[] = fb_t('ALLG.TEILWEISE'); }
            fb_log('Standort und Modellwerte gespeichert.');
            /* Neu rechnen, aber NUR wenn sich wirklich etwas geaendert hat.
             *
             * Der Anwender soll die Wirkung seiner Aenderung sehen, ohne
             * fuenf Minuten zu warten - das ist der Grund fuer den Aufruf.
             * Ein unveraendert abgeschicktes Formular ist aber keine
             * Aenderung, und ein Lauf ohne Anlass schreibt stand.json neu
             * und schickt eine MQTT-Salve. Gefunden hat das der
             * Wirkungstest: er meldete, dass ein unveraendertes Speichern
             * den Sonnenazimut in stand.json verschiebt. */
            if ($fb_neu_cfg !== $fb_vorher) { fb_lauf(true); }
        } else {
            $fb_fehler[] = fb_t('FEHLER.SPEICHERN');
        }
    }
    fb_config_freigeben($fb_sperre);
    $fb_tab = 'tab-settings';
}

/* ---------------- Speichern: die Fenster ----------------
 * Fasst NUR die Fenstertabelle an. Standort, Modellwerte und MQTT haben
 * ihre eigenen Formulare und ihre eigenen Handler. */
if ($fb_post && isset($_POST['speichern_fenster'])) {
    $fb_sperre = fb_config_sperre();      // Begruendung beim Modell-Handler
    $fb_cfg = fb_config();
    $fb_vorher = $fb_cfg;          // fuer den Vergleich, siehe unten
    $fb_feld = function ($name, $i) use ($fb_sauber) {
        $a = isset($_POST[$name]) ? (array) $_POST[$name] : array();
        return isset($a[$i]) ? $fb_sauber($a[$i]) : '';
    };
    $fb_neu = array();
    $fb_kuerzel_gesehen = array();
    /* Nur EIN Fall blockiert das Speichern, und er wird hier als Merker
     * gefuehrt - nicht durch Nachsehen im Meldungstext. Ein Blockieren, das
     * an einer Zeichenkette haengt, faellt beim naechsten Uebersetzen
     * lautlos aus. */
    $fb_blockiert = false;
    for ($fb_i = 0; $fb_i < FB_FENSTER; $fb_i++) {
        $f = fb_fenster_vorgabe();
        $roh_kuerzel  = $fb_roh('f_kuerzel', $fb_i);
        $f['kuerzel'] = fb_kuerzel_richten($roh_kuerzel);
        $f['name']    = $fb_feld('f_name', $fb_i);
        $roh_raum     = $fb_roh('f_raum', $fb_i);
        $f['raum']    = fb_raumschluessel_richten($roh_raum);
        $f['horizont'] = $fb_feld('f_horizont', $fb_i);
        $f['aktiv']     = !empty($_POST['f_aktiv'][$fb_i]) ? 1 : 0;
        $f['raumwerte'] = !empty($_POST['f_raumwerte'][$fb_i]) ? 1 : 0;
        $f['daemmen']   = !empty($_POST['f_daemmen'][$fb_i]) ? 1 : 0;

        $bez = fb_t('EINST.FENSTER') . ' ' . ($fb_i + 1);
        foreach (array(
            'azimut'    => array('f_azimut', 0, 359, 'EINST.L_AZIMUT', 0),
            'neigung'   => array('f_neigung', 0, 90, 'EINST.L_NEIGUNG', 0),
            'flaeche'   => array('f_flaeche', 0.1, 30.0, 'EINST.L_FLAECHE', 2),
            'gwert'     => array('f_gwert', 5, 95, 'EINST.L_GWERT', 0),
            'traegheit' => array('f_traegheit', 0, 50, 'EINST.L_TRAEGHEIT', 0),
            'blend_hoehe'  => array('f_blend_h', 0, 60, 'EINST.L_BLEND_HOEHE', 0),
            'blend_winkel' => array('f_blend_w', 5, 89, 'EINST.L_BLEND_WINKEL', 0),
        ) as $fb_f => $fb_d) {
            $w = $fb_zahl($fb_feld($fb_d[0], $fb_i), $fb_d[1], $fb_d[2],
                          $bez . ' / ' . fb_t($fb_d[3]), $fb_d[4]);
            if ($w !== null) { $f[$fb_f] = $w; }
        }

        $leer = ($f['kuerzel'] === '' && $f['name'] === '' && $roh_raum === '');
        if (!$leer) {
            if ($f['kuerzel'] === '') {
                $fb_fehler[] = sprintf(fb_t('FEHLER.KUERZEL_FEHLT'), $fb_i + 1);
            } elseif ($f['kuerzel'] !== $roh_kuerzel) {
                /* MELDEN, nicht stillschweigend uebernehmen: aus dem
                 * Kuerzel wird der Name eines virtuellen Eingangs und ein
                 * MQTT-Zweig. Wer nicht erfaehrt, dass Zeichen weggefallen
                 * sind, sucht den Wert spaeter unter dem falschen Namen. */
                $fb_fehler[] = sprintf(fb_t('FEHLER.KUERZEL_GEAENDERT'),
                                       $fb_i + 1, $roh_kuerzel, $f['kuerzel']);
            }
            if ($f['kuerzel'] !== '') {
                if (isset($fb_kuerzel_gesehen[strtoupper($f['kuerzel'])])) {
                    /* Zwei gleiche Kuerzel ergaeben zwei virtuelle Eingaenge
                     * mit demselben Titel und zwei MQTT-Themen, die einander
                     * ueberschreiben - lautlos. */
                    $fb_fehler[] = sprintf(fb_t('FEHLER.KUERZEL_DOPPELT'),
                                           $fb_i + 1, $f['kuerzel'],
                                           $fb_kuerzel_gesehen[strtoupper($f['kuerzel'])]);
                    $fb_blockiert = true;
                } else {
                    $fb_kuerzel_gesehen[strtoupper($f['kuerzel'])] = $fb_i + 1;
                }
            }
            if (!empty($_POST['f_raumwerte'][$fb_i]) && $f['raum'] === '') {
                $fb_fehler[] = sprintf(fb_t('FEHLER.RAUM_FEHLT'), $fb_i + 1);
            }
            /* Auch der Raumschluessel wird zurechtgerueckt - und das wurde
             * bis zum ersten Prueflauf gar nicht gemeldet. Aus ihm entsteht
             * der Messwertname 'ist.<raum>' und der Titel des virtuellen
             * Ausgangs; wer nicht erfaehrt, dass "Bad Zwei" zu "badzwei"
             * geworden ist, traegt in Loxone den falschen Namen ein. */
            if ($f['raum'] !== '' && $f['raum'] !== $roh_raum) {
                $fb_fehler[] = sprintf(fb_t('FEHLER.RAUM_GEAENDERT'),
                                       $fb_i + 1, $roh_raum, $f['raum']);
            }
            list($fb_punkte, $fb_unlesbar) = fb_horizont_lesen($f['horizont']);
            if ($fb_unlesbar) {
                $fb_fehler[] = sprintf(fb_t('FEHLER.HORIZONT'), $fb_i + 1,
                                       implode(' | ', $fb_unlesbar));
            }
        }
        $fb_neu[$fb_i] = $f;
    }
    /* Beanstandungen melden, aber speichern, was in Ordnung ist - sonst
     * tippt der Benutzer wegen einer Zeile alles noch einmal. Blockiert
     * wird nur, wenn ein Kuerzel doppelt vorkommt: dann gingen in Loxone
     * und ueber MQTT Werte lautlos verloren, weil zwei Fenster denselben
     * Namen truegen. */
    if (!$fb_blockiert) {
        $fb_cfg['fenster'] = $fb_neu;
        $fb_neu_cfg = fb_config_richten($fb_cfg);
        if (fb_config_speichern($fb_neu_cfg)) {
            fb_config_freigeben($fb_sperre); $fb_sperre = null;
            $fb_meldungen[] = fb_t('ALLG.GESPEICHERT');
            fb_log('Fensterliste gespeichert (' . count(fb_fenster()) . ' belegte Zeilen).');
            /* Nur bei einer echten Aenderung neu rechnen - Begruendung beim
             * Modell-Handler weiter oben. */
            if ($fb_neu_cfg !== $fb_vorher) { fb_lauf(true); }
        } else {
            $fb_fehler[] = fb_t('FEHLER.SPEICHERN');
        }
    }
    fb_config_freigeben($fb_sperre);
    $fb_tab = 'tab-settings';
}

/* ---------------- Speichern: die Raumflaechen ----------------
 *
 * Eigenes Formular, eigener Handler - wie bei allem anderen auch. Die
 * Raumflaeche gehoert weder zum Fenster (mehrere Fenster teilen sich einen
 * Raum) noch zum Modell (sie ist eine Eigenschaft des Hauses), und ein
 * gemeinsames Formular haette bedeutet, dass ein Tippfehler in der einen
 * Tabelle die andere mit abweist.
 *
 * Angeboten werden nur die Raeume, die auch BENUTZT werden. Eine Liste
 * aller 23 Raeume aus der Projektdatei waere laenger und weniger nuetzlich:
 * eine Flaeche fuer die Garage aendert an keinem Urteil etwas. */
if ($fb_post && isset($_POST['speichern_raeume'])) {
    $fb_sperre = fb_config_sperre();
    $fb_cfg = fb_config();
    $fb_vorher = $fb_cfg;
    $fb_raeume_neu = array();
    $fb_roh_qm = isset($_POST['r_qm']) ? (array) $_POST['r_qm'] : array();
    foreach ($fb_roh_qm as $fb_rr => $fb_qq) {
        $fb_rr = fb_raumschluessel_richten(is_string($fb_rr) ? $fb_rr : '');
        if ($fb_rr === '') { continue; }
        $fb_qq = is_string($fb_qq) ? trim($fb_qq) : '';
        /* LEER heisst "unbekannt" und ist etwas anderes als 0. Wer das Feld
         * leert, will die Vorgabe zurueck - und die Begruendungszeile sagt
         * dann wieder "geschaetzt". Eine 0 dagegen waere ein Raum ohne
         * Ausdehnung, und der waere sofort voll. */
        if ($fb_qq === '') { continue; }
        $fb_w = $fb_zahl($fb_qq, 0.1, 1000.0,
                         fb_t('EINST.L_RAUMFLAECHE') . ' ' . $fb_rr, 1);
        if ($fb_w !== null) { $fb_raeume_neu[$fb_rr] = $fb_w; }
    }
    $fb_cfg['raumflaechen'] = $fb_raeume_neu;
    $fb_neu_cfg = fb_config_richten($fb_cfg);
    if (fb_config_speichern($fb_neu_cfg)) {
        fb_config_freigeben($fb_sperre); $fb_sperre = null;
        $fb_meldungen[] = fb_t('ALLG.GESPEICHERT');
        fb_log('Raumflaechen gespeichert (' . count($fb_neu_cfg['raumflaechen']) . ' Raeume).');
        if ($fb_neu_cfg !== $fb_vorher) { fb_lauf(true); }
    } else {
        $fb_fehler[] = fb_t('FEHLER.SPEICHERN');
    }
    fb_config_freigeben($fb_sperre);
    $fb_tab = 'tab-settings';
}

/* ---------------- Fensterliste aus einer Projektdatei ----------------
 *
 * Die Ausrichtung aller Fenster steht bereits in der .Loxone-Datei, in Dir
 * und DirTol der AutoJalousie-Bausteine. Sie noch einmal von Hand
 * einzutippen ist nicht nur Arbeit, es ist eine Fehlerquelle: eine
 * vertauschte Ziffer im Azimut ist von aussen unsichtbar und verschiebt die
 * Beschattung eines Fensters um Stunden.
 *
 * VORGESCHLAGEN wird, nicht gesetzt. Die gelesene Liste landet in einer
 * Zwischendatei, wird angezeigt, und erst ein zweiter Knopf uebernimmt sie -
 * und auch dann nur in LEERE Zeilen. Wer schon Fenster eingerichtet hat,
 * verliert sie nicht. */
/* EIN Auswerter fuer BEIDE Wege.
 *
 * Die Datei kommt entweder ueber die Absendung oder aus einem Ordner auf
 * dem LoxBerry. Was danach mit ihrem Inhalt geschieht, ist beide Male
 * dasselbe - und gehoert deshalb an EINE Stelle. Zwei Kopien liefen
 * auseinander, sobald am Einlesen etwas geaendert wird, und der seltener
 * benutzte Weg faellt dabei monatelang nicht auf. */
$fb_projekt_auswerten = function ($inhalt) use (&$fb_meldungen, &$fb_fehler, $fb_p) {
    if ($inhalt === false || $inhalt === '') {
        $fb_fehler[] = fb_t('PROJEKT.LEER');
        return;
    }
    list($liste_p, $fehler_p) = fb_projekt_lesen($inhalt);
    /* Die Grundflaechen der Raeume kommen aus demselben Lesevorgang. Sie
     * sind NICHT die Fensterflaeche - die steht nirgends in der Datei -,
     * sondern die Groesse, an der der Bilanzteil haengt. */
    list($raum_p, $raum_doppelt) = fb_projekt_raeume($inhalt);
    if (!$liste_p) {
        $fb_fehler[] = sprintf(fb_t('PROJEKT.NICHTS'), fb_liste_kurz($fehler_p));
        return;
    }
    fb_json_schreiben($fb_p['datadir'] . '/vorschlag.json',
        array('zeit' => time(), 'liste' => $liste_p,
              'unlesbar' => $fehler_p, 'raeume' => $raum_p), 0644);
    $fb_meldungen[] = sprintf(fb_t('PROJEKT.GELESEN'),
        count($liste_p), count($fehler_p));
    if ($raum_p) {
        $fb_meldungen[] = sprintf(fb_t('PROJEKT.RAEUME_GELESEN'), count($raum_p));
    }
    if ($raum_doppelt) {
        /* MELDEN, NICHT WAEHLEN. "KG Vorrat Ost" und "KG Vorrat Nord"
         * ergeben denselben Raumschluessel; welche der beiden Flaechen
         * gemeint ist, weiss nur der Anwender. */
        $namen_d = array();
        foreach ($raum_doppelt as $rr => $tt) { $namen_d[] = $tt[0] . ' / ' . $tt[1]; }
        $fb_fehler[] = sprintf(fb_t('PROJEKT.RAEUME_DOPPELT'), fb_liste_kurz($namen_d));
    }
    if ($fehler_p) {
        $fb_fehler[] = sprintf(fb_t('PROJEKT.UNLESBAR'), fb_liste_kurz($fehler_p));
    }
};

/* ---------------- Der Weg AN DER ABSENDUNG VORBEI ----------------
 *
 * Der Anlass ist gemessen: PHP nimmt ab Werk 2 MB je Datei an, eine
 * .Loxone-Datei dieser Anlage ist 3 bis 4 MB gross, und das Plugin kann die
 * Grenze NICHT anheben - ini_set() gibt fuer upload_max_filesize und
 * post_max_size false zurueck (gemessen mit PHP 7.4.33 und 8.4.24). Selbst
 * wenn es ginge, waere es zu spaet: PHP weist die Absendung ab, bevor die
 * erste Zeile dieses Plugins laeuft.
 *
 * Wer die Datei stattdessen auf den LoxBerry LEGT, an dem kommt keine
 * dieser Grenzen mehr vor. Der Pfad wird dabei nie aus dem Formular
 * uebernommen: das Formular nennt Ordnernummer und Dateinamen, und beides
 * wird gegen die frisch gelesene Liste gehalten. Ein freies Pfadfeld waere
 * bequemer und waere ein Leseloch. */
if ($fb_post && isset($_POST['projekt_datei'])) {
    $fb_tab = 'tab-settings';
    $fb_o = isset($_POST['d_ordner']) && is_string($_POST['d_ordner'])
            ? (int) $_POST['d_ordner'] : -1;
    $fb_n = isset($_POST['d_name']) && is_string($_POST['d_name']) ? $_POST['d_name'] : '';
    $fb_d = ($fb_n === '') ? null : fb_projekt_datei_finden($fb_o, $fb_n);
    if ($fb_d === null) {
        $fb_fehler[] = fb_t('PROJEKT.DATEI_WEG');
    } else {
        /* memory_limit ist die EINZIGE Grenze, die auf diesem Weg noch gilt:
         * die Datei wird als Zeichenkette gelesen. Sie vorher zu pruefen ist
         * ehrlicher, als PHP mitten im Lesen abbrechen zu lassen - ein
         * Abbruch dort sieht aus wie eine kaputte Datei. Der Faktor 4 ist
         * gewaehlt und nicht gemessen: die Zeichenkette selbst, die Kopie
         * beim Zerlegen und etwas Luft. */
        $fb_gr = fb_grenzen();
        if ($fb_gr['memory_limit'][1] < $fb_d['groesse'] * 4) {
            $fb_fehler[] = sprintf(fb_t('PROJEKT.SPEICHER'),
                $fb_d['name'], $fb_d['groesse'] / 1048576.0,
                $fb_gr['memory_limit'][0]);
        }
        $fb_inhalt = fb_holen_datei($fb_d['pfad']);
        if ($fb_inhalt === false) {
            $fb_fehler[] = sprintf(fb_t('PROJEKT.NICHT_LESBAR'), $fb_d['pfad']);
        } else {
            $fb_meldungen[] = sprintf(fb_t('PROJEKT.AUS_ORDNER'),
                $fb_d['name'], $fb_d['ordnerpfad'], $fb_d['groesse'] / 1048576.0);
            $fb_projekt_auswerten($fb_inhalt);
        }
    }
}

if ($fb_post && isset($_POST['projekt_lesen'])) {
    $fb_tab = 'tab-settings';
    if (!isset($_FILES['projekt']) || !is_array($_FILES['projekt'])) {
        $fb_fehler[] = fb_t('PROJEKT.KEINE_DATEI');
    } elseif ((int) $_FILES['projekt']['error'] !== UPLOAD_ERR_OK) {
        /* Den Grund NENNEN - und den AUSWEG gleich mit. Eine Projektdatei
         * ist mehrere Megabyte gross, und die Vorgabe von PHP fuer
         * upload_max_filesize ist 2M; das ist der wahrscheinlichste Fall.
         * Ohne die Zahlen sucht der Anwender an der falschen Stelle, und
         * ohne den Ordner haelt er die Sache fuer erledigt, sobald er
         * merkt, dass er dafuer an die php.ini muesste. */
        $fb_ord = fb_projekt_ordner();
        $fb_fehler[] = sprintf(fb_t('PROJEKT.UPLOAD_FEHLER'),
            (int) $_FILES['projekt']['error'],
            (string) ini_get('upload_max_filesize'),
            (string) ini_get('post_max_size'),
            isset($fb_ord[0]) ? $fb_ord[0] : '');
    } else {
        $fb_roh_projekt = fb_holen_datei($_FILES['projekt']['tmp_name']);
        $fb_projekt_auswerten($fb_roh_projekt);
    }
}

if ($fb_post && isset($_POST['projekt_uebernehmen'])) {
    $fb_tab = 'tab-settings';
    $fb_sperre = fb_config_sperre();
    $fb_v = fb_json_lesen($fb_p['datadir'] . '/vorschlag.json');
    $fb_cfg = fb_config();
    if (empty($fb_v['liste'])) {
        $fb_fehler[] = fb_t('PROJEKT.NICHTS_ZU_UEBERNEHMEN');
    } else {
        /* Nur in LEERE Zeilen schreiben, und nur Kuerzel, die es noch nicht
         * gibt. Ein Uebernehmen, das bestehende Zeilen ueberschreibt, waere
         * ein Loeschwerkzeug mit Nebenwirkung. */
        $fb_belegt = array();
        foreach ($fb_cfg['fenster'] as $fb_ff) {
            if ($fb_ff['kuerzel'] !== '') { $fb_belegt[strtoupper($fb_ff['kuerzel'])] = true; }
        }
        $fb_gesetzt = 0; $fb_uebersprungen = 0;
        foreach ($fb_v['liste'] as $fb_x) {
            $fb_k = fb_kuerzel_richten($fb_x['kuerzel']);
            if ($fb_k === '' || isset($fb_belegt[strtoupper($fb_k)])) {
                $fb_uebersprungen++;
                continue;
            }
            $fb_frei = -1;
            for ($fb_i = 0; $fb_i < FB_FENSTER; $fb_i++) {
                if ($fb_cfg['fenster'][$fb_i]['kuerzel'] === '') { $fb_frei = $fb_i; break; }
            }
            if ($fb_frei < 0) { $fb_uebersprungen++; continue; }
            $fb_cfg['fenster'][$fb_frei]['kuerzel'] = $fb_k;
            $fb_cfg['fenster'][$fb_frei]['name']    = (string) $fb_x['titel'];
            $fb_cfg['fenster'][$fb_frei]['azimut']  = (int) $fb_x['azimut'];
            $fb_cfg['fenster'][$fb_frei]['raum']    = fb_raumschluessel_richten($fb_x['raum']);
            /* UND DEN HAKEN MITSETZEN.
             *
             * Ohne diese Zeile blieb er auf 0 - und das war ein stiller
             * Fehler der schlimmsten Sorte. Eine LEERE Fensterzeile hat
             * keinen Raum, und fb_config_richten() setzt deshalb ihren
             * Haken zu Recht auf 0. Das Uebernehmen trug danach den Raum
             * ein, den Haken aber nicht - und das Urteil rechnete fuer alle
             * 25 Fenster ohne Raumwerte weiter. Gemeldet wurde dabei
             * NICHTS: es fehlte ja kein Messwert, OK stand auf 1, und nur
             * der Begruendungssatz sagte leise "ohne Raumwerte gerechnet".
             *
             * Gefunden hat das der erste Vollversuch mit der echten
             * Projektdatei, nicht das Lesen. */
            $fb_cfg['fenster'][$fb_frei]['raumwerte'] =
                (fb_raumschluessel_richten($fb_x['raum']) !== '') ? 1 : 0;
            $fb_belegt[strtoupper($fb_k)] = true;
            $fb_gesetzt++;
        }
        /* DIE RAUMFLAECHEN, und zwar nur fuer Raeume, die auch benutzt
         * werden - und nur dort, wo noch keine steht.
         *
         * Nicht ueberschreiben ist hier wichtiger als vollstaendig sein:
         * wer eine Flaeche von Hand korrigiert hat, weil das Zimmer eine
         * Dachschraege hat, soll sie beim naechsten Einlesen nicht wieder
         * verlieren. Das zweite Uebernehmen aendert deshalb nichts mehr -
         * genau wie bei den Fensterzeilen. */
        $fb_qm_gesetzt = 0;
        if (!empty($fb_v['raeume']) && is_array($fb_v['raeume'])) {
            $fb_benutzt = array();
            foreach ($fb_cfg['fenster'] as $fb_ff) {
                if ($fb_ff['raum'] !== '') { $fb_benutzt[$fb_ff['raum']] = true; }
            }
            foreach ($fb_v['raeume'] as $fb_rr => $fb_qq) {
                $fb_rr = fb_raumschluessel_richten($fb_rr);
                if ($fb_rr === '' || !isset($fb_benutzt[$fb_rr])) { continue; }
                if (isset($fb_cfg['raumflaechen'][$fb_rr])
                    && (float) $fb_cfg['raumflaechen'][$fb_rr] > 0.0) { continue; }
                $fb_cfg['raumflaechen'][$fb_rr] = (float) $fb_qq;
                $fb_qm_gesetzt++;
            }
        }
        if ($fb_gesetzt > 0 && fb_config_speichern(fb_config_richten($fb_cfg))) {
            $fb_meldungen[] = sprintf(fb_t('PROJEKT.UEBERNOMMEN'),
                $fb_gesetzt, $fb_uebersprungen);
            if ($fb_qm_gesetzt > 0) {
                $fb_meldungen[] = sprintf(fb_t('PROJEKT.RAEUME_UEBERNOMMEN'), $fb_qm_gesetzt);
            }
            fb_log('Fensterliste aus einer Projektdatei uebernommen: '
                 . $fb_gesetzt . ' Zeilen.');
            @unlink($fb_p['datadir'] . '/vorschlag.json');
        } elseif ($fb_gesetzt === 0) {
            $fb_fehler[] = sprintf(fb_t('PROJEKT.NICHTS_NEU'), $fb_uebersprungen);
        } else {
            $fb_fehler[] = fb_t('FEHLER.SPEICHERN');
        }
    }
    fb_config_freigeben($fb_sperre);
}

/* ---------------- Was waere wenn: rechnen ohne zu speichern ----------------
 *
 * Wer die Tagesgrenze von 20 auf 22 setzen will, musste bisher SPEICHERN -
 * und damit die laufende Anlage veraendern -, um zu sehen, was daraus wird.
 * Hier wird mit den tatsaechlichen Messwerten gerechnet und NICHTS
 * geschrieben. */
if ($fb_post && isset($_POST['wasware'])) {
    $fb_tab = 'tab-test';
    $fb_probe_cfg = fb_config();
    foreach (array('tagesgrenze', 'spreizung_tag', 'spreizung_raum', 'gewicht_raum',
                   'gewicht_tag', 'gewicht_bilanz', 'bilanz_voll_qm', 'gewicht_morgen',
                   'schwelle_ein', 'schwelle_aus', 'e_ref') as $fb_k) {
        if (isset($_POST['w_' . $fb_k]) && is_string($_POST['w_' . $fb_k])
            && trim($_POST['w_' . $fb_k]) !== ''
            && is_numeric(str_replace(',', '.', trim($_POST['w_' . $fb_k])))) {
            $fb_probe_cfg[$fb_k] = (int) round((float) str_replace(',', '.',
                trim($_POST['w_' . $fb_k])));
        }
    }
    if (isset($_POST['w_himmelsmodell']) && is_string($_POST['w_himmelsmodell'])
        && in_array($_POST['w_himmelsmodell'], array('isotrop', 'hdkr'), true)) {
        $fb_probe_cfg['himmelsmodell'] = $_POST['w_himmelsmodell'];
    }
    $fb_probe_cfg = fb_config_richten($fb_probe_cfg);
    $fb_testausgabe = fb_test_wasware($fb_probe_cfg);
}

/* ---------------- Speichern: MQTT ---------------- */
if ($fb_post && isset($_POST['speichern_mqtt'])) {
    $fb_sperre = fb_config_sperre();      // Begruendung beim Modell-Handler
    $fb_cfg = fb_config();
    $fb_cfg['mqtt_ein'] = !empty($_POST['mqtt_ein']) ? 1 : 0;
    /* Gegen den ROHEN Wert pruefen, nicht gegen den gesaeuberten. Sonst
     * wird "haus fenster" beanstandet und "haus\"fenster" stillschweigend
     * zu "hausfenster" - dieselbe Eingabeart, zweierlei Verhalten. */
    $fb_thema_roh = (isset($_POST['mqtt_topic']) && is_string($_POST['mqtt_topic']))
        ? trim($_POST['mqtt_topic']) : '';
    $fb_thema = trim(strtolower($fb_thema_roh), '/');
    if ($fb_thema === '') {
        $fb_cfg['mqtt_topic'] = 'fenster';
    } elseif (!preg_match('#^[a-z0-9_\-/]+$#D', $fb_thema)) {
        // Ein Thema mit + oder # ist ein Filtermuster und als Ziel unbrauchbar.
        $fb_fehler[] = sprintf(fb_t('FEHLER.THEMA'), $fb_thema);
    } else {
        $fb_cfg['mqtt_topic'] = $fb_thema;
    }
    /* Auch hier wird gespeichert, was in Ordnung ist: ein unzulaessiges
     * Thema hat vorher den Haken "MQTT einschalten" mitgerissen, den der
     * Bediener im selben Formular gesetzt hatte - gemessen. Das Thema
     * bleibt in diesem Fall auf seinem alten Wert stehen. */
    if (fb_config_speichern($fb_cfg)) {
        if (!$fb_fehler) { $fb_meldungen[] = fb_t('ALLG.GESPEICHERT'); }
        else { $fb_meldungen[] = fb_t('ALLG.TEILWEISE'); }
        fb_log('MQTT-Einstellungen gespeichert.');
    } else {
        $fb_fehler[] = fb_t('FEHLER.SPEICHERN');
    }
    fb_config_freigeben($fb_sperre);
    $fb_tab = 'tab-mqtt';
}

/* ---------------- Neues Wortzeichen ---------------- */
if ($fb_post && isset($_POST['token_neu'])) {
    $fb_sperre = fb_config_sperre();      // Begruendung beim Modell-Handler
    $fb_cfg = fb_config();
    $fb_cfg['aktionstoken'] = fb_token_erzeugen();
    fb_config_speichern($fb_cfg);
    fb_config_freigeben($fb_sperre);
    $fb_merkmal = fb_formtoken();   // das Merkmal haengt daran und wechselt mit
    $fb_meldungen[] = fb_t('LOX.TOKEN_NEU_OK');
    fb_log('Neues Wortzeichen erzeugt.');
    $fb_tab = 'tab-loxone';
}

/* ---------------- Protokoll leeren ---------------- */
if ($fb_post && isset($_POST['log_leeren'])) {
    @file_put_contents($fb_p['log'], '');
    /* Den Merker der Wiederholungsbremse mit wegraeumen. Sonst schweigt die
     * naechste Ursachenmeldung, weil sie "schon einmal dagestanden hat" -
     * in einer Datei, die es nicht mehr gibt. */
    @unlink($fb_p['datadir'] . '/letzte_meldung.json');
    fb_log('Protokoll geleert.');
    $fb_meldungen[] = fb_t('LOG.GELEERT');
    $fb_tab = 'tab-log';
}

/* ---------------- Test ---------------- */
if ($fb_post && isset($_POST['test'])) {
    $fb_testausgabe = fb_test_ausfuehren(
        is_string($_POST['test']) ? $_POST['test'] : '');
    $fb_tab = 'tab-test';
}

/* ================= Werte fuer die Anzeige ================= */
$fb_cfg = fb_config();
$fb_stand = fb_stand();
$fb_liste = fb_fenster();
$fb_mqtt = fb_mqtt_zustand();
$fb_messwerte = fb_messwerte();
$fb_logzeilen = fb_log_ende($fb_p['log'], 400);
$fb_grund_nr = fb_grund_nr();
$fb_vorschlag = fb_json_lesen($fb_p['datadir'] . '/vorschlag.json');
$fb_ohne_standort = (abs((float) $fb_cfg['breite']) < 0.001
                     && abs((float) $fb_cfg['laenge']) < 0.001);

$fb_rahmen = class_exists('LBWeb', false);
if ($fb_rahmen) {
    LBWeb::lbheader(fb_t('ALLG.TITEL'), 'https://wiki.loxberry.de/', 'help.html');
}
?>
<style>
/* Hausstandard: eigener Behaelter, kein Schattenwurf, Reiter im Fluss */
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
          padding: 9px 18px; font-size: 0.95em; color: #444 !important; text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
/* Bedienelemente werden von jQuery Mobile umgebaut und bekommen einen eigenen
   Behaelter. Begrenzt man das Feld selbst, bleibt der Behaelter breit - man
   sieht ein schmales Feld in einem breiten weissen Kasten. Und beim
   Auswahlfeld liegt das unsichtbare <select> ueber dem Knopf und faengt die
   Klicks ab; wer es gestaltet, schiebt es weg. Deshalb wird ausschliesslich
   der Behaelter begrenzt. */
.sm-feld .ui-input-text, .sm-feld .ui-select, .sm-feld .ui-textinput { max-width: 520px; }
.sm-feld .ui-input-text input, .sm-feld .ui-input-text textarea { font-size: 0.95em; }
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa;
    border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
/* Eine Tabelle mit Eingabefeldern oder mit mehr als sechs Spalten kommt in
   einen eigenen Rollbehaelter. Ohne ihn ist auf einem schmalen Schirm die
   letzte Spalte unerreichbar - nicht bloss unbequem. */
.sm-breit { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px 0; }
.sm-breit .sm-tbl { margin: 0; min-width: 760px; }
.sm-mono { font-family: Consolas, "Courier New", monospace; background: #f0f0f0;
    padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.85em;
    overflow: auto; margin: 8px 0; white-space: pre-wrap; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
/* LoxBerry bringt jQuery Mobile mit. Das formatiert JEDES <button> mit eigenem
   Hintergrund UND eigenen Hover-Regeln. Ohne !important steht weisse Schrift
   auf hellgrauem Grund - und beim Ueberfahren weiss auf weiss. Die
   Hover-Farben unten sind kein Feinschliff, sondern Pflicht: fehlen sie, kommt
   der Hover-Zustand vom Rahmen und ist unlesbar. */
.sm-wrap .sm-knopfreihe .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    flex: 0 0 auto; min-width: 250px; text-align: center; display: inline-flex;
    align-items: center; justify-content: center; line-height: 1.25;
    padding: 10px 14px !important; border-radius: 6px !important;
    color: #fff !important; text-decoration: none !important; font-size: 0.92em;
    border: 0 !important; cursor: pointer; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: none !important;
    opacity: 1 !important; margin: 0 !important; width: auto !important; }
/* Statuskacheln — bewusst ein anderer Name als sm-knopfreihe.
   Beide zu verwechseln hat am 26.07.2026 die Statusanzeige zerlegt. */
.sm-kacheln { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
.sm-kachel { border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; min-width: 130px; }
.sm-kachel b { display: block; font-size: 1.35em; color: #33691e; }

.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
/* Eigene Hover- und Fokusfarben je Gruppe - sonst uebernimmt der Rahmen. */
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
/* Reiterinhalte: nur der aktive ist sichtbar.
   Ohne diese zwei Zeilen stehen alle fuenf Reiter untereinander.
   MIT ihnen und OHNE serverseitiges sm-active ist die Seite dagegen
   vollstaendig leer, sobald das Skript nicht laeuft. Die Klasse gehoert
   deshalb schon ins ausgelieferte HTML, siehe die Reiterleiste unten. */
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
/* Ein Auswahlfeld muss man als Auswahlfeld erkennen. Die Raute im SVG wird
   als %23 geschrieben: eine rohe Raute beendet in einer CSS-Adresse den Wert. */
.sm-wrap select {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath d='M1 1l6 6 6-6' fill='none' stroke='%234f7d17' stroke-width='2'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center;
    padding-right: 32px; cursor: pointer; }
.sm-tbl select { padding-right: 28px; background-position: right 7px center; }
/* Nachgetragene Definition: benutzt, aber in der Vorlage nicht gesetzt -
   wortgleich aus der Referenzimplementierung uebernommen. */
.sm-log { background: #1e1e1e; color: #ddd; font-family: monospace; font-size: 0.82em;
  padding: 10px; border-radius: 6px; max-height: 460px; overflow: auto; white-space: pre-wrap; }
</style>

<div class="sm-wrap">

<?php if (fb_sprache_fehlt()) { ?>
<!-- Bewusst fest im Quelltext: wenn diese Meldung noetig ist, kann fb_t()
     nichts uebersetzen. -->
<div class="sm-warnung"><b>Die Sprachdateien wurden nicht gefunden.</b>
  Unten stehen deshalb nur die Schl&uuml;ssel statt der Texte. Erwartet werden sie unter
  <span class="sm-mono">&lt;LoxBerry&gt;/templates/plugins/<?= fb_e($fb_p['plugin']) ?>/lang/</span>.
  Meist hilft ein erneutes Installieren des Plugins.</div>
<?php } ?>

<?php if ($fb_meldungen) { ?>
<div class="sm-hinweis"><?= implode('<br>', array_map('fb_e', $fb_meldungen)) ?></div>
<?php } ?>
<?php if ($fb_fehler) { ?>
<div class="sm-warnung"><b><?= fb_e(fb_t('ALLG.BEANSTANDUNG')) ?></b><br><?= implode('<br>', array_map('fb_e', $fb_fehler)) ?></div>
<?php } ?>
<?php if ($fb_ergaenzt) { ?>
<div class="sm-hinweis"><?= sprintf(fb_t('ALLG.ERGAENZT'), fb_e(implode(', ', $fb_ergaenzt))) ?></div>
<?php } ?>

<div class="sm-kacheln">
  <div class="sm-kachel"><?= fb_e(fb_t('ALLG.LETZTER_LAUF')) ?>
    <b class="<?= fb_alter() >= 0 && fb_alter() < 900 ? 'sm-an' : 'sm-aus' ?>"><?= fb_alter() < 0 ? '&ndash;' : (int) floor(fb_alter() / 60) ?></b>
    <span class="sm-hilfe"><?= fb_alter() < 0 ? fb_e(fb_t('ALLG.NIE')) : fb_e(fb_t('ALLG.MINUTEN_HER')) ?></span>
  </div>
  <div class="sm-kachel"><?= fb_e(fb_t('ALLG.FENSTER')) ?>
    <b><?= count($fb_liste) ?></b>
    <span class="sm-hilfe"><?= fb_e(fb_t('ALLG.EINGERICHTET')) ?></span>
  </div>
  <div class="sm-kachel"><?= fb_e(fb_t('ALLG.BESCHATTEN')) ?>
    <b><?= isset($fb_stand['beschatten_anzahl']) ? (int) $fb_stand['beschatten_anzahl'] : 0 ?></b>
    <span class="sm-hilfe"><?= fb_e(fb_t('ALLG.GERADE')) ?></span>
  </div>
  <div class="sm-kachel"><?= fb_e(fb_t('ALLG.SONNE')) ?>
    <b><?= isset($fb_stand['sonne_hoehe']) ? (int) round($fb_stand['sonne_hoehe']) : 0 ?>&deg;</b>
    <span class="sm-hilfe"><?= isset($fb_stand['sonne_azimut']) ? fb_e(sprintf(fb_t('ALLG.AZIMUT'), (int) round($fb_stand['sonne_azimut']))) : '&ndash;' ?></span>
  </div>
  <div class="sm-kachel"><?= fb_e(fb_t('ALLG.STRAHLUNG')) ?>
    <b><?= isset($fb_stand['strahlung']) && $fb_stand['strahlung'] >= 0 ? (int) round($fb_stand['strahlung']) : '&ndash;' ?></b>
    <span class="sm-hilfe">W/m&sup2;</span>
  </div>
  <div class="sm-kachel"><?= fb_e(fb_t('ALLG.SAISON')) ?>
    <b class="<?= isset($fb_stand['saison']) && (int) $fb_stand['saison'] < 0 ? 'sm-aus' : 'sm-an' ?>"><?= isset($fb_stand['saison']) ? (int) $fb_stand['saison'] : 0 ?></b>
    <span class="sm-hilfe"><?= fb_e(fb_t('ALLG.SAISON_HILFE')) ?></span>
  </div>
</div>

<!-- Reiterleiste: echte Links, JavaScript faengt den Klick ab. So bleibt jeder
     Reiter verlinkbar und die Seite ohne Skript bedienbar. Welcher Reiter
     offen ist, entscheidet der SERVER. Ausgeschrieben, nicht erzeugt. -->
<div class="sm-tabs">
	<a class="sm-tab<?= $fb_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-ziel="tab-settings"
	   href="index.php?form=settings"><?= fb_e(fb_t('REITER.EINSTELLUNGEN')) ?></a>
	<a class="sm-tab<?= $fb_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" data-ziel="tab-mqtt"
	   href="index.php?form=mqtt">MQTT</a>
	<a class="sm-tab<?= $fb_tab === 'tab-loxone' ? ' sm-active' : '' ?>" data-ziel="tab-loxone"
	   href="index.php?form=loxone"><?= fb_e(fb_t('REITER.LOXONE')) ?></a>
	<a class="sm-tab<?= $fb_tab === 'tab-test' ? ' sm-active' : '' ?>" data-ziel="tab-test"
	   href="index.php?form=test"><?= fb_e(fb_t('REITER.TEST')) ?></a>
	<a class="sm-tab<?= $fb_tab === 'tab-log' ? ' sm-active' : '' ?>" data-ziel="tab-log"
	   href="index.php?form=log"><?= fb_e(fb_t('REITER.LOG')) ?></a>
</div>

<!-- ================= Reiter: Einstellungen ================= -->
<div class="sm-seite<?= $fb_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">

<h2><?= fb_e(fb_t('EINST.H_LAGE')) ?></h2>
<div class="sm-step"><?= fb_t('EINST.LAGE_ERKLAERUNG') ?></div>

<?php if ($fb_ohne_standort) { ?>
<div class="sm-warnung"><?= fb_t('EINST.KEIN_STANDORT') ?></div>
<?php } ?>
<?php if (!empty($fb_stand['fehlend'])) { ?>
<div class="sm-warnung"><?= sprintf(fb_t('EINST.MESSWERTE_FEHLEN'),
      fb_e(implode(', ', $fb_stand['fehlend'])), (int) $fb_cfg['hoechstalter']) ?></div>
<?php } ?>

<?php if (!empty($fb_stand['fenster'])) { ?>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= fb_e(fb_t('TAB.KUERZEL')) ?></th><th><?= fb_e(fb_t('TAB.NAME')) ?></th>
    <th><?= fb_e(fb_t('TAB.URTEIL')) ?></th>
<?php if ((int) $fb_cfg['vorschau'] > 0) { ?>
    <th><?= fb_e(sprintf(fb_t('TAB.URTEIL30'), (int) round($fb_cfg['vorschau'] / 60))) ?></th>
<?php } ?>
    <th><?= fb_e(fb_t('TAB.BESCHATTEN')) ?></th>
    <th><?= fb_e(fb_t('TAB.GRUND')) ?></th><th><?= fb_e(fb_t('TAB.GLAS')) ?></th>
    <th><?= fb_e(fb_t('TAB.WATT')) ?></th><th><?= fb_e(fb_t('TAB.WH')) ?></th>
    <th><?= fb_e(fb_t('TAB.BEGRUENDUNG')) ?></th></tr>
<?php foreach ($fb_stand['fenster'] as $fb_nr => $fb_ee) { ?>
<tr>
  <td><span class="sm-mono"><?= fb_e($fb_ee['kuerzel']) ?></span></td>
  <td><?= fb_e($fb_ee['name']) ?></td>
  <td><b class="<?= (int) $fb_ee['urteil'] < 0 ? 'sm-aus' : 'sm-an' ?>"><?= (int) $fb_ee['urteil'] > 0 ? '+' : '' ?><?= (int) $fb_ee['urteil'] ?></b></td>
<?php if ((int) $fb_cfg['vorschau'] > 0) { ?>
  <td><?= isset($fb_ee['urteil30']) ? ((int) $fb_ee['urteil30'] > 0 ? '+' : '') . (int) $fb_ee['urteil30'] : '&ndash;' ?><?= !empty($fb_ee['beschatten30']) ? ' *' : '' ?></td>
<?php } ?>
  <td><?= $fb_ee['beschatten'] ? '<b class="sm-aus">' . fb_e(fb_t('ALLG.JA')) . '</b>' : fb_e(fb_t('ALLG.NEIN')) ?>
<?php if (!empty($fb_ee['blendung'])) { ?><br><span class="sm-hilfe"><?= fb_e(fb_t('TAB.BLENDET')) ?></span><?php } ?>
<?php if (!empty($fb_ee['daemmen'])) { ?><br><span class="sm-hilfe"><?= fb_e(fb_t('TAB.DAEMMT')) ?></span><?php } ?>
<?php if (isset($fb_ee['gefahren']) && (int) $fb_ee['gefahren'] === 0) { ?><br><span class="sm-aus"><?= fb_e(sprintf(fb_t('TAB.NICHT_GEFAHREN'), (int) $fb_ee['stellung'])) ?></span><?php } ?>
  </td>
  <td><?= fb_e(fb_t('GRUND.' . strtoupper($fb_ee['grund']))) ?>
      <br><span class="sm-hilfe"><?= (int) $fb_ee['grundnr'] ?></span></td>
  <td><?= (int) $fb_ee['glas'] ?></td>
  <td><?= (int) $fb_ee['watt'] ?></td>
  <td><?= isset($fb_ee['wh']) ? (int) $fb_ee['wh'] : 0 ?></td>
  <td><span class="sm-hilfe"><?= fb_e($fb_ee['begruendung']) ?></span></td>
</tr>
<?php } ?>
</table>
</div>
<?php } else { ?>
<div class="sm-hinweis"><?= fb_t('EINST.NOCH_NICHTS') ?></div>
<?php } ?>

<h2><?= fb_e(fb_t('EINST.H_ORT')) ?></h2>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="fmt" value="<?= fb_e($fb_merkmal) ?>">
<div class="sm-step"><?= fb_t('EINST.ORT_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label for="fb_breite"><?= fb_e(fb_t('EINST.L_BREITE')) ?></label>
  <input data-role="none" type="text" id="fb_breite" name="breite" value="<?= fb_e($fb_cfg['breite']) ?>">
</div>
<div class="sm-feld">
  <label for="fb_laenge"><?= fb_e(fb_t('EINST.L_LAENGE')) ?></label>
  <input data-role="none" type="text" id="fb_laenge" name="laenge" value="<?= fb_e($fb_cfg['laenge']) ?>">
  <p class="sm-hilfe"><?= fb_t('EINST.H_ORT') ?></p>
</div>

<h2><?= fb_e(fb_t('EINST.H_MODELL')) ?></h2>
<div class="sm-step"><?= fb_t('EINST.MODELL_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label for="fb_tagesgrenze"><?= fb_e(fb_t('EINST.L_TAGESGRENZE')) ?></label>
  <input data-role="none" type="text" id="fb_tagesgrenze" name="tagesgrenze" value="<?= (int) $fb_cfg['tagesgrenze'] ?>">
  <p class="sm-hilfe"><?= fb_t('EINST.H_TAGESGRENZE') ?></p>
</div>
<div class="sm-feld">
  <label for="fb_spreizung_tag"><?= fb_e(fb_t('EINST.L_SPREIZUNG_TAG')) ?></label>
  <input data-role="none" type="text" id="fb_spreizung_tag" name="spreizung_tag" value="<?= (int) $fb_cfg['spreizung_tag'] ?>">
</div>
<div class="sm-feld">
  <label for="fb_spreizung_raum"><?= fb_e(fb_t('EINST.L_SPREIZUNG_RAUM')) ?></label>
  <input data-role="none" type="text" id="fb_spreizung_raum" name="spreizung_raum" value="<?= (int) $fb_cfg['spreizung_raum'] ?>">
  <p class="sm-hilfe"><?= fb_t('EINST.H_SPREIZUNG') ?></p>
</div>
<div class="sm-feld">
  <label for="fb_gewicht_raum"><?= fb_e(fb_t('EINST.L_GEWICHT_RAUM')) ?></label>
  <input data-role="none" type="text" id="fb_gewicht_raum" name="gewicht_raum" value="<?= (int) $fb_cfg['gewicht_raum'] ?>">
</div>
<div class="sm-feld">
  <label for="fb_gewicht_tag"><?= fb_e(fb_t('EINST.L_GEWICHT_TAG')) ?></label>
  <input data-role="none" type="text" id="fb_gewicht_tag" name="gewicht_tag" value="<?= (int) $fb_cfg['gewicht_tag'] ?>">
  <p class="sm-hilfe"><?= fb_t('EINST.H_GEWICHTE') ?></p>
</div>
<div class="sm-feld">
  <label for="fb_schwelle_ein"><?= fb_e(fb_t('EINST.L_SCHWELLE_EIN')) ?></label>
  <input data-role="none" type="text" id="fb_schwelle_ein" name="schwelle_ein" value="<?= (int) $fb_cfg['schwelle_ein'] ?>">
</div>
<div class="sm-feld">
  <label for="fb_schwelle_aus"><?= fb_e(fb_t('EINST.L_SCHWELLE_AUS')) ?></label>
  <input data-role="none" type="text" id="fb_schwelle_aus" name="schwelle_aus" value="<?= (int) $fb_cfg['schwelle_aus'] ?>">
  <p class="sm-hilfe"><?= fb_t('EINST.H_SCHWELLEN') ?></p>
</div>
<div class="sm-feld">
  <label for="fb_e_ref"><?= fb_e(fb_t('EINST.L_E_REF')) ?></label>
  <input data-role="none" type="text" id="fb_e_ref" name="e_ref" value="<?= (int) $fb_cfg['e_ref'] ?>">
  <p class="sm-hilfe"><?= fb_t('EINST.H_E_REF') ?></p>
</div>
<div class="sm-feld">
  <label for="fb_albedo"><?= fb_e(fb_t('EINST.L_ALBEDO')) ?></label>
  <input data-role="none" type="text" id="fb_albedo" name="albedo" value="<?= (int) $fb_cfg['albedo'] ?>">
</div>
<div class="sm-feld">
  <label for="fb_iam"><?= fb_e(fb_t('EINST.L_IAM')) ?></label>
  <input data-role="none" type="text" id="fb_iam" name="iam_b0" value="<?= (int) $fb_cfg['iam_b0'] ?>">
  <p class="sm-hilfe"><?= fb_t('EINST.H_IAM') ?></p>
</div>
<div class="sm-feld">
  <label for="fb_hoechstalter"><?= fb_e(fb_t('EINST.L_HOECHSTALTER')) ?></label>
  <input data-role="none" type="text" id="fb_hoechstalter" name="hoechstalter" value="<?= (int) $fb_cfg['hoechstalter'] ?>">
  <p class="sm-hilfe"><?= fb_t('EINST.H_HOECHSTALTER') ?></p>
</div>
<div class="sm-feld">
  <label for="fb_rechentakt"><?= fb_e(fb_t('EINST.L_RECHENTAKT')) ?></label>
  <input data-role="none" type="text" id="fb_rechentakt" name="rechentakt" value="<?= (int) $fb_cfg['rechentakt'] ?>">
  <p class="sm-hilfe"><?= fb_t('EINST.H_RECHENTAKT') ?></p>
</div>
<div class="sm-feld">
  <label for="fb_glaettung"><?= fb_e(fb_t('EINST.L_GLAETTUNG')) ?></label>
  <input data-role="none" type="text" id="fb_glaettung" name="glaettung" value="<?= (int) $fb_cfg['glaettung'] ?>">
  <p class="sm-hilfe"><?= fb_t('EINST.H_GLAETTUNG') ?></p>
</div>
<div class="sm-feld">
  <label for="fb_himmel"><?= fb_e(fb_t('EINST.L_HIMMELSMODELL')) ?></label>
  <select data-role="none" id="fb_himmel" name="himmelsmodell">
    <option value="isotrop"<?= $fb_cfg['himmelsmodell'] === 'isotrop' ? ' selected' : '' ?>><?= fb_e(fb_t('EINST.MODELL_ISOTROP')) ?></option>
    <option value="hdkr"<?= $fb_cfg['himmelsmodell'] === 'hdkr' ? ' selected' : '' ?>><?= fb_e(fb_t('EINST.MODELL_HDKR')) ?></option>
  </select>
  <p class="sm-hilfe"><?= fb_t('EINST.H_HIMMELSMODELL') ?></p>
</div>

<h3><?= fb_e(fb_t('EINST.H_VORSCHAU')) ?></h3>
<div class="sm-feld">
  <label for="fb_vorschau"><?= fb_e(fb_t('EINST.L_VORSCHAU')) ?></label>
  <input data-role="none" type="text" id="fb_vorschau" name="vorschau" value="<?= (int) $fb_cfg['vorschau'] ?>">
  <p class="sm-hilfe"><?= fb_t('EINST.H_VORSCHAU') ?></p>
</div>

<h3><?= fb_e(fb_t('EINST.H_BILANZ')) ?></h3>
<div class="sm-step"><?= fb_t('EINST.BILANZ_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label for="fb_gewicht_bilanz"><?= fb_e(fb_t('EINST.L_GEWICHT_BILANZ')) ?></label>
  <input data-role="none" type="text" id="fb_gewicht_bilanz" name="gewicht_bilanz" value="<?= (int) $fb_cfg['gewicht_bilanz'] ?>">
</div>
<div class="sm-feld">
  <label for="fb_bilanz_voll"><?= fb_e(fb_t('EINST.L_BILANZ_VOLL')) ?></label>
  <input data-role="none" type="text" id="fb_bilanz_voll" name="bilanz_voll_qm" value="<?= (int) $fb_cfg['bilanz_voll_qm'] ?>">
  <p class="sm-hilfe"><?= fb_t('EINST.H_BILANZ_VOLL') ?></p>
</div>
<div class="sm-feld">
  <label for="fb_raumflaeche_vorgabe"><?= fb_e(fb_t('EINST.L_RAUMFLAECHE_VORGABE')) ?></label>
  <input data-role="none" type="text" id="fb_raumflaeche_vorgabe" name="raumflaeche_vorgabe" value="<?= (int) $fb_cfg['raumflaeche_vorgabe'] ?>">
  <p class="sm-hilfe"><?= fb_t('EINST.H_RAUMFLAECHE_VORGABE') ?></p>
</div>
<div class="sm-feld">
  <label><input data-role="none" type="checkbox" name="lernen_ein" value="1"<?= $fb_cfg['lernen_ein'] ? ' checked' : '' ?>> <?= fb_e(fb_t('EINST.L_LERNEN')) ?></label>
  <p class="sm-hilfe"><?= fb_t('EINST.H_LERNEN') ?></p>
</div>

<h3><?= fb_e(fb_t('EINST.H_VORABEND')) ?></h3>
<div class="sm-step"><?= fb_t('EINST.VORABEND_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label for="fb_gewicht_morgen"><?= fb_e(fb_t('EINST.L_GEWICHT_MORGEN')) ?></label>
  <input data-role="none" type="text" id="fb_gewicht_morgen" name="gewicht_morgen" value="<?= (int) $fb_cfg['gewicht_morgen'] ?>">
</div>
<div class="sm-feld">
  <label for="fb_vorabend_ab"><?= fb_e(fb_t('EINST.L_VORABEND_AB')) ?></label>
  <input data-role="none" type="text" id="fb_vorabend_ab" name="vorabend_ab" value="<?= (int) $fb_cfg['vorabend_ab'] ?>">
</div>

<h3><?= fb_e(fb_t('EINST.H_DAEMMEN')) ?></h3>
<div class="sm-step"><?= fb_t('EINST.DAEMMEN_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label><input data-role="none" type="checkbox" name="daemmen_ein" value="1"<?= $fb_cfg['daemmen_ein'] ? ' checked' : '' ?>> <?= fb_e(fb_t('EINST.L_DAEMMEN_EIN')) ?></label>
</div>
<div class="sm-feld">
  <label for="fb_daemm_grenze"><?= fb_e(fb_t('EINST.L_DAEMM_GRENZE')) ?></label>
  <input data-role="none" type="text" id="fb_daemm_grenze" name="daemm_grenze" value="<?= (int) $fb_cfg['daemm_grenze'] ?>">
</div>

<h3><?= fb_e(fb_t('EINST.H_STELLUNG')) ?></h3>
<div class="sm-step"><?= fb_t('EINST.STELLUNG_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label><input data-role="none" type="checkbox" name="stellung_ein" value="1"<?= $fb_cfg['stellung_ein'] ? ' checked' : '' ?>> <?= fb_e(fb_t('EINST.L_STELLUNG_EIN')) ?></label>
</div>
<div class="sm-feld">
  <label for="fb_stellung_zu"><?= fb_e(fb_t('EINST.L_STELLUNG_ZU')) ?></label>
  <input data-role="none" type="text" id="fb_stellung_zu" name="stellung_zu" value="<?= (int) $fb_cfg['stellung_zu'] ?>">
</div>
<div class="sm-feld">
  <label for="fb_stellung_frist"><?= fb_e(fb_t('EINST.L_STELLUNG_FRIST')) ?></label>
  <input data-role="none" type="text" id="fb_stellung_frist" name="stellung_frist" value="<?= (int) $fb_cfg['stellung_frist'] ?>">
  <p class="sm-hilfe"><?= fb_t('EINST.H_STELLUNG_FRIST') ?></p>
</div>

<h3><?= fb_e(fb_t('EINST.H_BERICHT')) ?></h3>
<div class="sm-feld">
  <label><input data-role="none" type="checkbox" name="bericht_ein" value="1"<?= $fb_cfg['bericht_ein'] ? ' checked' : '' ?>> <?= fb_e(fb_t('EINST.L_BERICHT_EIN')) ?></label>
  <p class="sm-hilfe"><?= fb_t('EINST.H_BERICHT') ?></p>
</div>
<div class="sm-feld">
  <label for="fb_bericht_stunde"><?= fb_e(fb_t('EINST.L_BERICHT_STUNDE')) ?></label>
  <input data-role="none" type="text" id="fb_bericht_stunde" name="bericht_stunde" value="<?= (int) $fb_cfg['bericht_stunde'] ?>">
</div>

<h3><?= fb_e(fb_t('EINST.H_PV')) ?></h3>
<div class="sm-step"><?= fb_t('EINST.PV_ERKLAERUNG') ?></div>
<div class="sm-feld">
  <label><input data-role="none" type="checkbox" name="pv_gegenprobe" value="1"<?= $fb_cfg['pv_gegenprobe'] ? ' checked' : '' ?>> <?= fb_e(fb_t('EINST.L_PV_EIN')) ?></label>
</div>
<div class="sm-feld">
  <label for="fb_pv_abweichung"><?= fb_e(fb_t('EINST.L_PV_ABWEICHUNG')) ?></label>
  <input data-role="none" type="text" id="fb_pv_abweichung" name="pv_abweichung" value="<?= (int) $fb_cfg['pv_abweichung'] ?>">
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= fb_t('LEGENDE.AKTION_SPEICHERN') ?></span>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="speichern_modell" value="1"><?= fb_e(fb_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>

<h2><?= fb_e(fb_t('EINST.H_FENSTER')) ?></h2>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="fmt" value="<?= fb_e($fb_merkmal) ?>">
<div class="sm-step"><?= fb_t('EINST.FENSTER_ERKLAERUNG') ?></div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th>#</th><th><?= fb_e(fb_t('EINST.L_KUERZEL')) ?></th><th><?= fb_e(fb_t('EINST.L_NAME')) ?></th>
    <th><?= fb_e(fb_t('EINST.L_AZIMUT')) ?></th><th><?= fb_e(fb_t('EINST.L_NEIGUNG')) ?></th>
    <th><?= fb_e(fb_t('EINST.L_FLAECHE')) ?></th><th><?= fb_e(fb_t('EINST.L_GWERT')) ?></th>
    <th><?= fb_e(fb_t('EINST.L_RAUM')) ?></th><th><?= fb_e(fb_t('EINST.L_TRAEGHEIT')) ?></th>
    <th><?= fb_e(fb_t('EINST.L_HORIZONT')) ?></th>
    <th><?= fb_e(fb_t('EINST.L_BLEND_HOEHE')) ?></th>
    <th><?= fb_e(fb_t('EINST.L_AKTIV')) ?></th></tr>
<?php for ($fb_i = 0; $fb_i < FB_FENSTER; $fb_i++) { $f = $fb_cfg['fenster'][$fb_i]; ?>
<tr>
  <td><?= $fb_i + 1 ?></td>
  <td><input data-role="none" type="text" size="8" name="f_kuerzel[<?= $fb_i ?>]" value="<?= fb_e($f['kuerzel']) ?>"></td>
  <td><input data-role="none" type="text" size="18" name="f_name[<?= $fb_i ?>]" value="<?= fb_e($f['name']) ?>"></td>
  <td><input data-role="none" type="text" size="4" name="f_azimut[<?= $fb_i ?>]" value="<?= (int) $f['azimut'] ?>"></td>
  <td><input data-role="none" type="text" size="3" name="f_neigung[<?= $fb_i ?>]" value="<?= (int) $f['neigung'] ?>"></td>
  <td><input data-role="none" type="text" size="4" name="f_flaeche[<?= $fb_i ?>]" value="<?= fb_e($f['flaeche']) ?>"></td>
  <td><input data-role="none" type="text" size="3" name="f_gwert[<?= $fb_i ?>]" value="<?= (int) $f['gwert'] ?>"></td>
  <td><input data-role="none" type="text" size="10" name="f_raum[<?= $fb_i ?>]" value="<?= fb_e($f['raum']) ?>">
      <br><label class="sm-hilfe"><input data-role="none" type="checkbox" name="f_raumwerte[<?= $fb_i ?>]" value="1"<?= $f['raumwerte'] ? ' checked' : '' ?>> <?= fb_e(fb_t('EINST.L_RAUMWERTE')) ?></label></td>
  <td><input data-role="none" type="text" size="3" name="f_traegheit[<?= $fb_i ?>]" value="<?= (int) $f['traegheit'] ?>"></td>
  <td><input data-role="none" type="text" size="20" name="f_horizont[<?= $fb_i ?>]" value="<?= fb_e($f['horizont']) ?>"></td>
  <td><input data-role="none" type="text" size="3" name="f_blend_h[<?= $fb_i ?>]" value="<?= (int) $f['blend_hoehe'] ?>">
      <input data-role="none" type="text" size="3" name="f_blend_w[<?= $fb_i ?>]" value="<?= (int) $f['blend_winkel'] ?>"></td>
  <td><input data-role="none" type="checkbox" name="f_aktiv[<?= $fb_i ?>]" value="1"<?= $f['aktiv'] ? ' checked' : '' ?>>
      <br><label class="sm-hilfe"><input data-role="none" type="checkbox" name="f_daemmen[<?= $fb_i ?>]" value="1"<?= $f['daemmen'] ? ' checked' : '' ?>> <?= fb_e(fb_t('EINST.L_DAEMMEN')) ?></label></td>
</tr>
<?php } ?>
</table>
</div>
<p class="sm-hilfe"><?= fb_t('EINST.FELDER_HILFE') ?></p>
<?php
/* DER HINWEIS AUF DIE VORGABEFLAECHE.
 *
 * Die Glasflaeche geht NICHT in das Urteil ein - deshalb faellt eine
 * vergessene Flaeche im Betrieb nie auf. Sie bestimmt aber jede Wattzahl,
 * jede Wattstunde und damit die gemessene Aufheizkonstante. Ein Haus voller
 * 1,5-Quadratmeter-Fenster liefert eine Konstante, die aussieht wie eine
 * Messung und keine ist. Deshalb steht der Hinweis hier und nicht nur in
 * der Hilfe: an der Stelle, an der man ihn beheben kann. */
$fb_ohne_flaeche = fb_flaeche_vorgabe($fb_cfg);
if ($fb_ohne_flaeche) { ?>
<div class="sm-hinweis"><?= sprintf(fb_t('EINST.FLAECHE_VORGABE'),
    count($fb_ohne_flaeche), fb_e(fb_liste_kurz($fb_ohne_flaeche))) ?></div>
<?php } ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= fb_t('LEGENDE.AKTION_SPEICHERN') ?></span>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="speichern_fenster" value="1"><?= fb_e(fb_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>

<h2><?= fb_e(fb_t('EINST.H_RAEUME')) ?></h2>
<div class="sm-step"><?= fb_t('EINST.RAEUME_ERKLAERUNG') ?></div>
<?php
/* Nur die Raeume, die auch benutzt werden - in der Reihenfolge, in der sie
 * in der Fenstertabelle zum ersten Mal vorkommen. Alphabetisch waere
 * ordentlicher und unbrauchbarer: man sucht den Raum dort, wo man ihn
 * eingetragen hat. */
$fb_raeume_benutzt = array();
foreach ($fb_cfg['fenster'] as $fb_ff) {
    if ($fb_ff['kuerzel'] === '' || $fb_ff['raum'] === '') { continue; }
    if (!isset($fb_raeume_benutzt[$fb_ff['raum']])) {
        $fb_raeume_benutzt[$fb_ff['raum']] = array();
    }
    $fb_raeume_benutzt[$fb_ff['raum']][] = $fb_ff['kuerzel'];
}
if (!$fb_raeume_benutzt) { ?>
<div class="sm-hinweis"><?= fb_t('EINST.RAEUME_KEINE') ?></div>
<?php } else { ?>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="fmt" value="<?= fb_e($fb_merkmal) ?>">
<table class="sm-tbl">
<tr><th><?= fb_e(fb_t('EINST.L_RAUM')) ?></th>
    <th><?= fb_e(fb_t('EINST.L_RAUMFLAECHE')) ?></th>
    <th><?= fb_e(fb_t('EINST.L_RAUM_FENSTER')) ?></th>
    <th><?= fb_e(fb_t('EINST.L_RAUM_SCHWELLE')) ?></th></tr>
<?php foreach ($fb_raeume_benutzt as $fb_r => $fb_kk) {
    list($fb_qm, $fb_geschaetzt) = fb_raumflaeche($fb_cfg, $fb_r); ?>
<tr>
  <td><span class="sm-mono"><?= fb_e($fb_r) ?></span></td>
  <td><input data-role="none" type="text" size="5" name="r_qm[<?= fb_e($fb_r) ?>]"
             value="<?= $fb_geschaetzt ? '' : fb_e($fb_qm) ?>">
      <?php if ($fb_geschaetzt) { ?><span class="sm-hilfe"><?= sprintf(fb_e(fb_t('EINST.RAUM_GESCHAETZT')), $fb_qm) ?></span><?php } ?></td>
  <td class="sm-hilfe"><?= fb_e(fb_liste_kurz($fb_kk)) ?></td>
  <td class="sm-hilfe"><?= (int) round((float) $fb_cfg['bilanz_voll_qm'] * $fb_qm) ?> Wh</td>
</tr>
<?php } ?>
</table>
<p class="sm-hilfe"><?= fb_t('EINST.RAEUME_HILFE') ?></p>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= fb_t('LEGENDE.AKTION_SPEICHERN') ?></span>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="speichern_raeume" value="1"><?= fb_e(fb_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>
<?php } ?>

<h2><?= fb_e(fb_t('EINST.H_BILD')) ?></h2>
<div class="sm-step"><?= fb_t('EINST.BILD_ERKLAERUNG') ?></div>
<?php if ($fb_ohne_standort) { ?>
<div class="sm-warnung"><?= fb_t('EINST.KEIN_STANDORT') ?></div>
<?php } elseif (!$fb_liste) { ?>
<div class="sm-hinweis"><?= fb_t('LOX.KEINE_FENSTER') ?></div>
<?php } else { ?>
<?php foreach ($fb_liste as $fb_nr => $fb_f) { ?>
<details>
  <summary><span class="sm-mono"><?= fb_e($fb_f['kuerzel']) ?></span>
    &mdash; <?= fb_e($fb_f['name'] !== '' ? $fb_f['name'] : fb_t('EINST.FENSTER')) ?>
    (<?= (int) $fb_f['azimut'] ?>&#176;<?= $fb_f['horizont'] !== '' ? '' : ', ' . fb_e(fb_t('EINST.OHNE_HORIZONT')) ?>)</summary>
  <div class="sm-breit"><?= fb_horizont_svg($fb_f, $fb_cfg) ?></div>
</details>
<?php } ?>
<p class="sm-hilfe"><?= fb_t('EINST.BILD_LEGENDE') ?></p>
<?php } ?>

<h2><?= fb_e(fb_t('PROJEKT.H')) ?></h2>
<div class="sm-step"><?= fb_t('PROJEKT.ERKLAERUNG') ?></div>
<?php
/* DIE GRENZEN STEHEN VOR DEM FORMULAR, NICHT IN DER FEHLERMELDUNG.
 *
 * PHP weist eine zu grosse Absendung ab, BEVOR eine Zeile dieses Plugins
 * laeuft. Wer die Grenzen erst hinterher erfaehrt, hat eine
 * Vier-Megabyte-Datei hochgeladen und danach gelesen, dass es nie haette
 * klappen koennen. Angezeigt wird deshalb vorher - und zwar mit der
 * Antwort auf die Frage, die zaehlt: reicht das fuer eine Projektdatei?
 *
 * Anheben kann das Plugin sie nicht: upload_max_filesize und post_max_size
 * sind PHP_INI_PERDIR, ini_set() gibt fuer beide false zurueck. */
$fb_gr = fb_grenzen();
$fb_dateien = fb_projekt_dateien();
$fb_ordner  = fb_projekt_ordner();
/* Drei Megabyte als Massstab: die kleinste .Loxone-Datei dieser Anlage ist
 * knapp darueber. Die Zahl ist GEWAEHLT und nicht gemessen - sie entscheidet
 * nur, ob gewarnt wird, nie, ob gelesen wird. */
$fb_reicht = $fb_gr['grenze'] >= 3 * 1048576;
?>
<div class="<?= $fb_reicht ? 'sm-hinweis' : 'sm-warnung' ?>">
<?= sprintf(fb_t($fb_reicht ? 'PROJEKT.GRENZEN_OK' : 'PROJEKT.GRENZEN_ENG'),
    fb_e($fb_gr['upload_max_filesize'][0]), fb_e($fb_gr['post_max_size'][0]),
    fb_e(isset($fb_ordner[0]) ? $fb_ordner[0] : '')) ?>
</div>

<h3><?= fb_e(fb_t('PROJEKT.H_ORDNER')) ?></h3>
<div class="sm-step"><?= sprintf(fb_t('PROJEKT.ORDNER_ERKLAERUNG'),
    fb_e(isset($fb_ordner[0]) ? $fb_ordner[0] : '')) ?></div>
<?php if (!$fb_dateien) { ?>
<div class="sm-hinweis"><?= sprintf(fb_t('PROJEKT.ORDNER_LEER'),
    fb_e(implode(', ', $fb_ordner))) ?></div>
<?php } else { ?>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="fmt" value="<?= fb_e($fb_merkmal) ?>">
<div class="sm-feld">
  <label for="fb_d_name"><?= fb_e(fb_t('PROJEKT.L_ORDNERDATEI')) ?></label>
  <select data-role="none" id="fb_d_name" name="d_name" onchange="fbOrdnerSetzen(this)">
<?php foreach ($fb_dateien as $fb_nr => $fb_dd) { ?>
    <option value="<?= fb_e($fb_dd['name']) ?>" data-ordner="<?= (int) $fb_dd['ordner'] ?>"<?= $fb_nr === 0 ? ' selected' : '' ?>><?= fb_e(sprintf('%s  (%.1f MB, %s, %s)',
        $fb_dd['name'], $fb_dd['groesse'] / 1048576.0,
        date('d.m.Y H:i', $fb_dd['zeit']), $fb_dd['ordnerpfad'])) ?></option>
<?php } ?>
  </select>
  <input data-role="none" type="hidden" id="fb_d_ordner" name="d_ordner" value="<?= (int) $fb_dateien[0]['ordner'] ?>">
  <p class="sm-hilfe"><?= sprintf(fb_t('PROJEKT.H_ORDNERDATEI'), count($fb_dateien)) ?></p>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= fb_t('LEGENDE.LESEN_PROJEKT') ?></span>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="projekt_datei" value="1"><?= fb_e(fb_t('PROJEKT.K_ORDNER')) ?></button>
</div>
</form>
<script>
/* Die Ordnernummer wandert in ein verstecktes Feld mit. Sie kommt aus der
 * Liste, die der Server selbst gelesen hat, und wird dort auch wieder
 * dagegen gehalten - ohne dieses Skript stuende schlicht die Nummer der
 * zuerst angebotenen Datei darin, und der Server faende die gewaehlte
 * Datei im falschen Ordner nicht. Das ist die sichere Richtung. */
function fbOrdnerSetzen(sel) {
  var o = sel.options[sel.selectedIndex].getAttribute('data-ordner');
  document.getElementById('fb_d_ordner').value = o;
}
</script>
<?php } ?>

<h3><?= fb_e(fb_t('PROJEKT.H_ABSENDEN')) ?></h3>
<form action="index.php" method="post" enctype="multipart/form-data">
<input data-role="none" type="hidden" name="activetab" value="tab-settings">
<input data-role="none" type="hidden" name="fmt" value="<?= fb_e($fb_merkmal) ?>">
<div class="sm-feld">
  <label for="fb_projekt"><?= fb_e(fb_t('PROJEKT.L_DATEI')) ?></label>
  <input data-role="none" type="file" id="fb_projekt" name="projekt" accept=".Loxone,.loxone,.xml">
  <p class="sm-hilfe"><?= sprintf(fb_t('PROJEKT.H_DATEI'),
      fb_e($fb_gr['upload_max_filesize'][0]), fb_e($fb_gr['post_max_size'][0])) ?></p>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= fb_t('LEGENDE.LESEN_PROJEKT') ?></span>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="projekt_lesen" value="1"><?= fb_e(fb_t('PROJEKT.K_LESEN')) ?></button>
</div>
</form>

<?php if (!empty($fb_vorschlag['liste'])) { ?>
<h3><?= fb_e(sprintf(fb_t('PROJEKT.H_VORSCHLAG'), count($fb_vorschlag['liste']))) ?></h3>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= fb_e(fb_t('EINST.L_KUERZEL')) ?></th><th><?= fb_e(fb_t('EINST.L_AZIMUT')) ?></th>
    <th><?= fb_e(fb_t('EINST.L_RAUM')) ?></th><th><?= fb_e(fb_t('PROJEKT.SP_BAUSTEIN')) ?></th></tr>
<?php foreach ($fb_vorschlag['liste'] as $fb_x) { ?>
<tr><td><span class="sm-mono"><?= fb_e($fb_x['kuerzel']) ?></span></td>
    <td><?= (int) $fb_x['azimut'] ?>&#176;<?= $fb_x['dirtol'] !== null ? ' &plusmn;' . (int) $fb_x['dirtol'] . '&#176;' : '' ?></td>
    <td><span class="sm-mono"><?= fb_e($fb_x['raum']) ?></span></td>
    <td><?= fb_e($fb_x['titel']) ?></td></tr>
<?php } ?>
</table>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= fb_t('LEGENDE.AKTION_PROJEKT') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-settings">
    <input data-role="none" type="hidden" name="fmt" value="<?= fb_e($fb_merkmal) ?>">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="projekt_uebernehmen" value="1"><?= fb_e(fb_t('PROJEKT.K_UEBERNEHMEN')) ?></button>
  </form>
</div>
<?php } ?>
</div>

<!-- ================= Reiter: MQTT ================= -->
<div class="sm-seite<?= $fb_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">
<h2>MQTT</h2>
<div class="sm-step"><?= fb_t('MQTT.ERKLAERUNG') ?></div>
<?php if (!$fb_mqtt['gefunden']) { ?>
<div class="sm-warnung"><?= fb_t('MQTT.NICHT_GEFUNDEN') ?></div>
<?php } elseif (!$fb_mqtt['autostart']) { ?>
<div class="sm-warnung"><?= fb_t('MQTT.KEIN_AUTOSTART') ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= sprintf(fb_t('MQTT.LAEUFT'), fb_e((string) $fb_mqtt['udpport'])) ?></div>
<?php } ?>

<h3><?= fb_e(fb_t('MQTT.H_ABO')) ?></h3>
<?php if ((int) $fb_mqtt['fassung'] >= 2) { ?>
<div class="sm-hinweis"><?= sprintf(fb_t('MQTT.ABO_V2'), fb_e($fb_cfg['mqtt_topic'])) ?></div>
<?php } elseif ((int) $fb_mqtt['fassung'] === 1) { ?>
<div class="sm-warnung"><?= sprintf(fb_t('MQTT.ABO_V1'), fb_e($fb_cfg['mqtt_topic'])) ?></div>
<?php } else { ?>
<div class="sm-warnung"><?= sprintf(fb_t('MQTT.ABO_V1'), fb_e($fb_cfg['mqtt_topic'])) ?></div>
<div class="sm-hilfe"><?= sprintf(fb_t('MQTT.ABO_V2'), fb_e($fb_cfg['mqtt_topic'])) ?></div>
<?php } ?>

<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
<input data-role="none" type="hidden" name="fmt" value="<?= fb_e($fb_merkmal) ?>">
<div class="sm-feld">
  <label><input data-role="none" type="checkbox" name="mqtt_ein" value="1"<?= $fb_cfg['mqtt_ein'] ? ' checked' : '' ?>> <?= fb_e(fb_t('MQTT.EIN')) ?></label>
</div>
<div class="sm-feld">
  <label for="fb_thema"><?= fb_e(fb_t('MQTT.THEMA')) ?></label>
  <input data-role="none" type="text" id="fb_thema" name="mqtt_topic" value="<?= fb_e($fb_cfg['mqtt_topic']) ?>">
  <p class="sm-hilfe"><?= fb_t('MQTT.THEMA_HILFE') ?></p>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= fb_t('LEGENDE.AKTION_SPEICHERN') ?></span>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="speichern_mqtt" value="1"><?= fb_e(fb_t('ALLG.SPEICHERN')) ?></button>
</div>
</form>

<h3><?= fb_e(fb_t('MQTT.H_THEMEN')) ?></h3>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= fb_e(fb_t('MQTT.SP_THEMA')) ?></th><th><?= fb_e(fb_t('MQTT.SP_BEDEUTUNG')) ?></th></tr>
<?php foreach (fb_mqtt_themen() as $fb_k => $fb_schl) { ?>
<tr><td><span class="sm-mono"><?= fb_e($fb_cfg['mqtt_topic'] . '/' . $fb_k) ?></span></td>
    <td><?= fb_e(fb_t($fb_schl)) ?></td></tr>
<?php } ?>
</table>
</div>
<p class="sm-hilfe"><?= fb_t('MQTT.KUERZEL_HILFE') ?></p>

<!-- Dieser Wert traegt Auszeichnung (<span class='sm-mono'>) und geht deshalb
     ROH hinaus. Durch fb_e() gejagt las der Bediener die Auszeichnung
     woertlich in der Ueberschrift - gemessen am gerenderten HTML. -->
<h3><?= fb_t('MQTT.H_GRUND') ?></h3>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= fb_e(fb_t('MQTT.SP_ZAHL')) ?></th><th><?= fb_e(fb_t('MQTT.SP_BEDEUTUNG')) ?></th></tr>
<?php foreach ($fb_grund_nr as $fb_g => $fb_n) { ?>
<tr><td><span class="sm-mono"><?= (int) $fb_n ?></span></td>
    <td><?= fb_e(fb_t('GRUND.' . strtoupper($fb_g))) ?></td></tr>
<?php } ?>
</table>
</div>
</div>

<!-- ================= Reiter: Einbindung in Loxone ================= -->
<div class="sm-seite<?= $fb_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<h2><?= fb_e(fb_t('LOX.H')) ?></h2>
<div class="sm-step"><?= fb_t('LOX.S1') ?></div>
<div class="sm-step"><?php
    if ((int) $fb_mqtt['fassung'] >= 2) { echo sprintf(fb_t('LOX.S2_V2'), fb_e($fb_cfg['mqtt_topic']));
    } elseif ((int) $fb_mqtt['fassung'] === 1) { echo sprintf(fb_t('LOX.S2_V1'), fb_e($fb_cfg['mqtt_topic']));
    } else { echo sprintf(fb_t('LOX.S2_V1'), fb_e($fb_cfg['mqtt_topic']))
                  . '<br>' . sprintf(fb_t('LOX.S2_V2'), fb_e($fb_cfg['mqtt_topic'])); } ?></div>

<h3><?= fb_e(fb_t('LOX.H_MESSWERTE')) ?></h3>
<div class="sm-step"><?= fb_t('LOX.MESSWERTE_TEXT') ?></div>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= fb_e(fb_t('LOX.SP_TITEL')) ?></th><th><?= fb_e(fb_t('LOX.SP_BEFEHL')) ?></th>
    <th><?= fb_e(fb_t('LOX.SP_BEDEUTUNG')) ?></th><th><?= fb_e(fb_t('LOX.SP_EINGETROFFEN')) ?></th></tr>
<?php
$fb_basis = '/plugins/' . fb_e($fb_p['plugin']) . '/index.php?token=' . fb_e(fb_token())
          . '&amp;aktion=melden&amp;wert=';
foreach (fb_messgroessen() as $fb_name => $fb_info) { ?>
<tr><td><span class="sm-mono">FB_SET_<?= fb_e(strtoupper($fb_name)) ?></span></td>
    <td><span class="sm-mono"><?= $fb_basis . fb_e($fb_name) ?>&amp;v=&lt;v.0&gt;</span></td>
    <td><?= fb_e(fb_t($fb_info[2])) ?><?= $fb_info[0] ? '' : ' (' . fb_e(fb_t('LOX.OPTIONAL')) . ')' ?></td>
    <td><?php if (isset($fb_messwerte[$fb_name]['t'])) {
            echo fb_e(sprintf(fb_t('LOX.VOR_SEKUNDEN'),
                 max(0, time() - (int) $fb_messwerte[$fb_name]['t']),
                 (string) $fb_messwerte[$fb_name]['v']));
        } else { echo '<b class="sm-aus">' . fb_e(fb_t('LOX.NIE_EINGETROFFEN')) . '</b>'; } ?></td></tr>
<?php }
$fb_raeume = array();
foreach ($fb_liste as $fb_f) {
    if ($fb_f['raum'] === '' || empty($fb_f['raumwerte'])) { continue; }
    $fb_raeume[$fb_f['raum']] = true;
}
ksort($fb_raeume);
foreach (array_keys($fb_raeume) as $fb_raum) {
    foreach (array('ist' => 'FB_MESS.RAUM_IST', 'grenze' => 'FB_MESS.RAUM_GRENZE') as $fb_art => $fb_schl) {
        $fb_schluessel = $fb_art . '.' . $fb_raum; ?>
<tr><td><span class="sm-mono">FB_SET_<?= fb_e(strtoupper($fb_art)) ?>_<?= fb_e(strtoupper($fb_raum)) ?></span></td>
    <td><span class="sm-mono"><?= $fb_basis . fb_e($fb_schluessel) ?>&amp;v=&lt;v.0&gt;</span></td>
    <td><?= fb_e(fb_t($fb_schl)) ?>: <?= fb_e($fb_raum) ?></td>
    <td><?php if (isset($fb_messwerte[$fb_schluessel]['t'])) {
            echo fb_e(sprintf(fb_t('LOX.VOR_SEKUNDEN'),
                 max(0, time() - (int) $fb_messwerte[$fb_schluessel]['t']),
                 (string) $fb_messwerte[$fb_schluessel]['v']));
        } else { echo '<b class="sm-aus">' . fb_e(fb_t('LOX.NIE_EINGETROFFEN')) . '</b>'; } ?></td></tr>
<?php } } ?>
</table>
</div>
<?php if (!$fb_raeume) { ?>
<div class="sm-hinweis"><?= fb_t('LOX.KEINE_RAEUME') ?></div>
<?php } ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= fb_t('LEGENDE.TECHNIK_XML') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <input data-role="none" type="hidden" name="fmt" value="<?= fb_e($fb_merkmal) ?>">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="vorlage" value="vq"><?= fb_e(fb_t('LOX.K_VQ')) ?></button>
  </form>
</div>

<h3><?= fb_e(fb_t('LOX.H_ADRESSE')) ?></h3>
<p class="sm-hilfe"><?= fb_t('LOX.ADRESSE_HILFE') ?></p>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= fb_e(fb_t('LOX.SP_ZWECK')) ?></th><th><?= fb_e(fb_t('LOX.SP_ADRESSE')) ?></th></tr>
<tr><td><?= fb_e(fb_t('LOX.Z_STATUS')) ?></td><td><span class="sm-mono"><?= fb_e(fb_endpunkt() . '?token=' . fb_token() . '&aktion=status') ?></span></td></tr>
<tr><td><?= fb_e(fb_t('LOX.Z_JSON')) ?></td><td><span class="sm-mono"><?= fb_e(fb_endpunkt() . '?token=' . fb_token() . '&aktion=json') ?></span></td></tr>
<tr><td><?= fb_e(fb_t('LOX.Z_SELFTEST')) ?></td><td><span class="sm-mono"><?= fb_e(fb_endpunkt() . '?token=' . fb_token() . '&selftest=1') ?></span></td></tr>
</table>
</div>
<p class="sm-hilfe"><?= fb_t('LOX.TOKEN_HINWEIS') ?></p>

<h3><?= fb_e(fb_t('LOX.H_FELDER')) ?></h3>
<p class="sm-hilfe"><?= fb_t('LOX.FELDER_HILFE') ?></p>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= fb_e(fb_t('LOX.SP_TITEL')) ?></th><th><?= fb_e(fb_t('LOX.SP_EINHEIT')) ?></th>
    <th><?= fb_e(fb_t('LOX.SP_BEREICH')) ?></th><th><?= fb_e(fb_t('LOX.SP_BEDEUTUNG')) ?></th></tr>
<?php foreach (fb_summenfelder() as $fb_feld => $fb_info) { ?>
<tr><td><span class="sm-mono">FB_<?= fb_e($fb_feld) ?></span></td>
    <td><?= $fb_info[0] !== '' ? fb_e($fb_info[0]) : '&ndash;' ?></td>
    <td><span class="sm-mono"><?= (int) $fb_info[1] ?> &hellip; <?= (int) $fb_info[2] ?></span></td>
    <td><?= fb_e(fb_t($fb_info[3])) ?></td></tr>
<?php } ?>
<?php foreach ($fb_liste as $fb_f) {
        foreach (fb_felder() as $fb_feld => $fb_info) { ?>
<tr><td><span class="sm-mono"><?= fb_e(fb_titel($fb_f, $fb_feld)) ?></span></td>
    <td><?= $fb_info[0] !== '' ? fb_e($fb_info[0]) : '&ndash;' ?></td>
    <td><span class="sm-mono"><?= (int) $fb_info[1] ?> &hellip; <?= (int) $fb_info[2] ?></span></td>
    <td><?= fb_e($fb_f['name'] !== '' ? $fb_f['name'] : $fb_f['kuerzel']) ?>: <?= fb_e(fb_t($fb_info[3])) ?></td></tr>
<?php   }
      } ?>
</table>
</div>
<?php if (!$fb_liste) { ?>
<div class="sm-warnung"><?= fb_t('LOX.KEINE_FENSTER') ?></div>
<?php } ?>

<h3><?= fb_e(fb_t('LOX.H_ALLES')) ?></h3>
<div class="sm-step"><?= fb_t('LOX.ALLES_TEXT') ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-technik"></i> <?= fb_t('LEGENDE.TECHNIK_XML') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <input data-role="none" type="hidden" name="fmt" value="<?= fb_e($fb_merkmal) ?>">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="vorlage" value="vi"><?= fb_e(fb_t('LOX.K_VI')) ?></button>
  </form>
</div>

<h3><?= fb_e(fb_t('LOX.H_AUSFALL')) ?></h3>
<div class="sm-step"><?= fb_t('LOX.AUSFALL_TEXT') ?></div>

<h3><?= fb_e(fb_t('LOX.H_BAUSTEINE')) ?></h3>
<div class="sm-breit"><?= fb_t('LOX.BAUSTEINE') ?></div>
<div class="sm-hilfe"><?= fb_t('LOX.BAUSTEINE_ERL') ?></div>

<h3><?= fb_e(fb_t('LOX.H_GEGENPROBE')) ?></h3>
<div class="sm-step"><?= fb_t('LOX.GEGENPROBE_TEXT') ?></div>

<h3><?= fb_e(fb_t('LOX.H_TOKEN')) ?></h3>
<div class="sm-step"><?= fb_t('LOX.TOKEN_TEXT') ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= fb_t('LEGENDE.AKTION_TOKEN') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-loxone">
    <input data-role="none" type="hidden" name="fmt" value="<?= fb_e($fb_merkmal) ?>">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="1"><?= fb_e(fb_t('LOX.K_TOKEN_NEU')) ?></button>
  </form>
</div>
</div>

<!-- ================= Reiter: Test ================= -->
<div class="sm-seite<?= $fb_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<h2><?= fb_e(fb_t('TEST.H')) ?></h2>
<div class="sm-step"><?= fb_t('TEST.ERKLAERUNG') ?></div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= fb_t('LEGENDE.LESEN') ?></span>
<span><i class="sm-punkt sm-b-technik"></i> <?= fb_t('LEGENDE.TECHNIK') ?></span>
<span><i class="sm-punkt sm-b-aktion"></i> <?= fb_t('LEGENDE.AKTION_RECHNEN') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="fmt" value="<?= fb_e($fb_merkmal) ?>">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="selbstpruefung"><?= fb_e(fb_t('TEST.K_SELBSTPRUEFUNG')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="fmt" value="<?= fb_e($fb_merkmal) ?>">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="sonne"><?= fb_e(fb_t('TEST.K_SONNE')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="fmt" value="<?= fb_e($fb_merkmal) ?>">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="tagesgang"><?= fb_e(fb_t('TEST.K_TAGESGANG')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="fmt" value="<?= fb_e($fb_merkmal) ?>">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="selbsttest"><?= fb_e(fb_t('TEST.K_SELBSTTEST')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="fmt" value="<?= fb_e($fb_merkmal) ?>">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="zeile"><?= fb_e(fb_t('TEST.K_ZEILE')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="fmt" value="<?= fb_e($fb_merkmal) ?>">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="messwerte"><?= fb_e(fb_t('TEST.K_MESSWERTE')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="fmt" value="<?= fb_e($fb_merkmal) ?>">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="vorlage"><?= fb_e(fb_t('TEST.K_VORLAGE')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="fmt" value="<?= fb_e($fb_merkmal) ?>">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="endpunkt"><?= fb_e(fb_t('TEST.K_ENDPUNKT')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="fmt" value="<?= fb_e($fb_merkmal) ?>">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="bilanz"><?= fb_e(fb_t('TEST.K_BILANZ')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="fmt" value="<?= fb_e($fb_merkmal) ?>">
    <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="test" value="bericht"><?= fb_e(fb_t('TEST.K_BERICHT')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="fmt" value="<?= fb_e($fb_merkmal) ?>">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="lernkurve"><?= fb_e(fb_t('TEST.K_LERNKURVE')) ?></button>
  </form>
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="fmt" value="<?= fb_e($fb_merkmal) ?>">
    <button data-role="none" class="sm-btn sm-b-technik" type="submit" name="test" value="pv"><?= fb_e(fb_t('TEST.K_PV')) ?></button>
  </form>
</div>

<h3><?= fb_e(fb_t('TEST.H_WASWARE')) ?></h3>
<div class="sm-step"><?= fb_t('TEST.WASWARE_ERKLAERUNG') ?></div>
<form action="index.php" method="post">
<input data-role="none" type="hidden" name="activetab" value="tab-test">
<input data-role="none" type="hidden" name="fmt" value="<?= fb_e($fb_merkmal) ?>">
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= fb_e(fb_t('EINST.L_TAGESGRENZE')) ?></th><th><?= fb_e(fb_t('EINST.L_SPREIZUNG_TAG')) ?></th>
    <th><?= fb_e(fb_t('EINST.L_SPREIZUNG_RAUM')) ?></th><th><?= fb_e(fb_t('EINST.L_GEWICHT_RAUM')) ?></th>
    <th><?= fb_e(fb_t('EINST.L_GEWICHT_TAG')) ?></th><th><?= fb_e(fb_t('EINST.L_GEWICHT_BILANZ')) ?></th>
    <th><?= fb_e(fb_t('EINST.L_BILANZ_VOLL')) ?></th>
    <th><?= fb_e(fb_t('EINST.L_GEWICHT_MORGEN')) ?></th><th><?= fb_e(fb_t('EINST.L_SCHWELLE_EIN')) ?></th>
    <th><?= fb_e(fb_t('EINST.L_SCHWELLE_AUS')) ?></th><th><?= fb_e(fb_t('EINST.L_E_REF')) ?></th>
    <th><?= fb_e(fb_t('EINST.L_HIMMELSMODELL')) ?></th></tr>
<tr>
  <td><input data-role="none" type="text" size="4" name="w_tagesgrenze" value="<?= (int) $fb_cfg['tagesgrenze'] ?>"></td>
  <td><input data-role="none" type="text" size="4" name="w_spreizung_tag" value="<?= (int) $fb_cfg['spreizung_tag'] ?>"></td>
  <td><input data-role="none" type="text" size="4" name="w_spreizung_raum" value="<?= (int) $fb_cfg['spreizung_raum'] ?>"></td>
  <td><input data-role="none" type="text" size="4" name="w_gewicht_raum" value="<?= (int) $fb_cfg['gewicht_raum'] ?>"></td>
  <td><input data-role="none" type="text" size="4" name="w_gewicht_tag" value="<?= (int) $fb_cfg['gewicht_tag'] ?>"></td>
  <td><input data-role="none" type="text" size="4" name="w_gewicht_bilanz" value="<?= (int) $fb_cfg['gewicht_bilanz'] ?>"></td>
  <td><input data-role="none" type="text" size="4" name="w_bilanz_voll_qm" value="<?= (int) $fb_cfg['bilanz_voll_qm'] ?>"></td>
  <td><input data-role="none" type="text" size="4" name="w_gewicht_morgen" value="<?= (int) $fb_cfg['gewicht_morgen'] ?>"></td>
  <td><input data-role="none" type="text" size="4" name="w_schwelle_ein" value="<?= (int) $fb_cfg['schwelle_ein'] ?>"></td>
  <td><input data-role="none" type="text" size="4" name="w_schwelle_aus" value="<?= (int) $fb_cfg['schwelle_aus'] ?>"></td>
  <td><input data-role="none" type="text" size="5" name="w_e_ref" value="<?= (int) $fb_cfg['e_ref'] ?>"></td>
  <td><select data-role="none" name="w_himmelsmodell">
    <option value="isotrop"<?= $fb_cfg['himmelsmodell'] === 'isotrop' ? ' selected' : '' ?>><?= fb_e(fb_t('EINST.MODELL_ISOTROP')) ?></option>
    <option value="hdkr"<?= $fb_cfg['himmelsmodell'] === 'hdkr' ? ' selected' : '' ?>><?= fb_e(fb_t('EINST.MODELL_HDKR')) ?></option>
  </select></td>
</tr>
</table>
</div>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-lesen"></i> <?= fb_t('LEGENDE.LESEN_WASWARE') ?></span>
</div>
<div class="sm-knopfreihe">
  <button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="wasware" value="1"><?= fb_e(fb_t('TEST.K_WASWARE')) ?></button>
</div>
</form>

<h3><?= fb_e(fb_t('TEST.H_RECHNEN')) ?></h3>
<p class="sm-hilfe"><?= fb_t('TEST.RECHNEN_ERKLAERUNG') ?></p>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= fb_t('LEGENDE.AKTION_RECHNEN') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-test">
    <input data-role="none" type="hidden" name="fmt" value="<?= fb_e($fb_merkmal) ?>">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="test" value="rechnen"><?= fb_e(fb_t('TEST.K_RECHNEN')) ?></button>
  </form>
</div>
<?php if ($fb_testausgabe !== '') { ?>
<div class="sm-pre"><?= fb_e($fb_testausgabe) ?></div>
<?php } ?>
</div>

<!-- ================= Reiter: Logdateien ================= -->
<div class="sm-seite<?= $fb_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<h2><?= fb_e(fb_t('LOG.H')) ?></h2>
<p class="sm-hilfe"><?= fb_t('LOG.ERKLAERUNG') ?>
<span class="sm-mono"><?= fb_e($fb_p['log']) ?></span></p>
<?php if ($fb_logzeilen) { ?>
<div class="sm-log"><?= fb_e(implode("\n", $fb_logzeilen)) ?></div>
<?php } else { ?>
<div class="sm-hinweis"><?= fb_t('LOG.LEER') ?></div>
<?php } ?>
<div class="sm-legende">
<span><i class="sm-punkt sm-b-aktion"></i> <?= fb_t('LEGENDE.AKTION_LOG') ?></span>
</div>
<div class="sm-knopfreihe">
  <form action="index.php" method="post">
    <input data-role="none" type="hidden" name="activetab" value="tab-log">
    <input data-role="none" type="hidden" name="fmt" value="<?= fb_e($fb_merkmal) ?>">
    <button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="log_leeren" value="1"><?= fb_e(fb_t('LOG.K_LEEREN')) ?></button>
  </form>
</div>
</div>

</div><!-- /sm-wrap -->

<script>
(function () {
	var reiter = document.querySelectorAll('.sm-tab');
	function zeige(id) {
		reiter.forEach(function (r) { r.classList.toggle('sm-active', r.dataset.ziel === id); });
		document.querySelectorAll('.sm-seite').forEach(function (s) { s.classList.toggle('sm-active', s.id === id); });
		document.querySelectorAll('input[name="activetab"]').forEach(function (f) { f.value = id; });
		if (history.replaceState) { history.replaceState(null, '', 'index.php?form=' + id.replace('tab-', '')); }
	}
	reiter.forEach(function (r) {
		r.addEventListener('click', function (e) { e.preventDefault(); zeige(r.dataset.ziel); });
	});
	// Der Server hat sm-active bereits gesetzt; dieser Aufruf richtet nur die
	// versteckten activetab-Felder aus und ist ansonsten wirkungslos.
	zeige(<?= json_encode($fb_tab) ?>);
})();
</script>
<?php
if ($fb_rahmen) {
    LBWeb::lbfooter();
}
