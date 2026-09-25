#!/bin/bash
# Fensterbilanz - preupgrade
# command <ZUFALLSKENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <TEMPFOLDER>
#
# Die Reihenfolge des Installers ist (gemessen an plugininstall.pl):
#   :857  preupgrade
#   :886  purge_installation   <- raeumt config/plugins/<ordner>/ UND
#                                 data/plugins/<ordner>/ ab (:1629, :1631)
#   :1316 postinstall
#   :1341 postupgrade
#
# WAS HIER GERETTET WIRD, MUSS NEBEN DEN ORDNER - NICHT HINEIN.
# Und nicht nach /tmp, das auf dem LoxBerry fluechtig ist.
#
# BIS 0.12.6 WURDE NUR DIE KONFIGURATION GERETTET. Das war zu wenig:
# purge_installation loescht data/plugins/<ordner>/ bei JEDEM Upgrade, nicht
# nur bei der Deinstallation - die Bedingung "$option eq 'all'" steht erst
# zwoelf Zeilen spaeter und deckt andere Pfade. Damit gingen bei jedem
# Update verloren: die Tagesbilanz, die Aufheizkonstante (die eine Saison
# braucht), die PV-Gegenprobe und die Messwerte. postupgrade.sh hat
# gleichzeitig gemeldet, sie blieben erhalten - es argumentierte nur ueber
# das, was es SELBST loescht, und da hatte es recht; der Ordner war zu
# diesem Zeitpunkt aber laengst weg.
#
# ACHTUNG: $1 ist NICHT der Arbeitsordner, sondern eine zehnstellige
# Zufallskennung aus &generate(10). Der absolute Arbeitsordner steht im
# fuenften Argument. Deshalb wird hier ausschliesslich mit $3 und $5
# gearbeitet.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-fensterbilanz}"

# ---------- Die Wurzel: GELESEN, nicht geraten ----------
#
# Bis 0.12.9 stand hier BASE="${ARGV5:-$LBHOMEDIR}" und dahinter der feste
# Rueckfall $SELF/../.. - aus einem Archiv in einem fremden Baum (config/plugins
# und data/plugins, aber kein LoxBerry) ueberschrieb das Skript dort die
# Sicherung und legte Rettungsdateien an (in WSL gemessen,
# Pruefung-Beschattung_Fensterbilanz-0.12.10, Faelle W1, W2). Eine
# LoxBerry-Wurzel traegt immer config/system/general.json (Regeln/06).
# $5 und $LBHOMEDIR gelten mit config/plugins UND data/plugins darunter.
# Ohne Wurzel: <WARNING>, nichts anlegen, nichts sichern, Rueckgabe 1. Die
# Funktion steht in allen vier Hakenskripten wortgleich; Bauart Raumklima
# 0.11.11, Skoda-Connect-NG 0.9.24.
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
    echo "<WARNING> Es wurde NICHTS gesichert."
    exit 1
fi

# ---------- INHALT statt GROESSE ----------
#
# Rueckgabe 0 = traegt Inhalt, 1 = traegt keinen, 2 = NICHT PRUEFBAR (kein
# php). Art "token": lesbares JSON-Objekt mit nicht leerem Wortzeichen;
# "json": lesbares JSON-Objekt; "eingerichtet": Standort oder mindestens ein
# Fenster. Wortgleich in preupgrade.sh und postinstall.sh - ein Hakenskript
# kann sich nichts aus dem Plugin-Ordner holen, den es gerade erst auspackt.
# Bauart rk_inhalt() aus Raumklima 0.11.11.
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

# ---------- 0. Die Marke "Aktualisierung laeuft", als ERSTES ----------
#
# Zwischen dem Kopieren der neuen Dateien und postinstall.sh liegt fast eine
# Minute (Regeln/06, am Geraet gemessen). purge_installation hat
# data/plugins/<ordner>/ dann schon geleert; ein Messwert aus Loxone ueber
# den Endpunkt oder der Fuenf-Minuten-Takt legten eine frische bilanz.json
# an, und postinstall.sh verwarf daraufhin die Rettung (in WSL gemessen,
# Pruefung-Beschattung_Fensterbilanz-0.12.10, Faelle Z2-Z5). Solange die
# Marke gilt, rechnet und schreibt das Plugin nichts (fb_upgrade_laeuft() in
# webfrontend/html/fb_lib.php); postinstall.sh entfernt sie, uninstall raeumt
# sie weg. Sie liegt NEBEN dem Datenordner, sonst loeschte purge_installation
# sie mit. Inhalt: die Unixzeit.
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
if date +%s > "$MARKE" 2>/dev/null && [ -s "$MARKE" ]; then
    echo "<INFO> Marke gesetzt: das Plugin rechnet bis zum Ende der Aktualisierung nicht."
else
    echo "<WARNING> Die Marke $MARKE liess sich nicht anlegen."
    echo "<WARNING> Ein Lauf waehrend der Aktualisierung kann die Tagesbilanz verdraengen."
fi

FEHLER=0

# ---------- 1. Die Konfiguration ----------
#
# [ -f ] ist die schwaechste denkbare Bedingung: eine abgeschnittene oder
# leere fensterbilanz.json wuerde damit eine gute .backup.json
# ueberschreiben. Geprueft wird deshalb der INHALT - die Datei muss ein
# Aktionstoken tragen, sonst ist sie als Sicherung wertlos. Seit 0.12.10 mit
# json_decode (fb_inhalt): das grep bis 0.12.9 liess eine abgeschnittene Datei
# durch, solange die Zeile mit dem Wortzeichen noch darin stand. Ohne php
# bleibt es beim grep.
CF="$BASE/config/plugins/$PFOLDER/fensterbilanz.json"
BK="$BASE/config/plugins/$PFOLDER.backup.json"
fb_inhalt "$CF" token
CF_RC=$?
if [ "$CF_RC" = 2 ] && [ -s "$CF" ] && grep -q '"aktionstoken"[[:space:]]*:[[:space:]]*"[^"]\{1,\}"' "$CF"; then
    CF_RC=0
fi
if [ "$CF_RC" = 0 ]; then
    if cp -p "$CF" "$BK"; then
        chmod 600 "$BK" 2>/dev/null
        echo "<OK> Konfiguration gesichert."
    else
        echo "<FAIL> Die Konfiguration liess sich NICHT sichern ($CF -> $BK)."
        echo "<FAIL> Ein Update wuerde jetzt Wortzeichen und Einstellungen verlieren."
        FEHLER=1
    fi
elif [ -f "$CF" ]; then
    echo "<INFO> Die Konfiguration traegt kein Wortzeichen - die vorhandene"
    echo "<INFO> Sicherung bleibt unangetastet."
fi

# ---------- 2. Was ueber Tage und Wochen entstanden ist ----------
#
# Diese vier lassen sich nicht nachrechnen. Sie werden NEBEN den Datenordner
# gelegt, weil der Ordner selbst gleich abgeraeumt wird; postinstall.sh holt
# sie zurueck, uninstall raeumt sie mit ab.
#
# Seit 0.12.10 nach INHALT (ein lesbares JSON-Objekt, fb_inhalt) statt nach
# Groesse, und mit einem STEMPEL daneben: <ordner>.rettung.zeit traegt die
# Unixzeit dieser Rettung und die Namen der Dateien, die sie gerettet hat.
# postinstall.sh spielt nur ein, was im Stempel steht und hoechstens eine
# Stunde alt ist - eine Rettung aus einem abgebrochenen Update vor Tagen legte
# sonst alte Werte ueber eine frische Installation (Regeln/06, Ergaenzung vom
# 17.09.2026; Fall Z14). Eine unlesbare Datei ueberschreibt eine aeltere
# Rettung nicht.
PDATA="$BASE/data/plugins/$PFOLDER"
STEMPEL="$BASE/data/plugins/$PFOLDER.rettung.zeit"
GERETTET=0
NAMEN=""
for N in bilanz lernen pv messwerte; do
    Q="$PDATA/$N.json"
    Z="$BASE/data/plugins/$PFOLDER.rettung.$N.json"
    [ -e "$Q" ] || continue
    fb_inhalt "$Q" json
    Q_RC=$?
    if [ "$Q_RC" = 2 ] && [ -s "$Q" ]; then Q_RC=0; fi    # ohne php wie bis 0.12.9
    if [ "$Q_RC" = 0 ]; then
        if cp -p "$Q" "$Z" && cmp -s "$Q" "$Z"; then
            chmod 644 "$Z" 2>/dev/null
            GERETTET=$((GERETTET+1))
            NAMEN="$NAMEN $N"
        else
            echo "<FAIL> $N.json liess sich nicht retten - der Wert ist nach dem Update weg."
            FEHLER=1
        fi
    else
        echo "<WARNING> $N.json ist leer oder unlesbar und wurde nicht gerettet."
    fi
done
if [ "$GERETTET" -gt 0 ]; then
    if printf '%s%s\n' "$(date +%s 2>/dev/null)" "$NAMEN" > "$STEMPEL" 2>/dev/null; then
        echo "<OK> $GERETTET Datei(en) mit Messreihen gerettet (Tagesbilanz, Lernkurve, PV, Messwerte)."
    else
        echo "<WARNING> $GERETTET Datei(en) gerettet, aber der Stempel $STEMPEL liess sich nicht"
        echo "<WARNING> schreiben - postinstall.sh spielt sie dann nicht selbst ein."
    fi
else
    echo "<INFO> Es lagen keine Messreihen vor, die zu retten waeren."
fi

# ---------- 3. Die Projektdatei des Anwenders ----------
#
# Sie wird NICHT gerettet - sie ist bis zu vier Megabyte gross, und sie
# liegt auf dem PC des Anwenders ohnehin vor. Aber sie wird GENANNT, damit
# niemand hinterher sucht.
FREMD=$(find "$PDATA" -maxdepth 2 -iname '*.Loxone' 2>/dev/null)
if [ -n "$FREMD" ]; then
    echo "<INFO> Diese Projektdatei(en) liegen im Datenordner und ueberstehen das"
    echo "<INFO> Update NICHT - der Installer raeumt den Ordner ab:"
    echo "$FREMD" | sed 's/^/<INFO>    /'
    echo "<INFO> Sie liegen auf dem PC weiterhin vor. Ein Ablageort, der ein"
    echo "<INFO> Update uebersteht, steht im Reiter Einstellungen."
fi

if [ "$FEHLER" -ne 0 ]; then
    echo "<FAIL> preupgrade mit Fehlern abgeschlossen - siehe oben."
    exit 1
fi
echo "<OK> preupgrade abgeschlossen."
exit 0
