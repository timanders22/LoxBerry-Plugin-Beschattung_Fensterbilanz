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
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi

FEHLER=0

# ---------- 1. Die Konfiguration ----------
#
# [ -f ] ist die schwaechste denkbare Bedingung: eine abgeschnittene oder
# leere fensterbilanz.json wuerde damit eine gute .backup.json
# ueberschreiben. Geprueft wird deshalb der INHALT - die Datei muss ein
# Aktionstoken tragen, sonst ist sie als Sicherung wertlos.
CF="$BASE/config/plugins/$PFOLDER/fensterbilanz.json"
BK="$BASE/config/plugins/$PFOLDER.backup.json"
if [ -s "$CF" ] && grep -q '"aktionstoken"[[:space:]]*:[[:space:]]*"[^"]\{1,\}"' "$CF"; then
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
PDATA="$BASE/data/plugins/$PFOLDER"
GERETTET=0
for N in bilanz lernen pv messwerte; do
    Q="$PDATA/$N.json"
    Z="$BASE/data/plugins/$PFOLDER.rettung.$N.json"
    if [ -s "$Q" ]; then
        if cp -p "$Q" "$Z"; then
            chmod 644 "$Z" 2>/dev/null
            GERETTET=$((GERETTET+1))
        else
            echo "<FAIL> $N.json liess sich nicht retten - der Wert ist nach dem Update weg."
            FEHLER=1
        fi
    fi
done
if [ "$GERETTET" -gt 0 ]; then
    echo "<OK> $GERETTET Datei(en) mit Messreihen gerettet (Tagesbilanz, Lernkurve, PV, Messwerte)."
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
