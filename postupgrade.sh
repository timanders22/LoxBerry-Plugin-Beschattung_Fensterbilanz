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
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi
PDATA="$BASE/data/plugins/$PFOLDER"

# Nachsehen, ob postinstall.sh die Rettung wirklich zurueckgeholt hat.
# Was hier gemeldet wird, ist gezaehlt - nicht angenommen.
DA=0
for N in bilanz lernen pv messwerte; do
    [ -s "$PDATA/$N.json" ] && DA=$((DA+1))
done
UEBRIG=0
for R in "$BASE"/data/plugins/"$PFOLDER".rettung.*.json; do
    [ -f "$R" ] && UEBRIG=$((UEBRIG+1))
done

echo "<OK> postupgrade abgeschlossen - beim naechsten Lauf wird frisch gerechnet."
if [ "$DA" -gt 0 ]; then
    echo "<INFO> $DA von 4 Messreihen liegen wieder da (Tagesbilanz, Lernkurve,"
    echo "<INFO> PV-Gegenprobe, Messwerte)."
else
    echo "<INFO> Es liegen keine Messreihen vor. Bei einer Neuinstallation ist das"
    echo "<INFO> richtig; nach einem Update waere es ein Befund - dann bitte im"
    echo "<INFO> Reiter Test nachsehen."
fi
if [ "$UEBRIG" -gt 0 ]; then
    echo "<FAIL> $UEBRIG Rettungsdatei(en) sind liegengeblieben:"
    ls -1 "$BASE"/data/plugins/"$PFOLDER".rettung.*.json 2>/dev/null | sed 's/^/<FAIL>    /'
    echo "<FAIL> Sie gehoeren nach $PDATA/ - postinstall.sh hat sie nicht geholt."
fi
exit 0
