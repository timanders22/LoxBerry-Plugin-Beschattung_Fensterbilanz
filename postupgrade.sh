#!/bin/bash
# Fensterbilanz - postupgrade
# command <ZUFALLSKENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <TEMPFOLDER>
#
# postinstall.sh laeuft beim Upgrade ohnehin - der Installer ruft es immer
# auf. Was hier passiert, darf deshalb nicht dort noch einmal stehen.
#
# HIER STAND BIS 0.12.6 EINE FALSCHE ZUSAGE, und sie ging als <INFO>-Zeile
# bei jedem Update an den Anwender hinaus:
#
#     "Messwerte, Tagesbilanz, Lernkurve und PV-Gegenprobe bleiben erhalten."
#
# Der Denkfehler ist genau zu benennen: dieses Skript argumentierte darueber,
# was es SELBST loescht - und darin hatte es recht, es fasste die drei
# Dateien nie an. Der ganze Ordner war zu diesem Zeitpunkt aber laengst weg.
# Gemessen an plugininstall.pl:
#
#     :857   preupgrade
#     :886   &purge_installation;          <- ohne Argument
#     :1631  rm -rfv .../data/plugins/$pfolder/    <- ohne $option-Bedingung
#     :1316  postinstall
#     :1341  postupgrade                   <- erst hier laeuft diese Datei
#
# data/plugins/<ordner>/ faellt also bei JEDEM Upgrade. Deshalb rettet
# preupgrade.sh die vier Dateien jetzt NEBEN den Ordner, und postinstall.sh
# holt sie zurueck. Dieses Skript loescht seither nichts mehr: die beiden
# frueheren "rm -f" auf stand.json und letzte_meldung.json waren toter Code,
# die Dateien gab es zu diesem Zeitpunkt nicht mehr.
#
# Der gerechnete Stand wird bewusst NICHT gerettet: aendert sich der Aufbau
# von stand.json zwischen zwei Fassungen, zeigte die Oberflaeche sonst bis
# zum naechsten Lauf alte Felder - oder rechnete damit. Der naechste
# Cron-Lauf ist hoechstens fuenf Minuten entfernt.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-fensterbilanz}"

# ---------- Die Wurzel: GELESEN, nicht geraten ----------
# Bis 0.12.9: BASE="${ARGV5:-$LBHOMEDIR}" und der feste Rueckfall $SELF/../..
# Die Funktion steht in allen vier Hakenskripten wortgleich, Begruendung in
# preupgrade.sh.
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
    echo "<WARNING> Das Wurzelverzeichnis des LoxBerry liess sich nicht bestimmen -"
    echo "<WARNING> es wurde nichts nachgesehen."
    exit 1
fi
PDATA="$BASE/data/plugins/$PFOLDER"

# Nachsehen, was postinstall.sh wirklich zurueckgeholt hat.
#
# I6 (Durchgang 30.09.2026): gezaehlt wird, was postinstall.sh in den Merker
# <ordner>.zurueckgeholt schreibt - Zahl und Namen der zurueckgeholten
# Dateien. Bis 0.12.10 zaehlte dieses Skript die Dateien im Datenordner; eine
# bilanz.json, die der erste Lauf in postinstall FRISCH angelegt hatte, galt
# als "wieder da" (in WSL gemessen, Installer-Pruefer U3, U7: "1 von 4
# Messreihen liegen wieder da", zurueck kam keine).
ZM="$BASE/data/plugins/$PFOLDER.zurueckgeholt"
ZAHL=""
ZN=""
if [ -f "$ZM" ]; then
    { read -r ZAHL ZN < "$ZM"; } 2>/dev/null
    rm -f "$ZM" 2>/dev/null
fi
case "$ZAHL" in ''|*[!0-9]*) ZAHL="" ;; esac
UEBRIG=0
for R in "$BASE"/data/plugins/"$PFOLDER".rettung.*.json; do
    [ -f "$R" ] && UEBRIG=$((UEBRIG+1))
done

echo "<OK> postupgrade abgeschlossen - beim naechsten Lauf wird frisch gerechnet."
if [ -z "$ZAHL" ]; then
    echo "<INFO> Wie viele Messreihen zurueckgeholt wurden, ist nicht feststellbar (postinstall.sh"
    echo "<INFO> hat keinen Merker hinterlassen) - bitte im Reiter Test nachsehen."
elif [ "$ZAHL" -gt 0 ]; then
    echo "<INFO> $ZAHL von 4 Messreihen wurden zurueckgeholt:$ZN."
else
    echo "<INFO> Es wurde keine Messreihe zurueckgeholt. Lagen vor dem Update keine vor, ist"
    echo "<INFO> das richtig; sonst waere es ein Befund - dann bitte im Reiter Test nachsehen."
fi
if [ "$UEBRIG" -gt 0 ]; then
    # Seit 0.12.10 laesst postinstall.sh eine Rettung mit Absicht liegen, wenn
    # sie nicht aus dieser Aktualisierung stammt oder unlesbar ist - und sagt
    # dort, warum. Bis 0.12.9 stand hier "postinstall.sh hat sie nicht geholt".
    echo "<WARNING> $UEBRIG Rettungsdatei(en) liegen noch neben $PDATA/:"
    ls -1 "$BASE"/data/plugins/"$PFOLDER".rettung.*.json 2>/dev/null | sed 's/^/<WARNING>    /'
    echo "<WARNING> Warum, steht oben bei postinstall; die Deinstallation raeumt sie weg."
fi
exit 0
