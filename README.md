# Contao Class Book

Digitales Unterrichtsbuch für Lehrpersonen als Extension für das [Contao CMS](https://contao.org).

> **Status:** In Entwicklung. Noch nicht einsatzbereit, es gibt noch keine Releases.

## Geplanter Funktionsumfang

- Frontend-Applikation, nur für angemeldete Mitglieder
- Schuljahr, Ferien und Lektionsraster
- Fächer, Stammklassen und Lerngruppen
- Schülerinnen und Schüler (nur Name, Geburtsdatum, Geschlecht, eine Stammklasse, beliebig viele Lerngruppen)
- Klassenräume mit Pulten zeichnen, Sitzordnung losen oder von Hand setzen, speichern und drucken
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
2. In einem Artikel das Inhaltselement «ClassBook» anlegen (Kategorie «Verschiedenes»).
3. Die Seite nicht schützen: Ohne Anmeldung zeigt das Element sein eigenes Login-Formular
   (mit Zwei-Faktor-Code, falls aktiviert). Die Zwei-Faktor-Authentifizierung richtet man in
   ClassBook unter Einstellungen → Sicherheit ein.
4. Optional «Registrierung erlauben» am Inhaltselement einschalten: Lehrpersonen registrieren
   sich selbst und aktivieren ihr Konto über einen Link per E-Mail. «Erlaubte E-Mail-Domains»
   schränkt die Registrierung ein (z. B. `schule-ettiswil.ch, schule-grosswangen.ch`), leer
   dürfen sich alle registrieren.
5. Im Inhaltselement unter «Betreiber» Name und Adresse, Kontakt-E-Mail und Hosting eintragen.
   Die Angaben erscheinen im Impressum, in den Nutzungsbedingungen und in der
   Datenschutzerklärung (Links unter dem Login und im Footer der App). Die Texte liegen als
   Templates unter `contao/templates/twig/legal/` und lassen sich in der Installation
   überschreiben. Sie sind ein Entwurf für die Schweiz und ersetzen keine Rechtsberatung.

## Lizenz

MIT, siehe [LICENSE](LICENSE).

## Icons

Die Icons stammen aus [Font Awesome Free](https://fontawesome.com) (Lizenz der Icons: [CC BY 4.0](https://fontawesome.com/license/free)). Es werden nur die Pfade einzelner Icons übernommen (`npm run icons`), Font Awesome selbst wird nicht eingebunden.
