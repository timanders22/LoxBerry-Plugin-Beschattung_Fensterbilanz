<?php
/**
 * Fensterbilanz - der Endpunkt fuer den Miniserver
 *
 * Liegt im unangemeldeten Bereich, damit Loxone ihn ohne Zugangsdaten
 * erreicht, und ist deshalb durch ein Wortzeichen geschuetzt. Verglichen
 * wird mit hash_equals, also in gleichbleibender Zeit - ein einfaches ==
 * liesse sich ueber die Antwortzeit Zeichen fuer Zeichen erraten.
 *
 *   ?token=<TOKEN>&aktion=status                 alle Werte als Textzeilen
 *   ?token=<TOKEN>&aktion=json                   dasselbe als JSON, mit Begruendung
 *   ?token=<TOKEN>&aktion=fenster&k=<Kuerzel>    nur ein Fenster
 *   ?token=<TOKEN>&aktion=melden&wert=<Name>&v=<Zahl>   einen Messwert abliefern
 *   ?token=<TOKEN>&selftest=1                    Selbsttest, loest nichts aus
 *
 * WARUM AUCH DIE ABFRAGENDEN AUFRUFE EIN TOKEN VERLANGEN: in der Antwort
 * stehen Raumtemperaturen und Sollwerte. Das ist kein Betriebsgeheimnis,
 * aber es sagt jedem im Heimnetz, ob jemand zu Hause ist und wie er wohnt.
 *
 * WARUM 'melden' SCHREIBEN DARF: der Hausstandard sagt, ein Aufruf, der
 * etwas ausloest, verlangt ein Token - nicht, dass er nichts tun darf.
 *
 * Genau unterschieden wird zwischen VOR und NACH der Tokenpruefung, und der
 * Unterschied steht hier ausgeschrieben, weil ein zu pauschaler Satz an
 * dieser Stelle eine Fehlerquelle waere:
 *
 *   VOR der Pruefung wird ausschliesslich fb_config(FALSE) aufgerufen. Diese
 *   Form legt keinen Ordner an, schreibt nichts zurueck und stellt nichts aus
 *   der Zweitschrift wieder her. Ein abgewiesener Aufruf hinterlaesst deshalb
 *   KEINE Datei, auch keine harmlose - gemessen an einer leeren Attrappe.
 *
 *   NACH einer bestandenen Pruefung darf 'melden' schreiben: den Messwert,
 *   den gerechneten Stand und das Protokoll. Und ueber fb_lauf() auch eine
 *   fehlende Konfiguration vervollstaendigen - dasselbe, was der Cron-Lauf
 *   ohnehin tut. Wer das Wortzeichen kennt, ist kein Fremder.
 *
 * UND ER SCHALTET NICHTS. Es gibt keine Aktion, die einen Rollladen bewegt.
 * Das Plugin liefert ein Urteil, Loxone entscheidet.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
require_once __DIR__ . '/fb_lib.php';

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

$fb_cfg  = fb_config(false);          // NUR lesen - siehe Kopf
$fb_soll = (string) $fb_cfg['aktionstoken'];
/* is_string() VOR dem Cast, an JEDEM Parameter dieser Datei.
 *
 * Ein Feldparameter (?token[]=x) wird von (string) zu "array" - unter PHP 8
 * mit einer Warnung. Die steht dann VOR den Kopfzeilen, und
 * http_response_code(403) laeuft ins Leere: die abgewiesene Anfrage geht als
 * HTTP 200 mit Warntext hinaus. Gemessen unter 8.4 mit display_errors=On. */
$fb_ist  = (isset($_GET['token']) && is_string($_GET['token'])) ? $_GET['token'] : '';

/* Der leere Fall VOR dem Vergleich: hash_equals('', '') ist true, und der
 * Endpunkt stuende sonst genau auf der Anlage offen, bei der noch nie
 * jemand die Oberflaeche geoeffnet hat. */
if ($fb_soll === '') {
    http_response_code(403);
    echo "FEHLER;OK=0;GRUND=KEIN_TOKEN_GESETZT\n";
    echo "Die Plugin-Oberflaeche wurde noch nie geoeffnet - es gibt noch kein Wortzeichen.\n";
    exit;
}
if (!hash_equals($fb_soll, $fb_ist)) {
    http_response_code(403);
    echo "FEHLER;OK=0;GRUND=TOKEN\n";
    /* HIER WIRD ABSICHTLICH NICHT PROTOKOLLIERT, und das ist eine
     * Entscheidung, keine Luecke.
     *
     * Der Wachposten gegen fremde Formulare schreibt eine Zeile, wenn er
     * etwas abweist - er steht im ANGEMELDETEN Bereich, dort darf
     * geschrieben werden. Hier ist es umgekehrt: eine Zeile je abgewiesenem
     * Aufruf hiesse, dass jeder im Heimnetz ohne jeden Ausweis Ordner
     * anlegen und die Protokolldatei fuellen kann - und log/plugins liegt
     * auf einer Ramdisk. Die Eigenschaft "ein abgewiesener Aufruf
     * hinterlaesst keine Datei" ist mehr wert als die Zeile. Wer die
     * Versuche sehen will, findet sie im Zugriffsprotokoll des Webservers. */
    exit;
}

/* Der Selbsttest beantwortet die Tokenfrage, ohne etwas auszuloesen. */
if (isset($_GET['selftest'])) {
    $fb_s = fb_stand();
    $fb_m = fb_messwerte();
    echo "SELBSTTEST;OK=1\n";
    echo 'Plugin-Ordner      : ' . fb_paths()['plugin'] . "\n";
    echo 'PHP                : ' . PHP_VERSION . "\n";
    echo 'Standort gesetzt   : ' . ((abs((float) $fb_cfg['breite']) > 0.001
          || abs((float) $fb_cfg['laenge']) > 0.001) ? 'ja' : 'nein') . "\n";
    echo 'Fenster eingerichtet: ' . count(fb_fenster(false)) . "\n";
    echo 'Messwerte abgelegt : ' . count($fb_m) . "\n";
    echo 'Letzter Lauf       : ' . (isset($fb_s['ts']) && $fb_s['ts']
          ? date('Y-m-d H:i:s', (int) $fb_s['ts']) : 'noch nie') . "\n";
    echo 'Werteliste         : ' . (isset($fb_s['felder']) ? count($fb_s['felder']) : 0)
          . " Felder\n";
    exit;
}

$fb_aktion = (isset($_GET['aktion']) && is_string($_GET['aktion']))
    ? strtolower($_GET['aktion']) : 'status';
if (!in_array($fb_aktion, array('status', 'json', 'fenster', 'melden'), true)) {
    http_response_code(400);
    echo "FEHLER;OK=0;GRUND=AKTION_UNBEKANNT\n";
    echo "Erlaubt sind: status, json, fenster, melden\n";
    exit;
}

/* ---------------- Einen Messwert entgegennehmen ---------------- */
if ($fb_aktion === 'melden') {
    $fb_name = (isset($_GET['wert']) && is_string($_GET['wert']))
        ? strtolower($_GET['wert']) : '';
    $fb_v    = (isset($_GET['v']) && is_string($_GET['v'])) ? $_GET['v'] : '';
    list($fb_ok, $fb_grund) = fb_messwert_setzen($fb_name, $fb_v, $fb_cfg);
    if (!$fb_ok) {
        /* Abweisen und MELDEN, nicht stillschweigend zurechtbiegen. Ein
         * Endpunkt, der jede Eingabe mit OK=1 quittiert, laesst den
         * Anwender in dem Glauben, seine virtuellen Ausgaenge saessen
         * richtig - und die Werte kaemen nie an. */
        http_response_code(400);
        echo 'FEHLER;OK=0;GRUND=' . $fb_grund . "\n";
        if ($fb_grund === 'WERT_AUSSERHALB') {
            echo "Der Wert liegt ausserhalb dessen, was fuer diese Groesse moeglich ist.\n";
        }
        if ($fb_grund === 'NAME_UNBEKANNT') {
            /* Sagen, was erlaubt WAERE. Ein blosses "unbekannt" schickt den
             * Anwender in die Suche; die Liste beantwortet die Frage sofort -
             * und sie zeigt zugleich, welche Raumschluessel eingerichtet
             * sind. */
            echo "Erlaubt sind zurzeit: " . implode(', ', fb_messwertnamen($fb_cfg)) . "\n";
        }
        exit;
    }
    /* Nach der Annahme rechnen - aber nur, wenn der Rechentakt es erlaubt.
     * Loxone schickt bei einem Wetterwechsel ein Dutzend virtueller
     * Ausgaenge in derselben Sekunde los; ohne den Mindestabstand rechnete
     * das Plugin ein Dutzend Mal und schickte ein Dutzend MQTT-Salven. */
    list($fb_gerechnet, $fb_stand) = fb_lauf(false, false);   // legt nichts an
    echo 'OK=1;WERT=' . $fb_name . ';GERECHNET=' . ($fb_gerechnet ? 1 : 0) . "\n";
    exit;
}

$fb_stand = fb_stand();

if ($fb_aktion === 'json') {
    header('Content-Type: application/json; charset=utf-8');
    /* Das Alter gehoert in die Antwort, aber nicht in die Datei: es ist im
     * Augenblick der Frage zu rechnen, sonst waere es immer null. */
    $fb_stand['alter_stand'] = fb_alter();
    $fb_stand['messwerte'] = fb_messwerte();
    $j = json_encode($fb_stand, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($j === false) {
        /* json_encode gibt bei ungueltigem UTF-8 false zurueck. Ungeprueft
         * waere die Antwort eine leere Seite mit Status 200 - und eine leere
         * Antwort mit Erfolgsmeldung ist das Schlechteste, was eine
         * Schnittstelle liefern kann: die Gegenstelle haelt sie fuer gueltig. */
        http_response_code(500);
        echo json_encode(array('ok' => 0, 'fehler' => json_last_error_msg()));
        exit;
    }
    echo $j;
    exit;
}

if ($fb_aktion === 'fenster') {
    $fb_k = (isset($_GET['k']) && is_string($_GET['k']))
        ? strtoupper(preg_replace('/[^A-Za-z0-9_]/', '', $_GET['k'])) : '';
    foreach ((isset($fb_stand['fenster']) ? $fb_stand['fenster'] : array()) as $fb_e) {
        if (strtoupper($fb_e['kuerzel']) === $fb_k && $fb_k !== '') {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode($fb_e, JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    http_response_code(404);
    echo "FEHLER;OK=0;GRUND=FENSTER_UNBEKANNT\n";
    exit;
}

echo fb_zeile($fb_stand);
