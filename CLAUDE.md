# Contao Class Book – Spezifikation und Konventionen

Diese Datei ist die verbindliche Arbeitsgrundlage für `markocupic/contao-class-book`.
Vor jeder Arbeit am Bundle lesen, bei jeder Entscheidung nachführen.

## Zweck

Digitales Unterrichtsbuch für Lehrpersonen als Contao-Extension: Stundenplan, Lerngruppen
und Einträge (Notizen, Termine, Unterrichtsbemerkungen, Hausaufgaben) in einer
Frontend-Applikation.

## Nicht im Umfang

- Keine Absenzen, keine Noten, keine Schülerbeobachtungen (dafür gibt es ein anderes Tool).
- Vorerst keine Erfassung von Schülern. Das Datenmodell darf sie später aufnehmen
  (m:n zu Lerngruppen), es wird aber nichts dafür vorgebaut.

## Grundsätze

- Contao 5.7, vorbereitet für Contao 6.0. PHP ^8.3.
- Funktioniert nur im angemeldeten Zustand (Contao-Mitglied / Frontend-User).
- Hauptsächlich eine Frontend-Applikation: ein Frontend-Modul, Vue-Code im Twig-Template
  (keine `.vue`-Komponenten), Assets über Webpack + Symfony Encore.
- Ajax über eigene API-Routen mit signiertem Zugangs-Token, das bei jedem Seitenaufruf
  neu erzeugt wird (gleiches Muster wie im resource-booking-bundle).
- Mandantenfähig von Anfang an: Jeder Datensatz gehört einem Mitglied (`member`).
  Jede Abfrage und jede Schreiboperation filtert bzw. prüft auf das angemeldete Mitglied.
  Am Anfang gibt es nur einen Nutzer, weitere Lehrpersonen sollen ohne Umbau möglich sein.
- Die Fachlogik liegt in Service-Klassen (`src/Service/…`). API-Controller rufen nur
  diese Services auf. Grund: Ein späterer MCP-Server soll dieselben Services nutzen.
- Backend: nur schlanke DCA-Tabellen als Notfallzugriff. Erfasst wird im Frontend.

## Datenmodell

Tabellenpräfix `tl_cb_`. Jede Tabelle hat `id`, `tstamp`, `member`.

| Tabelle | Inhalt |
|---|---|
| `tl_cb_school_year` | Schuljahr: Titel, Beginn, Ende |
| `tl_cb_holiday` | Ferien/Feiertage: Titel, von/bis; blendet Lektionen aus |
| `tl_cb_period` | Lektionsraster: Lektionsnummer, Beginn- und Endzeit |
| `tl_cb_subject` | Fach: Bezeichnung, Kürzel, Farbe |
| `tl_cb_group` | Lerngruppe: Bezeichnung, Schuljahr |
| `tl_cb_lesson` | Stundenplan-Lektion: Wochentag, Lektion, Fach, Lerngruppe, Raum, gültig von/bis |
| `tl_cb_lesson_change` | Lektionsänderung an einem Datum: Ausfall, Verschiebung, Raumwechsel |
| `tl_cb_entry` | Eintrag (Task), siehe unten |

### Eintrag (`tl_cb_entry`)

Notizen, Termine, Unterrichtsbemerkungen und Hausaufgaben sind derselbe Datensatz.

- `type`: `note`, `appointment`, `remark`, `homework`
- `date`: Tag des Eintrags (immer gesetzt)
- Bindung: entweder an eine Lektion im Raster (`lesson` + `date`) oder nur an den Tag,
  dann mit optionaler Uhrzeit (`time`)
- `text`: Inhalt
- `done`: Jeder Eintrag kann als erledigt markiert werden
- `important` + `visibleFrom`: erscheint ab diesem Datum in der Box «Wichtige Termine»
  oben rechts, bis der Eintrag vorbei oder erledigt ist
- `dueDate` (nur Hausaufgabe): Fälligkeit; erscheint bei der entsprechenden Lektion der
  Lerngruppe

## Ansichten

- Wochenansicht: Raster Wochentage × Lektionen. Zelle zeigt Fach, Lerngruppe und Einträge.
  Klick in die Zelle legt einen Eintrag an. Tagesgebundene Einträge stehen in einer Zeile
  über dem Raster.
- Tagesansicht: dieselben Daten als Liste, mit mehr Platz für Text (Handy).
- Box «Wichtige Termine» oben rechts, in beiden Ansichten.
- Einstellungen: Schuljahr, Ferien, Lektionsraster, Fächer, Lerngruppen, Stundenplan.

## Etappen

1. Fundament: Bundle-Skelett, ECS, Encore, Frontend-Modul mit Login-Schutz, API mit Token.
2. Stammdaten: Schuljahr, Ferien, Lektionsraster, Fächer, Lerngruppen.
3. Stundenplan: Erfassung und Wochenansicht.
4. Einträge: Anlegen, Bearbeiten, Abhaken, an Lektion oder Tag.
5. Tagesansicht und Box «Wichtige Termine».
6. Lektionsänderungen und Hausaufgaben mit Fälligkeit.
7. Später, optional: MCP-Server auf Basis der Service-Klassen, an das Mitglied gebunden.

Jede Etappe besteht aus kleinen Commits und ist am Ende lauffähig.

### Stand

- [x] Plan und Bundle-Skelett (Bundle-Klasse, Manager-Plugin, DI-Extension, ECS, CI)
- [ ] Etappe 1: Encore, Frontend-Modul, API mit Token

## Konventionen

- Namespace `Markocupic\ContaoClassBook`, Bundle-Alias `markocupic_contao_class_book`.
- Moderne Twig-Templates unter `contao/templates/twig/<typ>/…` mit leerer `.twig-root`-Datei
  in `contao/templates/twig/`.
- Nie `htmlspecialchars()`, sondern `Contao\StringUtil::specialchars()`,
  `::specialcharsAttribute()` oder `::specialcharsUrl()`.
- Contao verwendet Input-Encoding: DB-Werte sind bereits HTML-kodiert. Bei Ausgabe über
  Twig/JS auf Doppelkodierung achten.
- Scripts und Stylesheets nur über Webpack + Symfony Encore.
- Coding Standard: `composer cs-fixer` (Contao ECS, Konfiguration in `tools/ecs`).
- Kleine, in sich abgeschlossene Commits mit englischer Commit-Message.
- Keine Tags und keine Releases anlegen, solange Marko das nicht ausdrücklich verlangt.
- Code, Bezeichner und Kommentare englisch; Oberfläche deutsch (Übersetzungen über
  Contao-Sprachdateien, damit weitere Sprachen möglich bleiben).
