# Contao Class Book

Digitales Unterrichtsbuch für Lehrpersonen als Extension für das [Contao CMS](https://contao.org).

> **Status:** In Entwicklung. Noch nicht einsatzbereit, es gibt noch keine Releases.

## Geplanter Funktionsumfang

- Frontend-Applikation, nur für angemeldete Mitglieder
- Schuljahr, Ferien und Lektionsraster
- Fächer, Lerngruppen sowie Schülerinnen und Schüler (nur Name, Geburtsdatum, Lerngruppe)
- Stundenplan mit Fach und Lerngruppe pro Lektion
- Wochen- und Tagesansicht des Stundenplans
- Einträge direkt im Stundenplanraster: Notizen, Termine, Unterrichtsbemerkungen, Hausaufgaben
- Einträge an eine Lektion oder nur an einen Tag (mit optionaler Uhrzeit) binden
- Einträge als erledigt markieren
- Box «Wichtige Termine» mit einstellbarem «Sichtbar ab» und Geburtstags-Reminder

Nicht vorgesehen sind Absenzen und Noten.

## Voraussetzungen

- PHP ^8.3
- Contao ^5.7

## Entwicklung

Spezifikation, Datenmodell, Etappen und Konventionen stehen in [CLAUDE.md](CLAUDE.md).

```bash
# Assets bauen (Ergebnis in public/ wird committet)
npm install
npm run build

# Unit-Tests
composer unit-tests

# Coding Standard
cd tools/ecs && composer update && cd ../..
composer cs-fixer
```

## Einrichtung

1. Nach der Installation und nach jedem Update `contao:migrate` ausführen.
2. Frontend-Modul «Unterrichtsbuch» anlegen.
3. Modul auf einer Seite einbinden, die nur für angemeldete Mitglieder zugänglich ist.

## Lizenz

MIT, siehe [LICENSE](LICENSE).
