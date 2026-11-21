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
- Von Schülerinnen und Schülern werden nur Name, Vorname, Geburtsdatum, Geschlecht (optional,
  nur für die Sitzordnung «Mädchen und Jungs mischen», Entscheid von Marko), Stammklasse und
  Lerngruppen erfasst. Keine weiteren Personendaten.

## Grundsätze

- Contao 5.7, vorbereitet für Contao 6.0. PHP ^8.3.
- Funktioniert nur im angemeldeten Zustand (Contao-Mitglied / Frontend-User).
- Hauptsächlich eine Frontend-Applikation: ein Inhaltselement (bis 21.11.2026 ein
  Frontend-Modul, auf Wunsch von Marko umgestellt), Vue-Code im Twig-Template
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
| `tl_cb_classroom` | Klassenraum: Bezeichnung, Raumplan (`layout`, JSON mit den Pulten) |
| `tl_cb_group` | Stammklasse oder Lerngruppe: Bezeichnung, Art (`class`/`group`), Schuljahr, Klassenraum, Sitzordnung (`seating`, JSON) |
| `tl_cb_student` | Schüler/in: Nachname, Vorname, Geburtsdatum (optional), Geschlecht (optional, `f`/`m`), Stammklasse |
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

### Klassenraum und Sitzordnung

- Raumplan (`tl_cb_classroom.layout`): `{desks: [{id, type, x, y, r}]}`. Raster 40 × 26 Zellen
  (`ROOM` in `class-book.js`), ein Sitzplatz belegt 4 × 3 Zellen. Pulttypen (`DESK_TYPES`):
  `teacher` (kein Sitzplatz), `single`, `double`, `quad`; `r` ist 0 oder 90. Pulte überlappen
  sich nicht. Die Wandtafel ist oben.
- Ein Sitzplatz hat den Schlüssel `<Pult-ID>:<Nummer>`. Pult-IDs bleiben beim Verschieben
  gleich, damit gespeicherte Sitzordnungen gültig bleiben.
- Sitzordnung (`tl_cb_group.seating`), genau eine pro Klasse/Lerngruppe (Entscheid von Marko):
  `{seats: {Platz: Schüler-ID}, blocked: [Platz], fixed: [Platz]}`. Beim Laden verwirft das
  Frontend Plätze und Schüler, die es nicht mehr gibt (`parseSeating`).
- Losen (`drawSeating`): Lehrerpulte und gesperrte Plätze bleiben leer. Von Hand gesetzte
  Plätze sind fixiert (Stecknadel) und bleiben, der Rest wird gelost. So geht auch eine ganz
  manuelle oder eine teilweise manuelle Sitzordnung. Mit «Mädchen und Jungs mischen» sitzt, wo
  immer möglich, ein Junge neben einem Mädchen: zuerst neben bereits Gesetzten, dann an so
  vielen freien Nachbarplätzen wie möglich (Nachbarn = Platzpaar am selben Pult). Wer kein
  Geschlecht erfasst hat, wird zufällig verteilt.
- Der Server prüft bei `layout` und `seating` nur, dass es gültiges JSON ist (Feldtyp `json`,
  wird roh gespeichert, nicht HTML-kodiert). Die Struktur gehört dem Frontend.
- Ein Klassenraum kann nicht gelöscht werden, solange ihn eine Gruppe verwendet.

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

Notizen, Aufgaben, Termine und Unterrichtsbemerkungen sind derselbe Datensatz.

- `type`: `remark`, `note`, `task`, `appointment` (Hausaufgabe `homework` folgt in Etappe 6).
  Die Reihenfolge im Auswahlfeld kommt aus `MSC.classBook.options.type`; die erste Art
  (Unterrichtsbemerkung) ist die Vorgabe für neue Einträge.
- `entryDate`: Tag des Eintrags (immer gesetzt)
- Bindung an das Datum, nicht an die Stundenplan-Lektion: entweder an eine Lektion des
  Rasters (`period`) oder an den ganzen Tag (`period = 0`), dann mit optionaler Uhrzeit
  (`entryTime`). Ändert sich der Stundenplan, bleiben alte Einträge an ihrem Platz.
- `content`: Text (mehrzeilig)
- `done`: Nur Aufgaben (`task`) haben die Checkbox «Erledigt». Bei allen anderen Arten ist
  `done` immer `false` (`EntryService::save()` erzwingt das).
- `important` + `visibleFrom`: erscheint ab diesem Datum in der Box «Wichtige Termine»
  oben rechts (Etappe 5), bis der Eintrag vorbei oder erledigt ist
- `reminder`: Erinnerung per E-Mail an das eigene Mitglied, so lange vor dem Eintrag
  (`ResourceDefinitions::REMINDERS`: 15/30/45 Minuten, 1–8 Stunden, 1–6 Tage, 1–3 Wochen,
  1–3 Monate; Namen in `MSC.classBook.options.reminder`). Eine Erinnerung pro Eintrag.
  Der Eintrag beginnt zu seiner Uhrzeit, sonst zum Beginn seiner Lektion, sonst (ganzer Tag
  ohne Uhrzeit) um 07:00 (`ReminderService::WHOLE_DAY_TIME`). Erledigte Aufgaben und bereits
  begonnene Einträge erhalten keine Erinnerung.
- `reminderSent`: Erinnerung wurde gesendet. `EntryService::save()` setzt den Wert zurück,
  wenn sich Datum, Lektion, Uhrzeit oder Erinnerung ändern (auch beim Verschieben und
  Kopieren); übermittelte Werte werden ignoriert.
- Unerledigte Aufgaben bleiben an ihrem Tag. Liegt ihr Datum vor heute, erscheint in der
  Wochenansicht ein Hinweis mit Anzahl und Liste. Gezählt werden nur Aufgaben.
- Verschieben heisst: das Datum des Eintrags ändern (im Dialog) oder den Eintrag per Drag
  and Drop in eine andere Lektion, einen anderen Tag oder an eine andere Position ziehen.
- Kopieren: Kopiersymbol rechts am Eintrag (bei Hover). Die Kopie steht direkt unter dem
  Original, eine kopierte Aufgabe ist nicht erledigt (`EntryService::copy()`).
- `sorting`: Position innerhalb der Lektion bzw. des ganzen Tages. Neue Einträge und
  Einträge, die über den Dialog Tag oder Lektion wechseln, kommen ans Ende. `EntryService`
  setzt den Wert, übermittelte Werte werden ignoriert. `EntryService::move()` setzt die
  Position und nummeriert die Lektion neu.

## Ansichten

- Wochenansicht: Raster Wochentage × Lektionen. Zelle zeigt nur das Kürzel des Fachs (Fach,
  Klasse/Lerngruppe und Raum stehen im Tooltip) und die Einträge.
  Klick in die Zelle legt einen Eintrag an. Tagesgebundene Einträge stehen in einer Zeile
  über dem Raster.
- Tagesansicht: dieselben Daten als Liste, mit mehr Platz für Text. Zeigt «Ganzer Tag» und
  alle Lektionen des Rasters, auch leere ohne Fach (schmaler, gestrichelt). Ab 62 rem Breite steht
  «Ganzer Tag» (Ferien, Geburtstage, Einträge ohne Lektion) als Spalte rechts neben den Lektionen,
  darunter oberhalb. Unter 640 px Breite die Startansicht.
  Ein Klick auf einen Wochentag im Kopf des Wochenrasters öffnet den Tag.
- Wochenende: Der Knopf «Sa/So» in der Wochenansicht blendet Samstag und Sonntag ein oder aus
  (Vorgabe aus, Wahl in `localStorage` `cb-weekend`). Ausgeblendet zeigt der Knopf die Anzahl
  Einträge und Geburtstage der ausgeblendeten Tage. Die Tagesansicht zeigt immer alle sieben Tage.
  Der Stundenplan-Editor hat denselben Knopf: Lektionen sind an jedem Wochentag möglich. Ein
  Samstag oder Sonntag mit Lektionen erscheint in Wochenansicht und Editor immer.
- Navigation: einfache Pfeile blättern eine Woche bzw. einen Tag, Doppelpfeile vier Wochen
  bzw. eine Woche; das Datumsfeld springt zu einem beliebigen Datum; «Heute» führt zurück.
- Box «Wichtige Termine» oben rechts, in beiden Ansichten, nur wenn es etwas zu zeigen gibt:
  wichtige Einträge (ab `visibleFrom`, bis sie vorbei oder als Aufgabe erledigt sind) und
  Geburtstage der nächsten 7 Tage (`WeekController::BIRTHDAY_DAYS`).
- Geburtstage: 29. Februar wird in Nicht-Schaltjahren am 28. Februar angezeigt.
- Gruppen: Zufallsgruppen für eine Klasse (Schüler mit dieser Stammklasse) oder Lerngruppe.
  Aufteilung nach Anzahl Gruppen oder nach Gruppengrösse (höchstens); die Gruppen
  unterscheiden sich höchstens um eine Person. Fehlende werden abgewählt. Rein im Browser:
  weder die Abwahl (sie käme einer Absenz gleich) noch die Gruppen werden gespeichert.
  Export: Word-Datei (`.docx`) wird im Browser ohne Bibliothek erzeugt (`createZip`,
  `createGroupsDocx` in `class-book.js`); PDF über den Druckdialog des Browsers mit eigenem
  Drucklayout (`html.cb-print-groups`, nur das Gruppenblatt, schwarz auf weiss).
- Sitzordnung: Klasse/Lerngruppe wählen, Plätze per Klick sperren, losen, Namen ziehen oder
  mit zwei Klicks setzen (auch aus «Ohne Platz» und dorthin zurück), speichern. PDF über den
  Druckdialog (`html.cb-print-seating`, A4 quer; `printSeating()` setzt das Seitenformat).
- Einstellungen: Schuljahr, Ferien, Lektionsraster, Fächer, Klassenräume (Raumeditor: Pulte
  aus der Leiste in den Raum ziehen oder anklicken, verschieben mit Zeiger oder Pfeiltasten,
  drehen, entfernen), Lerngruppen, Schülerinnen und Schüler, Stundenplan.

## Design

- Die Applikation heisst in der Oberfläche «ClassBook» (Wunsch von Marko): Titel im Kopf,
  Name des Inhaltselements und der Backend-Modulgruppe, Signatur der Erinnerungs-E-Mail
  (`MSC.classBook.title`).
- Design «Klar» (Variante A aus dem Design-Canvas «Unterrichtsbuch – 3 Designvarianten»):
  ruhig, helle Flächen, Schrift Inter, feine Linien, die Spalte von heute leicht hinterlegt.
  Eine Lektion trägt die Fachfarbe nur als Streifen am linken Rand, ohne farbige Fläche
  (Wunsch von Marko: sonst wird die Tabelle zu bunt). Die Art eines Eintrags zeigt ein Punkt,
  Aufgaben haben stattdessen die Checkbox.
- Hell und dunkel: Alle Farben sind Custom Properties auf `.cb-app`, das dunkle Thema
  (`.cb-app[data-theme="dark"]`) ersetzt nur die Werte. Neue Styles nie mit festen Farben,
  immer mit `var(--cb-…)`. Start mit der Systemeinstellung (`prefers-color-scheme`), der
  Knopf oben rechts schaltet um und merkt sich die Wahl in `localStorage` (`cb-theme`).
- Farben kräftig statt pastell (Wunsch von Marko), weil die Fachfarbe nur noch als Streifen
  erscheint. Im dunklen Thema wird der Streifen per `color-mix()` aufgehellt
  (`--cb-stripe-lighten`), damit auch Braun und Grau sichtbar bleiben.
- Fachfarben kommen aus einer festen Palette von 12 Tönen (`ResourceDefinitions::SUBJECT_COLORS`,
  Namen in `MSC.classBook.options.color`); der Validator lehnt andere Werte ab. Ältere, frei
  gewählte Farben bleiben gespeichert und werden angezeigt; beim Bearbeiten ist der
  ähnlichste Ton der Palette vorgewählt.
- Inter wird mit dem Bundle ausgeliefert (`@fontsource-variable/inter`, nur Latin und
  Latin Extended, nach `public/fonts/`). Es werden keine Schriften von fremden Servern geladen.
- Die App malt ihren eigenen Hintergrund. Der Hintergrund der umgebenden Seite gehört dem
  Theme der Website und wird nicht verändert.
- Sichtprüfung ohne Contao: gerendertes Template + Mock für `fetch` in einer statischen
  HTML-Datei, Screenshot mit `google-chrome --headless=new --screenshot`.

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
- [x] Etappe 5: Tagesansicht (Standard auf dem Handy), Box «Wichtige Termine» oben rechts,
      Geburtstage in der Box (7 Tage im Voraus) und am Tag selbst in Wochen- und Tagesansicht
- [x] Klassenräume mit Raumeditor, Sitzordnung losen/setzen/speichern/drucken, Geschlecht
      bei Schülerinnen und Schülern
- [x] Erinnerung per E-Mail für Einträge (Cronjob)
- [ ] Etappe 6: Lektionsänderungen und Hausaufgaben mit Fälligkeit

## Architektur (Ist-Zustand)

- `src/Controller/ContentElement/ClassBookController.php`: Inhaltselement `class_book`
  (Kategorie `miscellaneous`, Palette in `contao/dca/tl_content.php`, Name in `CTE.class_book`). Ohne
  angemeldetes Mitglied nur ein Hinweis. Sonst Übergabe von Token, API-URLs und Mitglied als
  JSON im Attribut `data-class-book`.
- `contao/templates/twig/content_element/class_book.html.twig`: enthält das Vue-Template.
  Vue-Delimiter sind `[[ ]]`, Texte kommen über `MSC.classBook.*` (Domain `contao_default`).
- `assets/js/class-book.js`: Vue-App (globales `Vue`, kein Build-Schritt für den Code).
  `assets/styles/class-book.scss`: Styles, Präfix `cb-`.
- `src/Security/AccessTokenManager.php`: signiertes Token (HMAC mit `kernel.secret`, 12 h
  gültig), gebunden an Mitglied und Inhaltselement. `ApiAuthenticator` prüft Header
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
  und `/_class_book/api/entries/{save|done|copy|move|delete|open}`. Die Wochen-API liefert zusätzlich
  `entries` (Montag bis Sonntag) und `openCount`.
- Erinnerungen: `src/Cron/SendRemindersCron.php` (`#[AsCronJob('minutely')]`) ruft
  `ReminderService::sendDue()` auf (alle Mitglieder; Fälligkeit wird beim Lauf berechnet, nicht
  gespeichert). `ReminderMailer` (hinter `ReminderMailerInterface`, damit der Service ohne
  Contao testbar ist) sendet eine Text-E-Mail an `tl_member.email` in der Sprache des Mitglieds,
  Absender ist die Administrator-Adresse aus den Contao-Einstellungen. Pünktlich nur mit echtem
  Cronjob der Installation (`contao:cron` jede Minute); ohne ihn hängt der Versand von
  Seitenaufrufen ab.
- `BirthdayService::getBetween()` liefert Geburtstage eines Zeitraums. Die Wochen-API liefert
  `birthdays` (angezeigte Woche) und `important` (`entries`, `birthdays`; unabhängig von der
  angezeigten Woche, bezogen auf heute).
- Drag and Drop der Einträge: natives HTML5-DnD ohne Bibliothek (`drag` im App-State,
  `onDragStart`, `onDragOverSlot`, `onDragOverEntry`, `onDrop`). Der Eintragstext ist ein
  `span role="button"`, weil Firefox aus einem `<button>` heraus keinen Drag startet.
- Frontend: `view` ist `week`, `day`, `groups`, `seating` oder `settings`. Die Tagesansicht nutzt die Daten der
  geladenen Woche; `day` ist immer ein Tag dieser Woche.
- `MasterDataService::findBy()` nimmt Bedingungen als `[Feld, Operator, Wert]`; Felder und
  Operatoren werden gegen die Definition bzw. eine feste Liste geprüft.
- `src/Service/MasterData/ResourceDefinitions.php`: einzige Quelle für die Stammdaten-
  Ressourcen (Tabelle, Sortierung, Felder mit Typ und Regeln). Der Validator, der Service
  und das Frontend (über `schema` in der `list`-Antwort) richten sich danach. Eine neue
  Ressource braucht: Eintrag hier, DCA-Datei, Sprachdateien, Labels `MSC.classBook.*`.
- Felder mit `hidden` (z. B. `layout`, `seating`) erscheinen nicht in den generischen Listen
  und Formularen, ihr Wert bleibt beim Speichern über das Formular erhalten. Der Klassenraum
  hat im Formular zusätzlich den Raumeditor (`roomEditor`).
- `MasterDataService`: alle Abfragen mit `member = ?`. Referenzen werden nur auf eigene
  Datensätze akzeptiert. Löschen wird verweigert, solange eine Referenz auf den Datensatz
  zeigt (`RecordInUseException`). Feldtyp `references` liegt in einer Verknüpfungstabelle,
  `where` schränkt eine Referenz ein, `lockedWhenReferenced` sperrt ein Feld bei Verwendung.
- Speicherformate: Datum `YYYY-MM-DD`, Uhrzeit `HH:MM`, Farbe `#rrggbb` (jeweils varchar).
  Texte werden wie in Contao üblich HTML-kodiert gespeichert (`StringUtil::specialchars`)
  und für die API dekodiert (`StringUtil::decodeEntities`). Das gilt nur für Contao 5, siehe
  Konventionen zu Contao 6.
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
- Contao 5 verwendet Input-Encoding: DB-Werte sind bereits HTML-kodiert. Bei Ausgabe über
  Twig/JS auf Doppelkodierung achten.
- Ab Contao 6 ist das Input-Encoding Geschichte: Werte stehen roh in der Datenbank, Twig
  übernimmt das Escaping bei der Ausgabe. Folgen für dieses Bundle beim Umstieg auf Contao 6:
  `MasterDataService` darf Texte dann nicht mehr mit `StringUtil::specialchars()` kodieren
  und nicht mehr mit `decodeEntities()` dekodieren (sonst zeigt das Backend `&amp;` statt
  `&`), und bereits gespeicherte Texte (`string`- und `text`-Felder aller `tl_cb_*`-Tabellen)
  müssen einmalig per Migration dekodiert werden. Solange Contao 5.7 unterstützt wird,
  braucht es dafür eine Weiche nach Contao-Version.
- Scripts und Stylesheets nur über Webpack + Symfony Encore.
- Coding Standard: `composer cs-fixer` (Contao ECS, Konfiguration in `tools/ecs`).
- Kleine, in sich abgeschlossene Commits mit englischer Commit-Message.
- Keine Tags und keine Releases anlegen, solange Marko das nicht ausdrücklich verlangt.
- Code, Bezeichner und Kommentare englisch; Oberfläche deutsch (Übersetzungen über
  Contao-Sprachdateien, damit weitere Sprachen möglich bleiben).
