# HealthHub (IP-Symcon)

Privates IP-Symcon-Modul, das Gesundheits- und Ernährungsdaten als **Tageswerte** in archivierten Variablen sammelt und sie über einen integrierten, **read-only** MCP-Server für Claude abfragbar macht.

| Quelle | Weg | Werte |
|---|---|---|
| FDDB (Premium) | Web-Login + Tagebuchseite pro Tag, nachts per Timer | Einzeleinträge (SQLite) + kcal, Protein, Fett, KH, Zucker, Ballaststoffe |
| Fitdays-Waage | Health Connect → App „HC Webhook“ → `/hook/healthhub/ingest` *(Phase 2)* | Gewicht, Körperfett, magere Masse, Wasser, Knochen |
| Pixel Watch | Health Connect → HC Webhook *(Phase 2)* | Schritte, Aktivkalorien, Schlaf, Ruhepuls |
| manuell | Variable / Formular / `HH_SetWaist` | Bauchumfang |

> **Stand: Phase 4 (MCP-Server)** – Grundgerüst, Health-Connect-Empfang (HC Webhook), FDDB-Import mit Einzeleinträgen und der MCP-Server, über den Claude Code und Claude Desktop die Daten lesen.

## Installation

1. Modul-Control → Bibliothek hinzufügen (Git-URL des Repos oder lokaler Ordner).
2. Instanz **HealthHub** anlegen (Kategorie beliebig).
3. Im Formular **Wartung → Archiv-Selbsttest** ausführen. Erwartet: `ARCHIVE SELF TEST PASSED`.
4. Handy einrichten, siehe *Health Connect einrichten*.

## Health Connect einrichten (HC Webhook)

Brücke Handy → IPS ist die Open-Source-App **HC Webhook** ([GitHub](https://github.com/mcnaveen/health-connect-webhook), Play Store: *Health Connect Webhook*, Paket `com.hcwebhook.app`). Sie liest Health Connect und schickt JSON per POST an IPS.

1. App installieren, **Grant Permissions** → mindestens diese Typen freigeben: *Steps, Sleep, Active Calories, Weight, Body Fat, Lean Body Mass, Body Water Mass, Bone Mass, Resting Heart Rate*.
   Die App ist im Play Store kostenpflichtig. Kostenlos und identisch: `app-foss-release.apk` von [GitHub Releases](https://github.com/mcnaveen/health-connect-webhook/releases/latest) am Handy laden und installieren (einmalig „unbekannte Apps installieren“ für den Browser erlauben). Updates genauso drüberinstallieren.
2. **Webhooks → hinzufügen**
   - URL: `http://<IPS-IP>:3777/hook/healthhub/ingest` (genau so, Hooks sind case-sensitive)
   - Delivery format: **JSON (HTTP POST)**, nicht Protobuf/gRPC
   - **Kopfzeilen verwalten** einschalten, Schlüssel `Authorization`, Wert `Bearer <Token>` (Leerzeichen nach *Bearer*). Den Token zeigt das Instanzformular unter *Health-Connect-Empfang*. **Wichtig:** danach auf **„Kopfzeile hinzufügen“** tippen, erst dann *Speichern*. Ohne diesen Schritt verwirft die App den Header. Alternativ im Reiter *JSON* `{"Authorization": "Bearer <Token>"}` einfügen und *JSON anwenden*.
   - **„Webhook testen“** schickt erfundene Testwerte (Kennzeichen `"test": true`). Das Modul antwortet mit `200`, speichert sie aber **nicht** (Status: „test payload … not stored“).
3. Sync-Modus: **Intervall** 30–60 min oder **Zeitplan** (z. B. 08:00 und 21:00).
4. Falls die App es anbietet: Auflösung für *Steps* und *Active Calories* auf **täglich** stellen. Dann liefert Health Connect fertige, quellenübergreifend bereinigte Tagessummen. Das Modul kommt aber auch mit Einzel- oder Minuten-Records zurecht.
5. Akku-Optimierung für HC Webhook ausschalten, sonst verpasst Android Syncs.
6. **Sync Now** drücken. Im Webhook-Log der App muss `200` stehen, in IPS ändern sich *Letzter Health-Connect-Empfang* und *Status*.
7. Im Formular unter **Gesehene Health-Connect-Quellen** stehen jetzt die Paketnamen aller Quell-Apps. Bei Bedarf als bevorzugte Quelle eintragen (siehe Konfiguration). Bekannte Paketnamen: Fitdays-Waage `cn.fitdays.fitdays`, Pixel Watch / Fitbit `com.fitbit.FitbitMobile` (schreibt auch manuell eingetragene Gewichte). Schritte und Aktivkalorien kommen als Health-Connect-Tagessumme ohne Quelle.

Diese 10 Datentypen in der App aktivieren (Berechtigungen **und** Webhook-Auswahl): Schritte, Schlaf, Aktive Kalorien, **Herzfrequenz** (für den Ruhepuls), Ruheherzfrequenz, Gewicht, Körperfett, Magere Körpermasse, Körperwassermasse, Knochenmasse. Andere Typen (HRV, Sauerstoff, Distanz …) bringen nur Daten, die das Modul nicht nutzt.

**Rückwirkend:** Die App liest im Hintergrund ein rollierendes 48-h-Fenster und schickt nur Neues. Für ältere Tage in der App eine **manuelle Synchronisierung mit Zeitraum** auslösen. Das Modul nimmt Tage bis *Records puffern für* (Standard 14 Tage) zurück an. Für eine größere Nachbefüllung den Wert vorübergehend erhöhen (max. 120).

**Nicht ins Internet freigeben.** Der Hook ist nur per Token geschützt, also nur im LAN oder per VPN nutzen. Ist **IP-Symcon Connect** aktiv, ist jeder `/hook/`-Pfad auch über die Connect-Adresse erreichbar. Dann schützt allein der Token.

### Was mit den Daten passiert

| Health-Connect-Typ | Variable | Regel pro Tag |
|---|---|---|
| `weight`, `body_fat`, `lean_body_mass`, `body_water_mass`, `bone_mass` | Gewicht, Körperfett, magere Masse, Körperwasser, Knochenmasse | Messung nach Regel *Mehrere Körpermessungen pro Tag* (Standard: Median der ersten Sitzung) |
| `resting_heart_rate`, sonst `heart_rate` + `sleep` | Ruhepuls | gelieferter Wert (letzter des Tages), sonst **berechnet** aus der Herzfrequenz im Schlaf (siehe unten) |
| `steps` | Schritte | Summe, siehe unten |
| `active_calories` | Aktivkalorien (Uhr) | Summe, siehe unten. **Uhrschätzung, vermutlich 30–50 % zu hoch, nicht fürs Kalorienziel.** |
| `sleep` | Schlafdauer | Summe aller Sitzungen, die an diesem Tag **enden** (Aufwachtag), ohne Wachphasen (Stages *awake*, *out of bed*, *awake in bed*). Ohne Stages zählt die ganze Sitzung. |

**Magere Masse berechnet:** Fitdays schreibt nur Gewicht, Körperfett und Knochenmasse nach Health Connect, **keine** magere Masse und **kein** Körperwasser (verifiziert an echten Payloads, Okt. 2026). Fehlt `lean_body_mass` an einem Tag, berechnet das Modul sie aus **derselben** Messung: Gewicht × (1 − Körperfett/100), mit Gewicht und Körperfett gleicher Quelle und gleichem Zeitpunkt (±60 s). Das entspricht der fettfreien Masse in Fitdays. Kommt doch eine echte `lean_body_mass`, hat sie Vorrang. Körperwasser bleibt leer.

**Ruhepuls berechnet:** Die Fitbit-/Google-Health-App der Pixel Watch schreibt (Stand Okt. 2026) keine Ruheherzfrequenz nach Health Connect, wohl aber die laufende Herzfrequenz. Das Modul nimmt deshalb den **niedrigsten 5-Minuten-Durchschnitt der Herzfrequenz während der Schlafsitzungen** eines Aufwachtags (mind. 3 Messpunkte im Fenster, Ergebnis 30–120 bpm). Für mindestens **die Hälfte der Schlafminuten** müssen Herzfrequenzwerte vorliegen, sonst bleibt der Tag leer (Uhr nachts abgelegt, Messlücken). Wird eine Nacht nachträglich als zu lückenhaft erkannt, löscht das Modul einen schon gespeicherten Wert wieder. Ein einzelner Minutenwert wäre zu anfällig für Messfehler der Uhr. Quelle wie beim Schlaf (bevorzugte Aktivitätsquelle). Liefert irgendwann eine App eine echte `resting_heart_rate`, hat diese Vorrang. Herzfrequenz-Punkte (je Minute einer) werden nur **3 Tage** gepuffert. Kommt der Schlaf erst später als die Herzfrequenz, wird der Ruhepuls nachträglich berechnet, solange die Punkte noch da sind. Bei einer Synchronisierung mit Zeitraum kommt beides gemeinsam und wird für alle Tage berechnet.

**Körperwasser:** Fitdays zeigt es in der App, schreibt es aber nicht nach Health Connect (dort fehlt der Eintrag „Gesamtkörperwasser“ unter *Daten und Zugriff*). Die Variable wird deshalb **ausgeblendet**, solange sie nie einen Wert bekommen hat. Liefert eine Quelle irgendwann Körperwasser, blendet das Modul sie automatisch wieder ein.

**Angeschnittener erster Tag:** Bei jeder Synchronisierung beginnt das Fenster mitten an einem Tag (z. B. 12:10 vor 30 Tagen). Die Health-Connect-Tagessumme dieses Tages zählt nur ab dann und wird verworfen (`partial` im Status). Die Tagessumme von heute (ab 00:00 bis jetzt) zählt normal.

Andere Typen (z. B. Herzfrequenz, Distanz) werden angenommen, aber ignoriert und in der Antwort als `unused_types` gemeldet. Die Tageszuordnung erfolgt in der eingestellten Zeitzone.

**Deduplizierung:** Die App schickt Datensätze wiederholt und überlappend, im JSON aber **ohne Record-ID**. Das Modul puffert daher alle Datensätze der letzten Tage (Schlüssel = Typ + Zeit/Intervall + Quell-App) und berechnet jeden betroffenen Tag aus dem kompletten Puffer neu. Beim Empfang wird nie aufaddiert. Dieselben Daten nochmal senden ändert nichts.

**Schritte/Aktivkalorien:** Pro Quelle werden Intervalle verworfen, die in einem größeren Intervall derselben Quelle enthalten sind. So ersetzt die wachsende Tagessumme von heute die ältere, und ein angeschnittener Tag am Fensteranfang überschreibt nicht den vollen Tag. Der Rest wird summiert.

**Welche Quelle zählt:** Pro Tag und Variable genau **eine** Quelle, damit Handy und Uhr nicht doppelt zählen:
1. die erste eingetragene bevorzugte Quelle, die an dem Tag Daten hat,
2. sonst, nur bei Schritten/Aktivkalorien, die Health-Connect-Tagessumme (schon quellenübergreifend bereinigt),
3. sonst die Quelle mit den meisten Datensätzen an dem Tag.

**Antwort des Hooks:** `200 {"ok":true,"accepted":n,"duplicates":n,"ignored":n,"too_old":n,"partial":n,"hr_points":n,"unused_types":[…],"written":{…}}`. Fehlender oder falscher Token → `401`, kein POST → `405`, kein gültiges JSON → `400`.

## FDDB-Import

FDDB hat keine offizielle Schnittstelle. Das Modul meldet sich mit deinen Zugangsdaten auf fddb.info an und liest pro Tag die **Tagebuchseite** (`/db/i18n/myday20/`), so wie das Projekt [itobey/fddb-exporter](https://github.com/itobey/fddb-exporter).

**Warum Tagebuchseite statt CSV-Export:** Die Tagebuchseite enthält pro Eintrag Uhrzeit, Mahlzeit, Produkt, Menge, kJ, kcal, Fett, Kohlenhydrate und Protein, dazu die Tagessummen inklusive Zucker, Ballaststoffe, Alkohol, Wasser, Cholesterin und Salz. Der CSV-Export ist laut itobey unvollständig.

**Ablauf pro Tag:**
1. Tagebuchseite laden. Ein FDDB-Tag läuft von 02:00 bis 01:59 Uhr des Folgetags.
2. Ist die Sitzung abgelaufen, meldet sich das Modul einmal neu an.
3. Alle Einträge des Tages in der Datenbank ersetzen (Transaktion, idempotent).
4. Tageswerte **aus der Datenbank** lesen und ins Archiv schreiben.

**Sonderfälle:**
- Ein Tag ohne Einträge ist **kein 0-kcal-Tag**: Er wird aus Datenbank und Archiv entfernt.
- Erkennt das Modul eine Seite nicht (Wartung, geändertes Layout), bricht der Lauf ab, ohne etwas zu ändern. Der Status zeigt den Fehler.

**Zeitplan:**
- Nächtlich zur eingestellten Zeit (Standard 03:30) werden die letzten *n* Tage bis gestern neu gelesen. So kommen Nachträge mit.
- *Jetzt importieren* liest die letzten *n* Tage plus heute.
- *Zeitraum importieren* (max. 400 Tage) für die Erstbefüllung läuft im Hintergrund in Häppchen von 6 Tagen, mit kurzer Pause zwischen den Abrufen. Der Fortschritt steht in *Status*. Ein IPS-Neustart setzt den Lauf fort.

**Speicher:** `<IPS-Datenverzeichnis>/HealthHub/healthhub.sqlite`. Fehlt `pdo_sqlite` im IPS-PHP, schreibt das Modul JSON-Dateien pro Monat nach `HealthHub/food/`. **Das ist unter Windows der Normalfall:** Das eingebettete PHP 8.5 von IP-Symcon bringt weder `pdo_sqlite` noch `sqlite3` mit (geprüft Okt. 2026). Funktional ist das gleichwertig. Welcher Fall vorliegt, zeigt *Wartung → Systemprüfung*.

| Tabelle | Inhalt |
|---|---|
| `food_entries` | `id`, `day`, `time`, `meal` (Breakfast, Lunch, Dinner, Snack 1…), `product`, `brand` (leer, FDDB liefert keine eigene Marke), `amount`, `unit`, `kj`, `kcal`, `protein`, `fat`, `carbs`, `sugar`/`fiber` (**leer**: die Tagebuchseite hat Zucker und Ballaststoffe nur als Tagessumme), `link` (Produktseite), `imported_at` |
| `food_days` | FDDB-Tagessummen: `day`, `entries`, `kcal`, `kj`, `fat`, `carbs`, `sugar`, `protein`, `fiber`, `alcohol`, `water_l`, `cholesterol_mg`, `salt`, `imported_at` |

Die Variablen *Kalorien gegessen*, *Protein*, *Fett*, *Kohlenhydrate*, *Zucker* und *Ballaststoffe* kommen aus `food_days`. Das sind dieselben Summen, die FDDB anzeigt.

**Datenschutz:** Das FDDB-Passwort liegt als Instanz-Eigenschaft in der IPS-Konfiguration (`settings.json`), wie bei allen IPS-Modulen. Mit dem Debug-Modus landet jede Tagebuchseite als `HealthHub/raw/fddb_<Tag>.html` auf der Platte.

## Claude anbinden (MCP-Server)

Das Modul enthält einen MCP-Server unter `http://<IPS-IP>:3777/hook/healthhub/mcp`. Claude kann damit Fragen beantworten wie „Wie ist mein echter Gesamtumsatz seit Oktober?“, „Passt mein Proteinschnitt?“ oder „Verliere ich magere Masse?“.

- **Strikt read-only:** Kein Tool schreibt etwas oder führt IPS-Funktionen aus.
- **Eigenes Token**, getrennt vom Health-Connect-Token: Formular → *MCP-Server (Claude)* → *MCP-Token*.
- **Nur LAN/VPN.** Bewusst **kein** Custom Connector über claude.ai, sonst müsste der Server aus dem Internet erreichbar sein. Ist IP-Symcon Connect aktiv, ist der Pfad auch darüber erreichbar, dann schützt allein das Token.

### Claude Code

Eine Zeile in der Eingabeaufforderung, `<IPS-IP>` und `<MCP-Token>` ersetzen:

```
claude mcp add --transport http --scope user healthhub http://<IPS-IP>:3777/hook/healthhub/mcp --header "Authorization: Bearer <MCP-Token>"
```

- `--scope user` macht den Server in allen Projekten verfügbar. Ohne `--scope` gilt er nur im aktuellen Ordner.
- Prüfen mit `claude mcp list` (erwartet: `healthhub … ✔ Connected`) oder in Claude Code mit `/mcp`.
- `✘` mit 401 bedeutet: Token falsch. 404 bedeutet: Pfad falsch oder MCP-Server im Formular deaktiviert.

### Claude Desktop

Claude Desktop verbindet lokale Server über eine Brücke. Dafür wird [Node.js](https://nodejs.org) benötigt, `npx` lädt [`mcp-remote`](https://github.com/geelen/mcp-remote) automatisch.

1. Claude Desktop → *Einstellungen → Entwickler → Konfiguration bearbeiten* öffnet `claude_desktop_config.json` (unter Windows `%APPDATA%\Claude\claude_desktop_config.json`).
2. Eintragen bzw. in einen bestehenden `mcpServers`-Block ergänzen:

```json
{
  "mcpServers": {
    "healthhub": {
      "command": "npx",
      "args": [
        "-y", "mcp-remote",
        "http://<IPS-IP>:3777/hook/healthhub/mcp",
        "--allow-http",
        "--transport", "http-only",
        "--header", "Authorization:${AUTH_HEADER}"
      ],
      "env": { "AUTH_HEADER": "Bearer <MCP-Token>" }
    }
  }
}
```

3. Claude Desktop komplett beenden (auch im Tray) und neu starten. Unter dem Werkzeug-Symbol erscheinen die HealthHub-Tools.

Hinweise zur Konfiguration:
- `Authorization:${AUTH_HEADER}` steht bewusst **ohne Leerzeichen**. Claude Desktop unter Windows reicht Argumente mit Leerzeichen sonst falsch weiter, deshalb kommt der Wert über `env`.
- `--allow-http` ist nötig, weil der Server im LAN ohne HTTPS läuft.

### Tools

Alle Datumsangaben `JJJJ-MM-TT` (Ortszeit), Zeitraum max. *Max. Zeitraum pro Abfrage* (Standard 366 Tage). Antworten sind kompaktes JSON mit **einer Zeile pro Tag**, fehlende Werte `null`.

| Tool | Parameter | Inhalt |
|---|---|---|
| `get_profile` | – | Ziele (1800 kcal, 130 g Protein, 60 g Fett), Methode, Zieltempo, Startwerte, Datenquellen, Einschränkungen |
| `get_daily_overview` | `from`, `to` | Pro Tag alle Werte aus allen Quellen. Wichtigstes Tool. |
| `get_nutrition` | `from`, `to` | FDDB-Tagessummen inkl. Zucker, Ballaststoffe, Alkohol, Salz, Anzahl Einträge; Durchschnitt über getrackte Tage und Ziele |
| `get_food_entries` | `from`, `to` (max. 31 Tage), opt. `search`, `meal` | Einzeleinträge mit Uhrzeit, Mahlzeit, Produkt, Menge, Makros |
| `get_food_stats` | `from`, `to`, opt. `sort_by` (`kcal`/`protein`/`count`), `limit` | Pro Produkt: Häufigkeit, Tage, Menge, Makros, Anteil an kcal/Protein |
| `get_meal_pattern` | `from`, `to` | Ø kcal/Protein pro Mahlzeit und Tageszeitfenster, Wochentag vs. Wochenende |
| `get_body` | `from`, `to` | Gewicht, Körperfett, magere Masse, Wasser, Knochen, Bauchumfang (nur Messtage) |
| `get_activity` | `from`, `to` | Schritte, Aktivkalorien (Uhrschätzung, vermutlich zu hoch), Schlaf, Ruhepuls |
| `get_weekly_summary` | `from`, `to` | ISO-Wochen: Mittelwerte, Gewichtstrend, getrackte Tage, **geschätzter Gesamtumsatz (TDEE)** pro Woche und über den Zeitraum, mit Methode und Unsicherheit |

**TDEE-Schätzung:** `Ø kcal_in − (Δ Wochenmittel-Gewicht × 7700) / Tage`.
- Pro Woche nur, wenn mindestens 5 Ernährungstage getrackt sind und in dieser und der Vorwoche gewogen wurde.
- Gesamtwert: erste gegen letzte qualifizierte Woche, mit Unsicherheit in kcal. Eine einzelne Woche ist wegen Wasserschwankungen grob (±300–550 kcal). Über mehrere Wochen wird die Schätzung deutlich genauer.
- Uhr-Kalorien fließen nicht ein. Fehlende FDDB-Einträge drücken die Schätzung nach unten.

`query_food_sql` aus der ursprünglichen Planung gibt es **nicht**: Das IPS-PHP hat kein SQLite, und mit dem JSON-Speicher lässt sich „nur lesen“ nicht technisch erzwingen.

**Protokoll:** Streamable HTTP, nur POST mit JSON-Antwort (kein SSE). Der Server ist „dual-era“:
- Er spricht das zustandslose MCP **2026-07-28**: `server/discover`, Header `MCP-Protocol-Version`/`Mcp-Method`/`Mcp-Name` werden gegen den Body geprüft.
- Er bedient ebenso ältere Clients mit `initialize` (2025-11-25, 2025-06-18, 2025-03-26), ohne Session-ID.
- GET/DELETE → 405, Notifications → 202, ein fremder `Origin`-Header → 403.

**Testen ohne Claude:** Formular → *MCP-Test* → Tool wählen, Argumente als JSON (z. B. `{"from":"2026-09-15","to":"2026-10-19"}`) → *Ausführen*. Das zeigt genau die Antwort, die Claude bekommt.

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
| `RestingHR` | Ruhepuls | Integer | `HH.bpm` | Uhr, berechnet aus der Herzfrequenz im Schlaf |
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
| Debug-Modus (`DebugMode`) | aus | ein/aus | Jede empfangene Roh-Payload ins Debug-Fenster und als Datei `hc_<Datum>_<Zeit>_….json` unter `<IPS-Datenverzeichnis>/HealthHub/raw/` (die letzten 300 Dateien bleiben). Zum Einrichten und zur Fehlersuche einschalten, im Dauerbetrieb aus. |

### Health-Connect-Empfang

| Parameter | Standard | Bereich | Wirkung |
|---|---|---|---|
| Token (`IngestToken`) | zufällig (32 Hex-Zeichen) | Text | Wird beim ersten Übernehmen automatisch erzeugt. Muss in HC Webhook als Header `Authorization: Bearer <Token>` eingetragen sein. Ebenfalls akzeptiert: Basic Auth mit beliebigem Benutzer und dem Token als Passwort, oder Header `X-Api-Key: <Token>`. Leerer Token = alles wird abgewiesen. *Token neu generieren* erzeugt einen neuen, danach in der App nachtragen. |
| Bevorzugte Quellen Körperwerte (`BodySources`) | leer | Paketnamen, kommagetrennt | Welche Quell-App für Gewicht, Körperfett, magere Masse, Körperwasser und Knochenmasse zählt, falls mehrere Apps diese Werte in Health Connect schreiben. Reihenfolge = Priorität. Paketnamen aus der Liste *Gesehene Health-Connect-Quellen* kopieren, z. B. den der Fitdays-App. Leer = automatisch (Quelle mit den meisten Messungen am Tag). |
| Bevorzugte Quellen Aktivität/Schlaf (`ActivitySources`) | leer | Paketnamen, kommagetrennt | Wie oben für Schritte, Aktivkalorien, Schlaf und Ruhepuls, typischerweise die App der Pixel Watch (Google Health/Fitbit). Leer = automatisch (bei Schritten/Aktivkalorien die Health-Connect-Tagessumme). |
| Mehrere Körpermessungen pro Tag (`MeasureRule`) | Erste Sitzung des Tages, Median | Sitzung-Median / erste / letzte / Mittelwert | Welche Waagen-Messung als Tageswert gilt. **Sitzung-Median** (Standard): alle Messungen bis 15 min nach der ersten Messung des Tages bilden die Morgen-Sitzung, davon zählt der Median (bei 2 Messungen der Mittelwert). Ein einzelner Ausreißer, z. B. mit Kleidung oder vor dem Einschwingen der Waage, fällt so heraus, spätere Messungen am Tag zählen nicht. *Erste* = erste Messung des Tages, *letzte* = letzte, *Mittelwert* = alle des Tages. Die berechnete magere Masse folgt derselben Regel. Bestehende Instanzen mit „Erste“ werden mit v0.6 einmalig auf Sitzung-Median umgestellt. |
| Records puffern für (`IngestKeepDays`) | 14 Tage | 3–120 | Wie viele Tage empfangene Datensätze gepuffert werden. Ältere Datensätze werden abgelehnt (`too_old`), damit ein unvollständiger alter Tag keinen guten Archivwert überschreibt. Für eine einmalige Nachbefüllung erhöhen, danach zurückstellen (der Puffer liegt in einem Instanz-Attribut). |

### FDDB-Import

| Parameter | Standard | Bereich | Wirkung |
|---|---|---|---|
| Nächtlicher Import aktiv (`FddbEnabled`) | aus | ein/aus | Schaltet den täglichen Import ein. Die Buttons funktionieren auch ohne. |
| FDDB-Benutzername oder E-Mail (`FddbUser`) | leer | Text | Login wie auf fddb.info. |
| FDDB-Passwort (`FddbPassword`) | leer | Text | Wird verdeckt angezeigt. |
| Importzeit Stunde / Minute (`FddbHour`, `FddbMinute`) | 3 / 30 | 0–23 / 0–59 | Wann der nächtliche Import startet. Nach 02:00, weil ein FDDB-Tag erst um 02:00 endet. |
| Tage rückwirkend neu importieren (`FddbDaysBack`) | 3 | 1–14 | Wie viele Tage bis gestern jede Nacht neu gelesen werden (Nachträge). *Jetzt importieren* nimmt diese Tage plus heute. |

### MCP-Server (Claude)

| Parameter | Standard | Bereich | Wirkung |
|---|---|---|---|
| MCP-Server aktiv (`McpEnabled`) | ein | ein/aus | Aus = der Pfad `/hook/healthhub/mcp` antwortet mit 404. |
| MCP-Token (`McpToken`) | zufällig (32 Hex-Zeichen) | Text | Wird beim ersten Übernehmen erzeugt. Claude sendet es als `Authorization: Bearer <Token>`. Getrennt vom Health-Connect-Token. *MCP-Token neu generieren* macht das alte sofort ungültig, danach Claude Code/Desktop neu einrichten. |
| Max. Zeitraum pro Abfrage (`McpMaxDays`) | 366 Tage | 7–366 | Längste erlaubte Spanne `from`–`to`. Größere Anfragen beantwortet das Tool mit einer Fehlermeldung, die Claude selbst korrigiert (Zeitraum aufteilen). |

### Ziele

Die Ziele liefert das MCP-Tool `get_profile` an Claude, damit die Bewertung ohne Erklärung funktioniert.

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
| `HH_IngestJson(int $InstanceID, string $Json): string` | Verarbeitet eine Payload wie der WebHook (ohne Token-Prüfung) und liefert das Ergebnis als JSON. Zum Testen ohne Handy, z. B. mit einer Datei aus `HealthHub/raw`: `echo HH_IngestJson(12345, file_get_contents('…json'));` |
| `HH_RegenerateIngestToken(int $InstanceID)` | Neuen Ingest-Token erzeugen, der alte gilt sofort nicht mehr. |
| `HH_DeleteHealthConnectData(int $InstanceID, string $From, string $To): string` | Löscht für den Zeitraum (`JJJJ-MM-TT`) die archivierten Health-Connect-Werte (Körperwerte, Schritte, Aktivkalorien, Schlaf, Ruhepuls – **nicht** FDDB und Bauchumfang) und die gepufferten Datensätze dieser Tage. Auch als Button unter *Gesehene Health-Connect-Quellen*. Für Fehlimporte, danach in der App neu synchronisieren. |
| `HH_ResetIngestState(int $InstanceID)` | Puffer der empfangenen Datensätze und die Quellenliste leeren. Archivwerte bleiben. |
| `HH_FddbTestLogin(int $InstanceID): string` | Meldet sich bei FDDB an und liest die heutige Tagebuchseite, ohne zu speichern. Zeigt Einträge und kcal. |
| `HH_FddbImportNow(int $InstanceID): string` | Reiht die letzten *n* Tage plus heute zum Import ein. |
| `HH_FddbImportRange(int $InstanceID, string $From, string $To): string` | Reiht einen Zeitraum (`JJJJ-MM-TT`, max. 400 Tage, nicht in der Zukunft) zum Import ein. |
| `HH_FddbNightly(int $InstanceID)` / `HH_FddbWorker(int $InstanceID)` | Interne Timer: nächtlicher Lauf bzw. Abarbeiten der Warteschlange. |
| `HH_CheckSystem(int $InstanceID): string` | Zeigt PHP-Version, ob `pdo_sqlite`, `dom` und `curl` vorhanden sind, und das Datenverzeichnis. |
| `HH_McpTest(int $InstanceID, string $Tool, string $ArgumentsJson): string` | Führt ein MCP-Tool lokal aus (ohne Token) und liefert die JSON-Antwort, z. B. `echo HH_McpTest(12345, 'get_weekly_summary', '{"from":"2026-09-15","to":"2026-10-19"}');` |
| `HH_RegenerateMcpToken(int $InstanceID)` | Neues MCP-Token erzeugen. |
| `HH_ProcessPending(int $InstanceID)` | Interner Timer: entfernt die 0-Einträge beim Logging-Start und wiederholt fehlgeschlagene Re-Aggregationen. Läuft nur, solange etwas ansteht. |
| `HH_SelfTestArchive(int $InstanceID): string` | Prüft die Schreibstrategie auf diesem System mit einer temporären, versteckten Variable und liefert einen Bericht. Zeigt auch das Roh-Verhalten von `SetValue` + `AC_AddLoggedValues`. Startet das Archiv zweimal neu (Logging an/aus), daher nicht im laufenden Betrieb dauernd ausführen. |

## Entwicklung / Tests

Die reine Logik (`libs/DayClock.php`, `libs/ArchiveWriter.php`, `libs/HcIngest.php`, `libs/HookAuth.php`, `libs/FddbParser.php`, `libs/FddbClient.php`, `libs/FoodStore.php`, `libs/FddbImport.php`, `libs/McpServer.php`, `libs/McpTools.php`) ist frei von IPS-Funktionen und mit PHPUnit getestet. Die IPS-Anbindung steckt in `HHIpsArchiveBackend`.

```bash
php phpunit.phar
```

Alle Klassen tragen das Präfix `HH`, weil sich alle Module einen PHP-Prozess teilen und gleichnamige Klassen anderer Module sonst kollidieren.

## Bekannte Einschränkungen

- HC Webhook schaut im Hintergrund nur **48 h** zurück. War das Handy länger offline oder die App gestoppt, fehlen Tage, bis man manuell mit Zeitraum synchronisiert.

- FDDB: Laut Übergabe fehlen Einträge aus **KI-FoodScan** und Rezepte aus dem FDDB-Rezeptbereich im **CSV-Export**. Das Modul liest stattdessen die Tagebuchseite. Ob sie dort vollständig sind, ist noch zu prüfen: Summe in der FDDB-App mit *Kalorien gegessen* vergleichen.
- FDDB: Bei einer Layout-Änderung von fddb.info bricht der Import mit Fehler im Status ab, statt falsche Werte zu schreiben. Dann muss der Parser (`libs/FddbParser.php`) angepasst werden.
