# Changelog

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
