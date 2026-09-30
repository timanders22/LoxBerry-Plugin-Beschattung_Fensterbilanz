#!/bin/bash
# Fensterbilanz - preinstall
# command <ZUFALLSKENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <TEMPFOLDER>
#
# Neu in 0.12.11 (I1, Entscheidung Nr. 1 vom 29.09.2026), Bauform
# AudiConnect 0.9.22. Der Installer ruft dieses Skript bei JEDEM Einbau auf,
# nach dem Aufraeumen der alten Fassung und VOR dem Kopieren von
# Konfiguration, Cron-Datei und Oberflaeche (sbin/plugininstall.pl am Geraet:
# preupgrade :846, purge :874, preinstall :877, Cron :990, HTML :1066 -
# Geraet/2026-09-05/08_plugininstall.pl).
#
# Eine Aktualisierung erkennt es allein an der Marke
# data/plugins/<ordner>.upgrade_laeuft, die preupgrade.sh als Erstes anlegt
# (kein Altersvergleich). Dann tut es nichts: Zweitschrift und Rettungen
# braucht postinstall.sh.
#
# Ohne Marke ist es eine NEUINSTALLATION. Liegengebliebene Zweitschriften
# (config/plugins/<ordner>.backup.json mit dem Wortzeichen, .kaputt.json),
# Rettungen (data/plugins/<ordner>.rettung.*.json), ihr Stempel und der
# Zaehlmerker einer frueheren Installation gehen nach <name>.alt, gemeldet mit
# genau einer <WARNING>. Bis 0.12.10 spielte postinstall.sh die Zweitschrift
# ungefragt zurueck - Wortzeichen, Standort und Fenster einer frueheren
# Installation, gemeldet als "Aktualisierung abgeschlossen"; der Takt heilte
# sogar schon in der Luecke vor postinstall.sh aus ihr (in WSL gemessen,
# Installer-Pruefer N2, N2T). Weil dieses Skript vor der Cron-Kopie laeuft,
# schliesst es auch diese Luecke. Die Selbstheilung der Bibliothek liest .alt
# nie; die Deinstallation raeumt es ab.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-fensterbilanz}"

# ---------- Die Wurzel: GELESEN, nicht geraten ----------
# Die Funktion steht in allen Hakenskripten wortgleich, Begruendung in
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
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt - nichts beiseitegelegt."
    exit 0
fi
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts beiseitegelegt."; exit 0 ;;
esac
[ -f "$BASE/data/plugins/$PFOLDER.upgrade_laeuft" ] && exit 0

BEISEITE=""
FEST=""
for ZIEL in "$BASE/config/plugins/$PFOLDER.backup.json" \
            "$BASE/config/plugins/$PFOLDER.kaputt.json" \
            "$BASE"/data/plugins/"$PFOLDER".rettung.*.json \
            "$BASE/data/plugins/$PFOLDER.rettung.zeit"; do
    if [ -f "$ZIEL" ] && [ ! -L "$ZIEL" ]; then
        rm -f "${ZIEL:?}.alt" 2>/dev/null
        if mv -f "$ZIEL" "$ZIEL.alt" 2>/dev/null; then
            BEISEITE="$BEISEITE $ZIEL.alt"
        else
            FEST="$FEST $ZIEL"
        fi
    fi
done
for A in "$BASE/config/plugins/$PFOLDER.backup.json.alt" "$BASE/config/plugins/$PFOLDER.kaputt.json.alt"; do
    [ -f "$A" ] && [ ! -L "$A" ] && chmod 600 "$A" 2>/dev/null
done
# Der Zaehlmerker aus postinstall.sh (I6) gehoert zu keinem laufenden Vorgang.
rm -f "$BASE/data/plugins/$PFOLDER.zurueckgeholt" 2>/dev/null
if [ -n "$BEISEITE" ] || [ -n "$FEST" ]; then
    T="<WARNING> Neuinstallation: Einstellungen, Wortzeichen und Messreihen einer frueheren Installation werden NICHT eingespielt."
    [ -n "$BEISEITE" ] && T="$T Beiseitegelegt:$BEISEITE (die Deinstallation raeumt sie ab)."
    [ -n "$FEST" ] && T="$T Nicht zu verschieben, bitte von Hand entfernen:$FEST"
    echo "$T"
fi
exit 0
