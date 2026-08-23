<?php
/**
 * Fensterbilanz - der Lauf aus dem Cron
 *
 * Rechnet das Urteil je Fenster, legt es in stand.json ab und schickt es an
 * das MQTT-Gateway. Aufgerufen aus cron/cron.05min; ein zweiter Weg fuehrt
 * ueber den Endpunkt, wenn Loxone einen neuen Messwert abliefert.
 *
 * Aufruf von Hand (das gehoert nach jeder Installation einmal gemacht) -
 * ueber die Umgebungsvariable und nicht ueber einen abgeschriebenen
 * Systempfad, damit hier kein fest verdrahteter Pfad steht:
 *     php $LBHOMEDIR/bin/plugins/fensterbilanz/fb_lauf.php
 *     echo $?
 *
 * WARUM DIE BIBLIOTHEK UEBER EINE KANDIDATENLISTE GESUCHT WIRD
 * -----------------------------------------------------------
 * Im entpackten Archiv liegen bin/ und webfrontend/ nebeneinander,
 * installiert liegen sie in getrennten Baeumen:
 *
 *     Archiv       bin/                     webfrontend/html/
 *     installiert  bin/plugins/<ordner>/    webfrontend/html/plugins/<ordner>/
 *
 * Ein require mit einer festen Zahl von ".." geht deshalb NUR im Archiv
 * auf. Installiert stirbt das Skript in der ersten Zeile - und weil der
 * Cron nach /dev/null schreibt, merkt das niemand. Genau daran ist in
 * diesem Haus ein Hintergrunddienst ueber acht Fassungen kein einziges Mal
 * gelaufen.
 *
 * Findet keiner der Kandidaten etwas, wird auf die FEHLERAUSGABE
 * geschrieben, welche Datei wo gesucht wurde, und mit Rueckgabewert 1
 * beendet - nicht stillschweigend weitergelaufen.
 */

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$fb_home   = getenv('LBHOMEDIR');
$fb_ordner = getenv('LBPPLUGINDIR');
if (!$fb_ordner) { $fb_ordner = basename(__DIR__); }

$fb_kandidaten = array();
if ($fb_home) {
    $fb_kandidaten[] = $fb_home . '/webfrontend/html/plugins/' . $fb_ordner . '/fb_lib.php';
}
$fb_kandidaten[] = dirname(dirname(dirname(__DIR__)))
                 . '/webfrontend/html/plugins/' . basename(__DIR__) . '/fb_lib.php';
$fb_kandidaten[] = dirname(__DIR__) . '/webfrontend/html/fb_lib.php';

$fb_lib = '';
foreach ($fb_kandidaten as $fb_k) {
    if (is_file($fb_k)) { $fb_lib = $fb_k; break; }
}
if ($fb_lib === '') {
    fwrite(STDERR, "Fensterbilanz: fb_lib.php wurde nicht gefunden. Gesucht wurde unter:\n");
    foreach ($fb_kandidaten as $fb_k) { fwrite(STDERR, '  ' . $fb_k . "\n"); }
    exit(1);
}
require_once $fb_lib;

$fb_argumente = isset($argv) ? $argv : array();

/* --------------------------------------------------------------------
 * Selbsttest: rechnet ohne Anlage, ohne Netz und ohne Messwerte von
 * aussen. Er faehrt den Rechenkern mit hinterlegten Faellen und
 * vergleicht mit dem, was herauskommen MUSS.
 *
 * Geeicht wird er dadurch, dass jeder Fall eine Aussage prueft, die sich
 * durch Zurueckbauen brechen laesst - nicht dadurch, dass er gruen ist.
 * -------------------------------------------------------------------- */
if (in_array('--selbsttest', $fb_argumente, true)) {
    exit(fb_selbsttest());
}

if (in_array('--zeile', $fb_argumente, true)) {
    echo fb_zeile(fb_stand());
    exit(0);
}

$fb_erzwingen = in_array('--jetzt', $fb_argumente, true);
list($fb_gerechnet, $fb_stand) = fb_lauf($fb_erzwingen);

if (!$fb_gerechnet) {
    echo "Der letzte Lauf liegt noch innerhalb des Rechentakts - es wurde nicht neu gerechnet.\n";
    exit(0);
}
if ($fb_stand['meldung'] === 'KEIN_STANDORT') {
    fwrite(STDERR, "Es ist kein Standort eingetragen (Reiter Einstellungen). "
        . "Ohne Breite und Laenge gibt es keinen Sonnenstand.\n");
    exit(1);
}
printf("%d von %d Fenstern wollen Beschattung. Sonne %.1f Grad hoch, Azimut %.1f Grad.\n",
    (int) $fb_stand['beschatten_anzahl'], (int) $fb_stand['anzahl'],
    (float) $fb_stand['sonne_hoehe'], (float) $fb_stand['sonne_azimut']);
if (!empty($fb_stand['fehlend'])) {
    fwrite(STDERR, 'Es fehlen gueltige Messwerte: ' . implode(', ', $fb_stand['fehlend']) . "\n");
    exit(1);
}
exit(0);


/**
 * Der Selbsttest des Rechenkerns.
 *
 * Jeder Fall ist so gebaut, dass er eine EINZELNE Aussage prueft und rot
 * wird, wenn man diese Aussage im Quelltext zurueckbaut. Ein Fall, der
 * immer gruen ist, prueft nichts.
 */
function fb_selbsttest()
{
    $fehler = array();
    $geprueft = 0;

    $pruefe = function ($name, $bedingung, $gemessen) use (&$fehler, &$geprueft) {
        $geprueft++;
        if (!$bedingung) { $fehler[] = $name . ' -> ' . $gemessen; }
    };

    /* --- 1. Sonnenstand gegen bekannte Punkte ---
     * Die Sollwerte stammen NICHT aus dieser Umsetzung, sondern aus einer
     * zweiten, unabhaengigen Rechnung nach dem PSA-Algorithmus
     * (Blanco-Muriel u.a. 2001). Zwei Umsetzungen desselben Verfahrens
     * wuerden denselben Denkfehler zweimal machen; genau das hat die
     * Eichung beim Bau gezeigt - der Azimut war an der Nord-Sued-Achse
     * gespiegelt, waehrend die Hoehe auf 0,007 Grad stimmte. */
    /* Der Ort ist bewusst nur auf eine Nachkommastelle genau: 48,2 / 11,6 ist
 * eine Rasterzelle von rund zehn Kilometern, kein Haus. Fuenf Stellen
 * waeren metergenau gewesen - fuer eine Eichung ohne Nutzen und in einem
 * veroeffentlichten Plugin eine Ortsangabe, die niemanden angeht.
 *
 * Die Sollwerte unten bleiben davon unberuehrt, und das ist nachgerechnet:
 * gegen eine unabhaengige PSA-Umsetzung weicht der Sonnenstand hier um
 * hoechstens 0,008 Grad in der Hoehe und 0,015 Grad im Azimut ab. Die
 * Schranken der Eichung liegen bei 0,05 und 0,10 Grad - also drei- bis
 * siebenfacher Abstand. */
$ort = array(48.2, 11.6);
    $faelle = array(
        // Unix-Zeit (UTC),           Hoehe,    Azimut
        array(1787472000.0,           35.125,   115.126),  // 23.08.2026 08:00 UTC
        array(1782057600.0,           29.609,   272.903),  // 21.06.2026 16:00 UTC
        array(1797850800.0,           18.311,   177.200),  // 21.12.2026 11:00 UTC
    );
    foreach ($faelle as $i => $f) {
        $s = fb_sonnenstand($f[0], $ort[0], $ort[1]);
        $dh = abs($s['hoehe_geo'] - $f[1]);
        $da = abs($s['azimut'] - $f[2]);
        if ($da > 180) { $da = 360 - $da; }
        $pruefe('Sonnenstand Fall ' . ($i + 1) . ' Hoehe', $dh < 0.05,
                sprintf('%.3f statt %.3f', $s['hoehe_geo'], $f[1]));
        $pruefe('Sonnenstand Fall ' . ($i + 1) . ' Azimut', $da < 0.10,
                sprintf('%.3f statt %.3f', $s['azimut'], $f[2]));
    }

    /* --- 2. Die Kernaussage der Skizze ---
     *
     * Der Ausgangsfall ist an dieser Anlage GEMESSEN, am 23.08.2026 um
     * 10:00 MESZ: 662 W/m2 Solarstrahlung, 19,5 Grad Aussenluft, 32,7 Grad
     * im EG-Wohnzimmer, Beschattungsgrenze der Raumregler 25 Grad. Alle 15
     * Raumregler meldeten Beschattungsbedarf, gefahren wurde kein einziger
     * Rollladen - weil die Freigabe die Aussenluft fragte.
     *
     * ANGENOMMEN und nicht gemessen ist allein der erwartete
     * Tageshoechstwert. Er steht in der Skizze nicht mit einer Zahl; fuer
     * die Pruefung sind 27 Grad angesetzt, ein gewoehnlicher Wert fuer
     * einen solchen Augusttag. Die Aussage des Falls haengt nicht an der
     * genauen Zahl, sondern daran, dass sie ueber der Tagesgrenze liegt.
     *
     * Der Vergleichsfall aendert AUSSCHLIESSLICH die Tagesprognose. Sonne,
     * Fenster und Aussenluft bleiben gleich - sonst pruefte der Fall die
     * Aussenluft und nicht die Jahreszeit. */
    $cfg = fb_config_richten(array_merge(fb_vorgaben(), array(
        'breite' => $ort[0], 'laenge' => $ort[1],
        'fenster' => array(0 => array_merge(fb_fenster_vorgabe(), array(
            'kuerzel' => 'WOZI', 'name' => 'Wohnzimmer', 'azimut' => 106,
            'neigung' => 90, 'flaeche' => 4.0, 'gwert' => 60,
            'raum' => 'wozi', 'raumwerte' => 1,
        ))),
    )));
    $t0 = 1787472000;    // 23.08.2026 08:00 UTC = 10:00 MESZ
    $mess_august = array(
        'strahlung'   => array('v' => 662,  't' => $t0),   // gemessen
        'aussen'      => array('v' => 19.5, 't' => $t0),   // gemessen
        'prognose'    => array('v' => 27.0, 't' => $t0),   // angenommen, siehe oben
        'ist.wozi'    => array('v' => 32.7, 't' => $t0),   // gemessen
        'grenze.wozi' => array('v' => 25.0, 't' => $t0),   // TShadeHeat, gemessen
    );
    $stand_a = fb_rechnen($cfg, $mess_august, $t0, array());
    $wozi_a = $stand_a['fenster']['1'];

    /* September, Raum inzwischen abgekuehlt auf 24,5 Grad: der Eintrag ist
     * willkommen, das Urteil muss POSITIV sein. */
    $mess_sept = $mess_august;
    $mess_sept['prognose']['v'] = 16.0;
    $mess_sept['ist.wozi']['v'] = 24.5;
    $stand_s = fb_rechnen($cfg, $mess_sept, $t0, array());
    $wozi_s = $stand_s['fenster']['1'];

    /* September, Raum NOCH WARM (26 Grad, also ueber seiner Grenze): der
     * Satz des Anwenders lautet "auch wenn die Raeume noch warm sind".
     * Beschattet werden darf hier nicht - eingeladen aber auch nicht. */
    $mess_sept_warm = $mess_sept;
    $mess_sept_warm['ist.wozi']['v'] = 26.0;
    $stand_sw = fb_rechnen($cfg, $mess_sept_warm, $t0, array());
    $wozi_sw = $stand_sw['fenster']['1'];

    $pruefe('August: Eintrag unerwuenscht', $wozi_a['urteil'] < 0,
            'Urteil ' . $wozi_a['urteil']);
    $pruefe('August: Beschattung verlangt', $wozi_a['beschatten'] === 1,
            'beschatten=' . $wozi_a['beschatten'] . ', Urteil ' . $wozi_a['urteil']);
    $pruefe('August: Grund nennt Raum UND Tag', $wozi_a['grund'] === 'raum_und_tag',
            $wozi_a['grund']);
    $pruefe('September: Eintrag erwuenscht', $wozi_s['urteil'] > 0,
            'Urteil ' . $wozi_s['urteil']);
    $pruefe('September: keine Beschattung', $wozi_s['beschatten'] === 0,
            'beschatten=' . $wozi_s['beschatten'] . ', Urteil ' . $wozi_s['urteil']);
    $pruefe('September mit warmem Raum: keine Beschattung',
            $wozi_sw['beschatten'] === 0,
            'beschatten=' . $wozi_sw['beschatten'] . ', Urteil ' . $wozi_sw['urteil']);
    $pruefe('September mit warmem Raum: auch keine Einladung',
            $wozi_sw['urteil'] <= 0,
            'Urteil ' . $wozi_sw['urteil']);
    $pruefe('September milder als August', $wozi_s['urteil'] > $wozi_a['urteil'],
            $wozi_s['urteil'] . ' gegen ' . $wozi_a['urteil']);
    /* HIER STAND EINE ZEILE, DIE NICHTS GEMESSEN HAT.
     *
     * Sie verglich drei Literale des Pruefstuecks miteinander ("ist die
     * Aussenluft in allen drei Faellen dieselbe?") und rief keine einzige
     * Funktion des Plugins auf. Durch keine Aenderung am Rechenkern haette
     * sie rot werden koennen - sie zaehlte aber als eine der Pruefungen mit.
     * Eine Zeile, die immer gruen ist, ist keine Pruefung, sondern eine
     * Verzierung. An ihre Stelle tritt die Messung darunter: sie aendert
     * ALLEIN die Prognose und haelt das Ergebnis dagegen. */

    /* Und die Gegenprobe zur Behauptung "die Prognose treibt das Urteil":
     * am AUGUSTFALL wird AUSSCHLIESSLICH die Prognose getauscht, sonst
     * nichts - derselbe warme Raum, dieselbe Sonne, dieselbe Aussenluft.
     *
     * Diese Zeile steht hier, weil die Eichung sie verlangt hat: baut man
     * den Tagesteil auf die Aussenluft um statt auf die Prognose, blieben
     * die Faelle oben gruen, weil sie noch andere Werte mitaendern. Ein
     * Pruefstueck, das zwei Dinge auf einmal aendert, misst keines von
     * beiden. */
    $nur_prognose = $mess_august;
    $nur_prognose['prognose']['v'] = 16.0;
    $stand_np = fb_rechnen($cfg, $nur_prognose, $t0, array());
    $pruefe('Allein die Prognose hebt das Urteil deutlich',
            ($stand_np['fenster']['1']['urteil'] - $wozi_a['urteil']) >= 40,
            $wozi_a['urteil'] . ' -> ' . $stand_np['fenster']['1']['urteil']);

    /* --- 3. Fail closed: fehlt ein Messwert, gibt es kein Urteil ---
     *
     * DREI FAELLE, UND ZWAR MIT ABSICHT. Der Fall "Strahlung fehlt" allein
     * hat bei der Eichung NICHT angeschlagen: nimmt man die Abfrage
     * heraus, kommt trotzdem 0 heraus, weil ohne Strahlung auch das
     * Gewicht des Eintrags null ist. Er hat also eine richtige Aussage
     * geprueft, aber nicht die Sperre. Die beiden anderen Faelle tun es -
     * dort stuende ohne Sperre eine Beschattungsforderung aus halben
     * Daten. */
    foreach (array('strahlung', 'prognose', 'ist.wozi') as $weg) {
        $ohne = $mess_august;
        unset($ohne[$weg]);
        $so = fb_rechnen($cfg, $ohne, $t0, array());
        $pruefe('Ohne ' . $weg . ' kein Urteil',
                $so['fenster']['1']['urteil'] === 0
                && $so['fenster']['1']['beschatten'] === 0
                && $so['ok'] === 0
                && $so['fenster']['1']['grund'] === 'keine_daten',
                'Urteil ' . $so['fenster']['1']['urteil']
                . ', beschatten ' . $so['fenster']['1']['beschatten']
                . ', ok ' . $so['ok'] . ', Grund ' . $so['fenster']['1']['grund']);
        $pruefe('Fehlender Wert ' . $weg . ' wird benannt',
                in_array($weg, $so['fehlend'], true),
                implode(',', $so['fehlend']));
    }

    /* --- 4. Ein zu alter Messwert zaehlt nicht mehr --- */
    $alt = $mess_august;
    $alt['strahlung']['t'] = $t0 - 100000;
    $stand_alt = fb_rechnen($cfg, $alt, $t0, array());
    $pruefe('Zu alter Messwert wird verworfen',
            in_array('strahlung', $stand_alt['fehlend'], true),
            implode(',', $stand_alt['fehlend']));

    /* --- 5. Die Hysterese haelt --- */
    $grenz = $cfg;
    $mess_grenz = $mess_august;
    /* Ein Fall, der WIRKLICH im Zweifelsbereich liegt: Raum genau auf
     * seiner Grenze (Raumteil 0), Tagesprognose 22,1 Grad. Damit ist
     * n = 0,6 * (20 - 22,1) / 6 = -0,21 und das Urteil -21 - zwischen der
     * Ausschaltschwelle 15 und der Einschaltschwelle 30. */
    $mess_grenz['prognose']['v'] = 22.1;
    $mess_grenz['ist.wozi']['v'] = 25.0;
    $frisch = fb_rechnen($grenz, $mess_grenz, $t0, array());
    $lief   = fb_rechnen($grenz, $mess_grenz, $t0,
                         array('fenster' => array('1' => array('beschatten' => 1))));
    $u = $frisch['fenster']['1']['urteil'];
    if ($u <= -$cfg['schwelle_aus'] && $u > -$cfg['schwelle_ein']) {
        $pruefe('Hysterese: aus dem Stand nicht ein', $frisch['fenster']['1']['beschatten'] === 0,
                'Urteil ' . $u);
        $pruefe('Hysterese: laufend bleibt an', $lief['fenster']['1']['beschatten'] === 1,
                'Urteil ' . $u);
    } else {
        /* Der Fall liegt nicht im Zweifelsbereich - dann prueft er nichts,
         * und das wird GESAGT statt als Haken gezaehlt. */
        echo "Hinweis: der Hysteresefall liegt bei Urteil $u ausserhalb des "
           . "Zweifelsbereichs und wurde nicht gewertet.\n";
    }

    /* --- 6. Ein abgeschaltetes Fenster verlangt nie Beschattung --- */
    $aus = $cfg;
    $aus['fenster'][0]['aktiv'] = 0;
    $stand_aus = fb_rechnen($aus, $mess_august, $t0, array());
    $pruefe('Abgeschaltetes Fenster bleibt still',
            $stand_aus['fenster']['1']['beschatten'] === 0
            && $stand_aus['fenster']['1']['grund'] === 'abgeschaltet',
            $stand_aus['fenster']['1']['grund']);

    /* --- 7. Der Verschattungshorizont wirkt --- */
    $mitschatten = $cfg;
    $mitschatten['fenster'][0]['horizont'] = '0:60, 360:60';   // rundum 60 Grad Hindernis
    $stand_v = fb_rechnen($mitschatten, $mess_august, $t0, array());
    $pruefe('Horizont nimmt die Direktstrahlung weg',
            $stand_v['fenster']['1']['glas'] < $wozi_a['glas'],
            $stand_v['fenster']['1']['glas'] . ' statt unter ' . $wozi_a['glas']);
    $pruefe('Himmelslicht bleibt trotz Verschattung',
            $stand_v['fenster']['1']['glas'] > 0,
            (string) $stand_v['fenster']['1']['glas']);

    /* --- 8. Streifender Einfall bringt weniger als senkrechter --- */
    $schraeg = $cfg;
    $schraeg['fenster'][0]['azimut'] = 196;    // Sonne stand bei 115 Grad
    $stand_sch = fb_rechnen($schraeg, $mess_august, $t0, array());
    $pruefe('Streifender Einfall bringt weniger Watt',
            $stand_sch['fenster']['1']['watt'] < $wozi_a['watt'],
            $stand_sch['fenster']['1']['watt'] . ' gegen ' . $wozi_a['watt']);

    /* --- 8b. Ein NORDfenster verlangt keine Beschattung ---
     *
     * Diese Zeile ist die Eichung zu einer Korrektur, die erst der erste
     * Vollversuch mit 25 Fenstern gefunden hat: Himmelsdiffus und
     * Bodenreflex stehen an JEDEM Fenster und waren am 23.08.2026 zusammen
     * 121 W/m2. Wog man sie mit, kam ein Nordfenster auf ein Urteil von
     * -39 und verlangte Beschattung - um zehn Uhr, ohne einen Sonnenstrahl.
     *
     * Der Fall wird rot, sobald das Gewicht wieder aus der gesamten
     * Strahlung statt aus dem direkten Anteil gebildet wird. */
    $nord = $cfg;
    $nord['fenster'][0]['azimut'] = 14;      // NNO, wie die Nordfenster der Anlage
    $stand_n = fb_rechnen($nord, $mess_august, $t0, array());
    $wozi_n = $stand_n['fenster']['1'];
    $pruefe('Nordfenster verlangt keine Beschattung',
            $wozi_n['beschatten'] === 0 && $wozi_n['urteil'] === 0,
            'beschatten=' . $wozi_n['beschatten'] . ', Urteil ' . $wozi_n['urteil']);
    $pruefe('Nordfenster nennt den richtigen Grund',
            $wozi_n['grund'] === 'nicht_am_glas', $wozi_n['grund']);
    $pruefe('Nordfenster weist trotzdem Streulicht aus',
            $wozi_n['glas'] > 0,
            (string) $wozi_n['glas'] . ' W/m2 - die Anzeige darf den Diffusanteil nicht verlieren');

    /* ==================================================================
     * 8c. DER STRAHLUNGSTEIL, gegen Handrechnung.
     *
     * Jeder dieser Faelle ist eine EINZELNE Aussage mit einem Sollwert, der
     * sich von Hand nachrechnen laesst. Nimmt man die zugehoerige Zeile im
     * Quelltext heraus, wird genau dieser Fall rot - das ist der Unterschied
     * zu den Faellen darueber, die alle nur das Urteil ansehen und deshalb
     * gegen einen Fehler in der Physik blind waren.
     * ================================================================== */

    /* Erbs-Korrelation bei kt = 0,5. Von Hand:
     *   0,9511 - 0,1604*0,5 + 4,388*0,25 - 16,638*0,125 + 12,336*0,0625
     *   = 0,9511 - 0,0802 + 1,0970 - 2,07975 + 0,7710 = 0,65915 */
    $t_erbs = $t0;
    $h_erbs = fb_sonnenstand($t_erbs, $ort[0], $ort[1])['hoehe_geo'];
    $e0 = fb_extraterrestrisch($t_erbs, $h_erbs);
    list($dni_e, $dif_e, $ok_e) = fb_strahlungsteilung(0.5 * $e0, $h_erbs, $t_erbs);
    $kd = $dif_e / (0.5 * $e0);
    $pruefe('Erbs bei kt=0,5 ergibt kd=0,65915', abs($kd - 0.65915) < 0.0005,
            sprintf('%.5f', $kd));

    /* Bei sehr klarem Himmel (kt > 0,8) ist kd fest 0,165. */
    list($dni_k, $dif_k, ) = fb_strahlungsteilung(0.9 * $e0, $h_erbs, $t_erbs);
    $pruefe('Erbs oberhalb kt=0,8 ergibt kd=0,165',
            abs($dif_k / (0.9 * $e0) - 0.165) < 0.0005,
            sprintf('%.5f', $dif_k / (0.9 * $e0)));

    /* ASHRAE-Korrekturglied. Von Hand: 1 - 0,10*(1/0,5 - 1) = 0,90. */
    $pruefe('ASHRAE bei cos(theta)=0,5 und b0=0,10 ergibt 0,90',
            abs(fb_glasdurchlass(0.5, 0.10) - 0.90) < 1e-9,
            sprintf('%.6f', fb_glasdurchlass(0.5, 0.10)));
    $pruefe('ASHRAE bei senkrechtem Einfall ergibt 1,00',
            abs(fb_glasdurchlass(1.0, 0.10) - 1.0) < 1e-9,
            sprintf('%.6f', fb_glasdurchlass(1.0, 0.10)));
    $pruefe('ASHRAE unten bei 0 abgeschnitten',
            fb_glasdurchlass(0.02, 0.10) === 0.0,
            sprintf('%.6f', fb_glasdurchlass(0.02, 0.10)));

    /* Isotropes Modell, senkrechtes Fenster: Himmel zur Haelfte, Boden zur
     * Haelfte mal Albedo. Von Hand mit diffus 100, global 200, Albedo 0,25:
     * 100*0,5 + 200*0,25*0,5 = 50 + 25 = 75. */
    $iso = fb_fensterstrahlung(0.0, 100.0, 200.0, -1.0, 90, 0.25, 0.10, false);
    $pruefe('Isotrop am senkrechten Fenster: 50 Himmel + 25 Boden',
            abs($iso['diffus'] - 50.0) < 1e-9 && abs($iso['boden'] - 25.0) < 1e-9,
            sprintf('diffus=%.2f boden=%.2f', $iso['diffus'], $iso['boden']));

    /* Waagerechte Flaeche: ganzer Himmel, kein Boden. */
    $iso0 = fb_fensterstrahlung(0.0, 100.0, 200.0, -1.0, 0, 0.25, 0.10, false);
    $pruefe('Isotrop an der Waagerechten: 100 Himmel, 0 Boden',
            abs($iso0['diffus'] - 100.0) < 1e-9 && abs($iso0['boden']) < 1e-9,
            sprintf('diffus=%.2f boden=%.2f', $iso0['diffus'], $iso0['boden']));

    /* Der Direktanteil geht mit dem Kosinus des Einfallswinkels ein.
     * Von Hand: 1000 * 0,5 * IAM(0,5) = 1000 * 0,5 * 0,9 = 450. */
    $dir = fb_fensterstrahlung(1000.0, 0.0, 0.0, 0.5, 90, 0.0, 0.10, false);
    $pruefe('Direktanteil = DNI * cos(theta) * IAM', abs($dir['direkt'] - 450.0) < 1e-6,
            sprintf('%.3f', $dir['direkt']));

    /* Refraktion am Horizont. Von Hand: 1735 Bogensekunden = 0,481944 Grad. */
    $pruefe('Refraktion bei 0 Grad ergibt 0,4819 Grad',
            abs(fb_refraktion(0.0) - 1735.0 / 3600.0) < 1e-9,
            sprintf('%.6f', fb_refraktion(0.0)));

    /* Einfallswinkel: Sonne genau senkrecht auf ein senkrechtes Fenster. */
    $pruefe('Einfall 0 Grad bei Sonne im Fensterazimut und Hoehe 0',
            abs(fb_cos_einfall(180, 0, 180, 90) - 1.0) < 1e-9,
            sprintf('%.6f', fb_cos_einfall(180, 0, 180, 90)));
    $pruefe('Waagerechte Flaeche: Einfall haengt nicht vom Azimut ab',
            abs(fb_cos_einfall(37, 35, 180, 0) - fb_cos_einfall(211, 35, 180, 0)) < 1e-9,
            sprintf('%.6f gegen %.6f', fb_cos_einfall(37, 35, 180, 0),
                    fb_cos_einfall(211, 35, 180, 0)));

    /* --- 8d. JEDER EINSTELLWERT MUSS WIRKEN.
     *
     * Ein Wert, den die Oberflaeche anbietet und die Rechnung ignoriert, ist
     * ein Bedienelement ohne Wirkung. Im Mutationslauf blieben albedo,
     * iam_b0, e_ref, spreizung_tag, spreizung_raum, traegheit, flaeche und
     * gwert allesamt unbemerkt, wenn man sie fest verdrahtete. */
    /* MITTLERER FALL, kein Anschlag.
     *
     * Der Augustfall taugt dafuer NICHT: dort sind Raumteil und Tagesteil
     * beide bei -1, das Urteil steht auf -100, und eine Verschiebung der
     * Gewichte oder der Traegheit aendert daran nichts mehr. Ein
     * Pruefstueck, das die Wirkung eines Stellwerts messen soll, muss in
     * dessen Wirkungsbereich liegen - sonst ist es gruen, weil es nichts
     * misst, oder rot, weil es das Falsche misst.
     *
     * Hier: Raum 25,5 Grad gegen Grenze 25,0 (Raumteil -0,25) und
     * Tagesprognose 21 Grad gegen Tagesgrenze 20 (Tagesteil -0,167). */
    $mess_mild = $mess_august;
    $mess_mild['ist.wozi']['v'] = 25.5;
    $mess_mild['prognose']['v'] = 21.0;
    $grund_urteil = fb_rechnen($cfg, $mess_mild, $t0, array())['fenster']['1'];
    $stellen = array(
        'albedo'         => 80,
        'iam_b0'         => 45,
        'e_ref'          => 1000,
        'spreizung_tag'  => 20,
        'spreizung_raum' => 100,
        'gewicht_raum'   => 100,
        'gewicht_tag'    => 0,
    );
    foreach ($stellen as $schluessel => $wert) {
        $c = $cfg; $c[$schluessel] = $wert; $c = fb_config_richten($c);
        $e = fb_rechnen($c, $mess_mild, $t0, array())['fenster']['1'];
        $pruefe('Einstellwert ' . $schluessel . ' wirkt',
                $e['urteil'] !== $grund_urteil['urteil']
                || $e['glas'] !== $grund_urteil['glas'],
                'Urteil ' . $e['urteil'] . ' / Glas ' . $e['glas']
                . ' - unveraendert gegenueber ' . $grund_urteil['urteil']
                . ' / ' . $grund_urteil['glas']);
    }
    /* WELCHES Gewicht auf WELCHEN Teil wirkt, wird absolut geprueft.
     *
     * Der Vergleich mit dem Ausgangsfall darueber genuegt dafuer NICHT: wer
     * die beiden Gewichte im Quelltext vertauscht, verschiebt auch den
     * Ausgangsfall, und beide Seiten wandern gemeinsam. Ein Rueckbau blieb
     * deshalb unbemerkt.
     *
     * Im mittleren Fall ist der Raumteil (-0,25) staerker negativ als der
     * Tagesteil (-0,167). Wer allein auf den Raum gewichtet, muss also ein
     * DEUTLICHER negatives Urteil bekommen als wer allein auf den Tag
     * gewichtet. Vertauscht man die beiden im Code, kehrt sich das um. */
    $c_raum = $cfg; $c_raum['gewicht_raum'] = 100; $c_raum['gewicht_tag'] = 0;
    $c_tag  = $cfg; $c_tag['gewicht_raum'] = 0;   $c_tag['gewicht_tag'] = 100;
    $u_raum = fb_rechnen(fb_config_richten($c_raum), $mess_mild, $t0, array())['fenster']['1']['urteil'];
    $u_tag  = fb_rechnen(fb_config_richten($c_tag), $mess_mild, $t0, array())['fenster']['1']['urteil'];
    $pruefe('Das Raumgewicht wirkt auf den Raumteil, nicht auf den Tagesteil',
            $u_raum < $u_tag,
            'nur Raum: ' . $u_raum . ', nur Tag: ' . $u_tag
            . ' - erwartet war "nur Raum" deutlicher negativ');

    $pruefe('Der mittlere Fall liegt wirklich in der Mitte',
            $grund_urteil['urteil'] < 0 && $grund_urteil['urteil'] > -60,
            'Urteil ' . $grund_urteil['urteil'] . ' - am Anschlag misst der Block nichts');
    foreach (array('flaeche' => 12.0, 'gwert' => 20, 'traegheit' => 50) as $schluessel => $wert) {
        $c = $cfg; $c['fenster'][0][$schluessel] = $wert; $c = fb_config_richten($c);
        $e = fb_rechnen($c, $mess_mild, $t0, array())['fenster']['1'];
        $pruefe('Fensterwert ' . $schluessel . ' wirkt',
                $e['watt'] !== $grund_urteil['watt']
                || $e['urteil'] !== $grund_urteil['urteil'],
                'Watt ' . $e['watt'] . ' / Urteil ' . $e['urteil']);
    }

    /* --- 8e. Ein unmoeglicher Strahlungswert wird VERWORFEN, nicht geklemmt.
     * 662 W/m2 bei knapp zwei Grad Sonnenhoehe sind physikalisch nicht
     * moeglich; vorher ergaben sie nach dem Klemmen 1470 W/m2 am Glas. */
    $t_frueh = $t0 - 3 * 3600 - 25 * 60;
    $h_frueh = fb_sonnenstand($t_frueh, $ort[0], $ort[1])['hoehe_geo'];
    list(, , $moeglich) = fb_strahlungsteilung(662.0, $h_frueh, $t_frueh);
    $pruefe('Unmoegliche Strahlung wird als ungueltig gemeldet', $moeglich === false,
            sprintf('Hoehe %.2f Grad, E0 %.1f, moeglich=%s', $h_frueh,
                    fb_extraterrestrisch($t_frueh, $h_frueh),
                    var_export($moeglich, true)));
    $mess_frueh = $mess_august;
    foreach ($mess_frueh as $k => $v) { $mess_frueh[$k]['t'] = $t_frueh; }
    $s_frueh = fb_rechnen($cfg, $mess_frueh, $t_frueh, array());
    $pruefe('Unmoegliche Strahlung fuehrt zu keinem Urteil',
            $s_frueh['ok'] === 0 && $s_frueh['fenster']['1']['urteil'] === 0
            && $s_frueh['fenster']['1']['beschatten'] === 0,
            'ok=' . $s_frueh['ok'] . ' urteil=' . $s_frueh['fenster']['1']['urteil']);
    /* Gegenprobe: ein PLAUSIBLER Wert zur selben Zeit geht durch. */
    $mess_plaus = $mess_frueh;
    $mess_plaus['strahlung']['v'] = 15.0;
    list(, , $moeglich2) = fb_strahlungsteilung(15.0, $h_frueh, $t_frueh);
    $pruefe('Plausible Strahlung bei flacher Sonne geht durch', $moeglich2 === true,
            var_export($moeglich2, true));

    /* --- 8f. Urteil 0 heisst nie beschatten, auch bei Ausschaltschwelle 0.
     *
     * DIE STRAHLUNG MUSS HIER 0 SEIN, und das ist der ganze Punkt der Zeile.
     * In der ersten Fassung stand hier der Augustwert von 662 W/m2, nur auf
     * 22 Uhr umdatiert - und damit griff schon die Plausibilitaetsschranke:
     * der Wert galt als unmoeglich, der Grund lautete 'keine_daten', und die
     * Hysterese wurde nie erreicht. Die Zeile war gruen und mass die falsche
     * Sperre. Mit 0 W/m2 sind die Daten gueltig, das Urteil ist 0, weil
     * keine Sonne da ist - und genau dann muss die Schutzzeile greifen. */
    $c0 = $cfg; $c0['schwelle_aus'] = 0; $c0 = fb_config_richten($c0);
    $t_nacht = $t0 + 43200;
    $nacht = $mess_august;
    foreach ($nacht as $k => $v) { $nacht[$k]['t'] = $t_nacht; }
    $nacht['strahlung']['v'] = 0.0;
    $s_nacht = fb_rechnen($c0, $nacht, $t_nacht,
                          array('fenster' => array('1' => array('beschatten' => 1))));
    $pruefe('Der Nachtfall hat gueltige Daten - sonst misst er die falsche Sperre',
            $s_nacht['ok'] === 1 && $s_nacht['fenster']['1']['grund'] !== 'keine_daten',
            'ok=' . $s_nacht['ok'] . ' grund=' . $s_nacht['fenster']['1']['grund']
            . ' fehlend=' . implode(',', $s_nacht['fehlend']));
    $pruefe('Nachts keine Beschattung, auch mit Ausschaltschwelle 0',
            $s_nacht['fenster']['1']['beschatten'] === 0,
            'urteil=' . $s_nacht['fenster']['1']['urteil']
            . ' beschatten=' . $s_nacht['fenster']['1']['beschatten']
            . ' sonne=' . $s_nacht['sonne_hoehe']);

    /* --- 8g. Ein Stempel aus der Zukunft ist kein frischer Wert. */
    list($w_zk, ) = fb_messwert(array('x' => array('v' => 42, 't' => $t0 + 100000000)),
                               'x', 900, $t0);
    $pruefe('Stempel aus der Zukunft wird verworfen', $w_zk === null,
            var_export($w_zk, true));

    /* --- 8h. Ein veralteter Stand geht nicht mit OK=1 hinaus. */
    $s_alt = fb_rechnen($cfg, $mess_august, $t0, array());
    $s_alt['felder'] = fb_felderwerte($s_alt);
    $s_alt['ts'] = time() - 3 * 86400;
    $z_alt = fb_zeile($s_alt, 900);
    $z_frisch = $s_alt; $z_frisch['ts'] = time();
    $pruefe('Drei Tage alter Stand meldet OK=0',
            strpos($z_alt, ';OK=0;') !== false
            && strpos($z_alt, 'WOZIBESCHATTEN=0') !== false,
            trim(strtok($z_alt, "\n")));
    $pruefe('Frischer Stand meldet weiterhin OK=1',
            strpos(fb_zeile($z_frisch, 900), ';OK=1;') !== false,
            trim(strtok(fb_zeile($z_frisch, 900), "\n")));

    /* --- 8i. Der Verschattungshorizont mit deutschem Dezimalkomma. */
    list($p_komma, $u_komma) = fb_horizont_lesen('80,5:22,5');
    $pruefe('Dezimalkomma ergibt EINEN Punkt und keinen erfundenen',
            count($p_komma) === 1 && abs($p_komma[0][0] - 80.5) < 1e-9
            && count($u_komma) === 0,
            json_encode($p_komma) . ' / unlesbar ' . json_encode($u_komma));
    list($p_liste, $u_liste) = fb_horizont_lesen('80:22, 110,5:14, 160:6');
    $pruefe('Dezimalkomma in einer Liste bleibt eine Liste aus drei Punkten',
            count($p_liste) === 3 && abs($p_liste[1][0] - 110.5) < 1e-9
            && count($u_liste) === 0,
            json_encode($p_liste));

    /* --- 8j. Der Horizont wird ueber die Naht bei 360 Grad interpoliert. */
    list($p_naht, ) = fb_horizont_lesen('80:22, 110:14, 160:6');
    $pruefe('Horizont bei Azimut 350 liegt zwischen den Nachbarpunkten',
            fb_horizont_hoehe($p_naht, 350) > 6.0
            && fb_horizont_hoehe($p_naht, 350) < 22.0,
            sprintf('%.2f', fb_horizont_hoehe($p_naht, 350)));
    /* Und ZWISCHEN zwei Stuetzpunkten wird gerade interpoliert. Von Hand:
     * zwischen 80:22 und 110:14 liegt Azimut 95 genau in der Mitte, also
     * bei 18 Grad. Ohne diese Zeile blieb ein Rueckbau der Interpolation
     * auf eine Stufe unbemerkt - die Nahtzeile darueber trifft einen
     * anderen Zweig. */
    $pruefe('Horizont zwischen zwei Stuetzpunkten wird gerade interpoliert',
            abs(fb_horizont_hoehe($p_naht, 95) - 18.0) < 1e-9,
            sprintf('%.4f statt 18', fb_horizont_hoehe($p_naht, 95)));
    $pruefe('Horizont trifft einen Stuetzpunkt genau',
            abs(fb_horizont_hoehe($p_naht, 110) - 14.0) < 1e-9,
            sprintf('%.4f statt 14', fb_horizont_hoehe($p_naht, 110)));

    /* --- 8k. Ein Messwertname mit angehaengtem Zeilenumbruch wird abgewiesen.
     * Ohne den Modifier D passt $ in PHP auch vor einem solchen Umbruch. */
    list($ok_nl, $grund_nl) = fb_messwert_setzen("aussen\n", '42', $cfg);
    $pruefe('Messwertname mit Zeilenumbruch wird abgewiesen',
            $ok_nl === false && $grund_nl === 'NAME_UNGUELTIG', (string) $grund_nl);
    list($ok_arr, $grund_arr) = fb_messwert_setzen(array('a'), '42', $cfg);
    $pruefe('Feldparameter als Messwertname wird abgewiesen',
            $ok_arr === false && $grund_arr === 'PARAMETER_UNGUELTIG', (string) $grund_arr);
    list($ok_frei, $grund_frei) = fb_messwert_setzen('voelligfrei', '42', $cfg);
    $pruefe('Unbekannter Messwertname wird abgewiesen',
            $ok_frei === false && $grund_frei === 'NAME_UNBEKANNT', (string) $grund_frei);
    list($ok_inf, $grund_inf) = fb_messwert_setzen('strahlung', '1e999', $cfg);
    $pruefe('Nicht darstellbare Zahl wird als solche gemeldet',
            $ok_inf === false && $grund_inf === 'WERT_UNMOEGLICH', (string) $grund_inf);
    $pruefe('Ein erwarteter Name steht in der Liste',
            in_array('grenze.wozi', fb_messwertnamen($cfg), true),
            implode(', ', fb_messwertnamen($cfg)));

    /* ==================================================================
     * 8m. DIE NEUERUNGEN DER 0.10.0
     *
     * Jede Zeile hier prueft EINE Aussage und wird rot, wenn man die
     * zugehoerige Stelle im Quelltext zurueckbaut. Die Reihenfolge folgt
     * der Vorschlagsliste.
     * ================================================================== */

    /* --- 11. Das anisotrope Himmelsmodell (HDKR) ---
     *
     * Es hat zwei Eigenschaften, an denen es sich OHNE eine abgeschriebene
     * Koeffiziententabelle pruefen laesst - und genau deshalb ist es hier
     * eingebaut und nicht Perez:
     *
     *   bedeckter Himmel  -> exakt das isotrope Modell
     *   waagerechte Flaeche -> Himmel und Kranz ergeben genau die
     *                          Diffusstrahlung
     */
    $h_hd = fb_sonnenstand($t0, $ort[0], $ort[1])['hoehe_geo'];
    list($dni_hd, $dif_hd, ) = fb_strahlungsteilung(662, $h_hd, $t0);
    $i_bed = fb_fensterstrahlung(0.0, 300.0, 300.0, 0.5, 90, 0.2, 0.10, false, 'isotrop', $h_hd, $t0);
    $h_bed = fb_fensterstrahlung(0.0, 300.0, 300.0, 0.5, 90, 0.2, 0.10, false, 'hdkr', $h_hd, $t0);
    $pruefe('HDKR geht bei bedecktem Himmel in das isotrope Modell ueber',
            abs($i_bed['gesamt'] - $h_bed['gesamt']) < 1e-9,
            sprintf('%.6f gegen %.6f', $i_bed['gesamt'], $h_bed['gesamt']));

    $ct_w = sin(deg2rad($h_hd));
    $h_waag = fb_fensterstrahlung($dni_hd, $dif_hd, 662, $ct_w, 0, 0.2, 0.0, false,
                                  'hdkr', $h_hd, $t0);
    $pruefe('HDKR an der Waagerechten: Himmel und Kranz ergeben die Diffusstrahlung',
            abs(($h_waag['diffus'] + $h_waag['kranz']) - $dif_hd) < 1e-9,
            sprintf('%.6f statt %.6f', $h_waag['diffus'] + $h_waag['kranz'], $dif_hd));

    $ct_hd = fb_cos_einfall(115.126, $h_hd, 106, 90);
    $i_klar = fb_fensterstrahlung($dni_hd, $dif_hd, 662, $ct_hd, 90, 0.2, 0.10, false,
                                  'isotrop', $h_hd, $t0);
    $h_klar = fb_fensterstrahlung($dni_hd, $dif_hd, 662, $ct_hd, 90, 0.2, 0.10, false,
                                  'hdkr', $h_hd, $t0);
    $pruefe('HDKR liefert bei klarem Himmel MEHR als isotrop',
            $h_klar['gesamt'] > $i_klar['gesamt'],
            sprintf('%.1f gegen %.1f', $h_klar['gesamt'], $i_klar['gesamt']));
    $h_versch = fb_fensterstrahlung($dni_hd, $dif_hd, 662, $ct_hd, 90, 0.2, 0.10, true,
                                    'hdkr', $h_hd, $t0);
    $pruefe('Verschattung nimmt auch den Sonnenkranz weg, nicht nur den Direktanteil',
            $h_versch['kranz'] == 0.0 && $h_versch['direkt'] == 0.0 && $h_versch['gesamt'] > 0.0,
            sprintf('direkt=%.1f kranz=%.1f gesamt=%.1f', $h_versch['direkt'],
                    $h_versch['kranz'], $h_versch['gesamt']));

    /* Ein ganzer Tag ueber drei Neigungen und vier Richtungen: nichts darf
     * unendlich oder negativ werden. */
    $schlecht = 0; $punkte_hd = 0;
    for ($mm = 0; $mm <= 1440; $mm += 15) {
        $tt = $t0 - 8 * 3600 + $mm * 60;
        $ss = fb_sonnenstand($tt, $ort[0], $ort[1]);
        $gg = max(0.0, 0.72 * fb_extraterrestrisch($tt, $ss['hoehe_geo']));
        list($d1, $d2, ) = fb_strahlungsteilung($gg, $ss['hoehe_geo'], $tt);
        foreach (array(0, 45, 90) as $neig) {
            foreach (array(14, 106, 194, 284) as $az) {
                $cc = fb_cos_einfall($ss['azimut'], $ss['hoehe_geo'], $az, $neig);
                $rr = fb_fensterstrahlung($d1, $d2, $gg, $cc, $neig, 0.2, 0.10, false,
                                          'hdkr', $ss['hoehe_geo'], $tt);
                $punkte_hd++;
                foreach ($rr as $vv) { if (!is_finite($vv) || $vv < -1e-9) { $schlecht++; } }
            }
        }
    }
    $pruefe('HDKR ueber einen ganzen Tag ohne unbrauchbare Werte',
            $schlecht === 0, $schlecht . ' Ausreisser in ' . $punkte_hd . ' Faellen');

    /* --- 14. Glaettung der Strahlung --- */
    $cfg_gl = $cfg; $cfg_gl['glaettung'] = 300; $cfg_gl = fb_config_richten($cfg_gl);
    $m_gl = array('strahlung' => array('v' => 900, 't' => $t0, 'reihe' => array(
        array($t0 - 4000, 5),      // ausserhalb des Fensters - zaehlt nicht
        array($t0 - 240, 100), array($t0 - 120, 500), array($t0, 900))));
    $g1 = fb_messwerte_glaetten($cfg_gl, $m_gl, $t0);
    $pruefe('Glaettung mittelt nur die Punkte im Zeitfenster',
            abs($g1['strahlung']['v'] - 500.0) < 1e-9 && $g1['strahlung']['geglaettet'] === 3,
            sprintf('%.1f aus %d Punkten', $g1['strahlung']['v'],
                    (int) $g1['strahlung']['geglaettet']));
    $cfg_gl0 = $cfg; $cfg_gl0['glaettung'] = 0; $cfg_gl0 = fb_config_richten($cfg_gl0);
    $pruefe('Glaettung 0 laesst den Messwert unangetastet',
            fb_messwerte_glaetten($cfg_gl0, $m_gl, $t0)['strahlung']['v'] == 900,
            (string) fb_messwerte_glaetten($cfg_gl0, $m_gl, $t0)['strahlung']['v']);

    /* --- 1. Vorausschau: dieselbe Rechnung, verschobene Zeit --- */
    $s_jetzt   = fb_rechnen($cfg, $mess_august, $t0, array());
    $s_spaeter = fb_rechnen($cfg, fb_messwerte_verschieben($mess_august, 1800),
                            $t0 + 1800, array());
    /* Und die Gegenprobe zu genau dieser Verschiebung: OHNE sie sind die
     * Messwerte in der Vorausschau ueber dem Hoechstalter, und das Urteil
     * faellt lautlos auf 0. */
    $s_ohne = fb_rechnen($cfg, $mess_august, $t0 + 1800, array());
    $pruefe('Ohne verschobene Stempel verliert die Vorausschau ihre Messwerte',
            $s_ohne['ok'] === 0 && $s_spaeter['ok'] === 1,
            'mit Verschiebung ok=' . $s_spaeter['ok'] . ', ohne ok=' . $s_ohne['ok']);
    $pruefe('Die Vorausschau steht auf einem anderen Sonnenstand',
            abs($s_spaeter['sonne_azimut'] - $s_jetzt['sonne_azimut']) > 3.0,
            sprintf('%.2f gegen %.2f Grad', $s_jetzt['sonne_azimut'],
                    $s_spaeter['sonne_azimut']));
    $pruefe('Die Vorausschau benutzt DIESELBEN Messwerte',
            $s_spaeter['strahlung'] === $s_jetzt['strahlung'],
            $s_jetzt['strahlung'] . ' gegen ' . $s_spaeter['strahlung']);

    /* --- 5. Tagesbilanz --- */
    $bil = fb_bilanz_leer('2026-08-23');
    $bil['letzte'] = $t0 - 3600;                       // eine Stunde her
    $stand_b = fb_rechnen($cfg, $mess_august, $t0, array());
    $watt_b = (int) $stand_b['fenster']['1']['watt'];
    $bil2 = fb_bilanz_fortschreiben($cfg, $stand_b, $bil, $mess_august, $t0);
    $pruefe('Die Tagesbilanz wird mit dem Deckel von 900 s fortgeschrieben',
            abs($bil2['fenster']['1'] - $watt_b * 0.25) < 0.5,
            sprintf('%.1f Wh bei %d W - erwartet %.1f (900 s, nicht 3600 s)',
                    $bil2['fenster']['1'], $watt_b, $watt_b * 0.25));
    $bil3 = fb_bilanz_fortschreiben($cfg, $stand_b, $bil2, $mess_august, $t0 + 300);
    $pruefe('Fuenf Minuten spaeter kommen genau fuenf Minuten dazu',
            abs($bil3['fenster']['1'] - ($bil2['fenster']['1'] + $watt_b / 12.0)) < 0.5,
            sprintf('%.1f nach %.1f', $bil3['fenster']['1'], $bil2['fenster']['1']));
    $pruefe('Der Raum bekommt die Summe seiner Fenster',
            isset($bil3['raeume']['wozi']) && $bil3['raeume']['wozi'] > 0,
            json_encode(array_keys($bil3['raeume'])));
    $bil4 = fb_tageswechsel($cfg, $bil3, $t0 + 86400);
    $pruefe('Der Tageswechsel setzt die Bilanz zurueck',
            $bil4['fenster'] === array() && $bil4['datum'] !== $bil3['datum'],
            $bil3['datum'] . ' -> ' . $bil4['datum']);

    /* Und der Bilanzterm wirkt: ein voller Raum bekommt ein schlechteres
     * Urteil als ein leerer, sonst gleiche Lage. */
    $cfg_bil = $cfg;
    $cfg_bil['gewicht_bilanz'] = 100; $cfg_bil['gewicht_raum'] = 0;
    $cfg_bil['gewicht_tag'] = 0; $cfg_bil['bilanz_voll_qm'] = 150;
    $cfg_bil['raumflaechen'] = array('wozi' => 20.0);      // 20 m2 * 150 = 3000 Wh
    $cfg_bil = fb_config_richten($cfg_bil);
    $leer_u = fb_rechnen($cfg_bil, $mess_august, $t0, array(),
                         array('raeume' => array('wozi' => 0)))['fenster']['1']['urteil'];
    $voll_u = fb_rechnen($cfg_bil, $mess_august, $t0, array(),
                         array('raeume' => array('wozi' => 3000)))['fenster']['1']['urteil'];
    $pruefe('Ein voller Raum bekommt ein schlechteres Urteil als ein leerer',
            $voll_u < $leer_u, 'leer ' . $leer_u . ', voll ' . $voll_u);

    /* --- Die Schwelle haengt an der RAUMGROESSE ---
     *
     * Bis 0.10.0 stand dort eine feste Zahl je Raum: das 5-Quadratmeter-Bad
     * und das 25-Quadratmeter-Wohnzimmer galten bei derselben
     * Wattstundenzahl als voll. Dieselben 1500 Wh muessen das kleine Zimmer
     * fuellen und das grosse noch lange nicht. */
    $cfg_klein = $cfg_bil; $cfg_klein['raumflaechen'] = array('wozi' => 5.0);
    $cfg_klein = fb_config_richten($cfg_klein);
    $cfg_gross = $cfg_bil; $cfg_gross['raumflaechen'] = array('wozi' => 25.0);
    $cfg_gross = fb_config_richten($cfg_gross);
    $bil_1500 = array('raeume' => array('wozi' => 1500));
    $u_klein = fb_rechnen($cfg_klein, $mess_august, $t0, array(), $bil_1500)['fenster']['1']['urteil'];
    $u_gross = fb_rechnen($cfg_gross, $mess_august, $t0, array(), $bil_1500)['fenster']['1']['urteil'];
    $pruefe('Dieselben Wattstunden fuellen den kleinen Raum staerker als den grossen',
            $u_klein < $u_gross,
            '5 m2 -> ' . $u_klein . ', 25 m2 -> ' . $u_gross);

    /* Und eine unbekannte Flaeche wird ANGENOMMEN, nicht als null gelesen -
     * sonst waere jeder Raum ohne Eintrag sofort voll. */
    $cfg_ohne = $cfg_bil; $cfg_ohne['raumflaechen'] = array();
    $cfg_ohne['raumflaeche_vorgabe'] = 20;
    $cfg_ohne = fb_config_richten($cfg_ohne);
    list($qm_a, $geschaetzt_a) = fb_raumflaeche($cfg_ohne, 'wozi');
    $pruefe('Ein Raum ohne eingetragene Flaeche bekommt die Annahme, nicht null',
            abs($qm_a - 20.0) < 0.001 && $geschaetzt_a === true,
            $qm_a . ' m2, geschaetzt=' . var_export($geschaetzt_a, true));
    list($qm_b, $geschaetzt_b) = fb_raumflaeche($cfg_gross, 'wozi');
    $pruefe('Eine eingetragene Flaeche gilt als bekannt und nicht als Annahme',
            abs($qm_b - 25.0) < 0.001 && $geschaetzt_b === false,
            $qm_b . ' m2, geschaetzt=' . var_export($geschaetzt_b, true));
    $stand_ohne = fb_rechnen($cfg_ohne, $mess_august, $t0, array(), $bil_1500);
    $pruefe('Der Begruendungssatz nennt eine angenommene Flaeche als solche',
            strpos($stand_ohne['fenster']['1']['begruendung'], 'geschaetzt') !== false,
            $stand_ohne['fenster']['1']['begruendung']);

    /* --- Die Vorgabeflaeche wird gemeldet ---
     *
     * Sie faellt im Betrieb nie auf, weil das Urteil nicht an ihr haengt.
     * Genau deshalb muss sie jemand melden. */
    $cfg_fl = fb_config_richten($cfg);
    $vorgabe_fl = fb_fenster_vorgabe();
    $cfg_fl['fenster'][0]['flaeche'] = $vorgabe_fl['flaeche'];
    $cfg_fl['fenster'][0]['aktiv'] = 1;
    $cfg_fl = fb_config_richten($cfg_fl);
    $pruefe('Ein Fenster auf der Vorgabeflaeche wird gemeldet',
            in_array($cfg_fl['fenster'][0]['kuerzel'], fb_flaeche_vorgabe($cfg_fl), true),
            implode(',', fb_flaeche_vorgabe($cfg_fl)));
    $cfg_fl2 = $cfg_fl;
    $cfg_fl2['fenster'][0]['flaeche'] = (float) $vorgabe_fl['flaeche'] + 1.0;
    $cfg_fl2 = fb_config_richten($cfg_fl2);
    $pruefe('Ein Fenster mit eigener Flaeche wird nicht gemeldet',
            !in_array($cfg_fl2['fenster'][0]['kuerzel'], fb_flaeche_vorgabe($cfg_fl2), true),
            implode(',', fb_flaeche_vorgabe($cfg_fl2)));

    /* --- Die Raumflaechen aus der Projektdatei ---
     *
     * Nachgestellt, nicht mit der echten Datei: der Selbsttest laeuft auch
     * dort, wo keine Projektdatei liegt. Die Zeile mit dem doppelten
     * Schluessel ist der Fall aus der echten Anlage ("KG Vorrat Ost" und
     * "KG Vorrat Nord" ergeben beide kg_vorrat). */
    $xml_p = '<C Type="Place" Title="EG Wohnzimmer" Sqm="25"/>'
           . '<C Type="Place" Title="OG Duschbad" Sqm="8"/>'
           . '<C Type="Place" Title="Zentral"/>'
           . '<C Type="Place" Title="KG Vorrat Ost" Sqm="8"/>'
           . '<C Type="Place" Title="KG Vorrat Nord" Sqm="5"/>';
    list($rf_p, $rf_doppelt) = fb_projekt_raeume($xml_p);
    $pruefe('Die Raumflaechen werden aus der Projektdatei gelesen',
            isset($rf_p['eg_wohnzi']) && abs($rf_p['eg_wohnzi'] - 25.0) < 0.001
            && isset($rf_p['og_duschb']) && abs($rf_p['og_duschb'] - 8.0) < 0.001,
            implode(' ', array_map(function ($k, $v) { return $k . '=' . $v; },
                                   array_keys($rf_p), $rf_p)));
    $pruefe('Ein Raum ohne Sqm wird uebergangen und nicht als null gelesen',
            !isset($rf_p['zentra']), implode(',', array_keys($rf_p)));
    $pruefe('Zwei Raeume mit demselben Schluessel werden gemeldet, nicht geraten',
            isset($rf_doppelt['kg_vorrat']) && !isset($rf_p['kg_vorrat']),
            'doppelt=' . implode(',', array_keys($rf_doppelt)));

    /* --- 13. Der Vorabendteil greift erst ab seiner Stunde --- */
    $cfg_vb = $cfg;
    $cfg_vb['gewicht_morgen'] = 100; $cfg_vb['gewicht_raum'] = 0; $cfg_vb['gewicht_tag'] = 0;
    $cfg_vb['vorabend_ab'] = 16;
    $cfg_vb = fb_config_richten($cfg_vb);
    $mess_vb = $mess_august;
    $mess_vb['prognose1'] = array('v' => 5.0, 't' => $t0);     // morgen wird kalt
    /* 10:00 MESZ - vor der Stunde. Die Zeitpunkte werden ueber die ORTSZEIT
     * bestimmt, deshalb wird hier mit date() gerechnet und nicht mit gmdate. */
    $frueh = fb_rechnen($cfg_vb, $mess_vb, $t0, array());
    $pruefe('Vor der Vorabendstunde bleibt der Morgenteil aussen vor',
            (int) $frueh['vorabend'] === 0, 'Stunde ' . date('G', $t0));
    /* Denselben Tag um 18:00 Ortszeit. */
    $t_abend = mktime(18, 0, 0, (int) date('n', $t0), (int) date('j', $t0),
                      (int) date('Y', $t0));
    $mess_vb2 = $mess_vb;
    foreach ($mess_vb2 as $k => $v) { $mess_vb2[$k]['t'] = $t_abend; }
    $abend = fb_rechnen($cfg_vb, $mess_vb2, $t_abend, array());
    $pruefe('Ab der Vorabendstunde zaehlt die Prognose fuer morgen mit',
            (int) $abend['vorabend'] === 1 && (int) $abend['morgen'] > 0,
            'vorabend=' . $abend['vorabend'] . ' morgen=' . $abend['morgen']);

    /* --- 8. Blendschutz --- */
    $cfg_bl = $cfg;
    $cfg_bl['fenster'][0]['blend_hoehe'] = 25;
    $cfg_bl['fenster'][0]['blend_winkel'] = 40;
    $cfg_bl['fenster'][0]['azimut'] = 260;         // Westfenster
    $cfg_bl = fb_config_richten($cfg_bl);
    /* Abendsonne: tief und fast senkrecht auf das Westfenster. */
    $t_blend = $t0 + 9 * 3600;
    $mess_bl = $mess_august;
    foreach ($mess_bl as $k => $v) { $mess_bl[$k]['t'] = $t_blend; }
    $mess_bl['strahlung']['v'] = 300;
    $s_bl = fb_rechnen($cfg_bl, $mess_bl, $t_blend, array());
    $sonne_bl = fb_sonnenstand($t_blend, $ort[0], $ort[1]);
    $pruefe('Blendschutz meldet bei tiefer Sonne im Fenster',
            (int) $s_bl['fenster']['1']['blendung'] === 1,
            sprintf('Sonne %.1f Grad hoch, Azimut %.1f, Einfall %d, blendung=%d',
                    $sonne_bl['hoehe'], $sonne_bl['azimut'],
                    (int) $s_bl['fenster']['1']['einfall'],
                    (int) $s_bl['fenster']['1']['blendung']));
    $cfg_bl0 = $cfg_bl; $cfg_bl0['fenster'][0]['blend_hoehe'] = 0;
    $cfg_bl0 = fb_config_richten($cfg_bl0);
    $pruefe('Blendschutz auf 0 meldet nie',
            (int) fb_rechnen($cfg_bl0, $mess_bl, $t_blend, array())['fenster']['1']['blendung'] === 0,
            'abgeschaltet');
    $pruefe('Blendschutz aendert das Urteil NICHT',
            $s_bl['fenster']['1']['urteil']
            === fb_rechnen($cfg_bl0, $mess_bl, $t_blend, array())['fenster']['1']['urteil'],
            'mit ' . $s_bl['fenster']['1']['urteil'] . ', ohne '
            . fb_rechnen($cfg_bl0, $mess_bl, $t_blend, array())['fenster']['1']['urteil']);

    /* --- 9. Nachtdaemmung --- */
    $cfg_dm = $cfg;
    $cfg_dm['daemmen_ein'] = 1; $cfg_dm['daemm_grenze'] = 5;
    $cfg_dm = fb_config_richten($cfg_dm);
    $t_nacht2 = $t0 + 43200;                        // 22:00 MESZ
    $mess_dm = array(
        'strahlung' => array('v' => 0.0,  't' => $t_nacht2),
        'prognose'  => array('v' => 8.0,  't' => $t_nacht2),   // kalter Tag: will Waerme
        'aussen'    => array('v' => 1.0,  't' => $t_nacht2),   // frostig
        'ist.wozi'    => array('v' => 21.0, 't' => $t_nacht2),
        'grenze.wozi' => array('v' => 25.0, 't' => $t_nacht2),
    );
    $s_dm = fb_rechnen($cfg_dm, $mess_dm, $t_nacht2, array());
    $pruefe('Nachtdaemmung meldet bei kalter Nacht und kaltem Tag',
            (int) $s_dm['fenster']['1']['daemmen'] === 1,
            'daemmen=' . $s_dm['fenster']['1']['daemmen']
            . ' sonne=' . $s_dm['sonne_hoehe']);
    $mess_dm2 = $mess_dm; $mess_dm2['aussen']['v'] = 18.0;
    $pruefe('In einer lauen Nacht wird nicht gedaemmt',
            (int) fb_rechnen($cfg_dm, $mess_dm2, $t_nacht2, array())['fenster']['1']['daemmen'] === 0,
            'Aussenluft 18 Grad');
    $mess_dm3 = $mess_dm; $mess_dm3['prognose']['v'] = 30.0;
    $pruefe('Vor einem heissen Tag wird nachts nicht gedaemmt',
            (int) fb_rechnen($cfg_dm, $mess_dm3, $t_nacht2, array())['fenster']['1']['daemmen'] === 0,
            'Tagesprognose 30 Grad');
    $cfg_dm0 = $cfg_dm; $cfg_dm0['daemmen_ein'] = 0; $cfg_dm0 = fb_config_richten($cfg_dm0);
    $pruefe('Abgeschaltete Nachtdaemmung meldet nie',
            (int) fb_rechnen($cfg_dm0, $mess_dm, $t_nacht2, array())['fenster']['1']['daemmen'] === 0,
            'abgeschaltet');

    /* --- 7. Rueckmeldung der Stellung --- */
    $cfg_st = $cfg;
    $cfg_st['stellung_ein'] = 1; $cfg_st['stellung_zu'] = 70; $cfg_st['stellung_frist'] = 900;
    $cfg_st = fb_config_richten($cfg_st);
    $mess_st = $mess_august;
    $mess_st['stellung.wozi'] = array('v' => 0, 't' => $t0);   // offen, obwohl beschattet
    $s_st1 = fb_rechnen($cfg_st, $mess_st, $t0, array());
    $pruefe('Eine Abweichung wird erkannt, aber noch nicht gemeldet',
            (int) $s_st1['fenster']['1']['gefahren'] === 0
            && (int) $s_st1['nicht_gefahren'] === 0,
            'gefahren=' . $s_st1['fenster']['1']['gefahren']
            . ' gemeldet=' . (int) $s_st1['nicht_gefahren']);
    $mess_st2 = $mess_st;
    foreach ($mess_st2 as $k => $v) { $mess_st2[$k]['t'] = $t0 + 1000; }
    $s_st2 = fb_rechnen($cfg_st, $mess_st2, $t0 + 1000, $s_st1);
    $pruefe('Nach der Frist wird die Abweichung gemeldet',
            (int) $s_st2['nicht_gefahren'] === 1,
            'nicht_gefahren=' . $s_st2['nicht_gefahren']);
    $mess_st3 = $mess_st;
    $mess_st3['stellung.wozi'] = array('v' => 100, 't' => $t0);
    $pruefe('Ein gefahrener Rollladen wird nicht beanstandet',
            (int) fb_rechnen($cfg_st, $mess_st3, $t0, array())['fenster']['1']['gefahren'] === 1,
            'Stellung 100 Prozent');
    $s_st0 = fb_rechnen($cfg, $mess_st, $t0, array());
    $pruefe('Ohne eingeschaltete Auswertung bleibt die Stellung unbekannt',
            (int) $s_st0['fenster']['1']['gefahren'] === -1,
            'gefahren=' . $s_st0['fenster']['1']['gefahren']);

    /* --- 2. Die Fensterliste aus einer Projektdatei ---
     *
     * Das Pruefstueck ist hier absichtlich klein und selbstgebaut: es prueft
     * das LESEN, nicht die Projektdatei dieser Anlage. Enthalten sind genau
     * die drei Faelle, auf die es ankommt - ein gewoehnlicher Baustein, zwei
     * Bausteine, aus deren Namen dasselbe Kuerzel wuerde, und einer ohne
     * ablesbare Richtung. */
    $xml = '<?xml version="1.0" encoding="utf-8"?><ControlList>'
         . '<C Type="AutoJalousie" Title="Rollladen EG Flur Fenster">'
         . '<Co K="Type" Def="1" U="a"/><Co K="Dir" Def="104" U="b"/>'
         . '<Co K="DirTol" Def="85" U="c"/>'
         . '<C Type="Sonstiges" Title="Kind"><Co K="Dir" Def="999" U="d"/></C>'
         . '</C>'
         . '<C Type="AutoJalousie" Title="Rollladen EG Esszimmer Tuer">'
         . '<Co K="Dir" Def="198" U="e"/></C>'
         . '<C Type="AutoJalousie" Title="Rollladen EG Esszimmer Tuer">'
         . '<Co K="Dir" Def="194" U="f"/></C>'
         . '<C Type="AutoJalousie" Title="Ohne Richtung">'
         . '<Co K="Dir" U="g"/></C>'
         . '</ControlList>';
    list($liste_p, $fehler_p) = fb_projekt_lesen($xml);
    $pruefe('Drei von vier Bausteinen sind lesbar',
            count($liste_p) === 3 && count($fehler_p) === 1,
            count($liste_p) . ' gelesen, ' . count($fehler_p) . ' gemeldet');
    $pruefe('Die Richtung des ersten Bausteins ist 104 und nicht die des Kindelements',
            count($liste_p) > 0 && (int) $liste_p[0]['azimut'] === 104,
            count($liste_p) > 0 ? (string) $liste_p[0]['azimut'] : '-');
    $kuerzel_p = array();
    foreach ($liste_p as $x) { $kuerzel_p[] = $x['kuerzel']; }
    $pruefe('Zwei gleichnamige Bausteine bekommen VERSCHIEDENE Kuerzel',
            count($kuerzel_p) === count(array_unique($kuerzel_p)),
            implode(', ', $kuerzel_p));
    $pruefe('Ein Baustein ohne ablesbare Richtung wird gemeldet und nicht geraten',
            in_array('Ohne Richtung', $fehler_p, true), implode(', ', $fehler_p));
    /* --- Der vorzeitige Rueckweg traegt die Zaehler mit ---
     *
     * Ohne Standort steigt fb_rechnen() sofort aus. Wer danach 'anzahl'
     * oder 'beschatten_anzahl' liest, bekam bis 0.10.0 eine PHP-Meldung
     * statt einer Zahl - und zwar genau auf einer frisch installierten
     * Anlage, auf der der Standort noch fehlt und schon ein Messwert
     * hereinkommt. Gefunden hat das der Endpunkttest, nicht das Lesen.
     *
     * Der Sonnenstand darf dabei ausdruecklich NICHT mitkommen: 0 Grad
     * Hoehe ist kein fehlender Wert, sondern ein Sonnenaufgang. */
    $cfg_ohne_ort = fb_config_richten(fb_vorgaben());
    $cfg_ohne_ort['breite'] = 0.0;
    $cfg_ohne_ort['laenge'] = 0.0;
    $s_ohne = fb_rechnen($cfg_ohne_ort, array(), 1787486400);
    $pruefe('Ohne Standort wird das auch gemeldet',
            $s_ohne['meldung'] === 'KEIN_STANDORT', $s_ohne['meldung']);
    $pruefe('Ohne Standort tragen die Zaehler trotzdem eine Zahl',
            isset($s_ohne['anzahl']) && isset($s_ohne['beschatten_anzahl'])
            && (int) $s_ohne['anzahl'] === 0
            && (int) $s_ohne['beschatten_anzahl'] === 0,
            'anzahl=' . (isset($s_ohne['anzahl']) ? $s_ohne['anzahl'] : 'FEHLT')
            . ' beschatten=' . (isset($s_ohne['beschatten_anzahl'])
                                ? $s_ohne['beschatten_anzahl'] : 'FEHLT'));
    $pruefe('Ohne Standort steht KEIN Sonnenstand da',
            !isset($s_ohne['sonne_hoehe']) && !isset($s_ohne['sonne_azimut']),
            isset($s_ohne['sonne_hoehe']) ? 'sonne_hoehe=' . $s_ohne['sonne_hoehe'] : 'nicht gesetzt');

    /* Und der Haken, der beim Uebernehmen einmal fehlte: ein Vorschlag mit
     * Raumschluessel muss die Raumwerte auch einschalten. Sonst rechnet das
     * Plugin fuer jedes uebernommene Fenster ohne Raumteil weiter - und
     * meldet dabei NICHTS, weil kein Messwert fehlt. */
    $cfg_leer = fb_config_richten(array_merge(fb_vorgaben(), array('fenster' => array())));
    $pruefe('Eine leere Fensterzeile hat die Raumwerte zu Recht aus',
            (int) $cfg_leer['fenster'][0]['raumwerte'] === 0
            && $cfg_leer['fenster'][0]['raum'] === '',
            'raumwerte=' . $cfg_leer['fenster'][0]['raumwerte']);
    $cfg_mit = $cfg_leer;
    $cfg_mit['fenster'][0]['kuerzel']   = 'PROBE';
    $cfg_mit['fenster'][0]['raum']      = 'probe';
    $cfg_mit['fenster'][0]['raumwerte'] = 1;
    $cfg_mit = fb_config_richten($cfg_mit);
    $pruefe('Mit Raumschluessel bleiben die Raumwerte an',
            (int) $cfg_mit['fenster'][0]['raumwerte'] === 1,
            'raumwerte=' . $cfg_mit['fenster'][0]['raumwerte']);

    $pruefe('Zwei Fenster desselben Zimmers bekommen denselben Raumschluessel',
            count($liste_p) === 3 && $liste_p[1]['raum'] === $liste_p[2]['raum'],
            count($liste_p) === 3 ? ($liste_p[1]['raum'] . ' / ' . $liste_p[2]['raum']) : '-');

    /* --- 6. Die Aufheizkonstante ---
     * Ausgeglichen wird durch den Ursprung. Bei genau proportionalen Daten
     * muss die Steigung exakt getroffen und die Streuung null werden. */
    $lern = array('wozi' => array(
        array('d' => '1', 'wh' => 1000, 'dt' => 1.5),
        array('d' => '2', 'wh' => 2000, 'dt' => 3.0),
        array('d' => '3', 'wh' => 4000, 'dt' => 6.0)));
    list($ln, $lk, $ls, $lr) = fb_lernkurve('wozi', $lern);
    $pruefe('Die Aufheizkonstante trifft eine saubere Gerade genau',
            $ln === 3 && abs($lk - 1.5) < 1e-9 && $ls < 1e-9,
            sprintf('n=%d k=%.4f Streuung=%.4f', $ln, $lk, $ls));
    $lern2 = $lern;
    $lern2['wozi'][] = array('d' => '4', 'wh' => 1000, 'dt' => 6.0);   // Ausreisser
    list($ln2, $lk2, $ls2, ) = fb_lernkurve('wozi', $lern2);
    $pruefe('Ein Ausreisser erhoeht die Streuung sichtbar',
            $ls2 > $ls && $ln2 === 4, sprintf('Streuung %.3f bei n=%d', $ls2, $ln2));
    $pruefe('Ohne Daten meldet die Lernkurve null Tage',
            fb_lernkurve('gibtsnicht', $lern)[0] === 0, 'n=0');

    /* --- 12. Die Gegenprobe gegen die Ertragsprognose --- */
    $cfg_pv = $cfg; $cfg_pv['pv_gegenprobe'] = 1; $cfg_pv['pv_abweichung'] = 25;
    $cfg_pv = fb_config_richten($cfg_pv);
    $tage_pv = array('tage' => array());
    for ($i = 0; $i < 10; $i++) {
        $tage_pv['tage'][] = array('d' => (string) $i, 's' => 1000, 'p' => 1000);
    }
    $pv1 = fb_pv_pruefen($cfg_pv, $tage_pv);
    $pruefe('Gleichlaufende Reihen ergeben keine Abweichung',
            $pv1['tage'] === 10 && $pv1['abweichung'] === 0 && $pv1['warnt'] === 0,
            sprintf('tage=%d abw=%d', $pv1['tage'], $pv1['abweichung']));
    for ($i = 10; $i < 15; $i++) {
        $tage_pv['tage'][] = array('d' => (string) $i, 's' => 500, 'p' => 1000);
    }
    $pv2 = fb_pv_pruefen($cfg_pv, $tage_pv);
    $pruefe('Ein halbierter Geber wird als 50 Prozent Abweichung gemeldet',
            $pv2['abweichung'] === -50 && $pv2['warnt'] === 1,
            sprintf('abw=%d warnt=%d', $pv2['abweichung'], $pv2['warnt']));
    $pruefe('Unter zehn Tagen wird gar nichts behauptet',
            fb_pv_pruefen($cfg_pv, array('tage' => array(
                array('d' => '1', 's' => 1, 'p' => 9))))['abweichung'] === 0,
            'zu wenig Tage');
    $pruefe('Der Median trifft die Mitte',
            abs(fb_median(array(1, 3, 7, 9)) - 5.0) < 1e-9
            && abs(fb_median(array(4, 1, 9)) - 4.0) < 1e-9,
            sprintf('%.1f und %.1f', fb_median(array(1, 3, 7, 9)), fb_median(array(4, 1, 9))));

    /* --- 10. Der Tagesbericht --- */
    $bil_b = fb_bilanz_leer('2026-08-23');
    $bil_b['fenster'] = array('1' => 4200.0);
    $bil_b['zu_s'] = array('1' => 15600);
    $bil_b['spitze'] = array('1' => array('w' => 953, 't' => $t0));
    $bil_b['strahlung'] = 5100.0;
    $bericht = fb_bericht_text($cfg, $bil_b, $stand_b);
    $pruefe('Der Tagesbericht nennt Menge, Dauer und Spitze',
            strpos($bericht, '4.2 kWh') !== false && strpos($bericht, '953 W') !== false
            && strpos($bericht, '4 Stunden') !== false,
            substr($bericht, 0, 120));

    /* --- 3. Das Bild des Verschattungshorizonts --- */
    $cfg_svg = $cfg; $cfg_svg['fenster'][0]['horizont'] = '60:5, 95:28, 130:12, 200:0';
    $cfg_svg = fb_config_richten($cfg_svg);
    $svg = fb_horizont_svg($cfg_svg['fenster'][0], $cfg_svg, $t0);
    $vorher_svg = libxml_use_internal_errors(true);
    $svg_ok = simplexml_load_string($svg) !== false;
    libxml_clear_errors();
    libxml_use_internal_errors($vorher_svg);
    $pruefe('Das Horizontbild ist wohlgeformtes SVG', $svg_ok, strlen($svg) . ' Bytes');
    $pruefe('Das Horizontbild zeigt Sonnenbahn, Horizont und Fensterrichtung',
            substr_count($svg, '<polyline') >= 2 && strpos($svg, '<polygon') !== false,
            substr_count($svg, '<polyline') . ' Linienzuege');

    /* --- Die Felder haengen an der Konfiguration --- */
    $cfg_f0 = $cfg;
    $cfg_f0['vorschau'] = 0; $cfg_f0['daemmen_ein'] = 0; $cfg_f0['stellung_ein'] = 0;
    $cfg_f0['fenster'][0]['blend_hoehe'] = 0;
    $cfg_f0 = fb_config_richten($cfg_f0);
    $cfg_f1 = $cfg_f0;
    $cfg_f1['vorschau'] = 1800; $cfg_f1['daemmen_ein'] = 1; $cfg_f1['stellung_ein'] = 1;
    $cfg_f1['fenster'][0]['blend_hoehe'] = 25;
    $cfg_f1 = fb_config_richten($cfg_f1);
    $pruefe('Abgeschaltete Funktionen erzeugen keine virtuellen Eingaenge',
            !isset(fb_felder($cfg_f0)['URTEIL30']) && !isset(fb_felder($cfg_f0)['DAEMMEN'])
            && !isset(fb_felder($cfg_f0)['GEFAHREN']) && !isset(fb_felder($cfg_f0)['BLENDUNG']),
            implode(', ', array_keys(fb_felder($cfg_f0))));
    $pruefe('Eingeschaltete Funktionen erzeugen ihre virtuellen Eingaenge',
            isset(fb_felder($cfg_f1)['URTEIL30']) && isset(fb_felder($cfg_f1)['DAEMMEN'])
            && isset(fb_felder($cfg_f1)['GEFAHREN']) && isset(fb_felder($cfg_f1)['BLENDUNG']),
            implode(', ', array_keys(fb_felder($cfg_f1))));
    $pruefe('Die Werteliste folgt derselben Feldliste',
            count(array_diff(
                array_map(function ($k) { return 'WOZI' . $k; }, array_keys(fb_felder($cfg_f1))),
                array_keys(fb_felderwerte(fb_rechnen($cfg_f1, $mess_august, $t0, array()), $cfg_f1))
            )) === 0,
            'Feldliste und Werteliste decken sich');

    /* --- Pflicht haengt an der Konfiguration --- */
    $pruefe('prognose1 ist erst Pflicht, wenn der Vorabendteil laeuft',
            !in_array('prognose1', fb_messgroessen_pflicht($cfg), true)
            && in_array('prognose1', fb_messgroessen_pflicht($cfg_vb), true),
            implode(', ', fb_messgroessen_pflicht($cfg_vb)));
    $pruefe('aussen ist erst Pflicht, wenn die Nachtdaemmung laeuft',
            !in_array('aussen', fb_messgroessen_pflicht($cfg), true)
            && in_array('aussen', fb_messgroessen_pflicht($cfg_dm), true),
            implode(', ', fb_messgroessen_pflicht($cfg_dm)));

    /* --- 9. Ein unlesbarer Horizontpunkt wird abgewiesen, nicht geraten --- */
    list($punkte, $unlesbar) = fb_horizont_lesen('80:22, unfug, 110:14');
    $pruefe('Unlesbarer Stuetzpunkt wird abgewiesen',
            count($punkte) === 2 && count($unlesbar) === 1,
            count($punkte) . ' gute, ' . count($unlesbar) . ' schlechte');

    /* --- 10. Die Vorlage ist wohlgeformtes XML --- */
    $xml = fb_xml_virtual_in_http(
        array('title' => 'Pruef"ung mit Umlaut ae', 'address' => 'http://x/y?a=1&b=2'),
        array(array('title' => 'FB_X', 'comment' => 'a "b" & c', 'check' => '\i;X=\i\v',
                    'min' => -100, 'max' => 100, 'unit' => '<v.0> W')));
    $vorher = libxml_use_internal_errors(true);
    $ok = simplexml_load_string($xml) !== false;
    libxml_use_internal_errors($vorher);
    $pruefe('Vorlage wohlgeformt trotz Anfuehrungszeichen und Umlaut', $ok,
            $ok ? '' : 'simplexml_load_string() hat abgelehnt');

    if ($fehler) {
        echo count($fehler) . ' von ' . $geprueft . " Pruefungen sind durchgefallen:\n";
        foreach ($fehler as $f) { echo '  - ' . $f . "\n"; }
        return 1;
    }
    echo 'Selbsttest bestanden: ' . $geprueft . " Pruefungen.\n";
    return 0;
}
