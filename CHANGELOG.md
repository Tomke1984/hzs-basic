# Changelog

## [0.4.0] – 2026-10-26 – `dev`

### Toegevoegd
- Per trainer: **afdeling** (competitie, zwemschool of beide) en een **foto** (uploaden in het beheer).
- Nieuwe pagina **Trainers zwemschool** (`/zwemschool/trainers/`): overzicht van alle zwemschooltrainers met
  foto en naam (en hun groepen), zonder contactgegevens.
- Trainer zonder foto krijgt een rondje met initialen.

### Gewijzigd
- **Trainers & contact** toont enkel competitiegroepen en competitietrainers, nu met foto, telefoon en e-mail.
- Inhoudsblokken: `[[trainers:competitie]]` (ook `[[trainers]]`) = contactlijst per groep;
  `[[trainers:zwemschool]]` = fotoverzicht.
- Gegevens worden automatisch bijgewerkt (versie 3): bestaande trainers krijgen hun afdeling op basis van hun
  groepen, en de pagina Trainers zwemschool wordt toegevoegd.

## [0.3.0] – 2026-10-26 – `dev`

### Toegevoegd
- Trainers als aparte gegevens in het beheer (**Trainers**): naam, telefoon, e-mail, keuze om contactgegevens
  op de website te tonen, en koppeling aan groepen. Toevoegen, bewerken en verwijderen (verwijderen koppelt ook los).
- In het groepsformulier kies je trainers uit een lijst in plaats van namen te typen.
- Pagina **Trainers & contact** wordt automatisch opgebouwd: per groep de trainers met belbare nummers en
  e-mailadressen; groepen met dezelfde trainers worden samengevoegd.
- Inhoudsblok `[[trainers]]`, `[[trainers:zwemschool]]` en `[[trainers:competitie]]`, bruikbaar op elke pagina
  (bv. de contactpagina).
- Automatisch bijwerken van bestaande gegevens bij een nieuwe versie (`data/meta.json`): oude trainersnamen
  worden gekoppelde trainers, de oude getypte trainerspagina wordt het nieuwe blok.

### Gewijzigd
- Trainersnamen bij de les- en trainingsuren komen uit de gekoppelde trainers.

## [0.2.1] – 2026-10-26 – `dev`, `main`

### Gewijzigd
- Publiceren gebruikt standaard gewoon FTP: de hosting ondersteunt geen FTPS (`500 AUTH not understood`).

## [0.2.0] – 2026-10-26 – `dev`

### Toegevoegd
- GitHub Actions-workflow: testreeks bij elke push naar `dev` en `main`; bij `main` daarna automatisch
  publiceren via FTPS naar de hosting (enkel gewijzigde bestanden; `config.php`, `data/` en `uploads/` worden nooit aangeraakt).
- Handmatig proefdraaien van de publicatie via "Run workflow".

### Gewijzigd
- Minimale PHP-versie in de documentatie: 8.1.
- `.ftp-deploy-sync-state.json` is niet via het web leesbaar.

## [0.1.0] – 2026-10-26 – `dev`

### Toegevoegd
- Publieke website met de inhoud van khzs.be in de vormgeving van de HZS-clubapp:
  zwemschool, competitie, lid worden, club, privacy, kalender en de drie nieuwste nieuwsberichten.
- Beheer op `/beheer`: eerste beheerder via installatiecode, aanmelden, pagina's (editor, menu, volgorde,
  verbergen), nieuws (met foto, concept, gepland), kalender (activiteiten, vakanties, sluitingsdagen,
  seizoen), groepen en uren, instellingen, beheerders, bestanden, back-ups.
- Lesuren en trainingsuren worden opgebouwd uit de groepen.
- Agenda-abonnementen (.ics) per groep met wekelijkse trainingen; vakanties en sluitingsdagen vallen weg.
- Opslag in JSON met vergrendeling en automatische back-up (laatste 40 per onderdeel).
- Startinhoud in `seed/`, lokale ontwikkelserver (`router.php`) en testreeks (`tests/run.php`).
