# LoxBerry-Plugin „Beschattung Fensterbilanz"

Version 0.12.13

Ein Urteil je Fenster: **ist der Sonneneintrag durchs Glas gerade erwünscht?**
Eine Zahl von −100 (unbedingt beschatten) bis +100 (Sonne hereinlassen), dazu
ein Digitalwert für die einfache Verdrahtung — über MQTT und über einen
tokengeschützten HTTP-Endpunkt.

**Das Plugin schaltet nichts.** Es ersetzt den `AutoJalousie`-Baustein nicht.
Es liefert die eine Größe, die Loxone fehlt, und hängt an dessen Eingang
`AutoShade`.

## Neu in 0.12.13

Kopf wie alle Hausplugins: Statusübersicht über den Reitern, Zusammenfassung oben im ersten Reiter.

* **Zusammenfassung** des Plugins in einem grünen Kasten oben im Reiter Einstellungen, vor „Wie es gerade steht“.
* Die Statuskacheln über den Reitern stehen schon und bleiben unverändert.
* Nur Oberfläche; gerendert unter PHP 7.4, 8.4 und 8.5, nicht am Gerät angesehen.

---

## Woraus es entstanden ist

Am 23.08.2026 um 10:00 Uhr, gemessen in einer laufenden Anlage:

| | |
| --- | --- |
| Solarstrahlung | 662 W/m² |
| Außentemperatur | 19,5 °C |
| EG Wohnzimmer | **32,7 °C** bei 28 °C Soll |
| Alle 15 Raumregler | melden Beschattungsbedarf |
| Gefahrene Rollläden | **keiner** |

Die Freigabe verlangte *Außenluft ≥ 23 °C*. Sie war nicht kaputt — sie fragte
**die falsche Größe**. Das Problem ist der Sonneneintrag durchs Glas, gefragt
wurde die Lufttemperatur.

Die eigentliche Regel lautet in einem Satz:

> Im Juli und August ist der Eintrag unerwünscht, selbst bei 19 °C draußen.
> Im September ist er willkommen, weil es draußen kälter wird — auch wenn die
> Räume noch warm sind.

Das ist keine Schaltbedingung, das ist eine Energiebilanz. Genau dort hört die
Loxone-Logik auf und ein Plugin fängt an.

---

## Was gerechnet wird

Je Fenster und Lauf:

1. **Geometrie** — Sonnenstand aus Ort und Zeit (NOAA-Verfahren, reine
   Rechnung, kein Dienst und kein Netz), daraus der Einfallswinkel auf das
   Glas. Bei streifendem Einfall spiegelt das Glas den größten Teil weg
   (ASHRAE-Korrekturglied).
2. **Verschattungshorizont** — je Fenster eine Handvoll Stützpunkte
   „ab Azimut X steht ein Hindernis Y Grad hoch". Steht die Sonne darunter,
   fällt der **direkte** Anteil weg; das Himmelslicht bleibt.
3. **Wärmebedarf** — bis zu vier Teile, jeder stetig von +1 nach −1 und
   gewichtet addiert:
   * der **Raum** gegen seine Beschattungsgrenze (in Loxone `TShadeHeat`,
     nicht der Heizsollwert),
   * der erwartete **Tageshöchstwert** gegen die Tagesgrenze,
   * die **Tagesbilanz** des Raums, in Wattstunden **je Quadratmeter
     Grundfläche** — *ab Werk aus*,
   * die Prognose für **morgen**, ab einer einstellbaren Stunde — *ab Werk
     aus*.
4. **Urteil** — Wärmebedarf mal Gewicht des Eintrags. Das Gewicht folgt der
   **direkten** Strahlung aufs Glas: ein Nordfenster bekommt Himmelslicht,
   aber nichts, worüber zu entscheiden wäre, und sein Urteil bleibt 0.

**Die Glasfläche steht nicht im Urteil.** Ein kleines und ein großes Südfenster
wollen zur selben Zeit dasselbe — gerechnet wird in Watt je Quadratmeter. Die
Fläche bestimmt dafür jede Wattzahl, jede Wattstunde und die gemessene
Aufheizkonstante; und sobald die Tagesbilanz ein Gewicht hat, wirkt sie über
den Tag doch auf das Urteil. Sie gehört also je Fenster eingetragen, auch wenn
ihr Fehlen im Betrieb nie auffällt — und genau deshalb sagen es die
Fensterliste und der Reiter *Test* von sich aus, solange dort noch die Vorgabe
steht.

Dazu je Fenster **ein Satz, der das Urteil begründet** — mit Sonnenhöhe,
Einfallswinkel, Watt am Glas, Raumtemperatur und Tagesprognose. Wer nicht
sieht, *warum* ein Fenster beschattet wird, schaltet das Plugin nach der ersten
Überraschung ab.

**Vorausschau.** Dieselbe Rechnung läuft ein zweites Mal für den Zeitpunkt in
einer halben Stunde. Es wandert allein die Geometrie, die Messwerte bleiben,
wie sie sind — das braucht **keine Wettervorhersage**. Damit lässt sich in
Loxone vorausschauend freigeben, statt der Sonne hinterherzufahren.

**Drei getrennte Aussagen** neben dem Urteil, die bewusst *nicht* hineingemischt
werden — wer sie zusammenwirft, kann hinterher nicht mehr sagen, warum ein
Fenster zu ist:

| Wert | Bedeutung |
| --- | --- |
| `BLENDUNG` | tief stehende Sonne blendet — auch im Januar, wenn die Wärme willkommen ist |
| `DAEMMEN` | nachts kalt, der Tag will Wärme: der geschlossene Rollladen ist Wärmeschutz |
| `GEFAHREN` | hat Loxone die Forderung überhaupt umgesetzt? |

---

## Was hereinkommen muss

Das Plugin misst nichts selbst. Die Werte kommen über **einen virtuellen
Ausgang** aus Loxone; die fertige Importdatei erzeugt der Reiter *Einbindung in
Loxone*.

| Schlüssel | Nötig | Bedeutung |
| --- | --- | --- |
| `strahlung` | immer | Globalstrahlung auf die Waagerechte in W/m² |
| `prognose` | immer | erwarteter Tageshöchstwert — **die Größe, die alles trägt** |
| `ist.<raum>` | je Raum | Raumtemperatur |
| `grenze.<raum>` | je Raum | Beschattungsgrenze des Raums (`TShadeHeat`) |
| `aussen` | mit Nachtdämmung | Außentemperatur; sonst freiwillig, steht dann in der Begründung |
| `prognose1` | mit Vorabendteil | erwarteter Tageshöchstwert **morgen** |
| `pv_prognose` | mit PV-Gegenprobe | Ertragsprognose als Vergleichsgröße zur Strahlung |
| `stellung.<kürzel>` | mit Rückmeldung | Rollladenstellung des Fensters in Prozent |

Was nicht eingeschaltet ist, wird auch nicht verlangt und erzeugt in Loxone
keinen einzigen Eingang.

**Fail closed:** fehlt ein nötiger Wert oder ist er älter als das eingestellte
Höchstalter, wird nicht geraten. Urteil 0, Beschatten 0, `FB_OK` auf 0 — und in
Loxone greift wieder die eigene Freigabe des `AutoJalousie`. Dasselbe gilt für
einen **unmöglichen** Wert: 662 W/m² bei zwei Grad Sonnenhöhe sind kein
Messwert, sondern ein Defekt, und werden verworfen statt geklemmt.

---

## Was hinausgeht

**Über MQTT** (Regelweg), Präfix einstellbar, ab Werk `fenster`:

    fenster/ok                    Daten gültig
    fenster/herz                  Minuten seit dem letzten Lauf, −1 = noch nie
    fenster/saison                Wärmebedarf des Tages, +100 bis −100
    fenster/wh_tag                Wärmeeintrag des Tages über alle Fenster
    fenster/<kuerzel>/urteil      −100 … +100
    fenster/<kuerzel>/beschatten  0/1
    fenster/<kuerzel>/grund       Zahl, siehe Reiter MQTT
    fenster/<kuerzel>/watt        Wärme durch dieses Glas
    fenster/<kuerzel>/wh          Wattstunden dieses Fensters heute
    fenster/<kuerzel>/begruendung ein Satz (Text)

Ist die Vorausschau, die Blendung, die Nachtdämmung, die Stellungsrückmeldung,
die PV-Gegenprobe oder der Tagesbericht eingeschaltet, kommen die zugehörigen
Themen hinzu — welche genau, zeigt der Reiter *MQTT*, und der Reiter *Test*
hält die Liste gegen das, was tatsächlich gesendet wird.

Zurückbehalten (retained) geht seit 0.12.11 nur `fenster_anzahl` (eine
Einstellung). Alles andere geht flüchtig hinaus, auch `bericht` (ein
Tageswert) und die Urteile je Fenster (siehe unten).

**Sonnenstand für andere Plugins (Sonne-1), ab Werk aus.** Mit dem Haken
*Sonnenstand und „Sonne wirkt je Fassade“ unter haus/sonne/ senden* im Reiter
*MQTT* gehen in jedem Lauf zusätzlich, **ohne Präfix** und **flüchtig**, hinaus:

    haus/sonne/azimut                  Grad von Nord im Uhrzeigersinn, 2 Nachkommastellen
    haus/sonne/elevation               Sonnenhöhe mit Refraktion, Grad (nachts negativ)
    haus/sonne/fassaden                Ausrichtungen der aktiven Fenster, z. B. 90,180,270 (- ohne)
    haus/sonne/fassade/<azimut>/wirkt  1 = direkte Sonne erreicht ein Fenster dieser Fassade
    haus/sonne/ts                      Zeitpunkt der Rechnung (Unix-Sekunden), als letztes

Quelle ist die Fensterbilanz selbst; gedacht ist das für den
Beschattungswächter, der den Sonnenstand dann nicht zweimal rechnet. „Wirkt“
ist reine Geometrie (vor der Glasebene, über dem Horizont, nicht hinter dem
Verschattungshorizont, Dachüberstand verschattet nicht ganz) — ob Wolken
davor sind, misst der Abnehmer selbst. Ohne Standort oder mit ausgeschaltetem
MQTT geht nichts hinaus. Nichts davon ist zurückbehalten, es bleibt also auch
nichts im Broker stehen, wenn der Haken wieder aus ist.

**Über HTTP**, tokengeschützt, mit denselben Werten:

    /plugins/fensterbilanz/index.php?token=<TOKEN>&aktion=status
    /plugins/fensterbilanz/index.php?token=<TOKEN>&aktion=json
    /plugins/fensterbilanz/index.php?token=<TOKEN>&aktion=fenster&k=<K>
    /plugins/fensterbilanz/index.php?token=<TOKEN>&selftest=1

Auch die abfragenden Aufrufe verlangen ein Wortzeichen: in der Antwort stehen
Raumtemperaturen, und die sagen jedem im Heimnetz, ob jemand zu Hause ist.

Ein Stand, dessen Zeitstempel mehr als **5 Sekunden in der Zukunft** liegt
(die Uhr des LoxBerry ist zurückgesprungen), ist keine Aussage: die
Statuszeile trägt dann `OK=0`, alle Urteile 0 und `HERZ=-1`, und der nächste
Lauf rechnet sofort neu.

---

## Einrichten in vier Schritten

1. **Standort** eintragen (Reiter *Einstellungen*). Ohne geografische Breite
   und Länge wird nichts gerechnet — und nichts geraten.
2. **Fensterliste aus der Projektdatei einlesen.** Der Knopf im Reiter
   *Einstellungen* liest eine hochgeladene `.Loxone`-Datei, sucht alle
   `AutoJalousie`-Bausteine und schlägt Kürzel, Himmelsrichtung und Raum vor.
   Übernommen wird nur in **leere** Zeilen; Fläche, g-Wert und
   Verschattungshorizont ergänzt man von Hand. Wer lieber tippt, kann das
   weiterhin — die Zeilen lassen sich vollständig von Hand füllen.
   Die **Glasflächen** stehen nicht in der Projektdatei und müssen von Hand
   dazu — der `AutoJalousie`-Baustein führt die Richtung und die
   Lamellenmaße, keine Fenstermaße. Die **Grundflächen der Räume** stehen
   sehr wohl darin und werden mit übernommen.

   Dafür gibt es **drei Wege**, und der empfohlene braucht die Datei gar
   nicht erst auf dem LoxBerry:

   * **Einen Auszug einfügen.** Die Projektdatei ist knapp 4 MB — was das
     Plugin daraus braucht, sind rund **2,5 kB**: je Rollladenbaustein ein
     Titel und eine Himmelsrichtung, je Raum ein Titel und eine
     Grundfläche. Das mitgelieferte PowerShell-Skript liest genau das auf
     dem eigenen Rechner aus und legt es in die Zwischenablage; im Reiter
     *Einstellungen* einfügen, Knopf drücken. **Kommt an jeder
     Absendegrenze und an jedem Dateimanager vorbei.** Die Namensregeln
     bleiben dabei im Plugin, damit alle Wege dieselben Kürzel ergeben.
   * **Datei auf den LoxBerry legen** — über die Windows-Freigabe, mit
     WinSCP oder per `scp`. Sie steht dann im Reiter *Einstellungen* zur
     Auswahl. **Ohne Größenbeschränkung**, weil nichts abgesendet wird.
     **Nicht nach `data/plugins/fensterbilanz/`**: dieses Verzeichnis wird
     bei jedem Update und bei der Deinstallation abgeräumt, und die Datei
     ist dann lautlos weg. Den Ablageort, der beides übersteht, nennt der
     Reiter *Einstellungen* mit vollem Pfad.
   * **Datei über den Browser absenden** — bequemer, scheitert aber meist:
     PHP nimmt ab Werk 2 MB je Datei an, eine Projektdatei ist 3 bis 4 MB
     groß. **Das Plugin kann diese Grenze nicht anheben** —
     `upload_max_filesize` und `post_max_size` gelten je Verzeichnis, und
     PHP wertet sie aus, bevor eine Zeile des Plugins läuft; `ini_set()`
     gibt für beide eine Fehlanzeige zurück (gemessen mit PHP 7.4.33 und
     8.4.24). Die Oberfläche zeigt die beiden Werte deshalb **vor** dem
     Formular an, nicht erst in der Fehlermeldung.
3. **Beide Vorlagen** im Reiter *Einbindung in Loxone* herunterladen und in
   Loxone Config einlesen. Die Ausgangs-Vorlage liefert die Messwerte herein;
   **ohne sie rechnet das Plugin nichts.**
4. **Selbstprüfung** im Reiter *Test*. Sie beantwortet ohne Loxone, ob die
   Einrichtung trägt — von der Sprachdatei über den eigenen Endpunkt bis zum
   Rechenkern.

Beanstandet ein Formular eine Eingabe, wird **nichts** gespeichert, auch nicht
die übrigen Felder; die eingetippten Werte stehen danach wieder im Formular,
das beanstandete Feld ist rot umrandet. *Einstellungen sichern* warnt gelb,
wenn ein gespeicherter Wert das Zurückspielen der eigenen Sicherung nicht
bestehen würde, und liefert die Datei trotzdem vollständig.

Zum Prüfen der Einrichtung gibt es Bilder statt Zahlenkolonnen:

* **Sonnenbahn und Horizont** je Fenster (Reiter *Einstellungen*). Wo die
  graue Fläche die Bahn überdeckt, fällt an diesem Fenster keine direkte Sonne
  ein. Passt das Bild nicht zu dem, was man aus dem Fenster sieht, stimmt eine
  Himmelsrichtung oder ein Horizont nicht.
* **Tagesgang** (Reiter *Test*): stundenweise, welches Fenster wann Sonne hat.
* **Was wäre, wenn** (Reiter *Test*): das ganze Modell durchprobieren, **ohne
  zu speichern**. Gerechnet wird mit den tatsächlichen Messwerten von jetzt,
  daneben steht der Stand aus der gespeicherten Einstellung.

---

## Was über die Zeit dazukommt

Vier Dateien werden über ein Update gerettet — in ihnen steht, was sich
nicht nachrechnen lässt:

| Datei | Inhalt |
| --- | --- |
| `bilanz.json` | die Wattstunden des laufenden Tages, je Fenster und je Raum |
| `lernen.json` | je Raum und Tag: Wärmeeintrag gegen gemessene Temperaturspanne |
| `pv.json` | Tagessummen von gemeldeter Strahlung und Ertragsprognose |
| `messwerte.json` | was zuletzt aus dem Miniserver hereinkam |

**Wie das geht, und warum es bis 0.12.6 nicht ging.** Der LoxBerry-Installer
räumt `data/plugins/<ordner>/` bei **jedem** Upgrade ab, nicht nur bei der
Deinstallation — `purge_installation` in `plugininstall.pl` löscht das
Verzeichnis, bevor `postinstall` und `postupgrade` überhaupt laufen. Bis
0.12.6 stand an vier Stellen geschrieben, die drei Dateien überstünden ein
Update; sie überstanden es nicht, und `postupgrade.sh` meldete es bei jedem
Update ausdrücklich als gelungen. Seit 0.12.7 legt `preupgrade.sh` sie
**neben** den Ordner (`data/plugins/<ordner>.rettung.<name>.json`), und
`postinstall.sh` holt sie zurück; `postupgrade.sh` zählt nach und sagt, wie
viele wieder da sind.

`stand.json` wird weiterhin bewusst verworfen: ändert sich sein Aufbau
zwischen zwei Fassungen, zeigte die Oberfläche sonst bis zum nächsten Lauf
alte Felder. Der nächste Cron-Lauf ist höchstens fünf Minuten entfernt.

Wann ein Raum als **voll** gilt, steht als Zahl **je Quadratmeter
Grundfläche** — 150 Wh/m² sind für ein 5-m²-Bad 750 Wh und für ein
25-m²-Wohnzimmer 3750 Wh. Eine feste Zahl je Raum wäre für das kleine Bad viel
zu hoch und für das große Zimmer zu niedrig, denn was einen Raum aufheizt,
hängt an seiner Masse. Die Grundflächen kommen aus der Projektdatei; wo keine
bekannt ist, wird eine angenommen, und der Begründungssatz des Fensters sagt
dann „geschätzt".

Daraus entstehen drei Dinge, die ein Modell allein nicht liefert:

* **Die Aufheizkonstante je Raum** in Kelvin je Kilowattstunde, an dieser
  Anlage gemessen. Ausgeglichen wird durch den Ursprung — ohne Wärme keine
  Erwärmung. Angezeigt wird sie **mit der Zahl der Tage und der Streuung**:
  eine Konstante aus fünf Tagen ist keine, und das soll man sehen. Stehen
  dabei noch Fenster auf der Vorgabefläche, sagt der Reiter *Test* das
  **vor** der Zahl — sie fällt aus dem gerechneten Wärmeeintrag und wäre um
  denselben Faktor daneben.
* **Der Tagesbericht**, einmal am Abend: wie viel Sonne hereinkam, wie viele
  Fenster beschattet waren, welches am längsten, wo die Spitze lag.
* **Die Gegenprobe gegen die PV-Ertragsprognose.** Keine zweite Wetterquelle —
  der gerechnete Wert bleibt der gemessene. Läuft die gemeldete Strahlung über
  Tage von der Prognose weg, ist wahrscheinlich der Geber verschmutzt,
  verschattet oder verstellt. Gewarnt wird, eingegriffen nicht.

---

## Genauigkeit, ehrlich

Der Sonnenstand ist auf ein Hundertstel Grad genau, **geeicht gegen eine
zweite, unabhängige Rechnung** (PSA-Algorithmus nach Blanco-Muriel u. a. 2001)
an 73 Punkten über vier Jahreszeiten. Die Eichung hat beim Bau einen echten
Fehler gefunden: der Azimut war an der Nord-Süd-Achse gespiegelt, während die
Sonnenhöhe auf 0,007 Grad stimmte — jedes Ostfenster wäre mit einem
Westfenster vertauscht worden.

Alles Übrige ist **Modell, nicht Messung**, und die Quellen stehen im
Quelltext:

* Aufteilung in direkte und diffuse Strahlung: Erbs, Klein und Duffie (1982)
* Strahlung auf die geneigte Fläche: wahlweise das isotrope Modell nach Liu
  und Jordan oder **HDKR** (Hay, Davies, Klucher, Reindl) mit Sonnenkranz und
  Horizontaufhellung
* Einfallswinkelabhängigkeit des Glases: ASHRAE, `1 − b0 · (1/cos θ − 1)`

**Warum HDKR und nicht Perez.** Perez wäre genauer, verlangt aber eine Tabelle
mit achtundvierzig veröffentlichten Koeffizienten. Die hätte hier aus dem
Gedächtnis hingeschrieben werden müssen, ohne sie gegen eine zweite Quelle
halten zu können — und eine falsche Ziffer darin sähe genauso aus wie eine
richtige. HDKR kommt ohne eine einzige abgeschriebene Zahl aus und hat zwei
Eigenschaften, an denen es sich prüfen lässt: bei bedecktem Himmel geht es
**exakt** in das isotrope Modell über, und auf einer waagerechten Fläche
ergeben Himmel und Sonnenkranz zusammen **genau** die Diffusstrahlung. Beides
prüft der Selbsttest.

Für die Frage „will ich diese Wärme?" reicht das bei weitem — für eine
Ertragsprognose nicht.

---

## Voraussetzungen

* LoxBerry ab 3.0.0 (das Plugin liest `config/system/general.json`)
* PHP 7.4 oder 8.x — beides wird unterstützt und beides ist geprüft
* Für den MQTT-Weg: die PHP-Erweiterung `sockets` und ein eingerichtetes
  MQTT-Gateway. Fehlt eines von beidem, sagt das der Reiter *Test*, und der
  HTTP-Weg läuft davon unberührt weiter.
* Zum Einlesen einer Projektdatei: `upload_max_filesize` und `post_max_size`
  müssen größer sein als die Datei (meist 3 bis 6 MB). Reicht es nicht, nennt
  die Meldung beide Werte im Klartext.

Keine Python-Umgebung, keine Zusatzpakete, keine Internetverbindung im
Betrieb.

## Fassung 0.12.5 — der Stat-Zwischenspeicher
Die Protokollkappung (512 000 Byte) stand in
`webfrontend/html/fb_lib.php:1053`. PHP merkt sich aber die Antworten von
`stat()`: innerhalb **eines** Prozesses sieht `filesize()` die erste Größe
und danach nie wieder eine neue — `file_put_contents(…, FILE_APPEND)` macht
den Eintrag nicht ungültig. Die Kappung fällt dann still aus.

Gemessen am 29.08.2026, 20 000 Zeilen im selben Prozess:

| | ohne `clearstatcache` | mit |
|---|---|---|
| PHP 7.4.33 | 1 220 000 Byte, **nicht gekappt** | 220 332 Byte, gekappt |
| PHP 8.4.24 | 220 332 Byte, gekappt | 220 332 Byte, gekappt |

Die beiden PHP-Fassungen verhalten sich also verschieden — und LoxBerry 3.x
fährt 7.4. Wer nur unter 8.4 misst, sieht den Fehler nie. Folgen hatte das
hier nicht: die Aufrufer sind kurzlebig, und ein **frischer** Prozess kappt
richtig. Eine Funktion darf aber nicht davon abhängen, wer sie wie oft ruft.

Abhilfe: `clearstatcache(true, …)` **vor** dem Tor; der zweite Parameter
beschränkt das Leeren auf diese eine Datei. Dasselbe Muster tragen Robonect,
Saugroboter, SignalBot, Octopus, Sprachsteuerung und WärmepumpeCloud schon
länger — es ist am 29.08.2026 im ganzen Bestand nachgezogen worden.

## Fassung 0.12.12

Verbesserungen aus dem Durchgang vom 30.09.2026 (Verbesserungsliste
`Pruefung-Durchgang-2026-09-29/VERBESSERUNGEN_OFFEN.md`). Gemessen an
Attrappen unter PHP 7.4, 8.3 und 8.5; nicht am Gerät. Der Lernbestand blieb in
jeder Probe byte-gleich.

* Ein Stand mehr als 5 s aus der Zukunft (Uhrsprung) gilt als keine Aussage
  (`OK=0`, `HERZ=-1`) und wird sofort neu gerechnet.
* Reiter Loxone zeigt ein Schaubild der Bausteine #2–#8.
* **Neu, ab Werk aus: Sonnenstand für andere Plugins.** Unter `haus/sonne/` gehen
  Azimut, Elevation und je Fassade „Sonne wirkt“ hinaus (flüchtig), wenn
  „Werte über MQTT senden“ an ist.
* **Bei einer Beanstandung wird nichts gespeichert** – auch nicht die übrigen
  Felder (bis 0.12.11 wurde der Rest gespeichert, auch zurechtgerückte Kürzel und
  Namen). Die eingetippten Werte kommen markiert zurück. „Einstellungen sichern“
  warnt, wenn die eigene Sicherung beim Zurückspielen abgewiesen würde.
* Berichtigt: Die Adresse je Fenster heißt `&aktion=fenster&k=<K>`.

## Fassung 0.12.11 — Sicherung geprüft, ehrliche Werte, Lernbestand geschützt

Durchgang vom 30.09.2026 mit vier Prüfern (Code, Oberfläche, Installer, MQTT).
Befunde mit Datei:Zeile:
`Pruefung-Durchgang-2026-09-29/Fensterbilanz_BEFUNDE_UND_VERBESSERUNGEN.md`.

**Bitte beide Loxone-Vorlagen neu erzeugen und importieren.** Die Kommentare
sind auf 40 Zeichen gekürzt, `WH` hat einen größeren Bereich, und bei Aufruf
über 127.0.0.1 steht jetzt der Rechnername in der Adresse. Loxone Config legt
beim Import neu an und überschreibt nichts.

**Baustein-Liste berichtigt.** Zeile #5 verundete die Freigabe mit „Fensterbilanz
unbrauchbar“ – die Beschattung wäre nur bei ausgefallenem Plugin freigegeben
worden. Richtig ist: `FB_<kürzel>_BESCHATTEN` UND NICHT unbrauchbar, das Ergebnis
ODER die bisherige Freigabe. Wer die Liste nachgebaut hat, prüft diese Zeile.
Die Ausfallerkennung hängt jetzt am Zeitstempel, nicht an `FB_HERZ` (über MQTT
immer 0), mit einer Schwelle von 15 Minuten.

**Sicherung.** Beim Zurückspielen wird jeder Wert geprüft wie im Formular.
Bis 0.12.10 nahm der Endpunkt nach einer Sicherung mit einer Liste als Token
`token=Array` an, `fenster: "kaputt"` löschte alle Fenster, und ein leeres Token
wurde still neu gewürfelt.

**Ehrliche Werte**

* `OK=0`, sobald der Stand älter als das Dreifache des Takts ist (15 Minuten),
  auch wenn das Höchstalter höher eingestellt ist. Ohne jeden Stand antwortet
  der Endpunkt mit 503.
* Ohne Standort gehen Sonnenhöhe und -richtung nicht mehr als 0 hinaus, und
  `fenster_anzahl` zählt die eingerichteten Fenster.
* Keine leeren Nachrichten mehr (`begruendung`, `bericht` ohne Text: `-`).
* `bericht` geht flüchtig hinaus (ein Tageswert); zurückbehalten bleibt nur
  `fenster_anzahl`.
* Beim Präfixwechsel und beim Abschalten von MQTT räumt das Plugin die alten
  Themen ab und liest über den Broker nach. Die Abodatei
  `mqtt_subscriptions.cfg` führt es selbst.

**Lernbestand geschützt.** Eine abgeschnittene `lernen.json`, `pv.json` oder
`bilanz.json` wird nicht mehr still durch einen kurzen Bestand ersetzt,
sondern als `.kaputt` beiseitegelegt, mit einer Zeile im Protokoll. Ein
Rücksprung der Uhr schreibt keinen Lerntag. Bei einem Update kommen die
Rettungen nach der Upgrade-Marke zurück, nicht mehr nach dem Alter eines
Zeitstempels – bis 0.12.10 fehlte der Lernbestand nach einem Update, das länger
als eine Stunde dauerte.

**Oberfläche und Installation**

* Jedes Absenden endet mit einer Umleitung; F5 hängt keine Horizontpunkte
  doppelt an.
* Eingaben werden abgewiesen statt gerundet.
* Eine Neuinstallation spielt keine alte Zweitschrift mehr ein (`preinstall.sh`,
  `.alt`); der Abschluss sagt dann „Installation abgeschlossen“.
* Die Fehlerausgabe des Takts landet in `cron.err`.

## Fassung 0.12.10 — Retain, Aktualisierung, Deinstallation

**MQTT.** `ok` sagt, was das Plugin über seine eigenen Eingänge feststellt;
zurückbehalten stünde es nach dem Ende des Plugins für immer als „in Ordnung"
im Broker. Die Urteile je Fenster (`urteil`, `beschatten`, `grund`,
`begruendung`, `urteil30`, `beschatten30`, `blendung`, `daemmen`,
`gefahren`), ihre Zählungen (`*_anzahl` außer `fenster_anzahl`,
`nicht_gefahren`), `saison`, `wh_tag`, `<kuerzel>/wh` und `pv_abweichung`
werden allein durch die Uhr falsch — nach Sonnenuntergang stünde sonst
„beschatten" im Broker. Sie alle gehen jetzt flüchtig hinaus; nach einem
Neustart von Broker oder Gateway fehlen sie bis zum nächsten Rechenlauf
(höchstens fünf Minuten). Retained bleiben `fenster_anzahl` und `bericht`
(siehe oben). Den
Altwert aus 0.12.9 räumt das Plugin mit einer leeren Nachricht unmittelbar
vor dem gültigen Wert ab und fragt dafür den Broker (Brokerhost, -port,
-benutzer und -kennwort aus der LoxBerry-Konfiguration), ob noch etwas
dasteht; erst wenn er „nichts mehr" bestätigt, hört es damit auf (Merker
`data/plugins/<ordner>/retain_altlast_bestaetigt`). **Grenze:** ist der Broker
nicht zu fragen oder lehnt er die Anmeldung bzw. das Abonnement ab, räumt
jeder Lauf ab — der Miniserver sieht dann bei diesen Werten je Lauf für
einige Millisekunden einen leeren Wert vor dem gültigen. Altwerte eines
abgeschalteten Zweigs (etwa `blendung`) räumt erst die Deinstallation ab.

**Deinstallation.** Sie leert jetzt die zurückbehaltenen Themen des Plugins,
auch die von Fenstern, die es nicht mehr gibt (gefunden über die Rückfrage
beim Broker), höchstens drei Runden, und bricht nach 60 s bzw. 65 s hart ab,
statt hängen zu bleiben.

**Aktualisierung.** Zwischen dem Kopieren der neuen Dateien und dem
Zurückholen der geretteten Messreihen liegt fast eine Minute. Ein Messwert
aus Loxone oder der Fünf-Minuten-Takt legten in dieser Zeit eine frische
Tagesbilanz an, und die gerettete ging verloren. Jetzt rechnet und schreibt
das Plugin während einer Aktualisierung nichts (der Endpunkt antwortet mit
`GRUND=AKTUALISIERUNG`); zurückgeholt wird, was aus dieser Aktualisierung
stammt, auch über ein belegtes Ziel — eine Rettung aus einem abgebrochenen
Update von früher bleibt mit einer Warnung liegen. Nach einem Update steht
am Ende „Aktualisierung abgeschlossen" statt der Anleitung für die
Erstinstallation, sofern Standort oder Fenster eingetragen sind.

**Wurzel und Archiv.** Als LoxBerry gilt nur ein Ordner mit
`config/system/general.json`; ohne ihn warnen die Installationsskripte und
tun nichts. Aus einem ausgepackten Archiv heraus rechnet, sendet und
schreibt das Plugin nichts in eine installierte Anlage, und es sucht keine
Dateien mehr ab der Laufwerkswurzel. Eine Konfiguration ohne Wortzeichen wird
aus der Zweitschrift geheilt, wenn diese eines trägt.

Gemessen in WSL an Attrappen (UDP-Eingang und Broker), nicht am Gerät:
`Pruefung-Beschattung_Fensterbilanz-0.12.10/`.

## Lizenz

MIT, siehe `LICENSE`.
