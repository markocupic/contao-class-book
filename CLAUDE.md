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
- Funktioniert nur im angemeldeten Zustand (Contao-Mitglied / Frontend-User). Ohne Anmeldung
  zeigt das Inhaltselement sein eigenes Login-Formular, die Seite selbst ist nicht geschützt.
- Hauptsächlich eine Frontend-Applikation: ein Inhaltselement (bis 21.11.2026 ein
  Frontend-Modul, auf Wunsch von Marko umgestellt), Vue-Code im Twig-Template
  (keine `.vue`-Komponenten), Assets über Webpack + Symfony Encore.
- Ajax über eigene API-Routen mit signiertem Zugangs-Token, das bei jedem Seitenaufruf
  neu erzeugt wird (gleiches Muster wie im resource-booking-bundle).
- Mandantenfähig von Anfang an: Jeder Datensatz gehört einem Mitglied (`member`).
  Jede Abfrage und jede Schreiboperation filtert bzw. prüft auf das angemeldete Mitglied.
  Am Anfang gibt es nur einen Nutzer, weitere Lehrpersonen sollen ohne Umbau möglich sein.
  Einzige Ausnahme ist die Freigabe (siehe «ClassBook teilen»): Dann ist das Mitglied der
  Daten der Besitzer und nicht die angemeldete Person.
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
| `tl_cb_group` | Stammklasse oder Lerngruppe: Bezeichnung, Art (`class`/`group`), Schuljahr, Klassenraum (Hauptraum), Sitzordnungen (`seating`, JSON, eine pro Klassenraum) |
| `tl_cb_student` | Schüler/in: Nachname, Vorname, Geburtsdatum (optional), Geschlecht (optional, `f`/`m`), Stammklasse |
| `tl_cb_student_group` | Verknüpfung Schüler/in ↔ weitere Lerngruppen |
| `tl_cb_lesson` | Stundenplan-Lektion: Schuljahr, Wochentag, Lektion, Fach, Klasse/Lerngruppe, Raum |
| `tl_cb_lesson_change` | Lektionsänderung an einem Datum: Ausfall, Verschiebung, Raumwechsel |
| `tl_cb_entry` | Eintrag (Task), siehe unten |
| `tl_cb_share` | Freigabe: `member` (Besitzer), `guest` (Mitglied, 0 = wartet auf ein Konto), `guestEmail`, `access` (`read`/`write`); keine Ressource in `ResourceDefinitions` |

### Stammklasse und Lerngruppen

- Eine Schülerin oder ein Schüler gehört zu genau einer Stammklasse (`homeClass`, Gruppe mit
  Art `class`) und zu beliebig vielen weiteren Lerngruppen (Art `group`, über
  `tl_cb_student_group`).
- Die Art einer Gruppe kann nicht geändert und die Gruppe nicht gelöscht werden, solange
  ihr jemand zugeordnet ist.
- Im Stundenplan kann einer Lektion eine Stammklasse oder eine Lerngruppe zugeordnet werden.
- Raum einer Lektion (`tl_cb_lesson.room`, Text, 64 Zeichen): Der Dialog bietet die erfassten
  Klassenräume als Auswahl an (Wunsch von Marko) und speichert deren Bezeichnung, keine
  Referenz. Wird ein Klassenraum umbenannt, behalten bestehende Lektionen den alten Namen;
  ein früher frei eingegebener Raum bleibt als zusätzliche Option erhalten. Ohne Raum
  übernimmt die Lektion beim Wählen der Klasse/Lerngruppe deren Klassenraum.

### Klassenraum und Sitzordnung

- Raumplan (`tl_cb_classroom.layout`): `{desks: [{id, type, x, y, r}]}`. Raster 40 × 40 Zellen, quadratisch (Wunsch von Marko, vorher 40 × 26)
  (`ROOM` in `class-book.js`), ein Sitzplatz belegt 4 × 3 Zellen. Pulttypen (`DESK_TYPES`):
  `teacher` (kein Sitzplatz), `single`, `double`, `quad`; `r` ist 0 oder 90. Pulte überlappen
  sich nicht. Die Wandtafel ist oben.
- Ein Sitzplatz hat den Schlüssel `<Pult-ID>:<Nummer>`. Pult-IDs bleiben beim Verschieben
  gleich, damit gespeicherte Sitzordnungen gültig bleiben.
- Sitzordnung (`tl_cb_group.seating`): eine pro Klasse/Lerngruppe **und Klassenraum**, weil
  eine Lerngruppe in mehreren Schulzimmern Unterricht haben kann (Wunsch von Marko, vorher
  genau eine pro Gruppe): `{rooms: {Klassenraum-ID: {seats: {Platz: Schüler-ID}, blocked:
  [Platz], fixed: [Platz]}}}`. Das alte Format ohne `rooms` wird weiter gelesen und gehört
  zum Hauptraum (`readSeatingRooms`); gespeichert wird immer im neuen. Beim Laden verwirft
  das Frontend Plätze und Schüler, die es nicht mehr gibt (`parseSeating`).
- Der Klassenraum der Gruppe (`tl_cb_group.classroom`) ist ihr Hauptraum: Vorgabe für neue
  Lektionen und für die Sitzordnung. In der Ansicht «Sitzordnung» wählt man nach der Gruppe
  den Klassenraum (`seatTool.roomId`, `seatRoomOptions`): zuerst «Räume dieser Gruppe»
  (Hauptraum, Räume ihrer Lektionen im Stundenplan über die Bezeichnung, Räume mit
  gespeicherter Sitzordnung), dann alle anderen. Speichern ändert nur die Sitzordnung des
  gewählten Raums.
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
- Schuljahre überschneiden sich nicht (Entscheid von Marko): `noOverlap` in
  `ResourceDefinitions`, `MasterDataService::save()` lehnt ein Schuljahr ab, das mit einem
  anderen des Mitglieds auch nur einen Tag teilt (Fehler `overlap` am Feld `startDate`). Ein
  Datum gehört so zu höchstens einem Schuljahr. Lücken zwischen Schuljahren sind erlaubt.
  Schuljahre, die sich von früher her überschneiden, bleiben stehen, bis man eines ändert.
  Verworfen: ein «aktives Schuljahr» per Checkbox (die Anzeige bleibt beim Datum); offen:
  «Schuljahr mit allen Daten löschen».
- Welche Lektionen an einem Datum gelten: Schuljahr, dessen Zeitraum das Datum enthält.
  Liegt das Datum in Ferien (`tl_cb_holiday`), entfallen alle Lektionen des Tages.
- Fächer, Lektionen des Rasters, Klassen/Lerngruppen und Schuljahre können nicht gelöscht
  werden, solange der Stundenplan sie verwendet.
- Die Oberfläche bietet beim Erfassen nur Klassen/Lerngruppen des gewählten Schuljahrs an;
  serverseitig wird das nicht erzwungen.

### Eintrag (`tl_cb_entry`)

Notizen, Aufgaben, Termine und Unterrichtsbemerkungen sind derselbe Datensatz.

- `type`: `remark`, `note`, `task`, `appointment`, `exam` (Prüfung, roter Punkt),
  `parentMeeting` (Elterngespräch, oranger Punkt; Wunsch von Marko). Hausaufgabe `homework`
  folgt in Etappe 6. Eine neue Art braucht: `ResourceDefinitions`, DCA, Sprachdateien (2 ×),
  Punktfarbe in SCSS (`--cb-dot-…`, `.cb-entry--<type>`) und `ReminderMailer::TYPE_COLORS`.
  Die Reihenfolge im Auswahlfeld kommt aus `MSC.classBook.options.type`; die erste Art
  (Unterrichtsbemerkung) ist die Vorgabe für neue Einträge.
- `entryDate`: Tag des Eintrags (immer gesetzt)
- Bindung an das Datum, nicht an die Stundenplan-Lektion: entweder an eine Lektion des
  Rasters (`period`) oder an den ganzen Tag (`period = 0`), dann mit optionaler Uhrzeit
  (`entryTime`). Ändert sich der Stundenplan, bleiben alte Einträge an ihrem Platz.
- `location`: Ort, nur bei Terminen (`appointment`); bei allen anderen Arten leer
  (`EntryService::save()` erzwingt das). Erscheint unter dem Text des Eintrags, in der Box
  «Wichtige Termine» in Klammern und in der Erinnerungs-E-Mail.
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
  über dem Raster. Zeilenhöhe (Wunsch von Marko): Zeilen mit Inhalt (mindestens eine Lektion
  oder ein Eintrag in der Zeile) haben eine Mindesthöhe von 5 rem, «Ganzer Tag» 3.25 rem;
  Zeilen ganz ohne Inhalt (`.cb-timetable__row--empty`, `row.empty` bzw. `dayRowEmpty`) sind
  nur 1.75 rem hoch, Lektionsnummer und Zeit stehen dort auf einer Zeile.
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
- Navigation: Die Pfeile blättern eine Woche bzw. einen Tag; das Datumsfeld springt zu einem
  beliebigen Datum; «Heute» führt zurück. Pfeil gedrückt halten (Wunsch von Marko, ersetzt die
  früheren Doppelpfeile): nach 450 ms laufen die Wochen bzw. Tage durch, erst alle 220 ms, ab
  8 Schritten alle 90 ms (`HOLD_*`, `onHoldStart`, `onHoldEnd`). Dabei ändern nur die Angabe
  zur Woche und das Datumsfeld (`hold`, `navDate`, `navWeek`, KW im Browser berechnet mit
  `isoWeek`); geladen wird erst beim Loslassen.
  Die Angabe zur Woche bzw. zum Tag («KW 47 · 16.–22. November 2026») steht immer auf einer
  eigenen Zeile unter den Knöpfen (`.cb-nav__label`, Wunsch von Marko).
  Wischen (Wunsch von Marko): ein horizontaler Swipe von mindestens 5 cm (`SWIPE_DISTANCE`,
  189 CSS-Pixel) blättert in der Wochenansicht eine Woche und in der Tagesansicht einen Tag,
  nach links vorwärts, nach rechts zurück (`onSwipeStart`, `onSwipeEnd`). Nur klar seitwärts
  (höchstens halb so viel Höhe wie Breite), nicht aus Formularfeldern, nicht bei offenem
  Dialog und nicht, während ein Eintrag verschoben wird. Scrollt das Wochenraster seitwärts
  (schmaler Bildschirm), zählt der Swipe nur in die Richtung, in der es am Anschlag ist.
- Neu laden (Wunsch von Marko): Die App merkt sich Ansicht, Unterreiter der Einstellungen
  und Datum (Woche bzw. Tag) im `sessionStorage` des Tabs (`cb-state`, `readState()`, `$watch`
  nach dem ersten Laden) und stellt sie nach einem Reload wieder her. Nach dem Anmelden
  startet sie immer mit der Woche (Handy: Tag): Die Anmeldeseite (`data-cb-login`) leert den
  Eintrag (`clearState()`). Ein neuer Tab beginnt ebenfalls mit der Woche. `cb-state` enthält
  auch `owner`, wenn ein geteiltes ClassBook offen ist (siehe «ClassBook teilen»).
- Aktualisieren im Hintergrund (Wunsch von Marko): Solange jemand aktiv ist, lädt die App in
  der Wochen- und Tagesansicht die angezeigte Woche einmal pro Minute neu
  (`REFRESH_INTERVAL`, `startRefresh()`, `refreshWeek()`, `applyWeek()`), ohne Ladeanzeige.
  So erscheinen Änderungen von einem anderen Gerät oder aus einem geteilten ClassBook ohne
  Reload. Aktiv heisst: Zeiger, Tastatur, Rad oder Touch in den letzten 5 Minuten
  (`REFRESH_IDLE`); danach und in einem Tab im Hintergrund gibt es keine Requests. Die erste
  Eingabe nach einer Pause und die Rückkehr in den Tab laden sofort. Nie unter den Händen
  (`canRefresh()`): nicht bei offenem Dialog (Eintrag, unerledigte Aufgaben, Löschen
  bestätigen), offenem Menü, laufendem Request, während ein Eintrag verschoben oder ein
  Pfeil gehalten wird; die Antwort wird verworfen, wenn sich Ansicht, Woche oder Tag
  inzwischen geändert haben. Fehler bleiben still und werden beim nächsten Takt wieder
  versucht; nur 401 zeigt die Meldung zur Sitzung und beendet das Aktualisieren.
  Einstellungen, Gruppen und Sitzordnung aktualisieren nicht (ungespeicherte Eingaben).
- Box «Wichtige Termine» oben rechts, in beiden Ansichten, nur wenn es etwas zu zeigen gibt:
  wichtige Einträge (ab `visibleFrom`, bis sie vorbei oder als Aufgabe erledigt sind) und
  Geburtstage der nächsten 7 Tage (`WeekController::BIRTHDAY_DAYS`).
  Ab 2000 px Fensterbreite steht die Box rechts neben dem Wochenraster bzw. der Tagesliste
  (`.cb-view--important`, CSS-Grid, die Box bleibt beim Scrollen sichtbar), darunter wie bisher
  oben rechts über dem Raster.
- Geburtstage: 29. Februar wird in Nicht-Schaltjahren am 28. Februar angezeigt. Das
  Geburtsdatum ist freiwillig (Wunsch von Marko): ohne Datum gibt es für diese Person keinen
  Hinweis (`BirthdayService` überspringt sie). Das Formular sagt das unter dem Feld
  (`labels.fieldHints`, ebenso beim Geschlecht).
- Gruppen: Zufallsgruppen für eine Klasse (Schüler mit dieser Stammklasse) oder Lerngruppe.
  Aufteilung nach Anzahl Gruppen oder nach Gruppengrösse (höchstens); die Gruppen
  unterscheiden sich höchstens um eine Person. Fehlende werden abgewählt. Rein im Browser:
  weder die Abwahl (sie käme einer Absenz gleich) noch die Gruppen werden gespeichert.
  Export: Word-Datei (`.docx`) wird im Browser ohne Bibliothek erzeugt (`createZip`,
  `createGroupsDocx` in `class-book.js`); PDF über den Druckdialog des Browsers mit eigenem
  Drucklayout (`html.cb-print-groups`, nur das Gruppenblatt, schwarz auf weiss).
- Sitzordnung, «Ohne Platz» (Wunsch von Marko): ab 75 rem Fensterbreite stehen die Namen
  ohne Platz rechts neben dem Zimmerplan (`.cb-seating__layout`, Grid; die Box bleibt beim
  Scrollen sichtbar), darunter wie bisher unter dem Plan. Gedruckt wird nur das Blatt.
- Sitzordnung: Klasse/Lerngruppe und Klassenraum wählen, Plätze per Klick sperren, losen, Namen ziehen oder
  mit zwei Klicks setzen (auch aus «Ohne Platz» und dorthin zurück), speichern. PDF über den
  Druckdialog (`html.cb-print-seating`, A4 hoch; `printSeating()` setzt das Seitenformat).
- Listen in den Einstellungen sortieren (Wunsch von Marko, für alle Listen, gedacht für
  Schülerinnen und Schüler): Klick auf einen Spaltentitel sortiert aufsteigend, der zweite
  absteigend, der dritte hebt die Spalte auf. Jede weitere angeklickte Spalte ist eine weitere
  Stufe in der Reihenfolge der Klicks (Pfeil und Stufennummer im Titel, «Sortierung aufheben»
  rechts). Rein im Browser (`sortLevels`, `sortedRecords`, `toggleSort`, `compareSortKeys`):
  Zahlen als Zahlen, Daten wie gespeichert, alles andere wie angezeigt, mit `Intl.Collator`
  (`numeric`, «9.2» vor «10.1»); leere Werte immer zuletzt. Ohne Sortierung gilt die
  Reihenfolge des Servers (`orderBy` in `ResourceDefinitions`; Schuljahre und Ferien mit dem
  spätesten Beginn zuerst, Ferien auf Wunsch von Marko). Die Wahl pro Liste steht in `localStorage` (`cb-sort`).
- Liste der Schülerinnen und Schüler (Wunsch von Marko): Filter «Stammklasse anzeigen»
  (`studentFilter`, 0 = alle; nicht gespeichert) und eine Checkbox pro Zeile samt «alle
  angezeigten» im Kopf (`selected`). Die angehakten, angezeigten Personen kommen mit
  «Hinzufügen» in die gewählte Lerngruppe oder mit «Entfernen» wieder heraus
  (`assignGroup()`; jede Person wird einzeln über die Stammdaten-API gespeichert, wer schon
  drin bzw. draussen ist, wird übersprungen). Nur für Lerngruppen: Die Stammklasse wechselt
  man weiterhin im Formular der Person, weil jede genau eine hat.
- Formular einer Lerngruppe (Wunsch von Marko, nur bei Art «Lerngruppe»): unter den Feldern
  die Mitglieder als Tabelle (Nachname, Vorname, Geschlecht, Stammklasse) mit einem Icon zum
  Entfernen (`.cb-members`, `members`, `memberRows`), mehrstufig sortierbar wie die Listen
  (`sortState(name, MEMBER_LIST)`, Wahl in `cb-sort` unter `groupMembers`; ohne Wahl nach Name). Darüber ein Suchfeld: Jedes getippte Wort muss
  im Namen oder in der Stammklasse vorkommen («9.2» findet die ganze Klasse); die Treffer
  (höchstens 8, `MEMBER_MATCHES`, ohne bisherige Mitglieder) stehen mit Stammklasse darunter,
  Klick oder Enter nimmt die Person in die Tabelle auf, Pfeiltasten wählen, «Alle N
  hinzufügen» nimmt alle Treffer. Zuerst waren es Checkboxen für alle; das war bei vielen
  Namen unübersichtlich. «Speichern» speichert zuerst die Gruppe, dann jede Person, deren
  Zugehörigkeit sich geändert hat (`saveRecord()`, `saveStudentGroup()`); das geht auch bei
  einer neuen Gruppe. Stammklassen haben die Auswahl nicht.
- Schülerinnen und Schüler hinzufügen und bearbeiten (Wunsch von Marko): in einem Dialog
  statt im Formular unter der Liste (`formModal`, nur für `student`; dasselbe Formular mit
  `.cb-overlay` und `.cb-dialog--form`, schliesst mit X oder Escape, kein «Abbrechen»). Der
  Knopf «Schülerin oder Schüler hinzufügen» steht über und unter der Liste. Die Knöpfe der
  Zuteilung heissen «Zu LG hinzufügen» und «Aus LG entfernen» (LG = Lerngruppe, im Label des
  Auswahlfelds erklärt), damit sie sich vom Hinzufügen einer Person unterscheiden. Die anderen Listen behalten das
  Formular unter der Liste.
- Listen auf schmalen Bildschirmen (Wunsch von Marko): Die Tabelle steht in einem Rahmen
  (`.cb-table-wrap`), der seitwärts scrollt, statt die Seite zu verbreitern; die Spalte mit den
  Knöpfen bleibt rechts stehen (`.cb-table__actions`, `position: sticky`).
- Bearbeiten und Löschen sind überall Knöpfe nur mit Icon (`.cb-button--icon`, Icons `edit`,
  `delete`; Name in `title` und `aria-label`; Wunsch von Marko): in den Listen der
  Einstellungen und «Löschen» in den Dialogen von Eintrag und Lektion. «Sortierung aufheben»
  ist das Icon `reset`. Ausnahme: «Konto löschen» bleibt Text, ebenfalls
  gefüllt rot (`.cb-button--danger-solid`, wie «Zwei-Faktor-Authentifizierung ausschalten»). Löschknöpfe sind rot mit
  weissem Icon (`.cb-button--delete`, `--cb-delete`, in beiden Designs gleich; Wunsch von
  Marko), ebenso «Löschen» im Bestätigungsdialog (`.cb-button--danger-solid`).
- Einstellungen: Schuljahr, Ferien, Lektionsraster, Fächer, Klassenräume (Raumeditor: Pulte
  aus der Leiste in den Raum ziehen oder anklicken, verschieben mit Zeiger oder Pfeiltasten,
  drehen, entfernen), Lerngruppen, Schülerinnen und Schüler, Stundenplan.

## Design

- Die Applikation heisst in der Oberfläche «ClassBook» (Wunsch von Marko): Titel im Kopf,
  Name des Inhaltselements und der Backend-Modulgruppe, Signatur der Erinnerungs-E-Mail
  (`MSC.classBook.title`).
- Logo (Wahl von Marko: Vorschlag 4 «Geometrisch» aus dem Canvas «ClassBook – Logo-Vorschläge»):
  blaues Quadrat (`#2563eb`, runde Ecken) mit den Initialen CB aus einer runden weissen Linie,
  ohne Schrift. Quelle `assets/images/class-book-logo.svg`, dazu `class-book-logo.png`
  (192 px, für E-Mails); Encore kopiert beide ohne Hash nach `public/images/`. Im Template
  steht das SVG inline (`{% set logo %}`, Klasse `.cb-logo`): in der Karte von Login,
  Registrierung und 2FA, im Kopf der App neben dem Titel und im Footer der App
  (`.cb-footer`). In den E-Mails (Erinnerung, Aktivierung) bettet `LogoEmbedder` das PNG ein
  (`cid:class-book-logo`), damit Mailprogramme nichts von der Website laden müssen; die
  Templates erhalten `logo` (Content-ID oder `null`). Ändert sich das Logo: SVG im Template
  und in `assets/images` anpassen, PNG neu erzeugen, `npm run build`.
- Favicon (Wunsch von Marko): `class-book-favicon.ico` (16/32/48/64 px) und
  `class-book-touch-icon.png` (180 px, ohne runde Ecken, weil iOS selbst rundet) in
  `assets/images`. Im Standalone-Modus setzt das Template die `<link rel="icon">`-Tags (ICO,
  SVG, Apple-Touch-Icon) über `{% add … to head %}` in den `<head>`; dafür braucht es keinen
  Event-Subscriber. Ohne Standalone bleiben die Icons der umgebenden Website.
- Design «Klar» (Variante A aus dem Design-Canvas «Unterrichtsbuch – 3 Designvarianten»):
  ruhig, helle Flächen, Schrift Inter, feine Linien, die Spalte von heute leicht hinterlegt.
  Eine Lektion trägt die Fachfarbe nur als Streifen am linken Rand, ohne farbige Fläche
  (Wunsch von Marko: sonst wird die Tabelle zu bunt). Die Art eines Eintrags zeigt ein Punkt,
  Aufgaben haben stattdessen die Checkbox.
- Hell und dunkel: Alle Farben sind Custom Properties auf `.cb-app`, das dunkle Thema
  (`.cb-app[data-theme="dark"]`) ersetzt nur die Werte. Neue Styles nie mit festen Farben,
  immer mit `var(--cb-…)`. Start mit der Systemeinstellung (`prefers-color-scheme`), der
  Eintrag im Benutzermenü schaltet um und merkt sich die Wahl in `localStorage` (`cb-theme`).
- Farben kräftig statt pastell (Wunsch von Marko), weil die Fachfarbe nur noch als Streifen
  erscheint. Im dunklen Thema wird der Streifen per `color-mix()` aufgehellt
  (`--cb-stripe-lighten`), damit auch Braun und Grau sichtbar bleiben.
- Wichtige Einträge (`.cb-entry--important`) haben einen roten Hintergrund
  (`--cb-important`; Wunsch von Marko, vorher Gelb) und fette Schrift: im hellen Design zart
  (`#fcdad7`), im dunklen ein volles Rot (`#b91c1c`) mit weisser Schrift; dort sind auch Zeit,
  Ort, Kopierknopf und Checkbox weiss, und der Punkt hat einen weissen Ring.
- Dunkles Design mit vollen Farben (Wunsch von Marko): gefüllte Knöpfe (`.cb-button--primary`)
  sind dort Blau `#2563eb` mit weisser Schrift (`--cb-primary`, `--cb-on-primary`), nicht mehr
  das aufgehellte `--cb-accent`; das bleibt die Farbe für Text und Links auf dunklem Grund.
  Neue gefüllte Flächen nehmen `--cb-primary`, nicht `--cb-accent`. Eingeschaltete Knöpfe (aktiver
  Punkt der Hauptnavigation, «Sa/So») nehmen `--cb-active`/`--cb-on-active`: hell zartblau
  mit blauer Schrift, dunkel das volle Blau mit weisser Schrift. Gefüllte Knöpfe
  in Formularen sind nur während eines Requests gesperrt (`:disabled="loading"`), nicht bei
  leeren Feldern: Die Felder sind `required`, der Browser meldet das fehlende Feld. Ein
  gesperrter Knopf ist blass (`opacity`) und wirkte im dunklen Design wie eine gedämpfte
  Farbe. Gesperrt bleiben Knöpfe, die ohne Auswahl nichts tun können (Gruppen bilden,
  Zuteilung zu einer Lerngruppe). Gelb bleibt den Hinweisen vorbehalten (`--cb-notice`, z. B. unerledigte
  Aufgaben).
- Fachfarben kommen aus einer festen Palette von 12 Tönen (`ResourceDefinitions::SUBJECT_COLORS`,
  Namen in `MSC.classBook.options.color`); der Validator lehnt andere Werte ab. Ältere, frei
  gewählte Farben bleiben gespeichert und werden angezeigt; beim Bearbeiten ist der
  ähnlichste Ton der Palette vorgewählt.
- Fliessende Grössen mit `clamp()` (Wunsch von Marko), als Custom Properties auf `.cb-app`:
  `--cb-rem` ersetzt `1rem` in allen Schriftgrössen (15 px auf dem Handy bis 17 px auf grossen
  Bildschirmen; Schriftgrössen deshalb als `calc(<Faktor> * var(--cb-rem))`, nie in `rem`),
  `--cb-pad`/`--cb-pad-top`/`--cb-pad-bottom` für den Rand der App, `--cb-gap` für den Abstand
  zwischen den Blöcken einer Ansicht, `--cb-card` für den Innenabstand von Formularen und
  Dialogen. Abstände innerhalb von Bedienelementen bleiben fest in `rem`.
- Icons: Font Awesome Free (Wunsch von Marko), aber ohne Font Awesome einzubinden (kein CSS,
  keine Webfonts, kein JS von dort). `npm run icons` (`tools/icons/build.js`) liest die in der
  Liste `ICONS` genannten SVG-Dateien aus dem devDependency `@fortawesome/fontawesome-free` und
  schreibt nur deren Pfade nach `assets/js/class-book-icons.js` (`window.ClassBookIcons`, mit
  Lizenzhinweis CC BY 4.0; Ergebnis committen). Im Template `<cb-icon name="…"></cb-icon>`
  (Vue-Komponente, Farbe = Textfarbe). Neues Icon: Liste ergänzen, `npm run icons`,
  `npm run build`. Keine handgezeichneten SVGs mehr im Template.
- Dialoge (Eintrag, Lektion des Stundenplans, Schülerin/Schüler, Liste der unerledigten Aufgaben) schliessen nur mit der Escape-Taste oder
  dem X-Knopf oben rechts (Wunsch von Marko): kein Schliessen durch Klick neben den Dialog,
  kein «Abbrechen»-Knopf. Speichern und Löschen schliessen den Dialog weiterhin. Beim Öffnen
  steht der Cursor im ersten Formularfeld (`focusFirstField()`, Wunsch von Marko); im Dialog
  des Eintrags im Textfeld, weil man dort sofort schreibt.
- Löschen bestätigen (Wunsch von Marko): immer in einem eigenen Dialog, nie in der Zeile oder
  im Formular. Ein Dialog für alles (`confirm`, `askDelete(name, run)`, `confirmYes()`,
  `.cb-overlay--confirm` über den anderen Dialogen): Listen der Einstellungen, Eintrag,
  Lektion. Frage «‹Name› wirklich löschen?» (`recordName()`, bei Einträgen der Text, gekürzt
  auf 60 Zeichen; ohne Namen «Wirklich löschen?»), Knöpfe «Abbrechen» (hat den Fokus, damit
  Enter nicht löscht) und «Löschen»; Escape und X brechen ab. «Konto löschen» hat weiterhin
  sein eigenes Formular mit Passwort.
- Kleine Bildschirme (unter 48 rem, Wunsch von Marko): Die Hauptnavigation (Woche, Tag,
  Gruppen, Sitzordnung, Einstellungen) und das Benutzermenü (Name, Sicherheit, Design,
  Abmelden; `.cb-tabs__user`, unten im Panel) sind ein Off-Canvas-Panel von links
  (`.cb-tabs--open`, `navOpen`, Abdunkelung `.cb-nav-backdrop`). Im Kopf stehen nur noch Logo
  mit Name links und der Menü-Knopf rechts (`.cb-nav-toggle`); das User-Icon entfällt dort.
  Das Panel schliesst mit dem X, mit Escape, mit einem Klick daneben und nach der Wahl eines
  Punktes; geschlossen ist es per `visibility: hidden` auch für die Tastatur weg. Die zweite
  Ebene (Unterreiter der Einstellungen, `.cb-subtabs`) bleibt einzeilig und scrollt
  horizontal mit schmalem Scrollbalken. Die Icons der Navigation (`week`, `day`, `groups`,
  `seating`, `settings`, `menu`) erscheinen nur im Panel.
- Kopfzeile der App (`.cb-header`, Wunsch von Marko): hebt sich vom Inhalt ab, mit feiner
  Linie darunter (`--cb-line`) und 1.5 × `--cb-gap` Abstand zur Ansicht. Die Punkte der
  Hauptnavigation (`.cb-tab`) haben die volle Textfarbe statt der gedämpften (Wunsch von
  Marko); der aktive Punkt ist blau hinterlegt. Die Unterreiter der Einstellungen bleiben
  gedämpft.
- Einstellungen → Sicherheit (Wunsch von Marko): je eine Karte für Benutzername,
  E-Mail-Adresse, Zwei-Faktor-Authentifizierung und «Konto löschen» (`.cb-security__card`, Titel in der
  Karte, Zustand der 2FA als Pille, Teile mit Linie getrennt: `.cb-security__part`). Die
  Karten stehen nebeneinander, wo Platz ist, sonst untereinander (`.cb-security__grid`:
  höchstens 3 CSS-Spalten ab 21 rem statt Grid, damit unter kurzen Karten keine Lücke entsteht, wenn
  daneben eine hohe steht; die Karten füllen Spalte um Spalte); «Konto löschen» hat einen
  roten Rand, «ClassBook teilen» steht davor.
- Benutzermenü oben rechts (User-Icon, Knopf im Blau des Logos `--cb-brand`, weisses Icon;
  Wunsch von Marko): Name, «Sicherheit», «Dunkles Design» bzw. «Helles
  Design» (Wunsch von Marko: der Knopf stand vorher eigenständig in der Kopfzeile), «Abmelden».
- Inter wird mit dem Bundle ausgeliefert (`@fontsource-variable/inter`, nur Latin und
  Latin Extended, nach `public/fonts/`). Es werden keine Schriften von fremden Servern geladen.
- Standalone (Checkbox `cbStandalone` am Inhaltselement, Wunsch von Marko): Für eine Seite mit
  leerem Seitenlayout. Ein Inline-Script im `<head>` setzt vor dem ersten Zeichnen
  `html.cb-standalone` und `data-cb-theme`; das Stylesheet nimmt dem `body` Rand und
  Hintergrund, färbt die Seite wie die App (hell/dunkel, `$cb-bg-light`/`$cb-bg-dark`) und
  lässt die App die ganze Höhe füllen (`.cb-app--standalone`, keine runden Ecken). Kopf- und
  Fusszeile des Themes werden nicht versteckt, das ist Sache des Seitenlayouts.
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
- [x] Login-Formular, Abmelden und 2FA-Einrichtung im Inhaltselement
- [x] Registrierung für Lehrpersonen mit Aktivierungslink und Domainrestriktion
- [x] Konto löschen mit allen Daten
- [x] E-Mail-Adresse ändern mit Bestätigungslink
- [x] Impressum, Nutzungsbedingungen, Datenschutzerklärung und Zustimmung bei der Registrierung
- [x] ClassBook teilen: Freigabe an andere Mitglieder (lesen oder bearbeiten), Wechsel im Benutzermenü
- [ ] Etappe 6: Lektionsänderungen und Hausaufgaben mit Fälligkeit

## Architektur (Ist-Zustand)

- `src/Controller/ContentElement/ClassBookController.php`: Inhaltselement `class_book`
  (Kategorie `miscellaneous`, Palette in `contao/dca/tl_content.php`, Name in `CTE.class_book`). Ohne
  angemeldetes Mitglied nur ein Hinweis. Sonst Übergabe von Token, API-URLs und Mitglied als
  JSON im Attribut `data-class-book`.
- Anmeldung im Element (Wunsch von Marko): `state` ist `login`, `two_factor`, `app` oder
  `backend` (Platzhalter im Backend). Das Login-Formular hat dieselben Felder wie das
  Login-Element des Contao-Cores (`FORM_SUBMIT = tl_login_<id>`, `username`, `password`,
  `REQUEST_TOKEN`, `_target_path`; zweiter Schritt `verify`, `trusted`) und wird vom Login von
  Contao verarbeitet. Es gibt keine eigene Passwortprüfung. Vorlage dafür:
  `Contao\CoreBundle\Controller\ContentElement\LoginController`. Abmelden über die
  Logout-URL von Contao (`urls.logout`). Kein «Passwort vergessen», kein «Angemeldet bleiben».
- Registrierung für Lehrpersonen (Wunsch von Marko), nur wenn am Inhaltselement eingeschaltet
  (`cbRegistration`): Link unter dem Login-Formular, Formular in derselben Karte
  (`?register=1`, `registration.view` ist `login`, `form`, `sent`, `activated` oder `invalid`).
  Felder: Vorname, Nachname, E-Mail-Adresse, Passwort (mindestens 8 Zeichen) zweimal. Der
  Controller des Elements verarbeitet das Formular (`FORM_SUBMIT = cb_register_<id>`),
  `RegistrationValidator` prüft, `RegistrationService` legt das Mitglied an: `username` =
  generierter Name (siehe Benutzername), `login = 1`, `disable = 1`, keine Mitgliedergruppen. Aktivierung über das
  Double-Opt-in von Contao (`contao.opt_in`, Präfix `reg` wie das Registrierungsmodul des
  Cores, damit der Core nie aktivierte Registrierungen aufräumt; Link = Seite + `?token=reg-…`,
  24 Stunden gültig). Die E-Mail sendet `RegistrationMailer` (Wunsch von Marko: im Design der
  Erinnerung, Anrede «Hallo <Vorname>»): HTML-Fassung
  `contao/templates/twig/email/class_book_activation.html.twig` (überschreibbar) mit Knopf
  «Konto aktivieren» und dem Link als Text, dazu eine Text-Fassung. Betreff und Text werden
  wie bei Contao im Opt-in-Datensatz (`tl_opt_in`) festgehalten.
- Domainrestriktion: `cbRegistrationDomains` am Inhaltselement, Domains durch Komma, Leerzeichen
  oder Zeilenumbruch getrennt, mit oder ohne `@` (`EmailDomainRestriction`). Die Domain muss
  genau stimmen (keine Subdomains). Leer: alle dürfen sich registrieren.
- Die Registrierung verrät nicht, ob eine Adresse schon ein Konto hat: Die Meldung nach dem
  Absenden ist immer dieselbe. Bekannte aktive Adresse: nichts passiert. Bekannte, noch nicht
  aktivierte Adresse: der Link wird erneut gesendet, Name und Passwort der ersten Registrierung
  bleiben (sonst könnte jemand das Passwort eines fremden Kontos setzen). Schutz vor
  Missbrauch: unsichtbares Feld `website` (Honeypot) und der Rate-Limiter des Cores für
  Passwort-Resets (`contao.rate_limit.member_password_factory`, 3 Registrierungen pro
  IP-Adresse in 15 Minuten). Für `RegistrationService` und den Controller gibt es keine
  automatischen Tests (brauchen Contao).
- Rechtliches (Wunsch von Marko): Impressum, Nutzungsbedingungen und Datenschutzerklärung als
  Twig-Templates `contao/templates/twig/legal/class_book_{imprint|terms|privacy}.html.twig`
  (deutsch, für die Schweiz geschrieben, mit Verweis auf das KDSG des Kantons Luzern;
  überschreibbar). Erreichbar für alle, angemeldet oder nicht, über `?info=imprint|terms|privacy`
  (`LegalInfo`, `state = legal`, statische Karte mit «Zurück zu ClassBook»); Links unter der
  Karte von Login und Registrierung und im Footer der App (`.cb-legal-links`). Die Angaben
  zum Betreiber kommen vom Inhaltselement: `cbOperator` (Name und Adresse), `cbOperatorEmail`,
  `cbHosting`. Sie stehen bewusst nicht im Bundle, weil das Repository öffentlich ist und jede
  Installation ihren eigenen Betreiber hat. Die Texte sind ein sorgfältiger Entwurf, keine
  Rechtsberatung: Marko lässt sie prüfen (Jurist/in, Datenschutzbeauftragter des Kantons).
- Zustimmung bei der Registrierung: Das Formular zeigt, was gespeichert wird und was nicht
  hineingehört (keine leistungsrelevanten, familiären oder sensiblen Daten über Schülerinnen
  und Schüler), und verlangt die Checkbox `terms` (`RegistrationValidator`, Fehler `terms`).
  `RegistrationService` hält die Zustimmung am Mitglied fest: `tl_member.cbTermsAccepted`
  (Zeitstempel, 0 = keine) und `cbTermsVersion` (`LegalInfo::VERSION`, Datum der Fassung;
  bei inhaltlichen Änderungen der Nutzungsbedingungen erhöhen). Ein Zeitstempel mit Fassung
  statt eines Ja/Nein-Feldes, damit belegt ist, wann wer welcher Fassung zugestimmt hat.
  Mitglieder von vor dieser Änderung haben `0`; eine nachträgliche Zustimmung beim Anmelden
  gibt es noch nicht.
- Benutzername (Wunsch von Marko): Die Registrierung vergibt einen generierten Namen
  `cbuser_<3 Buchstaben>_<3 Ziffern>` (`UsernameService::generate()`, eindeutig); anmelden kann
  man sich mit dem Namen oder der E-Mail-Adresse. Ändern in Einstellungen → Sicherheit, API
  `/_class_book/api/account/username`. `UsernameService::validate()` wendet die Regeln des
  Feldes `tl_member.username` des Cores an: Pflicht, eindeutig, höchstens 64 Zeichen, keine
  Leerzeichen, `rgxp extnd` (keines der Zeichen `#&()/<=>`). Zusätzlich darf der Name nicht
  die E-Mail-Adresse eines anderen Mitglieds sein. Der Controller ändert den Namen am
  `FrontendUser` der Sitzung (`$user->save()`): Contao lädt das Mitglied bei jedem Request
  über den Benutzernamen neu, mit dem alten Namen wäre die Sitzung beendet.
- E-Mail-Adresse ändern (Wunsch von Marko): Einstellungen → Sicherheit, Karte «E-Mail-Adresse».
  API `/_class_book/api/account/email` mit `email`, `password`, `path`. Ablauf:
  `EmailChangeValidator` prüft Format, Länge und die Domainrestriktion des Inhaltselements
  (`cbRegistrationDomains`, Element aus dem Zugangs-Token: `ApiAuthenticator::getElementId()`;
  sonst liesse sich die Restriktion nach der Registrierung umgehen), dann das Passwort
  (derselbe Rate-Limiter wie beim Löschen). `EmailChangeService::request()` legt ein
  Opt-in-Token von Contao an (Präfix `cbmail`, enthält die neue Adresse, 24 Stunden gültig,
  ältere offene Tokens des Mitglieds werden gelöscht) und sendet den Link an die neue Adresse.
  Am Mitglied ändert sich bis zur Bestätigung nichts. Der Link (`?token=cbmail-…`) führt auf
  die Seite mit dem Element, angemeldet oder nicht (`state = email`, statische Karte):
  `EmailChangeService::confirm()` setzt `tl_member.email` und sendet der alten Adresse eine
  Mitteilung mit der neuen Adresse. Ein Link gilt einmal. Gehört die Adresse einem anderen
  Mitglied (als E-Mail oder Benutzername), wird nichts gesendet, und die Antwort ist dieselbe
  (verrät nicht, wer ein Konto hat); geprüft wird beim Anfordern und beim Bestätigen.
  `EmailChangeMailer` nutzt das Template der Aktivierungs-E-Mail (ohne `url` kein Knopf).
  Die Sitzung bleibt bestehen (Contao lädt das Mitglied über den Benutzernamen). Für Service,
  Mailer und Controller gibt es keine automatischen Tests (brauchen Contao), nur für den
  Validator.
- Konto löschen (Wunsch von Marko): Einstellungen → Sicherheit, bestätigt mit dem Passwort.
  API `/_class_book/api/account/delete` (`AccountController`): prüft das Passwort (Rate-Limiter
  des Cores, 3 Versuche in 15 Minuten), `AccountService::delete()` löscht in einer Transaktion
  alle Zeilen des Mitglieds in allen `tl_cb_*`-Tabellen (Liste aus `ResourceDefinitions`, inkl.
  Verknüpfungstabellen), seine Opt-in-Tokens (`tl_opt_in`, Präfixe `reg` und `cbmail`, mit
  E-Mail-Adresse und Text der E-Mail; Contao behielte bestätigte Tokens sonst bis zu drei
  Jahre) und das Mitglied selbst (`tl_member`), danach Abmeldung. Kein
  Papierkorb, keine Wiederherstellung. Eine neue Tabelle mit Daten von Mitgliedern muss in
  `AccountService::getTables()` erscheinen (automatisch, wenn sie eine Ressource ist).
- ClassBook teilen (Wunsch von Marko): Ein Mitglied gibt sein ClassBook für andere Mitglieder
  frei; der Gast bleibt mit dem eigenen Konto angemeldet und wechselt im Benutzermenü
  («ClassBook von …», zurück mit «Mein ClassBook»).
  - `ShareService` (ohne Contao, getestet): `grant()`, `getGranted()`, `getReceived()`,
    `getAccess(Besitzer, Gast)`, `revoke()` (Besitzer hebt auf, Gast entfernt), höchstens 20
    Freigaben pro Mitglied. Erfasst wird die E-Mail-Adresse des Gasts. Die Antwort verrät
    nicht, ob die Adresse ein Konto hat: Die Freigabe wird in jedem Fall gespeichert und an
    das Mitglied gebunden (`guest`), sofort bei genau einem aktiven Konto mit dieser Adresse,
    sonst sobald ein Mitglied mit dieser Adresse die App öffnet (`bindPending()` in
    `getReceived()`). Danach folgt sie dem Mitglied, nicht der Adresse; die Liste des
    Besitzers zeigt weiter die erfasste Adresse.
  - Zugangs-Token: `AccessToken::ownerId` (`o` im Token; ohne `o` = eigenes ClassBook).
    `ApiAuthenticator::getMemberId()` liefert das Mitglied, dem die Daten gehören (bei einem
    Gast-Token der Besitzer), und prüft die Freigabe bei jedem Request: Aufheben wirkt sofort
    (401). `canWrite()` ist bei «nur lesen» falsch; `EntryController` und
    `MasterDataController` antworten dann auf Schreibaktionen mit 403 `readOnly`. Ein neuer
    Controller mit Schreibaktionen muss `canWrite()` ebenfalls prüfen. `getMember()` bleibt
    die angemeldete Person: Konto, 2FA und Freigaben betreffen immer das eigene Konto.
  - API `/_class_book/api/share/{list|save|delete|switch}` (`ShareController`). `save` verlangt
    das Passwort (`PasswordChecker`, derselbe Rate-Limiter wie Konto löschen und E-Mail
    ändern); `switch` gibt ein Token für das ClassBook des Besitzers (oder das eigene) zurück.
  - Frontend: Karte «ClassBook teilen» in Einstellungen → Sicherheit (`shares`, `share`,
    `saveShare()`, `deleteShare()`, Aufheben über den Löschdialog). Wechseln (`switchBook()`)
    schreibt `owner` in `cb-state` und lädt die Seite neu, damit nichts vom anderen ClassBook
    zurückbleibt; beim Start holt `enterBook()` das Token. Im fremden ClassBook: Leiste
    `.cb-guestbar` mit «Zurück zu meinem ClassBook», Name im Kopf ist der des Besitzers
    (`bookName`), «Sicherheit» ist ausgeblendet. Bei «nur lesen» bleibt die Oberfläche gleich;
    ein Schreibversuch zeigt 6 Sekunden den Hinweis `errorReadOnly` (`readOnlyDenied`).
    Besteht die Freigabe nicht mehr, landet man im eigenen ClassBook (`shareGone`).
  - Bewusst nicht gebaut: keine E-Mail an den Gast, keine Kennzeichnung, wer einen Eintrag
    geändert hat, keine Live-Synchronisation (zuletzt gespeichert gewinnt). Erinnerungen
    gehen nur an den Besitzer.
  - Löschen: `AccountService` löscht erteilte (`member`) und erhaltene (`guest`) Freigaben mit
    dem Konto, `deleteOrphans()` auch Freigaben an Gäste, die es nicht mehr gibt.
  - `ApiAuthenticator` ist getestet (`ApiAuthenticatorTest`, läuft nur mit Contao, also in der
    CI). Für `ShareController` und `PasswordChecker` gibt es keine
    automatischen Tests (brauchen Contao/Symfony Security).
- Verwaiste Daten: Wird ein Mitglied im Backend gelöscht, löscht `MemberDeleteListener`
  (`config.ondelete` von `tl_member`) seine ClassBook-Daten sofort (`AccountService::
  deleteData()`); sie sind nicht Teil des Undo-Datensatzes von Contao, «Wiederherstellen»
  bringt nur das Konto zurück. Für alle anderen Wege (der Core räumt nie aktivierte
  Registrierungen auf, Löschen direkt in der Datenbank) entfernt `PurgeOrphansCron` (täglich)
  Zeilen, deren Mitglied nicht mehr existiert (`AccountService::deleteOrphans()`). Zeilen
  ohne Mitglied (`member = 0`) bleiben stehen.
- Anmelden mit E-Mail-Adresse: Contao kennt nur den Benutzernamen. `LoginIdentifierListener`
  (`kernel.request`, Priorität 10: vor der Firewall mit 8, aber nach der Request-Token-Prüfung
  von Contao mit 14; wer vorher die Session startet, löst bei Besuchern ohne Cookies
  «Ungültiges Anfrage-Token» aus) ersetzt im Login-Formular von ClassBook
  (verstecktes Feld `_class_book_login`) eine E-Mail-Adresse durch den Benutzernamen, wenn sie
  zu genau einem Mitglied mit Login-Erlaubnis gehört. Das Formular zeigt nach einem Fehler
  wieder die getippte Eingabe (Session `class_book_login_identifier`), nie den Benutzernamen,
  damit sich über die E-Mail-Adresse kein Benutzername herausfinden lässt.
- Zwischen Passwort und 2FA-Code: Contao leitet nach dem Passwort auf dieselbe Seite um und
  zeigt mit unvollständiger Anmeldung nur die Seite, die als Target-Path in der Session steht
  (`TwoFactorFrontendListener` des Cores), sonst «Nicht authentifiziert». Der Core speichert
  den Target-Path erst nach diesem Fehler. `TwoFactorTargetPathListener` (`kernel.response`)
  speichert ihn deshalb direkt nach dem Passwort für die Seite von ClassBook.
- Angemeldet heisst überall: `FrontendUser` **und** `ROLE_MEMBER`. Nach dem Passwort, aber vor
  dem 2FA-Code liefert `getUser()` bereits das Mitglied, das Token hat aber noch keine Rollen.
  Element und `ApiAuthenticator` prüfen deshalb die Rolle, sonst liesse sich 2FA umgehen.
- Zwei-Faktor-Authentifizierung einrichten: Einstellungen → Sicherheit. `TwoFactorService`
  nutzt die Dienste des Cores (`Authenticator`, `BackupCodeManager`, `TrustedDeviceManager`),
  dieselbe Logik wie das 2FA-Modul von Contao. API `/_class_book/api/two-factor/
  {status|setup|enable|disable|backup-codes}`; Änderungen verlangen `IS_AUTHENTICATED_FULLY`
  (sonst 403 `reauthenticate`). Das Secret wird nach dem Einschalten nicht mehr ausgegeben,
  Backup-Codes nur beim Erzeugen. Für `TwoFactorService`, den Controller und das Login gibt
  es keine automatischen Tests (brauchen Contao), sie werden auf der Website geprüft.
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
  einen eigenen Raster-Editor. Eine Lektion wird in einem Dialog erfasst, geändert
  und gelöscht (Wunsch von Marko; vorher ein Formular unter dem Raster, das bei hohem Raster
  ausserhalb des Fensters lag). Die Zeilen des Editors bleiben kompakt (`.cb-timetable--edit td`), unabhängig von der
  Mindesthöhe der Wochenansicht. `/_class_book/api/week` liefert zusätzlich `timetable`
  (`periods`, `subjects`, `groups`, `days[datum] = {holiday, lessons}`) aus `TimetableService`.
- Einträge sind die Ressource `entry` (`masterData => false`): Validierung und Speichern
  über `MasterDataService`, aber nicht in den Stammdaten-Listen und nicht über die
  Stammdaten-API. `EntryService` (Zeitraum, offene Einträge, erledigt, speichern, löschen)
  und `/_class_book/api/entries/{save|done|copy|move|delete|open}`. Die Wochen-API liefert zusätzlich
  `entries` (Montag bis Sonntag) und `openCount`.
- Erinnerungen: `src/Cron/SendRemindersCron.php` (`#[AsCronJob('minutely')]`) ruft
  `ReminderService::sendDue()` auf (alle Mitglieder; Fälligkeit wird beim Lauf berechnet, nicht
  gespeichert). `ReminderMailer` (hinter `ReminderMailerInterface`, damit der Service ohne
  Contao testbar ist) sendet eine Text-E-Mail an `tl_member.email` in der Sprache des Mitglieds
  (Aufbau nach Wunsch von Marko: «Hallo <Vorname>», «Du hast eine neue Erinnerung von <Domain>:»,
  Art/Datum/Zeit, Text, Hinweis auf die automatische Benachrichtigung; die Domain ist die der
  Seite mit dem ersten sichtbaren ClassBook-Element, sonst der Name der App). Die E-Mail hat
  eine HTML-Fassung (`contao/templates/twig/email/class_book_reminder.html.twig`, Tabellen-
  layout mit Inline-Styles in den Farben der App, überschreibbar als
  `templates/email/class_book_reminder.html.twig`) mit Knopf «In ClassBook öffnen» (nur wenn
  sich die Adresse der Seite ohne Request erzeugen lässt) und eine Text-Fassung gleichen Inhalts,
  Absender ist die Administrator-Adresse aus den Contao-Einstellungen. Pünktlich nur mit echtem
  Cronjob der Installation (`contao:cron` jede Minute); ohne ihn hängt der Versand von
  Seitenaufrufen ab.
- `BirthdayService::getBetween()` liefert Geburtstage eines Zeitraums. Die Wochen-API liefert
  `birthdays` (angezeigte Woche) und `important` (`entries`, `birthdays`; unabhängig von der
  angezeigten Woche, bezogen auf heute).
- Drag and Drop der Einträge: natives HTML5-DnD ohne Bibliothek (`drag` im App-State,
  `onDragStart`, `onDragOverSlot`, `onDragOverEntry`, `onDrop`). Der Eintragstext ist ein
  `span role="button"`, weil Firefox aus einem `<button>` heraus keinen Drag startet.
- Verschieben per Touch (Wunsch von Marko): Browser starten aus einer Berührung keinen
  verlässlichen HTML5-Drag, deshalb eigene Touch-Events (`onTouchStart`, `startTouchDrag`,
  `onTouchMove`, `onTouchEnd`). Eintrag 350 ms halten (`TOUCH_HOLD`), dann ziehen; eine Kopie
  (`.cb-entry--ghost`) folgt dem Finger, das Ziel wird mit `elementFromPoint` über
  `data-cb-entry` und `data-cb-slot-date`/`data-cb-slot-period` bestimmt und wie beim Drag
  mit der Maus in `drag.target` abgelegt (gleiche Anzeige, gleiches `onDrop()`). Bewegt sich
  der Finger vorher, scrollt die Seite wie gewohnt; ein kurzes Antippen öffnet den Eintrag.
  Am Fensterrand scrollt die Seite mit, das Wochenraster auch seitwärts. `onDragStart`
  bricht den Drag des Browsers ab, solange eine Berührung läuft.
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
- Backend: Modulgruppe `class_book` mit je einem Modul pro Tabelle, nur als Notfallzugriff. Das Icon
  der Modulgruppe (Wunsch von Marko): Initialen CB im Stil der Lucide-Icons von Contao
  (24 × 24, Strich 2, rund, 13 px gross), `assets/images/class-book-backend.svg` und
  `…--dark.svg` in den Farben der Core-Icons. `class-book-backend.scss` setzt es auf
  `#tl_navigation .group-class_book`; `BackendAssetsListener` hängt das Stylesheet im Backend
  an `$GLOBALS['TL_CSS']`.

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
- Lokale Installation: `C:\phpstorm\classbook.ch` (seit 21.11.2026, vorher
  `C:\phpstorm\schuleettiswil`), Bundle per Composer (VCS, `dev-main`) unter
  `vendor/markocupic/contao-class-book`. Dort gibt es für Claude kein PHP. Beim Kopieren nach
  `vendor/` nur ausliefern, was Composer auch ausliefert (`git archive`, beachtet
  `export-ignore`: ohne `.github`, `CLAUDE.md`, `tests`, `tools`).
- Sitzungsdauer auf classbook.ch (von Marko in `config/config.yaml` der Installation gesetzt):
  `framework.session.gc_maxlifetime: 72000` und `cookie_lifetime: 72000` (20 Stunden, das
  Cookie überlebt das Schliessen des Browsers). Das Zugangs-Token der App gilt 12 Stunden ab
  Seitenaufruf (`AccessTokenManager`), kürzer als die Sitzung. Die Datenschutzerklärung
  beschreibt das Sitzungs-Cookie deshalb ohne feste Dauer.
- Getestet wird im Browser auf https://classbook.ch/app (eigene Website nur für ClassBook,
  Inhaltselement im Standalone-Modus mit leerem Seitenlayout; seit 21.11.2026, vorher
  https://www.schule-ettiswil.ch/_class-book). Marko spielt Updates selbst ein und führt
  `contao:migrate` aus. Claude gibt dort keine Passwörter ein; angemeldete Zustände prüft
  Marko oder Claude in einer Sitzung, in der Marko sich selbst angemeldet hat.

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
- Rechtliche Texte nachführen (ausdrücklicher Wunsch von Marko, sehr wichtig): Bei jeder
  Änderung prüfen, ob sie Impressum, Nutzungsbedingungen oder Datenschutzerklärung berührt
  (`contao/templates/twig/legal/`), und die Texte im selben Zug anpassen. Auslöser sind
  insbesondere: neue oder geänderte gespeicherte Daten (Felder in `tl_member`, `tl_cb_*`,
  neue Arten von Einträgen), neue Cookies oder Einträge im lokalen Speicher, neue E-Mails
  oder geänderte E-Mail-Inhalte, eingebundene fremde Dienste, Schriften oder Bibliotheken,
  Protokolle, Exporte und Schnittstellen (z. B. der MCP-Server), geänderte Löschung oder
  Aufbewahrung. Auch die Kurzfassung im Registrierungsformular (`registerStored`,
  `registerForbidden`) und die Hinweise in den Formularen müssen dazu passen. Ändert sich der
  Sinn der Nutzungsbedingungen, `LegalInfo::VERSION` erhöhen (zuletzt 2026-11-23: Freigabe). In der Antwort an Marko immer
  sagen, ob die Texte angepasst wurden oder warum nicht.
- Scripts und Stylesheets nur über Webpack + Symfony Encore.
- Coding Standard: `composer cs-fixer` (Contao ECS, Konfiguration in `tools/ecs`).
- Kleine, in sich abgeschlossene Commits mit englischer Commit-Message.
- Keine Tags und keine Releases anlegen, solange Marko das nicht ausdrücklich verlangt.
- Code, Bezeichner und Kommentare englisch; Oberfläche deutsch (Übersetzungen über
  Contao-Sprachdateien, damit weitere Sprachen möglich bleiben).
