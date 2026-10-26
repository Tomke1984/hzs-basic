# HZS-website (basis)

Publieke website van de Hasseltse Zwemvereniging Spartacus, met een eigen beheer op `/beheer`.
De inhoud komt van de vorige site (khzs.be), de vormgeving volgt de HZS-clubapp.

- **PHP 8.1+**, geen database: alle inhoud staat in JSON-bestanden in `data/`.
- **Beheer** (`/beheer`): pagina's, nieuws, kalender (activiteiten, vakanties, sluitingsdagen),
  groepen en les-/trainingsuren, trainers (competitie/zwemschool, foto, gekoppeld aan groepen), instellingen van de startpagina en voettekst, beheerders en bestanden.
- **Agenda-abonnementen** per groep op `/kalender/ics/<groep>.ics`, live uit de gegevens.

## Branches

| Branch | Doel |
|---|---|
| `dev` | ontwikkeling en testen |
| `main` | productie – enkel na goedkeuring samenvoegen vanuit `dev` |

## Lokaal draaien

```bash
cp config.example.php config.php      # vul een eigen setup_code in
php -S localhost:8000 router.php
```

Open http://localhost:8000 voor de site en http://localhost:8000/beheer om de eerste beheerder aan te maken
met de `setup_code` uit `config.php`.

De eerste keer wordt `data/` gevuld met de startinhoud uit `seed/`. Daarna wordt `seed/` niet meer gebruikt.

## Inhoudsblokken

Te gebruiken in de tekst van elke pagina (op een eigen regel):

| Blok | Toont |
|---|---|
| `[[trainers:competitie]]` of `[[trainers]]` | competitietrainers per groep, met foto, telefoon en e-mail |
| `[[trainers:zwemschool]]` | overzicht van alle zwemschooltrainers met foto en naam |

## Nieuwe versie met andere gegevensstructuur

`run_migrations()` (in `app/site.php`) zet bestaande gegevens in `data/` automatisch om bij het eerste bezoek na
een update; de versie staat in `data/meta.json`. Elke stap maakt eerst een back-up.

## Testen

```bash
php tests/run.php
```

Draait een volledige reeks tests (publieke pagina's, afscherming, installatie, aanmelden, pagina's, nieuws,
kalender, uren, uploads, beveiliging) op een tijdelijke kopie. Je eigen `data/` en `uploads/` blijven onaangeroerd.

## Publiceren (automatisch bij push naar `main`)

De workflow `.github/workflows/deploy.yml`:

- draait bij elke push naar `dev` en `main` de testreeks;
- uploadt bij een push naar `main` (na geslaagde tests) de code via FTP naar
  `ftp.omnitbe.webhosting.be`, map `/www_before_autogit/hzs/`;
- stuurt na de eerste keer enkel gewijzigde bestanden (bijgehouden in `.ftp-deploy-sync-state.json` op de server);
- raakt `config.php`, `data/` en `uploads/` nooit aan.

Instellingen in GitHub (**Settings → Secrets and variables → Actions**):

| Naam | Soort | Verplicht | Standaard |
|---|---|---|---|
| `FTP_PASSWORD` | secret | ja | – |
| `FTP_PROTOCOL` | variable | nee | `ftp` (de host ondersteunt geen FTPS op poort 21; let op: onversleuteld) |
| `FTP_PORT` | variable | nee | `21` |
| `FTP_SERVER_DIR` | variable | nee | `/www_before_autogit/hzs/` (moet eindigen op `/`) |

Goedkeuring vóór elke upload: **Settings → Environments → production → Required reviewers** en jezelf toevoegen.
Proefdraaien zonder te uploaden: **Actions → Testen en publiceren → Run workflow** (op `main`, "Proefdraaien" aangevinkt).

## Handmatig installeren (FTP)

1. Upload alles behalve `.git`, `tests/` en `router.php`.
2. Maak `config.php` aan op basis van `config.example.php` met een eigen `setup_code`.
3. Zorg dat `data/` en `uploads/` schrijfbaar zijn (755, of 775 als opslaan niet lukt).
4. Ga naar `/beheer` en maak de eerste beheerder aan.
5. Geven adressen zoals `/zwemschool/lesuren/` een 404? Dan ondersteunt de hosting geen mod_rewrite:
   zet `'pretty_urls' => false` in `config.php`.

**Bij een update nooit `data/`, `uploads/` of `config.php` overschrijven** – daar staat wat het bestuur via het beheer aanpaste.

## Mappen

| Map | Inhoud |
|---|---|
| `index.php` | voorpagina: routeert naar site, beheer of agenda-bestanden |
| `app/` | PHP-code (`bootstrap`, `site`, `admin`, `ics`) – niet via het web bereikbaar |
| `assets/` | stijl, scripts, lettertypes, logo |
| `seed/` | startinhoud voor een nieuwe installatie |
| `data/` | live inhoud en accounts (niet in git) + `backup/` met automatische kopieën |
| `uploads/` | geüploade afbeeldingen en documenten (niet in git, geen scripts toegelaten) |
| `tests/` | lokale tests |

## Beveiliging

Wachtwoorden met `password_hash`, CSRF-tokens op elk formulier, sessiecookies `HttpOnly`/`SameSite`,
blokkering na 8 foute aanmeldpogingen, HTML uit de editor wordt gefilterd (geen scripts, event-handlers
of `javascript:`-links), uploads enkel jpg/png/gif/webp/pdf/docx/xlsx met controle van de inhoud.

## Bekende beperkingen

- Afbeeldingen en pdf's van de oude site laden nog van `legacy_media_host` (`config.php`).
- Opslag in JSON: als twee beheerders tegelijk dezelfde lijst opslaan, wint de laatste.
