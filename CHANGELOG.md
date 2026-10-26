# Changelog

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
