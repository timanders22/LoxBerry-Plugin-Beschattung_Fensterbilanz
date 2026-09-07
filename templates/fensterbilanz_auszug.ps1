<#
    Fensterbilanz - Auszug aus einer Loxone-Projektdatei

    WOFUER DIESES SKRIPT DA IST

    Die Projektdatei ist knapp vier Megabyte gross. Was das Plugin daraus
    braucht, sind ein paar Kilobyte: je Rollladenbaustein ein Titel und eine
    Himmelsrichtung, je Raum ein Titel und eine Grundflaeche.

    Genau die zieht dieses Skript heraus und legt sie in die Zwischenablage.
    Im Reiter Einstellungen des Plugins gibt es ein Feld zum Einfuegen - und
    damit kommt man an jeder Absendegrenze und an jedem Dateimanager vorbei.

    WAS ES NICHT TUT

    Es entscheidet nichts. Kuerzel und Raumschluessel entstehen weiterhin im
    Plugin aus den Titeln, damit beide Wege - Datei und Auszug - dieselben
    Namen ergeben. Aus zwei Regelwerken wuerden sonst zwei verschiedene
    virtuelle Eingaenge in Loxone.

    AUFRUF

        powershell -ExecutionPolicy Bypass -File fensterbilanz_auszug.ps1
        powershell -ExecutionPolicy Bypass -File fensterbilanz_auszug.ps1 -Datei "C:\Pfad\Projekt.Loxone"

    Ohne -Datei oeffnet sich ein Auswahlfenster.
#>

[CmdletBinding()]
param(
    [string]$Datei = '',
    [string]$Ausgabe = ''
)

$ErrorActionPreference = 'Stop'

function Schreib($text) { Write-Host $text }

if ($Datei -eq '') {
    try {
        Add-Type -AssemblyName System.Windows.Forms
        $dlg = New-Object System.Windows.Forms.OpenFileDialog
        $dlg.Filter = 'Loxone-Projektdatei (*.Loxone)|*.Loxone|Alle Dateien (*.*)|*.*'
        $dlg.Title = 'Loxone-Projektdatei auswaehlen'
        if ($dlg.ShowDialog() -ne [System.Windows.Forms.DialogResult]::OK) {
            Schreib 'Abgebrochen - keine Datei gewaehlt.'
            exit 1
        }
        $Datei = $dlg.FileName
    } catch {
        Schreib 'Es liess sich kein Auswahlfenster oeffnen. Bitte den Pfad angeben:'
        Schreib '   powershell -ExecutionPolicy Bypass -File fensterbilanz_auszug.ps1 -Datei "C:\...\Projekt.Loxone"'
        exit 1
    }
}

if (-not (Test-Path -LiteralPath $Datei -PathType Leaf)) {
    Schreib "Diese Datei gibt es nicht: $Datei"
    exit 1
}

$groesse = (Get-Item -LiteralPath $Datei).Length
Schreib ("Gelesen wird: {0}  ({1:N1} MB)" -f $Datei, ($groesse / 1MB))

# Die Datei ist XML in UTF-8. -Raw, weil zeilenweise bei vier Megabyte
# unnoetig langsam waere.
$inhalt = [System.IO.File]::ReadAllText($Datei, [System.Text.Encoding]::UTF8)

# ---------------------------------------------------------------- Fenster
#
# Die Bausteine werden am Anfangsmerkmal getrennt und der Abschnitt bis zum
# naechsten Anfang durchsucht. Das ist zulaessig, WEIL K="Dir" nur im
# AutoJalousie vorkommt - und genau das wird unten nachgezaehlt und
# gemeldet, falls es einmal nicht mehr stimmt. Geraten wird hier nichts.
$anfaenge = [regex]::Matches($inhalt, '<C Type="AutoJalousie"')
$dirGesamt = ([regex]::Matches($inhalt, '<Co K="Dir"')).Count

$fenster = New-Object System.Collections.ArrayList
$ohneRichtung = New-Object System.Collections.ArrayList

for ($i = 0; $i -lt $anfaenge.Count; $i++) {
    $von = $anfaenge[$i].Index
    $bis = if ($i + 1 -lt $anfaenge.Count) { $anfaenge[$i + 1].Index } else { $inhalt.Length }
    $block = $inhalt.Substring($von, $bis - $von)

    $kopfEnde = $block.IndexOf('>')
    $kopf = if ($kopfEnde -gt 0) { $block.Substring(0, $kopfEnde) } else { $block }
    $mT = [regex]::Match($kopf, '\sTitle="([^"]*)"')
    $titel = if ($mT.Success) { $mT.Groups[1].Value } else { '' }
    $titel = [System.Net.WebUtility]::HtmlDecode($titel)

    $mD = [regex]::Match($block, '<Co K="Dir"[^>]*\sDef="(-?\d+(?:\.\d+)?)"')
    $mDT = [regex]::Match($block, '<Co K="DirTol"[^>]*\sDef="(-?\d+(?:\.\d+)?)"')

    if ($mD.Success) {
        $null = $fenster.Add([ordered]@{
            titel  = $titel
            dir    = [int][math]::Round([double]$mD.Groups[1].Value)
            dirtol = if ($mDT.Success) { [int][math]::Round([double]$mDT.Groups[1].Value) } else { $null }
        })
    } else {
        # MELDEN, nicht raten. Ein Baustein, dessen Richtung an einem Eingang
        # haengt statt an einer festen Zahl, hat hier keine ablesbare Zahl.
        $null = $ohneRichtung.Add($(if ($titel -ne '') { $titel } else { 'ohne Titel' }))
        $null = $fenster.Add([ordered]@{ titel = $titel; dir = $null; dirtol = $null })
    }
}

# ----------------------------------------------------------------- Raeume
$raeume = New-Object System.Collections.ArrayList
foreach ($m in [regex]::Matches($inhalt, '<C\s[^>]*Type="Place"[^>]*>')) {
    $tag = $m.Value
    $mT = [regex]::Match($tag, '\sTitle="([^"]*)"')
    $mQ = [regex]::Match($tag, '\sSqm="(\d+(?:\.\d+)?)"')
    if (-not $mT.Success -or -not $mQ.Success) { continue }
    $null = $raeume.Add([ordered]@{
        titel = [System.Net.WebUtility]::HtmlDecode($mT.Groups[1].Value)
        qm    = [double]$mQ.Groups[1].Value
    })
}

# ---------------------------------------------------------------- Ausgabe
$auszug = [ordered]@{
    fensterbilanz = 1
    quelle        = [System.IO.Path]::GetFileName($Datei)
    fenster       = @($fenster)
    raeume        = @($raeume)
}
$json = $auszug | ConvertTo-Json -Depth 5 -Compress

Schreib ''
Schreib ("Rollladenbausteine gefunden : {0}" -f $anfaenge.Count)
Schreib ("davon mit ablesbarer Richtung: {0}" -f ($fenster.Count - $ohneRichtung.Count))
Schreib ("Raeume mit Grundflaeche      : {0}" -f $raeume.Count)
Schreib ("Auszug                       : {0:N1} kB" -f ($json.Length / 1KB))

if ($ohneRichtung.Count -gt 0) {
    Schreib ''
    Schreib 'Ohne ablesbare Himmelsrichtung (im Plugin von Hand nachtragen):'
    foreach ($n in $ohneRichtung) { Schreib ("   " + $n) }
}

if ($dirGesamt -ne $anfaenge.Count) {
    Schreib ''
    Schreib ("ACHTUNG: {0} Rollladenbausteine, aber {1} Richtungsangaben in der Datei." -f $anfaenge.Count, $dirGesamt)
    Schreib '         Dieses Skript ordnet die Richtung dem jeweils vorangehenden Baustein zu.'
    Schreib '         Bitte die Zahlen im Plugin gegen Loxone Config pruefen.'
}

if ($Ausgabe -ne '') {
    [System.IO.File]::WriteAllText($Ausgabe, $json, (New-Object System.Text.UTF8Encoding($false)))
    Schreib ''
    Schreib ("Geschrieben nach: {0}" -f $Ausgabe)
} else {
    $kopiert = $false
    try { Set-Clipboard -Value $json; $kopiert = $true } catch { }
    Schreib ''
    if ($kopiert) {
        Schreib 'Der Auszug liegt in der Zwischenablage.'
        Schreib 'Jetzt im Plugin: Reiter Einstellungen, Feld "Auszug einfuegen", Strg+V, Knopf druecken.'
    } else {
        Schreib 'Die Zwischenablage war nicht erreichbar - hier ist der Auszug zum Kopieren:'
        Schreib ''
        Schreib $json
    }
}
