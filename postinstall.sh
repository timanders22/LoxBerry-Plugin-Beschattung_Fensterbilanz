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
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi

PBIN="$BASE/bin/plugins/$PFOLDER"
PDATA="$BASE/data/plugins/$PFOLDER"
PLOG="$BASE/log/plugins/$PFOLDER"
PCONFIG="$BASE/config/plugins/$PFOLDER"

mkdir -p "$PDATA" "$PLOG" "$PCONFIG" || {
    echo "<FAIL> Ordner konnten nicht angelegt werden."
    exit 1
}
chmod 755 "$PDATA" "$PLOG" 2>/dev/null
chmod 700 "$PCONFIG" 2>/dev/null

[ -f "$PCONFIG/fensterbilanz.json" ] || echo '{}' > "$PCONFIG/fensterbilanz.json"
chmod 600 "$PCONFIG/fensterbilanz.json" 2>/dev/null

# Sicherung zurueckspielen (uebersteht Update UND Neuinstallation).
# Nur, wenn die Konfiguration wirklich leer ist - eine gefuellte wird nicht
# ueberschrieben.
BK="$BASE/config/plugins/$PFOLDER.backup.json"
CF="$PCONFIG/fensterbilanz.json"
if [ -f "$BK" ]; then
    INHALT=$(cat "$CF" 2>/dev/null)
    if [ ! -s "$CF" ] || [ "$INHALT" = "{}" ]; then
        cp -p "$BK" "$CF" && echo "<OK> Konfiguration aus Sicherung wiederhergestellt."
    fi
fi

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

chown -R loxberry:loxberry "$PBIN" "$PDATA" "$PLOG" "$PCONFIG" 2>/dev/null

echo "<OK> Installation abgeschlossen."
echo "<INFO> Naechste Schritte in der Plugin-Oberflaeche:"
echo "<INFO>  1. Reiter Einstellungen: Standort (Breite und Laenge) eintragen."
echo "<INFO>     Ohne ihn gibt es keinen Sonnenstand und damit kein Urteil."
echo "<INFO>  2. Reiter Einstellungen: je Fenster eine Zeile - Kuerzel,"
echo "<INFO>     Himmelsrichtung, Flaeche, Raum."
echo "<INFO>  3. Reiter Einbindung in Loxone: BEIDE Vorlagen herunterladen."
echo "<INFO>     Die Ausgangs-Vorlage liefert die Messwerte herein, ohne sie"
echo "<INFO>     rechnet das Plugin nichts."
echo "<INFO>  4. Reiter Test, Knopf 'Selbstpruefung' - er beantwortet in einer"
echo "<INFO>     Liste, ob die Einrichtung traegt."
exit 0
