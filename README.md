# HealthHub (IP-Symcon)

Privates IP-Symcon-Modul, das Gesundheits- und Ernährungsdaten als **Tageswerte** in archivierten Variablen sammelt und sie (ab Phase 4) über einen integrierten, **read-only** MCP-Server für Claude abfragbar macht.

| Quelle | Weg | Werte |
|---|---|---|
| FDDB (Premium) | Web-Login + CSV-Export, nachts per Timer *(Phase 3)* | kcal, Protein, Fett, KH, Zucker, Ballaststoffe |
| Fitdays-Waage | Health Connect → App „HC Webhook“ → `/hook/healthhub/ingest` *(Phase 2)* | Gewicht, Körperfett, magere Masse, Wasser, Knochen |
| Pixel Watch | Health Connect → HC Webhook *(Phase 2)* | Schritte, Aktivkalorien, Schlaf, Ruhepuls |
| manuell | Variable / Formular / `HH_SetWaist` | Bauchumfang |

> **Stand: Phase 1 (Grundgerüst)** – Variablen, Profile, automatische Archivierung, idempotentes Schreiben von Tageswerten, Bauchumfang-Eingabe, nächtliche Konsolidierung, Archiv-Selbsttest. Ingest, FDDB und MCP folgen.

## Installation

1. Modul-Control → Bibliothek hinzufügen (Git-URL des Repos oder lokaler Ordner).
2. Instanz **HealthHub** anlegen (Kategorie beliebig).
3. Im Formular **Wartung → Archiv-Selbsttest** ausführen. Erwartet: `ARCHIVE SELF TEST PASSED`.

## Variablen

Alle Tagesvariablen werden beim Übernehmen der Konfiguration **automatisch archiviert** (Aggregationstyp *Standard*). Bereits geloggte Variablen werden nicht angefasst.

| Ident | Name | Typ | Profil | Quelle |
|---|---|---|---|---|
| `KcalIn` | Kalorien gegessen | Float | `HH.kcal` | FDDB |
| `Protein` | Protein | Float | `HH.gram` | FDDB |
| `Fat` | Fett | Float | `HH.gram` | FDDB |
| `Carbs` | Kohlenhydrate | Float | `HH.gram` | FDDB |
| `Sugar` | Zucker | Float | `HH.gram` | FDDB |
| `Fiber` | Ballaststoffe | Float | `HH.gram` | FDDB |
| `Weight` | Gewicht | Float | `HH.kg` | Fitdays |
| `BodyFat` | Körperfett | Float | `HH.percent` | Fitdays |
| `LeanMass` | Magere Körpermasse | Float | `HH.kg` | Fitdays |
| `BodyWater` | Körperwasser | Float | `HH.kg` | Fitdays (Health Connect speichert Körperwasser als Masse) |
| `BoneMass` | Knochenmasse | Float | `HH.kg` | Fitdays |
| `Waist` | Bauchumfang | Float | `HH.cm` (30–250, Schritt 0,5) | manuell, **Aktionsvariable** |
| `Steps` | Schritte | Integer | `HH.steps` | Uhr |
| `ActiveKcal` | Aktivkalorien (Uhr) | Float | `HH.kcal` | Uhr – nur gespeichert, **nicht** fürs Kalorienziel (Uhr schätzt ca. 30–50 % zu hoch) |
| `SleepMinutes` | Schlafdauer | Integer | `HH.minutes` | Uhr, dem **Aufwachtag** zugeordnet |
| `RestingHR` | Ruhepuls | Integer | `HH.bpm` | Uhr |
| `LastIngest` | Letzter Health-Connect-Empfang | Integer | `~UnixTimestamp` | Status, nicht archiviert |
| `LastFddbImport` | Letzter FDDB-Import | Integer | `~UnixTimestamp` | Status, nicht archiviert |
| `Status` | Status | String | – | Status, nicht archiviert |

## Wie Tageswerte gespeichert werden

Pro Variable und Tag steht **genau ein** Archivwert im Archiv. Abgeschlossene Tage tragen den Zeitstempel **12:00 Uhr Ortszeit** (Zeitzone aus der Konfiguration). Mittag liegt nie in einer Sommer-/Winterzeit-Umstellung, die Tageszuordnung ist damit eindeutig.

**Vergangene Tage:** Tag komplett löschen (`AC_DeleteVariableData`), einen Wert um 12:00 eintragen (`AC_AddLoggedValues`), am Ende des Stapels einmal `AC_ReAggregateVariable`. Ist der Wert schon genau so vorhanden, passiert nichts.

**Heute – warum anders:** Die sichtbare Variable soll den heutigen Wert zeigen. `SetValue` auf eine archivierte Variable schreibt aber selbst einen Archiveintrag (mit aktueller Uhrzeit, und zwar **asynchron**). Würde man zusätzlich `AC_AddLoggedValues` für 12:00 aufrufen, hätte der Tag zwei Einträge. Außerdem kann der asynchrone SetValue-Eintrag sogar *nach* einem vorherigen Löschen ankommen. Deshalb gilt für heute:

| Lage | Aktion |
|---|---|
| Wert unverändert, genau ein Eintrag heute | nichts |
| Wert unverändert, Eintrag fehlt oder doppelt | Tag löschen, einen Eintrag um min(12:00, jetzt) eintragen (`SetValue` würde ohne Wertänderung nichts loggen) |
| Wert geändert | Tag löschen, dann **nur** `SetValue` – IPS loggt den Eintrag selbst |

Der heutige Eintrag trägt dadurch die Schreibzeit statt 12:00. Der Timer **Konsolidieren** (täglich 00:10) schiebt die letzten 3 abgeschlossenen Tage auf genau einen Eintrag um 12:00 (der letzte Wert des Tages gewinnt). Leser (MCP, ab Phase 4) nehmen immer den **letzten Eintrag eines Tages**, ein seltener Restduplikat-Eintrag vor der Konsolidierung stört also nicht.

**Verhalten von IPS, das das Modul abfängt** (am echten System mit dem Selbsttest bestätigt):

- **Logging einschalten loggt den aktuellen Wert.** Wird das Archiv für eine Variable aktiviert, schreibt IPS sofort deren Wert (0) mit. Für Tagesvariablen wäre das ein falscher „0-kcal-Tag“. Das Modul entfernt diese 0-Einträge rund um den Aktivierungszeitpunkt automatisch (Timer *Pending*, ca. 3 Minuten lang alle 20 s).
- **`AC_AddLoggedValues` lehnt Zeitstempel ab, die neuer sind als noch nicht geschriebene Pufferdaten.** Der Heute-Pfad weicht dann auf 00:00 des Tages aus, die Konsolidierung setzt den Wert nachts auf 12:00.
- **`AC_ReAggregateVariable` schlägt fehl, solange eine andere Aggregation läuft.** Das Modul merkt sich die Variable und wiederholt die Aggregation alle 20 s, bis sie klappt.

`null` als Tageswert **löscht** den Tag: ein FDDB-Tag ohne Einträge ist kein 0-kcal-Tag. Zukünftige Tage werden abgewiesen.

**Hinweis zu IPSView-Diagrammen:** Mittelt die Archiv-Aggregation *Standard* zeitgewichtet (noch am echten System zu prüfen), enthält ein Tagesbalken bis 12:00 noch den Vortageswert. Für exakte Tageswerte im Diagramm die Darstellung *Linie/Punkte auf Rohwerten* nutzen. Der MCP-Server liest immer die Rohwerte.

## Konfiguration

### Allgemein

| Parameter | Standard | Bereich | Wirkung |
|---|---|---|---|
| Archiv-Instanz (`ArchiveID`) | leer | Archive-Control-Instanz | In welches Archiv geloggt wird. Leer = erstes Archive Control im System (Normalfall). Nur ändern, wenn du mehrere Archive betreibst. |
| Zeitzone (`TimeZone`) | `Europe/Vienna` | PHP-Zeitzonenname | Bestimmt Tagesgrenzen (00:00–23:59) und den 12:00-Zeitstempel. Ungültiger Name → Instanzstatus *Ungültige Zeitzone*, Timer aus. Nach Befüllung des Archivs **nicht mehr ändern**, sonst verschieben sich die Tagesgrenzen. |
| Debug-Modus (`DebugMode`) | aus | ein/aus | Ab Phase 2: Roh-Payloads des Health-Connect-Webhooks ins Debug-Fenster und als Datei unter `<IPS-Datenverzeichnis>/HealthHub/raw/`. In Phase 1 noch ohne Wirkung. |

### Ziele

Die Ziele werden in Phase 4 über das MCP-Tool `get_profile` an Claude geliefert, damit die Bewertung ohne Erklärung funktioniert.

| Parameter | Standard | Bereich | Wirkung |
|---|---|---|---|
| Kalorienziel (`KcalTarget`) | 1800 kcal | 800–5000 | Pauschales Tagesziel aus FDDB. Sport ist eingerechnet, Uhr-Kalorien zählen nicht. |
| Proteinziel (`ProteinTarget`) | 130 g | 0–400 | Tagesziel Protein. |
| Fettziel (`FatTarget`) | 60 g | 0–300 | Tagesziel Fett. |
| Zieltempo von (`PaceMin`) | −0,50 kg/Woche | −2 … 2 | Schnelleres Ende des gewünschten Abnahmetempos. |
| Zieltempo bis (`PaceMax`) | −0,40 kg/Woche | −2 … 2 | Langsameres Ende des gewünschten Abnahmetempos. |
| Startdatum (`StartDate`) | 2026-09-01 | JJJJ-MM-TT | Bezugsdatum der Startwerte. |
| Startgewicht (`StartWeight`) | 74,2 kg | 0–300 | Ausgangsgewicht (Fitdays, Sept. 2026). |
| Start-Körperfett (`StartBodyFat`) | 21,4 % | 0–70 | Ausgangs-Körperfett. |
| Start magere Masse (`StartLeanMass`) | 58,2 kg | 0–200 | Ausgangswert fettfreie Masse. |

## Bauchumfang eintragen

- **Variable `Bauchumfang`** im Objektbaum / IPSView setzen → Wert gilt für **heute**.
- **Formular → Bauchumfang**: Wert + optionales Datum (`JJJJ-MM-TT`, leer = heute) → *Speichern*.
- **Skript** (auch für Nachträge):

```php
HH_SetWaist(12345, 86.5);                 // heute
HH_SetWaist(12345, 87.0, '2026-10-10');   // Nachtrag
```

Erlaubt sind 30–250 cm, keine Zukunftsdaten. Ein erneuter Eintrag für denselben Tag ersetzt den alten.

## Öffentliche Funktionen

| Funktion | Zweck |
|---|---|
| `HH_SetWaist(int $InstanceID, float $cm, string $date = ''): bool` | Bauchumfang für einen Tag speichern. |
| `HH_Consolidate(int $InstanceID)` | Letzte 3 abgeschlossene Tage aller Tagesvariablen auf genau einen 12:00-Eintrag normalisieren (läuft automatisch täglich 00:10). |
| `HH_ProcessPending(int $InstanceID)` | Interner Timer: entfernt die 0-Einträge beim Logging-Start und wiederholt fehlgeschlagene Re-Aggregationen. Läuft nur, solange etwas ansteht. |
| `HH_SelfTestArchive(int $InstanceID): string` | Prüft die Schreibstrategie auf diesem System mit einer temporären, versteckten Variable und liefert einen Bericht. Zeigt auch das Roh-Verhalten von `SetValue` + `AC_AddLoggedValues`. Startet das Archiv zweimal neu (Logging an/aus), daher nicht im laufenden Betrieb dauernd ausführen. |

## Entwicklung / Tests

Die reine Logik (`libs/DayClock.php`, `libs/ArchiveWriter.php`) ist frei von IPS-Funktionen und mit PHPUnit getestet. Die IPS-Anbindung steckt in `HHIpsArchiveBackend`.

```bash
php phpunit.phar
```

Alle Klassen tragen das Präfix `HH`, weil sich alle Module einen PHP-Prozess teilen und gleichnamige Klassen anderer Module sonst kollidieren.

## Bekannte Einschränkungen

- FDDB-Export: Einträge aus **KI-FoodScan** und Rezepte aus dem FDDB-Rezeptbereich fehlen im CSV-Export (Phase 3).
