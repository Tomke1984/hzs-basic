<?php
// Instellingen voor de HZS-website. Dit bestand is niet via het web leesbaar.
return [
    // Nodig om de allereerste beheerder aan te maken via /beheer. Daarna niet meer gebruikt.
    'setup_code' => 'VUL-HIER-EEN-EIGEN-CODE-IN',

    // true = mooie adressen (/zwemschool/lesuren/). Zet op false als je hosting geen mod_rewrite heeft;
    // dan worden adressen index.php?p=/zwemschool/lesuren/
    'pretty_urls' => true,

    // Afbeeldingen en pdf's die nog op de oude site staan (wp-content)
    'legacy_media_host' => 'https://tst.khzs.be',

    'timezone' => 'Europe/Brussels',

    // Max. grootte van een upload in MB
    'max_upload_mb' => 10,
];
