<?php
/**
 * Fensterbilanz - Sonnenstand, Strahlungsaufteilung, Fenstergeometrie
 *
 * Diese Datei enthaelt AUSSCHLIESSLICH Rechnung. Kein Dateizugriff, kein
 * Netz, keine Konfiguration, keine Sprachdatei. Das ist Absicht: dadurch
 * laesst sich der Kern ohne LoxBerry und ohne Anlage pruefen, und der
 * Selbsttest im Reiter Test misst wirklich die Rechnung und nicht die
 * Umgebung.
 *
 * WOHER DIE FORMELN STAMMEN
 * -------------------------
 * Alle vier Bloecke sind veroeffentlichte Standardverfahren. Keine Zahl hier
 * ist an dieser Anlage gemessen - sie sind Modell, und sie stehen deshalb mit
 * Quelle dabei. Was gemessen ist, kommt von aussen herein (Strahlung,
 * Temperaturen) und wird nie geraten.
 *
 *   1. Sonnenstand  NOAA Solar Calculator (Astronomical Algorithms, Meeus),
 *                   die Fassung, die das NOAA Global Monitoring Laboratory
 *                   als Tabellenblatt veroeffentlicht. Genauigkeit fuer
 *                   unsere Breiten deutlich besser als 0,1 Grad.
 *   2. Aufteilung in direkte und diffuse Strahlung: Erbs, Klein und Duffie
 *                   (Solar Energy 28, 1982), die ueblichen vier Abschnitte
 *                   ueber dem Klarheitsindex.
 *   3. Strahlung auf eine geneigte Flaeche: WAHLWEISE das isotrope Modell
 *                   nach Liu und Jordan oder das anisotrope HDKR-Modell
 *                   (Hay, Davies, Klucher, Reindl; Duffie und Beckman,
 *                   Solar Engineering of Thermal Processes, Gl. 2.16.7).
 *                   Beide sind unten ausgeschrieben.
 *   4. Einfallswinkelabhaengigkeit des Glases: ASHRAE-Korrekturglied
 *                   IAM = 1 - b0 * (1/cos(theta) - 1). b0 ist einstellbar,
 *                   Vorgabe 0,10 fuer klares Zweischeibenglas.
 *
 * WARUM HDKR UND NICHT PEREZ
 * --------------------------
 * Das isotrope Modell verteilt die Diffusstrahlung gleichmaessig ueber den
 * Himmel. Bei klarem Himmel ist sie aber in Sonnennaehe deutlich staerker
 * und am Horizont etwas heller. Beide Zuschlaege bringt HDKR, und zwar
 * OHNE eine einzige Zahl, die man abschreiben muesste: der
 * Anisotropieindex und der Horizontfaktor werden aus den Messwerten
 * gerechnet.
 *
 * Das Perez-Modell waere genauer, verlangt aber eine Tabelle mit
 * achtundvierzig veroeffentlichten Koeffizienten. Die haette ich hier aus
 * dem Gedaechtnis hinschreiben muessen, ohne sie gegen eine zweite Quelle
 * halten zu koennen - und eine falsche Ziffer darin saehe genauso aus wie
 * eine richtige. Eine Zahl, die niemand nachgemessen hat, darf nicht
 * aussehen wie eine, die jemand nachgemessen hat. Perez bleibt deshalb
 * offen und steht in der Vorschlagsliste.
 *
 * HDKR hat eine Eigenschaft, die es pruefbar macht: bei bedecktem Himmel
 * (kein Direktanteil) geht es EXAKT in das isotrope Modell ueber. Genau das
 * prueft der Selbsttest.
 *
 * Kompatibel mit PHP 7.4 und PHP 8.x (LoxBerry 3.x/4.x).
 */

/** Solarkonstante in W/m2 (WMO 1981, hier nur als Bezugswert gebraucht). */
if (!defined('FB_SOLARKONSTANTE')) { define('FB_SOLARKONSTANTE', 1367.0); }

/**
 * Sonnenstand zu einem Zeitpunkt.
 *
 * $ts     Unix-Zeit (UTC-bezogen, wie sie time() liefert)
 * $breite Geografische Breite in Grad, Nord positiv
 * $laenge Geografische Laenge in Grad, Ost positiv
 *
 * Rueckgabe: array mit
 *   hoehe      Sonnenhoehe ueber dem Horizont in Grad, mit Refraktion
 *   hoehe_geo  dieselbe ohne Refraktion (fuer die Geometrie am Glas)
 *   azimut     Sonnenazimut in Grad, von Nord im Uhrzeigersinn
 *              (0 = Nord, 90 = Ost, 180 = Sued, 270 = West)
 *   deklination, zeitgleichung  fuer die Anzeige und den Selbsttest
 *
 * Die Azimutzaehlung ist genau die von Loxone: der Parameter Dir des
 * AutoJalousie-Bausteins zaehlt ebenso von Nord im Uhrzeigersinn (in dieser
 * Anlage 14 fuer NNO, 104 fuer OSO, 194 fuer SSW, 284 fuer WNW). Wer hier
 * auf die andere gebraeuchliche Zaehlung (Sued = 0) umstellt, bekommt eine
 * Beschattung, die 180 Grad daneben liegt - und sie sieht richtig aus.
 */
function fb_sonnenstand($ts, $breite, $laenge)
{
    $ts = (float) $ts;
    $breite = (float) $breite;
    $laenge = (float) $laenge;

    /* Julianisches Datum aus der Unix-Zeit. 2440587.5 ist der 01.01.1970
     * 00:00 UTC - keine geratene Zahl, sondern die Definition. */
    $jd = $ts / 86400.0 + 2440587.5;
    $t  = ($jd - 2451545.0) / 36525.0;          // julianische Jahrhunderte

    $l0 = fmod(280.46646 + $t * (36000.76983 + $t * 0.0003032), 360.0);
    if ($l0 < 0) { $l0 += 360.0; }
    $m  = 357.52911 + $t * (35999.05029 - 0.0001537 * $t);
    $e  = 0.016708634 - $t * (0.000042037 + 0.0000001267 * $t);

    $mr = deg2rad($m);
    $c  = sin($mr) * (1.914602 - $t * (0.004817 + 0.000014 * $t))
        + sin(2 * $mr) * (0.019993 - 0.000101 * $t)
        + sin(3 * $mr) * 0.000289;

    $wahre_laenge = $l0 + $c;
    $omega  = 125.04 - 1934.136 * $t;
    $lambda = $wahre_laenge - 0.00569 - 0.00478 * sin(deg2rad($omega));

    $eps0 = 23.0 + (26.0 + (21.448 - $t * (46.815 + $t * (0.00059 - $t * 0.001813))) / 60.0) / 60.0;
    $eps  = $eps0 + 0.00256 * cos(deg2rad($omega));

    $deklination = rad2deg(asin(sin(deg2rad($eps)) * sin(deg2rad($lambda))));

    /* Zeitgleichung in Minuten. */
    $y = tan(deg2rad($eps / 2.0));
    $y = $y * $y;
    $zg = $y * sin(2 * deg2rad($l0))
        - 2 * $e * sin($mr)
        + 4 * $e * $y * sin($mr) * cos(2 * deg2rad($l0))
        - 0.5 * $y * $y * sin(4 * deg2rad($l0))
        - 1.25 * $e * $e * sin(2 * $mr);
    $zg = 4.0 * rad2deg($zg);

    /* Wahre Ortszeit in Minuten seit Mitternacht. Gerechnet wird in UTC -
     * die Zeitzone des Rechners spielt keine Rolle und darf es auch nicht:
     * eine Sommerzeitumstellung mitten in der Rechnung waere ein Fehler,
     * den man erst im Herbst bemerkt. */
    $minuten_utc = fmod($ts, 86400.0) / 60.0;
    if ($minuten_utc < 0) { $minuten_utc += 1440.0; }
    $wahre_zeit = fmod($minuten_utc + $zg + 4.0 * $laenge, 1440.0);
    if ($wahre_zeit < 0) { $wahre_zeit += 1440.0; }

    $stundenwinkel = $wahre_zeit / 4.0 - 180.0;   // Grad, 0 = Sonnenhoechststand

    $br = deg2rad($breite);
    $dr = deg2rad($deklination);
    $hr = deg2rad($stundenwinkel);

    $cos_zenit = sin($br) * sin($dr) + cos($br) * cos($dr) * cos($hr);
    $cos_zenit = max(-1.0, min(1.0, $cos_zenit));
    $zenit = rad2deg(acos($cos_zenit));
    $hoehe_geo = 90.0 - $zenit;

    /* Azimut von Nord im Uhrzeigersinn. Der Zaehler wird gegen 0 geprueft:
     * genau im Zenit (in unseren Breiten nie, aber die Formel weiss das
     * nicht) waere die Division nicht definiert.
     *
     * DIE BEIDEN ZEILEN MIT +180 UND 540- SIND NICHT SCHMUCK. Hier stand
     * zuerst die naheliegende Umkehrung ("nachmittags 360 minus Winkel").
     * Die Eichung gegen eine zweite, unabhaengige Rechnung (PSA-Algorithmus
     * in Python) hat sofort gezeigt, dass der Azimut damit an der
     * Nord-Sued-Achse gespiegelt ist: fuer den 23.08.2026 um 10:00 MESZ kam
     * 64,9 Grad heraus statt 115,1. Die Sonnenhoehe stimmte dabei auf 0,007
     * Grad - der Fehler war also nur an der einen Groesse zu sehen und haette
     * jedes Ostfenster mit einem Westfenster vertauscht. */
    $nenner = sin($br) * cos(deg2rad($zenit)) - sin($dr);
    $zaehler = cos($br) * sin(deg2rad($zenit));
    if (abs($zaehler) < 1e-12) {
        $azimut = ($breite >= 0) ? 180.0 : 0.0;
    } else {
        $wert = max(-1.0, min(1.0, $nenner / $zaehler));
        $winkel = rad2deg(acos($wert));
        $azimut = ($stundenwinkel > 0) ? ($winkel + 180.0) : (540.0 - $winkel);
    }
    $azimut = fmod($azimut, 360.0);
    if ($azimut < 0) { $azimut += 360.0; }

    return array(
        'hoehe'         => $hoehe_geo + fb_refraktion($hoehe_geo),
        'hoehe_geo'     => $hoehe_geo,
        'azimut'        => $azimut,
        'deklination'   => $deklination,
        'zeitgleichung' => $zg,
        'stundenwinkel' => $stundenwinkel,
    );
}

/**
 * Refraktion der Atmosphaere in Grad, nach der Naeherung des
 * NOAA-Tabellenblatts. Sie hebt die Sonne dicht ueber dem Horizont um gut
 * ein halbes Grad an.
 *
 * Fuer die Geometrie am Glas wird sie NICHT benutzt - dort zaehlt, wo der
 * Strahl wirklich herkommt. Fuer die Frage "ist die Sonne schon auf" schon.
 */
function fb_refraktion($hoehe)
{
    if ($hoehe > 85.0) { return 0.0; }
    $te = tan(deg2rad($hoehe));
    if ($hoehe > 5.0) {
        $r = 58.1 / $te - 0.07 / pow($te, 3) + 0.000086 / pow($te, 5);
    } elseif ($hoehe > -0.575) {
        $r = 1735.0 + $hoehe * (-518.2 + $hoehe * (103.4 + $hoehe * (-12.79 + $hoehe * 0.711)));
    } else {
        $r = -20.772 / $te;
    }
    return $r / 3600.0;
}

/**
 * Aussenstrahlung auf eine waagerechte Flaeche ausserhalb der Atmosphaere.
 * Bezugsgroesse fuer den Klarheitsindex; die Bahnexzentrizitaet steckt im
 * Kosinusglied.
 */
function fb_extraterrestrisch($ts, $hoehe_geo)
{
    if ($hoehe_geo <= 0.0) { return 0.0; }
    $tag = (int) gmdate('z', (int) $ts) + 1;      // 1..366
    $korr = 1.0 + 0.033 * cos(deg2rad(360.0 * $tag / 365.0));
    return FB_SOLARKONSTANTE * $korr * sin(deg2rad($hoehe_geo));
}

/**
 * Aussenstrahlung SENKRECHT zum Strahl, also ohne den Sinus der Sonnenhoehe.
 *
 * Sie ist der Bezug fuer den Anisotropieindex des HDKR-Modells: wie klar
 * steht die Sonne im Verhaeltnis zu dem, was ohne Atmosphaere ankaeme.
 */
function fb_extraterrestrisch_normal($ts)
{
    $tag = (int) gmdate('z', (int) $ts) + 1;      // 1..366
    return FB_SOLARKONSTANTE * (1.0 + 0.033 * cos(deg2rad(360.0 * $tag / 365.0)));
}

/**
 * Globalstrahlung in direkten und diffusen Anteil zerlegen (Erbs 1982).
 *
 * $global    gemessene Globalstrahlung auf die Waagerechte in W/m2
 * $hoehe_geo Sonnenhoehe ohne Refraktion in Grad
 *
 * Rueckgabe: array(dni, diffus_h, gueltig) - Direktstrahlung senkrecht zum
 * Strahl und Himmelsdiffusstrahlung auf die Waagerechte, beide in W/m2,
 * dazu die Auskunft, ob der Messwert ueberhaupt moeglich war.
 *
 * Warum die Zerlegung sein muss: dieselben 662 W/m2 bedeuten an einem klaren
 * Augusttag etwa 850 W/m2 auf einem senkrecht angestrahlten Fenster und
 * hinter einer geschlossenen Wolkendecke fast nichts davon - die Strahlung
 * kommt dann aus dem ganzen Himmel und nicht aus einer Richtung. Wer diesen
 * Unterschied ueberspringt, beschattet bei Hochnebel Fenster, auf die keine
 * Sonne faellt.
 */
function fb_strahlungsteilung($global, $hoehe_geo, $ts)
{
    $global = max(0.0, (float) $global);
    $e0 = fb_extraterrestrisch($ts, $hoehe_geo);

    /* ZUERST DIE PLAUSIBILITAET, DANN DIE RECHNUNG.
     *
     * Hier stand zuerst nur ein Deckel: ein Klarheitsindex ueber 1 wurde auf
     * 1 geklemmt. Gemessen tat der das GEGENTEIL dessen, was der Kommentar
     * daneben behauptete. Ein Strahlungswert von 662 W/m2 bei 1,8 Grad
     * Sonnenhoehe - moeglich durch einen stehengebliebenen Wert oder einen
     * falsch verdrahteten Ausgang - ergab nach dem Klemmen einen
     * Direktanteil von 1367 W/m2 und damit 1470 W/m2 am Glas. Das ist mehr
     * als die Solarkonstante und mehr als das Doppelte des gemeldeten
     * Messwerts; das Urteil lautete -100.
     *
     * Ein unmoeglicher Messwert ist kein Grund zu grosser Sicherheit,
     * sondern ein Grund, nichts zu behaupten. Der DRITTE Rueckgabewert sagt
     * dem Aufrufer, dass der Wert nicht taugt; fb_rechnen behandelt ihn dann
     * wie einen fehlenden - fail closed.
     *
     * Die Schranke ist GEWAEHLT, nicht gemessen, und steht deshalb hier mit
     * ihrer Begruendung: zehn Prozent fuer die Genauigkeit ueblicher
     * Strahlungsgeber, dazu 25 W/m2 fest, weil die Aussenstrahlung dicht am
     * Horizont gegen null geht und dort schon Streulicht und der
     * Kosinusfehler des Gebers jeden relativen Zuschlag sprengen. */
    if ($global > 1.10 * $e0 + 25.0) {
        return array(0.0, 0.0, false);
    }

    if ($global <= 0.0 || $hoehe_geo <= 1.0) {
        /* Unter einem Grad Sonnenhoehe wird die Division durch den Sinus
         * unbrauchbar - dort ist ohnehin alles Diffusstrahlung. */
        return array(0.0, $global, true);
    }
    if ($e0 <= 0.0) { return array(0.0, $global, true); }

    $kt = $global / $e0;
    if ($kt > 1.0) { $kt = 1.0; }     // innerhalb der Schranke noch abrunden

    if ($kt <= 0.22) {
        $kd = 1.0 - 0.09 * $kt;
    } elseif ($kt <= 0.80) {
        $kd = 0.9511 - 0.1604 * $kt + 4.388 * $kt * $kt
            - 16.638 * pow($kt, 3) + 12.336 * pow($kt, 4);
    } else {
        $kd = 0.165;
    }
    $kd = max(0.0, min(1.0, $kd));

    $diffus = $global * $kd;
    $direkt_h = $global - $diffus;
    $dni = $direkt_h / sin(deg2rad($hoehe_geo));
    /* Ueber der Solarkonstanten kann keine Direktstrahlung liegen. Das
     * begrenzt den Ausreisser, der bei flacher Sonne aus einem zu hohen
     * Messwert entstuende. */
    if ($dni > FB_SOLARKONSTANTE) { $dni = FB_SOLARKONSTANTE; }
    return array($dni, $diffus, true);
}

/**
 * Kosinus des Einfallswinkels auf eine geneigte Flaeche.
 *
 * $neigung 90 = senkrechtes Fenster, 0 = waagerechtes Dachfenster.
 * Rueckgabe <= 0 heisst: die Sonne steht hinter der Flaeche.
 */
function fb_cos_einfall($sonne_azimut, $sonne_hoehe, $flaeche_azimut, $neigung)
{
    $h = deg2rad((float) $sonne_hoehe);
    $d = deg2rad((float) $sonne_azimut - (float) $flaeche_azimut);
    $n = deg2rad((float) $neigung);
    return cos($h) * cos($d) * sin($n) + sin($h) * cos($n);
}

/**
 * Einfallswinkelabhaengigkeit des Glases (ASHRAE).
 *
 * IAM = 1 - b0 * (1/cos(theta) - 1), unten bei 0 abgeschnitten.
 *
 * Bei streifendem Einfall geht der Wert gegen null - genau das ist der
 * Punkt aus der Skizze: ein Winkelbereich allein laesst vermuten, es komme
 * viel herein, waehrend das Glas den Strahl fast vollstaendig spiegelt.
 */
function fb_glasdurchlass($cos_theta, $b0 = 0.10)
{
    if ($cos_theta <= 0.0) { return 0.0; }
    /* Unter etwa 5 Grad ueber der Flaeche wird 1/cos so gross, dass die
     * Formel ins Negative laeuft; abgeschnitten wird bei 0, nicht bei einem
     * kleinen Restwert - eine erfundene Untergrenze waere eine erfundene
     * Zahl. */
    $iam = 1.0 - (float) $b0 * (1.0 / $cos_theta - 1.0);
    return max(0.0, min(1.0, $iam));
}

/**
 * Strahlung auf das Fenster, aufgeschluesselt.
 *
 * Rueckgabe: array(direkt, kranz, diffus, boden, gesamt) in W/m2
 * Glasflaeche, Einfallswinkelabhaengigkeit bereits eingerechnet. 'kranz' ist
 * der Sonnenkranz des HDKR-Modells; beim isotropen Modell ist er null.
 *
 * $verschattet = true schaltet den Direktanteil UND den Sonnenkranz ab.
 * Beide kommen aus der Richtung der Sonne; steht sie hinter dem
 * Nachbarhaus, kommt von dort nichts. Der uebrige Himmel scheint trotzdem
 * noch herein - wer auch den streicht, rechnet ein dunkles Zimmer aus, das
 * es nicht gibt.
 *
 * $modell: 'isotrop' oder 'hdkr'.
 * $hoehe_geo und $ts werden nur fuer HDKR gebraucht.
 */
/**
 * Welcher Anteil des Fensters liegt im Schatten des Dachueberstands?
 *
 * Gerechnet wird ueber den PROFILWINKEL - den Winkel, unter dem die Sonne
 * in der senkrechten Ebene des Fensters steht:
 *
 *     tan(Profil) = tan(Sonnenhoehe) / cos(seitlicher Versatz)
 *
 * Er ist steiler als die Sonnenhoehe, sobald die Sonne seitlich steht, und
 * genau er bestimmt, wie weit der Schatten an der Wand herunterreicht:
 *
 *     Schattentiefe = Auskragung * tan(Profil)
 *
 * Davon geht der Abstand zwischen Ueberstand und Fensteroberkante ab; was
 * uebrig bleibt, liegt auf dem Fenster.
 *
 * WAS DIESE RECHNUNG NICHT KANN: sie nimmt einen Ueberstand an, der sich
 * seitlich weit genug erstreckt. Ein kurzes Vordach ueber einem breiten
 * Fenster verschattet in Wirklichkeit nur einen Teil der Breite. Das waere
 * mit zwei weiteren Zahlen zu haben und ist bewusst nicht gebaut - der
 * uebliche Fall ist ein durchlaufender Dachueberstand.
 *
 * @param float $hoehe   Sonnenhoehe in Grad
 * @param float $az_sonne  Azimut der Sonne in Grad
 * @param float $az_fenster Azimut des Fensters in Grad
 * @param float $tiefe   Auskragung des Ueberstands in cm
 * @param float $ueber   Abstand Ueberstand zur Fensteroberkante in cm
 * @param float $fenster Hoehe des Fensters in cm
 *
 * Rueckgabe: 0.0 (gar nicht verschattet) bis 1.0 (ganz im Schatten).
 */
function fb_dach_anteil($hoehe, $az_sonne, $az_fenster, $tiefe, $ueber, $fenster,
                        $neigung = 90)
{
    $tiefe = (float) $tiefe;
    $fenster = max(1.0, (float) $fenster);
    if ($tiefe <= 0.0) { return 0.0; }
    /* NUR AN SENKRECHTEN FENSTERN.
     *
     * Die Rechnung unten - Schatten = Auskragung mal Tangens des
     * Profilwinkels, verglichen mit der Fensterhoehe - gilt fuer eine
     * SENKRECHTE Wand. Bis 0.12.6 bekam diese Funktion die Neigung gar
     * nicht uebergeben und rechnete deshalb auch fuer ein Dachfenster mit
     * 30 Grad Neigung eine Zahl, als staende es senkrecht; bei Neigung 0
     * (waagerechtes Dachfenster, in der Hilfe ausdruecklich als zulaessig
     * genannt) ist sie ueberhaupt nicht definiert und lieferte trotzdem
     * ein Ergebnis.
     *
     * Bis die geneigte Geometrie an einem echten Fenster nachgemessen ist,
     * antwortet die Funktion dort mit 0 - also "der Ueberstand verschattet
     * nichts". Das ist die sichere Richtung: es wird eher zu viel
     * Beschattung verlangt als zu wenig. Die Grenze steht in der Hilfe. */
    if ((float) $neigung < 80.0) { return 0.0; }
    $hoehe = (float) $hoehe;
    /* Steht die Sonne unter dem Horizont, gibt es ohnehin keine direkte
     * Strahlung - und ein Schatten von nichts ist keiner. */
    if ($hoehe <= 0.0) { return 0.0; }

    $d = deg2rad((float) $az_sonne - (float) $az_fenster);
    $c = cos($d);
    /* Steht die Sonne hinter dem Fenster oder streift sie die Scheibe
     * genau von der Seite, faellt kein direktes Licht darauf. Dann
     * verschattet auch der Ueberstand nichts - es gibt nichts zu
     * verschatten. */
    if ($c <= 0.001) { return 0.0; }

    $profil = atan(tan(deg2rad($hoehe)) / $c);
    $schatten = $tiefe * tan($profil);
    $auf_dem_fenster = $schatten - (float) $ueber;
    if ($auf_dem_fenster <= 0.0) { return 0.0; }
    return fb_klemme($auf_dem_fenster / $fenster, 0.0, 1.0);
}

function fb_fensterstrahlung($dni, $diffus_h, $global, $cos_theta, $neigung,
                             $albedo = 0.2, $b0 = 0.10, $verschattet = false,
                             $modell = 'isotrop', $hoehe_geo = 0.0, $ts = 0,
                             $dach_frei = 1.0)
{
    /* $dach_frei ist der Anteil des Fensters, den der Dachueberstand NICHT
     * verschattet - 1.0 heisst "kein Ueberstand oder er wirft gerade
     * keinen Schatten". Er wirkt auf den direkten Anteil UND auf den
     * Sonnenkranz: beide kommen aus der Richtung der Sonne, und der
     * Ueberstand steht dazwischen. Das Himmelslicht und der Bodenreflex
     * bleiben unberuehrt - die kommen von ueberall her. */
    $dach_frei = fb_klemme((float) $dach_frei, 0.0, 1.0);
    $n = deg2rad((float) $neigung);
    $dni = (float) $dni;
    $diffus_h = (float) $diffus_h;
    $global = (float) $global;

    $direkt = 0.0;
    if (!$verschattet && $cos_theta > 0.0) {
        $direkt = $dni * $cos_theta * fb_glasdurchlass($cos_theta, $b0) * $dach_frei;
    }

    $himmelsanteil = (1.0 + cos($n)) / 2.0;
    $bodenanteil   = (1.0 - cos($n)) / 2.0;
    $boden = $global * (float) $albedo * $bodenanteil;
    $kranz = 0.0;

    if ($modell !== 'hdkr') {
        /* Isotropes Himmelsmodell (Liu und Jordan): der Anteil des Himmels,
         * den die Flaeche sieht. Beim senkrechten Fenster ist das die
         * Haelfte, und zwar unabhaengig davon, wo die Sonne steht. */
        $diffus = $diffus_h * $himmelsanteil;
        return array('direkt' => $direkt, 'kranz' => 0.0, 'diffus' => $diffus,
                     'boden' => $boden, 'gesamt' => $direkt + $diffus + $boden);
    }

    /* ---------------- HDKR ----------------
     *
     * Zwei Zuschlaege auf das isotrope Modell, beide aus den Messwerten
     * gerechnet und ohne abgeschriebene Tabelle:
     *
     *   Anisotropieindex  ai = DNI / E0n
     *       Wie klar steht die Sonne? Bei bedecktem Himmel ist DNI null,
     *       also ai null - und dann fallen beide Zuschlaege weg und es
     *       bleibt exakt das isotrope Modell uebrig. Genau daran laesst
     *       sich das Modell pruefen.
     *
     *   Horizontfaktor    f = Wurzel(DNI * sin(h) / GHI)
     *       Bei klarem Himmel ist der Horizont heller als der Zenit. Das
     *       Glied (1 + f * sin^3(beta/2)) traegt dem Rechnung; bei einer
     *       waagerechten Flaeche (beta = 0) ist es genau 1.
     *
     * Der Sonnenkranz verhaelt sich wie Direktstrahlung: er kommt aus der
     * Richtung der Sonne. Deshalb bekommt er dasselbe Verhaeltnis
     * cos(theta)/sin(h) und dieselbe Einfallswinkelabhaengigkeit des Glases
     * wie der Direktanteil - und deshalb faellt er bei Verschattung mit weg.
     */
    $ai = 0.0;
    $e0n = fb_extraterrestrisch_normal($ts);
    if ($e0n > 0.0) { $ai = fb_klemme($dni / $e0n, 0.0, 1.0); }

    $f = 0.0;
    if ($global > 0.0 && $hoehe_geo > 0.0) {
        $direkt_h = $dni * sin(deg2rad($hoehe_geo));
        $f = sqrt(fb_klemme($direkt_h / $global, 0.0, 1.0));
    }

    if (!$verschattet && $cos_theta > 0.0 && $hoehe_geo > 1.0 && $ai > 0.0) {
        $rb = $cos_theta / sin(deg2rad($hoehe_geo));
        /* Auch der Sonnenkranz kommt aus der Richtung der Sonne - der
         * Dachueberstand steht also ebenso davor wie vor dem direkten
         * Anteil. Ihn hier auszunehmen waere ein stiller Zuschlag. */
        $kranz = $diffus_h * $ai * $rb * fb_glasdurchlass($cos_theta, $b0) * $dach_frei;
    }

    $horizontglied = 1.0 + $f * pow(sin($n / 2.0), 3);
    $diffus = $diffus_h * (1.0 - $ai) * $himmelsanteil * $horizontglied;

    return array('direkt' => $direkt, 'kranz' => $kranz, 'diffus' => $diffus,
                 'boden' => $boden, 'gesamt' => $direkt + $kranz + $diffus + $boden);
}

/**
 * Den Verschattungshorizont aus Stuetzpunkten lesen.
 *
 * Eingabeform, wie sie in der Oberflaeche steht:  "80:22, 110:14, 160:6"
 * gelesen als "ab Azimut 80 Grad steht ein Hindernis 22 Grad hoch".
 *
 * Rueckgabe: aufsteigend nach Azimut sortierte Liste array(azimut, hoehe).
 * Unlesbare Paare werden ABGEWIESEN, nicht zurechtgebogen - der Aufrufer
 * bekommt die Fehlerliste im zweiten Rueckgabewert und meldet sie.
 */
/**
 * Aus Laienangaben die Stuetzpunkte des Verschattungshorizonts rechnen.
 *
 * WARUM DIE FUENF ZAHLEN SO UND NICHT ANDERS:
 *
 * Gefragt wird nach einer DRAUFSICHT - geradeaus und seitlich in Metern.
 * Das ist die einzige Ansicht, die ein Laie wirklich hat: eine Landkarte
 * oder ein Luftbild, und beides kann man ausmessen. Ein einzelner
 * "Abstand" waere zweideutig - Luftlinie zum Hindernis oder senkrecht von
 * der Fassade weg? -, und an genau dieser Zweideutigkeit sind zwei
 * Beispiele in der Hilfe um drei Grad auseinandergelaufen.
 *
 * Gerechnet wird je Kante UND fuer die Mitte, denn die Mitte steht naeher
 * und damit hoeher als die Kanten. Zwischen den Stuetzpunkten rechnet das
 * Plugin geradeaus; ohne den mittleren Punkt saehe ein breites Haus wie
 * eine flache Mulde aus, und es waere zu niedrig angesetzt.
 *
 * Die beiden Nullpunkte aussen sagen "hier hoert es auf". Ohne sie gilt
 * ausserhalb der jeweils naechste Punkt weiter - das Haus reichte dann
 * rechnerisch bis zum Horizont. Sie zu vergessen ist der wahrscheinlichste
 * Fehler beim Eintragen von Hand, und deshalb setzt sie diese Funktion.
 *
 * @param float $h_hindernis  Hoehe des Hindernisses ueber dem Boden, m
 * @param float $h_fenster    Hoehe des Fensters ueber dem Boden, m
 * @param float $vor          Entfernung geradeaus vom Fenster weg, m
 * @param float $seit         seitlicher Versatz der Mitte, m (negativ = links)
 * @param float $breite       Breite des Hindernisses, m
 * @param int   $azimut       Himmelsrichtung des Fensters, Grad
 *
 * Rueckgabe: array(Zeichenkette, Meldungen, Einzelwerte). Eine leere
 * Zeichenkette heisst: hier gibt es nichts einzutragen, und die Meldungen
 * sagen warum.
 */
function fb_horizont_rechnen($h_hindernis, $h_fenster, $vor, $seit, $breite, $azimut)
{
    $meldungen = array();
    $h = (float) $h_hindernis - (float) $h_fenster;
    $vor = (float) $vor;
    $seit = (float) $seit;
    $breite = max(0.0, (float) $breite);

    if ($vor <= 0.0) {
        return array('', array('ABSTAND'), array());
    }
    if ($h <= 0.0) {
        /* Ein Hindernis unterhalb der Fensteroberkante verschattet dieses
         * Fenster nicht. Das ist kein Fehler, sondern eine Antwort. */
        return array('', array('ZU_NIEDRIG'), array());
    }

    /* Die drei Stellen: linke Kante, Mitte, rechte Kante. */
    $stellen = array($seit - $breite / 2.0, $seit, $seit + $breite / 2.0);
    $punkte = array();
    foreach ($stellen as $x) {
        /* Waagerechte Entfernung zu DIESER Stelle - nicht der Wert aus dem
         * Formular. Die Kanten stehen schraeg und damit weiter weg. */
        $weite = sqrt($vor * $vor + $x * $x);
        $hoehe = rad2deg(atan2($h, $weite));
        $ab = rad2deg(atan2($x, $vor));
        $punkte[] = array(
            'azimut' => fb_azimut_normieren((float) $azimut + $ab),
            'hoehe'  => $hoehe,
            'weite'  => $weite,
        );
    }

    /* Bei einem schmalen Hindernis fallen die drei Stellen zusammen -
     * dann genuegt ein Punkt. Doppelte Stuetzpunkte auf demselben Azimut
     * waeren keine Verfeinerung, sondern eine Fehlerquelle. */
    $liste = array();
    foreach ($punkte as $pt) {
        $az = (int) round($pt['azimut']);
        $ho = (int) round($pt['hoehe']);
        if ($liste && (int) $liste[count($liste) - 1][0] === $az) {
            if ($ho > (int) $liste[count($liste) - 1][1]) {
                $liste[count($liste) - 1][1] = $ho;
            }
            continue;
        }
        $liste[] = array($az, $ho);
    }

    /* Die Nullpunkte aussen, vier Grad daneben. Vier, weil der Rand eines
     * Gebaeudes keine Kante im Sonnenlauf ist: die Sonne braucht ein paar
     * Minuten, um daran vorbeizuwandern. */
    $links  = fb_azimut_normieren($liste[0][0] - 4);
    $rechts = fb_azimut_normieren($liste[count($liste) - 1][0] + 4);
    $alle = array_merge(array(array((int) round($links), 0)), $liste,
                        array(array((int) round($rechts), 0)));

    $teile = array();
    foreach ($alle as $pt) { $teile[] = $pt[0] . ':' . $pt[1]; }

    if ($punkte[1]['hoehe'] >= 80.0) {
        $meldungen[] = 'SEHR_HOCH';
    }
    if ($breite <= 0.0) {
        $meldungen[] = 'OHNE_BREITE';
    }
    return array(implode(', ', $teile), $meldungen, array(
        'hoehe_mitte' => round($punkte[1]['hoehe'], 1),
        'hoehe_kante' => round($punkte[0]['hoehe'], 1),
        'weite_mitte' => round($punkte[1]['weite'], 1),
        'ueber'       => round($h, 2),
    ));
}

/** Einen Winkel auf 0 bis unter 360 Grad bringen. */
function fb_azimut_normieren($grad)
{
    $g = fmod((float) $grad, 360.0);
    if ($g < 0.0) { $g += 360.0; }
    return $g;
}

function fb_horizont_lesen($text)
{
    $punkte = array();
    $fehler = array();
    $text = trim((string) $text);
    if ($text === '') { return array($punkte, $fehler); }

    /* HIER WURDE ZUERST AN JEDEM KOMMA GETRENNT - und das war ein Fehler mit
     * Wirkung bis ins Urteil.
     *
     * Das Muster erlaubt ausdruecklich das deutsche Dezimalkomma. Wer
     * "80,5:22,5" eintraegt, meint EINEN Punkt. Ein Trennen an jedem Komma
     * zerlegte das aber in "80", "5:22" und "5" - und "5:22" ist wieder ein
     * gueltiges Paar. Gemessen: aus "80,5:22,5" wurde ein Hindernis bei
     * Azimut 5 Grad, das niemand eingetragen hat, und dasselbe Fenster
     * kippte deshalb von "keine Meinung" auf "unbedingt beschatten".
     * Abgewiesen wurde dabei NICHTS - die Truemmer ergaben ja ein Paar,
     * und die Zusage im Kopf dieser Funktion war damit unwahr.
     *
     * Jetzt werden die Paare herausgezogen statt der Text zerlegt, und was
     * danach uebrig bleibt, wird gemeldet. Trennzeichen zwischen Azimut und
     * Hoehe ist ein Doppelpunkt, ein Schraegstrich oder ein Leerzeichen; der
     * Dezimalteil muss unmittelbar auf das Komma folgen, sonst gilt das
     * Komma als Trennzeichen zwischen zwei Paaren. */
    $zahl = '-?\d+(?:[\.,]\d+)?';
    $muster = '/(' . $zahl . ')(?:\s*[:\/]\s*|\s+)(' . $zahl . ')/';
    $rest = $text;
    if (preg_match_all($muster, $text, $treffer, PREG_SET_ORDER)) {
        foreach ($treffer as $m) {
            /* Nur das ERSTE Vorkommen ersetzen: zwei gleiche Paare im Text
             * sollen auch zweimal als verbraucht gelten. */
            $stelle = strpos($rest, $m[0]);
            if ($stelle !== false) {
                $rest = substr_replace($rest, ' ', $stelle, strlen($m[0]));
            }
            $a = (float) str_replace(',', '.', $m[1]);
            $h = (float) str_replace(',', '.', $m[2]);
            if ($a < 0.0 || $a > 360.0 || $h < 0.0 || $h > 90.0) {
                $fehler[] = $m[0];
                continue;
            }
            $punkte[] = array($a, $h);
        }
    }
    /* Zwischen den Paaren duerfen Trennzeichen stehen. Alles andere ist ein
     * Eintrag, den niemand gelesen hat - und der gehoert genannt. */
    $rest = trim(preg_replace('/[\s,;]+/', ' ', $rest));
    if ($rest !== '') { $fehler[] = $rest; }
    usort($punkte, function ($x, $y) {
        if ($x[0] == $y[0]) { return 0; }
        return ($x[0] < $y[0]) ? -1 : 1;
    });
    return array($punkte, $fehler);
}

/**
 * Hindernishoehe bei einem Azimut, linear zwischen den Stuetzpunkten.
 *
 * Ausserhalb des angegebenen Bereichs gilt der jeweils naechste Punkt -
 * NICHT null. Wer nur den Ostsektor eingetragen hat, meint damit nichts
 * ueber Westen; ein Rueckfall auf null waere dort aber die Behauptung
 * "freie Sicht", und die stuende dann in Loxone.
 * Ohne einen einzigen Stuetzpunkt gibt es keine Aussage: 0 und die Angabe,
 * dass nichts hinterlegt ist, holt sich der Aufrufer ueber count().
 */
function fb_horizont_hoehe($punkte, $azimut)
{
    $n = count($punkte);
    if ($n === 0) { return 0.0; }
    $a = fmod((float) $azimut, 360.0);
    if ($a < 0) { $a += 360.0; }
    if ($n === 1) { return (float) $punkte[0][1]; }

    /* ZWISCHEN zwei Stuetzpunkten wird interpoliert. */
    for ($i = 1; $i < $n; $i++) {
        if ($a <= $punkte[$i][0] && $a >= $punkte[$i - 1][0]) {
            $a0 = $punkte[$i - 1][0]; $h0 = $punkte[$i - 1][1];
            $a1 = $punkte[$i][0];     $h1 = $punkte[$i][1];
            if ($a1 == $a0) { return (float) $h1; }
            return (float) $h0 + ($h1 - $h0) * ($a - $a0) / ($a1 - $a0);
        }
    }

    /* AUSSERHALB DES BELEGTEN BEREICHS GILT DER NAECHSTE PUNKT - so, wie
     * der Kopf dieser Funktion, beide Hilfedateien und der Text in der
     * Oberflaeche es zusagen.
     *
     * Bis 0.12.6 wurde hier ueber die Naht INTERPOLIERT, mit der
     * Begruendung, ein Azimut sei ein Kreis. Das ist richtig - nur ist
     * eine Interpolation nicht "der naechste Punkt", und der Kommentar
     * behauptete zudem eine Wirkung, die er nicht hatte. Gemessen an
     * "80:22, 110:14, 160:6":
     *
     *     Azimut 350  ->  geliefert 16,86   naechster Punkt 80:22
     *     Azimut 270  ->  geliefert 12,29   naechster Punkt 160:6
     *     Azimut 300  ->  geliefert 14,00   naechster Punkt 80:22
     *
     * Bei 270 Grad - Westsonne am Abend - stand damit ein Hindernis von
     * 12,3 Grad im Weg, obwohl der Anwender ueber Westen NICHTS
     * eingetragen hatte. Ein Westfenster galt als verschattet, der
     * Direktanteil wurde auf 0 gesetzt, und es wurde keine Beschattung
     * verlangt, waehrend die Sonne voll ins Zimmer schien. Das ist ein
     * erfundener Wert, und zwar in der gefaehrlichen Richtung.
     *
     * Genommen wird jetzt der Punkt, der auf dem Kreis naeher liegt.
     * Zwischen den beiden Randpunkten springt der Horizont dadurch einmal
     * - genau in der Mitte der Luecke, die der Anwender nicht beschrieben
     * hat. Ein Sprung in einem unbeschriebenen Sektor ist ehrlicher als
     * eine glatte Kurve, die etwas behauptet. */
    $a0 = (float) $punkte[$n - 1][0];      // letzter belegter Punkt
    $a1 = (float) $punkte[0][0];           // erster belegter Punkt
    $d0 = $a - $a0; if ($d0 < 0) { $d0 += 360.0; }   // Weg vorwaerts bis $a
    $d1 = $a1 - $a; if ($d1 < 0) { $d1 += 360.0; }   // Weg vorwaerts ab $a
    return (float) ($d0 <= $d1 ? $punkte[$n - 1][1] : $punkte[0][1]);
}

/**
 * Einen Wert auf einen Bereich begrenzen. Steht hier, weil beide Aufrufer
 * (Rechenkern und Selbsttest) dieselbe Begrenzung brauchen und zwei Kopien
 * zwei Wahrheiten waeren.
 */
function fb_klemme($wert, $von, $bis)
{
    $w = (float) $wert;
    if ($w < $von) { return (float) $von; }
    if ($w > $bis) { return (float) $bis; }
    return $w;
}
