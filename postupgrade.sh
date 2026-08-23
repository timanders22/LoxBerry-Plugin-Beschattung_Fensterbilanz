#!/bin/bash
# Fensterbilanz - postupgrade
# command <ZUFALLSKENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <TEMPFOLDER>
#
# postinstall.sh laeuft beim Upgrade ohnehin - der Installer ruft es immer
# auf. Was hier passiert, darf deshalb nicht dort noch einmal stehen.
#
# Verworfen wird der gerechnete Stand: aendert sich der Aufbau von
# stand.json zwischen zwei Fassungen, zeigte die Oberflaeche sonst bis zum
# naechsten Lauf alte Felder - oder rechnete damit. Der naechste Cron-Lauf
# ist hoechstens fuenf Minuten entfernt.
#
# NICHT geloescht werden die MESSWERTE. Sie kommen aus dem Miniserver und
# treffen erst wieder ein, wenn sich dort ein Wert aendert - das kann bei
# einer Tagesprognose Stunden dauern. Wer sie mit wegraeumt, laesst das
# Plugin nach jedem Update stundenlang "keine Daten" melden.
#
# UND ERST RECHT NICHT bilanz.json, lernen.json und pv.json. In ihnen steckt
# alles, was ueber Tage und Wochen zusammengetragen wurde und sich nicht
# nachrechnen laesst: die Wattstunden des laufenden Tages, die
# Aufheizkonstante je Raum (die eine Saison braucht) und die Gegenprobe
# gegen die Ertragsprognose. Ein Update, das sie mitnimmt, wirft eine
# Messreihe weg, die niemand wiederherstellen kann - genau der Fehler, an
# dem in diesem Haus schon einmal ein Heilungszaehler verlorenging.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-fensterbilanz}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi
rm -f "$BASE/data/plugins/$PFOLDER/stand.json"
rm -f "$BASE/data/plugins/$PFOLDER/letzte_meldung.json"
echo "<OK> postupgrade abgeschlossen - beim naechsten Lauf wird frisch gerechnet."
echo "<INFO> Messwerte, Tagesbilanz, Lernkurve und PV-Gegenprobe bleiben erhalten."
exit 0
