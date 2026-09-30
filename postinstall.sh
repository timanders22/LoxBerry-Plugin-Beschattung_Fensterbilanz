#!/bin/bash
# Fensterbilanz - postinstall
#
# Der Installer ruft mit:  <ZUFALLSKENNUNG> <NAME> <FOLDER> <VERSION> <BASE> <TEMPFOLDER>
#
# ACHTUNG: $1 ist NICHT der Arbeitsordner, sondern eine zehnstellige
# Zufallskennung aus &generate(10). Der absolute Arbeitsordner steht im
# FUENFTEN Argument, der Ordner mit dem entpackten Archiv im sechsten.
#
# postinstall laeuft IMMER, auch beim Upgrade - in plugininstall.pl gibt es
# dort kein if($isupgrade). Alles hier muss deshalb mehrfach ausfuehrbar
# sein, ohne Schaden anzurichten.
#
# Dieses Skript laeuft als Benutzer loxberry, NICHT als root. Ein
# "apt-get install" scheiterte hier still an fehlenden Rechten - das Plugin
# braucht ohnehin nichts nachzuinstallieren, es ist reines PHP.

ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-fensterbilanz}"

# ---------- Die Wurzel: GELESEN, nicht geraten ----------
#
# Bis 0.12.9 stand hier BASE="${ARGV5:-$LBHOMEDIR}" und dahinter der feste
# Rueckfall $SELF/../.. - aus einem Archiv in einem fremden Baum legte das
# Skript dort Ordner und eine Konfiguration an (in WSL gemessen,
# Pruefung-Beschattung_Fensterbilanz-0.12.10, Fall W3). Die Funktion steht in
# allen vier Hakenskripten wortgleich, Begruendung in preupgrade.sh.
fb_wurzel_suchen() {
    fb_v=$(cd "$(dirname "$(readlink -f "$0")")" 2>/dev/null && pwd -P)
    fb_i=0
    while [ -n "$fb_v" ] && [ "$fb_v" != "/" ] && [ "$fb_i" -lt 8 ]; do
        if [ -d "$fb_v/config/plugins" ] && [ -d "$fb_v/data/plugins" ] \
           && [ -f "$fb_v/config/system/general.json" ]; then
            echo "$fb_v"
            return 0
        fi
        fb_v=$(dirname "$fb_v")
        fb_i=$((fb_i + 1))
    done
    return 1
}
BASE="${ARGV5:-}"
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ]; then
    if [ -n "${LBHOMEDIR:-}" ] && [ -d "$LBHOMEDIR/config/plugins" ] && [ -d "$LBHOMEDIR/data/plugins" ]; then
        BASE="$LBHOMEDIR"
    else
        BASE=$(fb_wurzel_suchen) || BASE=""
    fi
fi
if [ -z "$BASE" ]; then
    echo "<WARNING> Das Wurzelverzeichnis des LoxBerry liess sich nicht bestimmen:"
    echo "<WARNING> weder das fuenfte Argument noch \$LBHOMEDIR noch der eigene"
    echo "<WARNING> Ablageort fuehrten auf einen Ordner mit config/plugins,"
    echo "<WARNING> data/plugins und config/system/general.json."
    echo "<WARNING> Es wurde NICHTS angelegt und nichts zurueckgespielt."
    exit 1
fi

# ---------- INHALT statt GROESSE ----------
# Wortgleich mit preupgrade.sh (Begruendung dort).
fb_inhalt() {   # $1 Datei, $2 Art: token | json | eingerichtet
    [ -s "$1" ] || return 1
    command -v php >/dev/null 2>&1 || return 2
    php -r '
        $d = json_decode((string) @file_get_contents($argv[1]), true);
        if (!is_array($d)) { exit(1); }
        $art = isset($argv[2]) ? $argv[2] : "";
        if ($art === "token") {
            exit((isset($d["aktionstoken"]) && is_string($d["aktionstoken"])
                  && trim($d["aktionstoken"]) !== "") ? 0 : 1);
        }
        if ($art === "eingerichtet") {
            $b = isset($d["breite"]) && is_numeric($d["breite"]) ? abs((float) $d["breite"]) : 0.0;
            $l = isset($d["laenge"]) && is_numeric($d["laenge"]) ? abs((float) $d["laenge"]) : 0.0;
            $f = 0;
            if (isset($d["fenster"]) && is_array($d["fenster"])) {
                foreach ($d["fenster"] as $z) {
                    if (is_array($z) && isset($z["kuerzel"]) && trim((string) $z["kuerzel"]) !== "") { $f++; }
                }
            }
            exit(($b > 0.001 || $l > 0.001 || $f > 0) ? 0 : 1);
        }
        exit(0);
    ' -- "$1" "$2" 2>/dev/null
    fb_rc=$?
    [ "$fb_rc" = 0 ] || [ "$fb_rc" = 1 ] || return 2
    return "$fb_rc"
}

# ---------- Die Marke aus preupgrade.sh ----------
#
# Sie sperrt das Plugin, solange die Aktualisierung laeuft (fb_upgrade_laeuft()
# in webfrontend/html/fb_lib.php). Hier wird sie ausgewertet und auf JEDEM
# Ausgang wieder entfernt (trap) - das Skript steigt an mehreren Stellen mit
# exit 1 aus, und ohne trap bliebe das Plugin bis zu einer Stunde stumm
# (Regeln/06, Ergaenzung vom 17.09.2026). Vor dem ersten Lauf unten wird sie
# ausdruecklich entfernt, sonst setzte er selbst aus.
#
# I1/I2 (Durchgang 30.09.2026, Entscheidung Nr. 1): LIEGT sie, ist dies eine
# Aktualisierung - ohne Altersvergleich. Nur dann werden Zweitschrift und
# Rettungen eingespielt. Bis 0.12.10 entschied das Alter von Marke und
# Stempel (hoechstens 3600 s): nach einem langsamen oder einem zweiten
# Update-Versuch fehlte die Lernkurve (in WSL gemessen, Installer-Pruefer U3,
# U7). Die 3600 s gelten weiter, aber nur als Startsperre des Plugins
# (fb_upgrade_laeuft()). Der Inhalt der Marke wird hier nicht mehr gelesen.
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
MARKE_DA=""
[ -f "$MARKE" ] && MARKE_DA=ja
trap 'rm -f "$MARKE" 2>/dev/null' EXIT

PBIN="$BASE/bin/plugins/$PFOLDER"
PDATA="$BASE/data/plugins/$PFOLDER"
PLOG="$BASE/log/plugins/$PFOLDER"
PCONFIG="$BASE/config/plugins/$PFOLDER"

mkdir -p "$PDATA" "$PLOG" "$PCONFIG" || {
    echo "<FAIL> Ordner konnten nicht angelegt werden."
    exit 1
}
# 750 auf den Datenordner. Bis 0.12.6 stand hier 775, damit der Anwender
# seine .Loxone-Projektdatei hier ABLEGEN konnte - und genau dahin hat ihn
# die Meldung am Ende dieses Skripts auch geschickt. Der Ordner wird aber
# bei JEDEM Upgrade abgeraeumt (plugininstall.pl:1631), und die Oberflaeche
# warnt seit jeher davor. Der Ablageort steht jetzt im Reiter Einstellungen;
# fb_ablageordner() sucht ihn ausserhalb dieses Ordners.
chmod 750 "$PDATA" 2>/dev/null
chmod 755 "$PLOG" 2>/dev/null
chmod 700 "$PCONFIG" 2>/dev/null

if [ ! -f "$PCONFIG/fensterbilanz.json" ]; then
    echo '{}' > "$PCONFIG/fensterbilanz.json" || {
        echo "<FAIL> Die Konfigurationsdatei liess sich nicht anlegen."
        exit 1
    }
fi
chmod 600 "$PCONFIG/fensterbilanz.json" 2>/dev/null

# Sicherung zurueckspielen - NUR bei einer Aktualisierung (liegende Marke).
# Bis 0.12.10 stand hier "uebersteht Update UND Neuinstallation", und eine
# Neuinstallation uebernahm Wortzeichen, Standort und Fenster einer frueheren
# Installation (I1, Installer-Pruefer N2). Bei einer Neuinstallation hat
# preinstall.sh die Zweitschrift schon nach .alt gelegt.
#
# NACH INHALT, NICHT NACH GROESSE. Bis 0.12.9 entschied "leer oder {}": eine
# Konfiguration mit irgendetwas darin, aber ohne Wortzeichen, blieb stehen
# (Fall Z19), und eine Sicherung OHNE Wortzeichen wurde eingespielt und als
# "wiederhergestellt" gemeldet (Fall Z12). Jetzt: traegt die Konfiguration
# ein Wortzeichen, bleibt sie; sonst wird die Sicherung eingespielt, wenn SIE
# eines traegt - ein verdraengter Stand, der nicht nur '{}' war, bleibt als
# .kaputt (0600) daneben liegen. Gemeldet wird, was geschah.
BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$PCONFIG/fensterbilanz.json"
if [ -f "$BK" ] && [ -z "$MARKE_DA" ]; then
    echo "<INFO> Neuinstallation: die Sicherung $BK wird nicht eingespielt."
elif [ -f "$BK" ]; then
    fb_inhalt "$CF" token
    CF_RC=$?
    if [ "$CF_RC" != 0 ]; then
        fb_inhalt "$BK" token
        BK_RC=$?
        if [ "$BK_RC" = 0 ]; then
            INHALT=$(cat "$CF" 2>/dev/null)
            if [ -s "$CF" ] && [ "$INHALT" != "{}" ]; then
                cp -p "$CF" "$CF.kaputt" 2>/dev/null && chmod 600 "$CF.kaputt" 2>/dev/null
                echo "<INFO> Die Konfiguration trug kein Wortzeichen; sie liegt als"
                echo "<INFO> $CF.kaputt daneben."
            fi
            if cp -p "$BK" "$CF" && cmp -s "$BK" "$CF"; then
                chmod 600 "$CF" 2>/dev/null
                echo "<OK> Konfiguration aus Sicherung wiederhergestellt (mit Wortzeichen)."
            else
                echo "<FAIL> Die gesicherte Konfiguration liess sich NICHT zurueckspielen."
                echo "<FAIL> Das Plugin startet mit Werkseinstellungen; das Wortzeichen"
                echo "<FAIL> wechselt damit, und die im Miniserver eingetragenen Adressen"
                echo "<FAIL> werden ungueltig. Die Sicherung liegt noch unter: $BK"
            fi
        elif [ "$BK_RC" = 1 ]; then
            echo "<INFO> Die Sicherung $BK traegt kein Wortzeichen -"
            echo "<INFO> es wurde nichts zurueckgespielt."
        else
            echo "<WARNING> Der Inhalt der Sicherung liess sich nicht pruefen (fehlt php?) -"
            echo "<WARNING> es wurde nichts zurueckgespielt. Sie liegt unter: $BK"
        fi
    fi
fi

# ---------- Die .user.ini von Hand anlegen ----------
#
# WARUM HIER UND NICHT AUS DEM ARCHIV: der Installer kopiert Punktdateien
# nicht mit. Gemessen am 16.09.2026 mit geraetestand_vergleichen.py gegen
# den Tag v0.12.8 - die Datei lag im Archiv und fehlte am Geraet, als
# einziger Unterschied ueberhaupt. Sie ist damit seit jeher nie angekommen,
# und der Reiter Einstellungen behauptete trotzdem, das Plugin lege sie ab.
#
# WOFUER: sie hebt upload_max_filesize und post_max_size fuer GENAU das
# Verzeichnis der Oberflaeche an. Eine .Loxone-Projektdatei ist 3 bis 4 MB
# gross, PHP nimmt ab Werk 2 MB - ohne diese Datei scheitert der Weg ueber
# den Browser.
#
# WANN SIE WIRKT: bei CGI, FastCGI und PHP-FPM. Laeuft PHP als
# Apache-Modul, wird sie stillschweigend uebergangen; auf dem Geraet dieses
# Hauses ist genau das der Fall (mod_php, php7.4.load, gemessen 16.09.2026).
# Schaden kann sie dort nicht - eine unbeachtete .user.ini ist folgenlos -,
# und der Reiter Test sagt in einer Zeile, welcher der drei Faelle vorliegt:
# liegt und wirkt, liegt und wird uebergangen, liegt nicht.
PHTMLAUTH="$BASE/webfrontend/htmlauth/plugins/$PFOLDER"
if [ -d "$PHTMLAUTH" ]; then
    if cat > "$PHTMLAUTH/.user.ini" <<'USERINI'
; Fensterbilanz - Grenzen fuer DIESES Verzeichnis.
; Angelegt von postinstall.sh: der Installer kopiert Punktdateien nicht mit.
; post_max_size gilt fuer die GANZE Absendung und muss deshalb ueber
; upload_max_filesize liegen.
; NACHWIRKUNG: PHP merkt sich diese Datei bis zu user_ini.cache_ttl Sekunden
; (Vorgabe 300) - nach dem Installieren kann es also fuenf Minuten dauern.
upload_max_filesize = 16M
post_max_size = 20M
USERINI
    then
        chmod 644 "$PHTMLAUTH/.user.ini" 2>/dev/null
        echo "<OK> .user.ini angelegt (hebt die Uploadgrenze auf 16 MB an,"
        echo "<OK> sofern dieser Webserver sie liest - der Reiter Test sagt es)."
    else
        echo "<INFO> Die .user.ini liess sich nicht anlegen. Das Plugin laeuft"
        echo "<INFO> trotzdem; der Weg ueber den Browser bleibt dann bei der"
        echo "<INFO> Grenze des Servers, der Weg ueber den Ablageordner nicht."
    fi
else
    echo "<INFO> $PHTMLAUTH gibt es nicht - die .user.ini entfaellt."
fi

# ---------- Die Messreihen aus preupgrade.sh zurueckholen ----------
#
# purge_installation loescht data/plugins/<ordner>/ bei JEDEM Upgrade
# (plugininstall.pl:1631, ohne Bedingung). preupgrade.sh legt deshalb vier
# Dateien NEBEN den Ordner; hier kommen sie zurueck.
#
# BIS 0.12.9 nur, wenn am Ziel nichts lag - und sonst wurde die Rettung
# GELOESCHT. In der Luecke vor diesem Skript schrieben Takt und Endpunkt aber
# eine frische bilanz.json; das Ziel war belegt, die Tagesbilanz weg (in WSL
# gemessen, Pruefung-Beschattung_Fensterbilanz-0.12.10, Faelle Z4, Z5).
#
# SEIT 0.12.11 ENTSCHEIDET DIE MARKE (I2, Entscheidung Nr. 1): liegt sie,
# ist dies eine Aktualisierung, und eingespielt wird, was im Stempel
# (<ordner>.rettung.zeit) steht - auch ueber ein belegtes Ziel, ohne
# Altersvergleich. Bis 0.12.10 musste der Stempel juenger als eine Stunde
# sein; nach einem langsamen oder einem zweiten Update-Versuch blieb die
# Lernkurve deshalb draussen (in WSL gemessen, Installer-Pruefer U3, U7).
# Rettungen aus einem FRUEHEREN Vorgang raeumt preupgrade.sh vorher nach
# .alt (I3). Liegt KEINE Marke (Neuinstallation), hat preinstall.sh sie schon
# beiseitegelegt; was trotzdem liegt, geht hier nach .alt, mit EINER WARNING
# (I4; bis 0.12.10 drei Zeilen je Datei und die Datei blieb unter ihrem
# Namen). Geloescht wird eine Rettung nur, wenn die Rueckholung nach Inhalt
# gelang (cmp).
STEMPEL="$BASE/data/plugins/$PFOLDER.rettung.zeit"
ST_ZEIT=""
ST_NAMEN=""
if [ -f "$STEMPEL" ]; then
    # In geschweiften Klammern: sonst meldete die Schale eine gescheiterte
    # Umlenkung selbst, bevor 2>/dev/null greift (Weissware 0.9.30, Fall C1).
    { read -r ST_ZEIT ST_NAMEN < "$STEMPEL"; } 2>/dev/null
fi
ZURUECK=0
ZNAMEN=""
NICHT=""
for N in bilanz lernen pv messwerte; do
    R="$BASE/data/plugins/$PFOLDER.rettung.$N.json"
    Z="$PDATA/$N.json"
    [ -e "$R" ] || continue
    case " $ST_NAMEN " in
        *" $N "*) GELISTET=ja ;;
        *) GELISTET="" ;;
    esac
    if [ -z "$MARKE_DA" ] || [ -z "$GELISTET" ]; then
        NICHT="$NICHT $R"
        continue
    fi
    fb_inhalt "$R" json
    case "$?" in
        0) ;;
        1)  echo "<WARNING> $R ist leer oder unlesbar - nicht eingespielt, sie bleibt liegen."
            continue ;;
        *)  echo "<WARNING> Der Inhalt von $R liess sich nicht pruefen (fehlt php?) -"
            echo "<WARNING> nicht eingespielt, sie bleibt liegen."
            continue ;;
    esac
    if cp -p "$R" "$Z" && cmp -s "$R" "$Z"; then
        chmod 644 "$Z" 2>/dev/null
        rm -f "$R"
        ZURUECK=$((ZURUECK+1))
        ZNAMEN="$ZNAMEN $N"
    else
        echo "<FAIL> $N.json liess sich nicht zurueckholen; die Rettung"
        echo "<FAIL> bleibt unter $R liegen."
    fi
done
if [ -n "$NICHT" ]; then
    # I4: EINE Meldung, und die Dateien gehen nach .alt statt unter ihrem
    # Namen liegenzubleiben (dort waeren sie beim naechsten Update wieder
    # Kandidaten).
    ALT_N=""
    for R in $NICHT; do
        rm -f "${R:?}.alt" 2>/dev/null
        mv -f "$R" "$R.alt" 2>/dev/null && ALT_N="$ALT_N $R.alt"
    done
    if [ -z "$MARKE_DA" ]; then
        W="<WARNING> Neuinstallation: liegengebliebene Messreihen einer frueheren Installation werden nicht eingespielt."
    else
        W="<WARNING> Diese Rettungen stehen nicht im Stempel dieser Aktualisierung und werden nicht eingespielt."
    fi
    echo "$W Beiseitegelegt:$ALT_N (die Deinstallation raeumt sie ab)."
fi
if [ "$ZURUECK" -gt 0 ]; then
    echo "<OK> $ZURUECK Datei(en) mit Messreihen zurueckgeholt (Tagesbilanz,"
    echo "<OK> Lernkurve, PV-Gegenprobe, Messwerte)."
fi
# I6 (Durchgang 30.09.2026): was WIRKLICH zurueckkam, fuer postupgrade.sh -
# es zaehlte bis 0.12.10 die Dateien im Datenordner und nannte eine frisch
# angelegte bilanz.json "wieder da" (in WSL gemessen, Installer-Pruefer U3,
# U7). Nur bei einer Aktualisierung; der Merker liegt neben dem Datenordner,
# postupgrade.sh liest und entfernt ihn.
if [ -n "$MARKE_DA" ]; then
    printf '%s%s\n' "$ZURUECK" "$ZNAMEN" > "$BASE/data/plugins/$PFOLDER.zurueckgeholt" 2>/dev/null
fi
# Der Stempel geht, sobald keine Rettung mehr liegt.
if ! ls "$BASE"/data/plugins/"$PFOLDER".rettung.*.json >/dev/null 2>&1; then
    rm -f "$STEMPEL" 2>/dev/null
fi
# Ab hier darf das Plugin wieder rechnen - der erste Lauf unten setzte sonst
# selbst aus. Der trap oben bleibt fuer die Ausgaenge davor.
rm -f "$MARKE" 2>/dev/null

# ---------- Laeuft die Rechnung ueberhaupt? ----------
# Der Selbsttest braucht weder Netz noch Miniserver noch Konfiguration. Er
# faehrt den Rechenkern gegen hinterlegte Faelle - darunter der gemessene
# Fall vom 23.08.2026, an dem die Freigabe in Loxone versagt hat.
#
# Der Rueckgabewert wird ausgewertet, nicht nur die Ausgabe: ein PHP, das
# mit einem toedlichen Fehler abbricht, schreibt unter Umstaenden gar
# nichts - und "keine Ausgabe" saehe dann aus wie "nichts zu beanstanden".
if [ -f "$PBIN/fb_lauf.php" ]; then
    if AUS=$(php "$PBIN/fb_lauf.php" --selbsttest 2>&1); then
        echo "<OK> Selbsttest: $(echo "$AUS" | tail -1)"
    else
        echo "<FAIL> Der Selbsttest des Rechenkerns ist nicht durchgelaufen:"
        echo "$AUS" | tail -20 | sed 's/^/<FAIL> /'
        echo "<INFO> Das Plugin ist installiert, rechnet aber moeglicherweise falsch."
        echo "<INFO> Bitte im Reiter Test nachsehen."
    fi
else
    echo "<INFO> fb_lauf.php wurde unter $PBIN nicht gefunden - der Selbsttest entfaellt."
fi

# ---------- Den Lauf einmal von Hand starten ----------
# Hausregel: jeden Cron-Dienst nach der Installation einmal von Hand
# aufrufen und den Rueckgabewert ansehen. Ein Skript, dessen require nur im
# entpackten Archiv aufgeht, laeuft installiert NIE - und der Cron schreibt
# nach /dev/null, also merkt es niemand. Genau daran ist in diesem Haus ein
# Hintergrunddienst ueber acht Fassungen vorbeigelaufen.
#
# Rueckgabewert 1 ist hier KEIN Fehler: solange kein Standort eingetragen
# ist und noch keine Messwerte eingetroffen sind, MUSS der Lauf sich
# beschweren. Unterschieden wird deshalb an der Meldung.
if [ -f "$PBIN/fb_lauf.php" ]; then
    AUS=$(php "$PBIN/fb_lauf.php" --jetzt 2>&1)
    RC=$?
    if echo "$AUS" | grep -q "fb_lib.php wurde nicht gefunden"; then
        echo "<FAIL> Der Lauf findet seine Bibliothek nicht:"
        echo "$AUS" | sed 's/^/<FAIL> /'
        exit 1
    fi
    if [ $RC -eq 0 ]; then
        echo "<OK> Erster Lauf durchgefuehrt: $(echo "$AUS" | head -1)"
    else
        echo "<INFO> Erster Lauf meldet - das ist vor der Einrichtung normal:"
        echo "$AUS" | head -3 | sed 's/^/<INFO> /'
    fi
fi

# Kein chown: plugininstall.pl ruft dieses Skript per "sudo -n -u loxberry"
# auf (:862) und setzt Eigentuemer und Rechte selbst (setrights/setowner).
# Ein chown als loxberry kann keinen Eigentuemer aendern - die Zeile hat mit
# 2>/dev/null still nichts getan und dabei nach Absicherung ausgesehen.

# ---------- Der Schlusstext ----------
#
# Die Erstanleitung nur, wenn noch nichts eingerichtet ist. postinstall.sh
# laeuft auch bei jedem Update, und bis 0.12.9 stand die Anleitung dann
# unbedingt da - der Anwender wurde aufgefordert, Standort und Fenster
# einzutragen, die laengst da waren (Regeln/06, "Nach einer Aktualisierung
# darf der Schlusstext nicht zur Erstinstallation raten"; Faelle Z9, Z10).
# Entschieden wird am INHALT der Konfiguration nach dem Zurueckspielen:
# Standort oder mindestens ein Fenster (fb_inhalt ... eingerichtet). Das
# Wortzeichen allein zaehlt nicht - es entsteht beim ersten Oeffnen der
# Oberflaeche. Scheiterte die Rueckholung, erscheint die Anleitung wieder.
# I1: "Aktualisierung abgeschlossen" nur bei einer Aktualisierung (Marke).
# Bis 0.12.10 stand der Satz auch nach einer Neuinstallation, die eine
# fremde Zweitschrift eingespielt hatte (Installer-Pruefer N2).
if [ -n "$MARKE_DA" ] && fb_inhalt "$CF" eingerichtet; then
    echo "<OK> Aktualisierung abgeschlossen, Einstellungen uebernommen - es ist nichts weiter zu tun."
    exit 0
fi
echo "<OK> Installation abgeschlossen."
echo "<INFO> Naechste Schritte in der Plugin-Oberflaeche:"
echo "<INFO>  1. Reiter Einstellungen: Standort (Breite und Laenge) eintragen."
echo "<INFO>     Ohne ihn gibt es keinen Sonnenstand und damit kein Urteil."
echo "<INFO>  2. Reiter Einstellungen: je Fenster eine Zeile - Kuerzel,"
echo "<INFO>     Himmelsrichtung, GLASFLAECHE in m2, Raum."
echo "<INFO>     Die Liste laesst sich aus der Loxone-Projektdatei fuellen."
echo "<INFO>     PHP nimmt ueber den Browser meist nur 2 MB an, eine"
echo "<INFO>     Projektdatei ist groesser. Der Reiter Einstellungen nennt"
echo "<INFO>     einen Ablageort, aus dem das Plugin sie ohne"
echo "<INFO>     Groessenbeschraenkung liest - dort steht der volle Pfad."
echo "<INFO>     NICHT in den Datenordner des Plugins legen: der wird bei"
echo "<INFO>     jedem Update und bei der Deinstallation abgeraeumt."
echo "<INFO>  3. Reiter Einbindung in Loxone: BEIDE Vorlagen herunterladen."
echo "<INFO>     Die Ausgangs-Vorlage liefert die Messwerte herein, ohne sie"
echo "<INFO>     rechnet das Plugin nichts."
echo "<INFO>  4. Reiter Test, Knopf 'Selbstpruefung' - er beantwortet in einer"
echo "<INFO>     Liste, ob die Einrichtung traegt."
exit 0
