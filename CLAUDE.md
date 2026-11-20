# Contao Class Book – Spezifikation und Konventionen

Diese Datei ist die verbindliche Arbeitsgrundlage für `markocupic/contao-class-book`.
Vor jeder Arbeit am Bundle lesen, bei jeder Entscheidung nachführen.

## Zweck

Digitales Unterrichtsbuch für Lehrpersonen als Contao-Extension: Stundenplan, Lerngruppen
mit Schülerinnen und Schülern und Einträge (Notizen, Termine, Unterrichtsbemerkungen,
Hausaufgaben) in einer
Frontend-Applikation.

## Nicht im Umfang

- Keine Absenzen, keine Noten, keine Schülerbeobachtungen (dafür gibt es ein anderes Tool).
- Von Schülerinnen und Schülern werden nur Name, Vorname, Geburtsdatum, Stammklasse und
  Lerngruppen erfasst. Keine weiteren Personendaten.

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
| `tl_cb_group` | Stammklasse oder Lerngruppe: Bezeichnung, Art (`class`/`group`), Schuljahr |
| `tl_cb_student` | Schüler/in: Nachname, Vorname, Geburtsdatum (optional), Stammklasse |
| `tl_cb_student_group` | Verknüpfung Schüler/in ↔ weitere Lerngruppen |
| `tl_cb_lesson` | Stundenplan-Lektion: Schuljahr, Wochentag, Lektion, Fach, Klasse/Lerngruppe, Raum |
| `tl_cb_lesson_change` | Lektionsänderung an einem Datum: Ausfall, Verschiebung, Raumwechsel |
| `tl_cb_entry` | Eintrag (Task), siehe unten |

### Stammklasse und Lerngruppen

- Eine Schülerin oder ein Schüler gehört zu genau einer Stammklasse (`homeClass`, Gruppe mit
  Art `class`) und zu beliebig vielen weiteren Lerngruppen (Art `group`, über
  `tl_cb_student_group`).
- Die Art einer Gruppe kann nicht geändert und die Gruppe nicht gelöscht werden, solange
  ihr jemand zugeordnet ist.
- Im Stundenplan kann einer Lektion eine Stammklasse oder eine Lerngruppe zugeordnet werden.

### Stundenplan

- Ein Stundenplan gehört zu einem Schuljahr (statt «gültig von/bis»). Pro Schuljahr,
  Wochentag und Lektion gibt es höchstens eine Stundenplan-Lektion.
- Welche Lektionen an einem Datum gelten: Schuljahr, dessen Zeitraum das Datum enthält.
  Liegt das Datum in Ferien (`tl_cb_holiday`), entfallen alle Lektionen des Tages.
- Fächer, Lektionen des Rasters, Klassen/Lerngruppen und Schuljahre können nicht gelöscht
  werden, solange der Stundenplan sie verwendet.
- Die Oberfläche bietet beim Erfassen nur Klassen/Lerngruppen des gewählten Schuljahrs an;
  serverseitig wird das nicht erzwungen.

### Eintrag (`tl_cb_entry`)

Notizen, Termine und Unterrichtsbemerkungen sind derselbe Datensatz («Task»).

- `type`: `note`, `appointment`, `remark` (Hausaufgabe `homework` folgt in Etappe 6)
- `entryDate`: Tag des Eintrags (immer gesetzt)
- Bindung an das Datum, nicht an die Stundenplan-Lektion: entweder an eine Lektion des
  Rasters (`period`) oder an den ganzen Tag (`period = 0`), dann mit optionaler Uhrzeit
  (`entryTime`). Ändert sich der Stundenplan, bleiben alte Einträge an ihrem Platz.
- `content`: Text (mehrzeilig)
- `done`: Jeder Eintrag kann als erledigt markiert werden
- `important` + `visibleFrom`: erscheint ab diesem Datum in der Box «Wichtige Termine»
  oben rechts (Etappe 5), bis der Eintrag vorbei oder erledigt ist
- Unerledigte Einträge bleiben an ihrem Tag. Liegt ihr Datum vor heute, erscheint in der
  Wochenansicht ein Hinweis mit Anzahl und Liste. Gezählt werden alle Arten.
- Verschieben heisst: das Datum des Eintrags ändern.

## Ansichten

- Wochenansicht: Raster Wochentage × Lektionen. Zelle zeigt Fach, Lerngruppe und Einträge.
  Klick in die Zelle legt einen Eintrag an. Tagesgebundene Einträge stehen in einer Zeile
  über dem Raster.
- Tagesansicht: dieselben Daten als Liste, mit mehr Platz für Text (Handy).
- Box «Wichtige Termine» oben rechts, in beiden Ansichten.
- Einstellungen: Schuljahr, Ferien, Lektionsraster, Fächer, Lerngruppen, Schülerinnen und
  Schüler, Stundenplan.

## Etappen

1. Fundament: Bundle-Skelett, ECS, Encore, Frontend-Modul mit Login-Schutz, API mit Token.
2. Stammdaten: Schuljahr, Ferien, Lektionsraster, Fächer, Lerngruppen, Schülerinnen und Schüler.
3. Stundenplan: Erfassung und Wochenansicht.
4. Einträge: Anlegen, Bearbeiten, Abhaken, an Lektion oder Tag.
5. Tagesansicht und Box «Wichtige Termine», inklusive Geburtstags-Reminder für Schülerinnen
   und Schüler (aus `tl_cb_student.dateOfBirth`).
6. Lektionsänderungen und Hausaufgaben mit Fälligkeit.
7. Später, optional: MCP-Server auf Basis der Service-Klassen, an das Mitglied gebunden.

Jede Etappe besteht aus kleinen Commits und ist am Ende lauffähig.

### Stand

- [x] Plan und Bundle-Skelett (Bundle-Klasse, Manager-Plugin, DI-Extension, ECS, CI)
- [x] Etappe 1: Encore, Frontend-Modul `class_book`, API mit Token (`/_class_book/api/week`),
      Wochen-Navigation als leeres Raster (Montag bis Freitag)
- [x] Etappe 2: Stammdaten (Schuljahre, Ferien, Lektionsraster, Fächer, Lerngruppen,
      Schülerinnen und Schüler) mit
      Ansicht «Einstellungen», API `/_class_book/api/master-data/{list|save|delete}`
- [x] Etappe 3: Stundenplan. Erfassung im Raster (Einstellungen → Stundenplan, pro Schuljahr),
      Wochenansicht mit Lektionen in der Fachfarbe, Ferien blenden Lektionen aus
- [x] Etappe 4: Einträge in der Wochenansicht (Lektion oder ganzer Tag), Abhaken, Hinweis auf
      unerledigte Einträge früherer Tage, Verschieben über das Datum
- [ ] Etappe 5: Tagesansicht, Box «Wichtige Termine», Geburtstags-Reminder

## Architektur (Ist-Zustand)

- `src/Controller/FrontendModule/ClassBookController.php`: Frontend-Modul `class_book`. Ohne
  angemeldetes Mitglied nur ein Hinweis. Sonst Übergabe von Token, API-URLs und Mitglied als
  JSON im Attribut `data-class-book`.
- `contao/templates/twig/frontend_module/class_book.html.twig`: enthält das Vue-Template.
  Vue-Delimiter sind `[[ ]]`, Texte kommen über `MSC.classBook.*` (Domain `contao_default`).
- `assets/js/class-book.js`: Vue-App (globales `Vue`, kein Build-Schritt für den Code).
  `assets/styles/class-book.scss`: Styles, Präfix `cb-`.
- `src/Security/AccessTokenManager.php`: signiertes Token (HMAC mit `kernel.secret`, 12 h
  gültig), gebunden an Mitglied und Modul. `ApiAuthenticator` prüft Header
  `X-Class-Book-Token` und dass das Token zum angemeldeten Mitglied gehört.
- `src/Controller/Api/*`: ein Controller pro Endpunkt, nur POST mit JSON, `_scope: frontend`,
  `_token_check: false`. Jeder Controller ruft zuerst `ApiAuthenticator::getMemberId()` auf.
- `src/Controller/Api/AbstractApiController.php`: JSON-Antworten (`private, no-store`) und
  Lesen des JSON-Bodys. Fehler als `{error: <code>}` mit passendem HTTP-Status
  (401 `unauthorized`, 400 `badRequest`, 404 `notFound`, 409 `inUse`, 422 `validation`
  mit `errors: {feld: code}`).
- `src/Service/*`: Fachlogik ohne HTTP-Bezug (`WeekCalculator`, `TimetableService`,
  `MasterData/*`).
- Stundenplan-Lektionen sind die Ressource `lesson` in `ResourceDefinitions` (mit `unique`)
  und laufen über dieselbe API wie die Stammdaten. Im Frontend haben sie statt der Liste
  einen eigenen Raster-Editor. `/_class_book/api/week` liefert zusätzlich `timetable`
  (`periods`, `subjects`, `groups`, `days[datum] = {holiday, lessons}`) aus `TimetableService`.
- Einträge sind die Ressource `entry` (`masterData => false`): Validierung und Speichern
  über `MasterDataService`, aber nicht in den Stammdaten-Listen und nicht über die
  Stammdaten-API. `EntryService` (Zeitraum, offene Einträge, erledigt, speichern, löschen)
  und `/_class_book/api/entries/{save|done|delete|open}`. Die Wochen-API liefert zusätzlich
  `entries` (Montag bis Sonntag) und `openCount`.
- `MasterDataService::findBy()` nimmt Bedingungen als `[Feld, Operator, Wert]`; Felder und
  Operatoren werden gegen die Definition bzw. eine feste Liste geprüft.
- `src/Service/MasterData/ResourceDefinitions.php`: einzige Quelle für die Stammdaten-
  Ressourcen (Tabelle, Sortierung, Felder mit Typ und Regeln). Der Validator, der Service
  und das Frontend (über `schema` in der `list`-Antwort) richten sich danach. Eine neue
  Ressource braucht: Eintrag hier, DCA-Datei, Sprachdateien, Labels `MSC.classBook.*`.
- `MasterDataService`: alle Abfragen mit `member = ?`. Referenzen werden nur auf eigene
  Datensätze akzeptiert. Löschen wird verweigert, solange eine Referenz auf den Datensatz
  zeigt (`RecordInUseException`). Feldtyp `references` liegt in einer Verknüpfungstabelle,
  `where` schränkt eine Referenz ein, `lockedWhenReferenced` sperrt ein Feld bei Verwendung.
- Speicherformate: Datum `YYYY-MM-DD`, Uhrzeit `HH:MM`, Farbe `#rrggbb` (jeweils varchar).
  Texte werden wie in Contao üblich HTML-kodiert gespeichert (`StringUtil::specialchars`)
  und für die API dekodiert (`StringUtil::decodeEntities`).
- Spaltennamen dürfen keine reservierten SQL-Wörter sein (deshalb `learningGroup` statt
  `group`). Die SQLite-Tests legen die Tabellen aus `ResourceDefinitions` an und decken das auf.
- Frontend-Texte: Der Controller übergibt `$GLOBALS['TL_LANG']['MSC']['classBook']` als
  `labels`. Im Vue-Template nie zwei schliessende eckige Klammern hintereinander schreiben
  (z. B. `a[b[c]]`), das kollidiert mit den Delimitern; dafür eine Methode verwenden.
- Backend: Modulgruppe `class_book` mit je einem Modul pro Tabelle, nur als Notfallzugriff.

## Build und Tests

- Assets: `npm install`, dann `npm run build`. Das Ergebnis in `public/` wird committet.
  `overrides.webpack` in `package.json` ist nötig, weil `copyFiles()` von Encore 5 mit
  neueren Webpack-Versionen bricht.
- `composer unit-tests` (PHPUnit), `composer cs-check` bzw. `composer cs-fixer` (ECS).
  Die Service-Tests laufen gegen SQLite im Speicher (`ext-pdo_sqlite`).
- Nach Änderungen an DCA-Dateien muss in der Installation `contao:migrate` laufen.
  CI prüft den Coding Style ohne `--fix`, Abweichungen lassen den Lauf scheitern.
  Häufigster Befund (`StatementGroupingFixer`): Aufrufe verschiedener Methoden oder Objekte
  hintereinander brauchen eine Leerzeile dazwischen, ebenso Zuweisung gefolgt von Aufruf.
- Lokale Installation: `C:\phpstorm\schuleettiswil`, Bundle per Composer (VCS, `dev-main`)
  unter `vendor/markocupic/contao-class-book`. Dort gibt es für Claude kein PHP.
- Getestet wird im Browser auf https://www.schule-ettiswil.ch/_class-book (Live-Website,
  Marko spielt Updates selbst ein und führt `contao:migrate` aus).

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
