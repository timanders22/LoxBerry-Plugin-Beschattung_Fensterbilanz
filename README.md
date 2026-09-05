# LoxBerry-Plugin „Beschattung Fensterbilanz"

Version 0.12.7

Ein Urteil je Fenster: **ist der Sonneneintrag durchs Glas gerade erwünscht?**
Eine Zahl von −100 (unbedingt beschatten) bis +100 (Sonne hereinlassen), dazu
ein Digitalwert für die einfache Verdrahtung — über MQTT und über einen
tokengeschützten HTTP-Endpunkt.

**Das Plugin schaltet nichts.** Es ersetzt den `AutoJalousie`-Baustein nicht.
Es liefert die eine Größe, die Loxone fehlt, und hängt an dessen Eingang
`AutoShade`.

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

**Über HTTP**, tokengeschützt, mit denselben Werten:

    /plugins/fensterbilanz/index.php?token=<TOKEN>&aktion=status
    /plugins/fensterbilanz/index.php?token=<TOKEN>&aktion=json
    /plugins/fensterbilanz/index.php?token=<TOKEN>&aktion=fenster&kuerzel=<K>
    /plugins/fensterbilanz/index.php?token=<TOKEN>&selftest=1

Auch die abfragenden Aufrufe verlangen ein Wortzeichen: in der Antwort stehen
Raumtemperaturen, und die sagen jedem im Heimnetz, ob jemand zu Hause ist.

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

## Lizenz

MIT, siehe `LICENSE`.
