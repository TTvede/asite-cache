# Asite Cache

Enkel og hurtig sidecache til WordPress med ét-kliks tømning. Bygget til Asites sites på Plesk-serveren (B-66 i bytvede-repoet).

## Hvad den gør
- Gemmer hver offentlig side som færdig HTML og leverer den via `advanced-cache.php`, før WordPress starter (header `X-Asite-Cache: HIT/MISS`).
- Cacher aldrig: indloggede, POST, søgning, 404, preview, adgangskodebeskyttet, kurv/kasse/konto (WooCommerce), ukendte forespørgselsparametre, stier med filendelse, udelukkede stier. `utm_*`, `gclid`, `fbclid` m.fl. ignoreres.
- Tømmer automatisk: gemt side + forside + forælder + arkiver/termer; alt ved ændring af Breakdance-skabeloner/header/footer/globale indstillinger, menu, tema, plugins, forsidevalg. WooCommerce-lager tømmer produktet.
- Forvarmer fra sitemappet (Rank Math, WordPress, sitemap.xml) i baggrunden.
- Levetid (standard 10 t – kortere end WordPress' nonce-levetid, så formularer virker). Timevis oprydning.
- Interface: Værktøjer → Asite Cache + knap i admin-linjen (»Tøm hele cachen«, »Tøm denne side«).

## Installation på et site
1. Installér pluginet (zip fra seneste release) og aktivér.
2. Deaktivér anden sidecache (WP Rocket m.fl.) – pluginet overskriver aldrig en fremmed `advanced-cache.php`.
3. Værktøjer → Asite Cache → »Slå sidecache til« → Gem. Pluginet lægger drop-in'en og sætter `WP_CACHE` i wp-config.php.
4. Tjek: `curl -sI https://site.dk/ | grep -i x-asite` (anden gang skal vise HIT).

Deaktivering fjerner drop-in og cache. Afinstallation fjerner også indstillinger.

## Ny version
1. Hæv `Version` og `ASITE_CACHE_VERSION` i `asite-cache/asite-cache.php`.
2. `./byg.sh` → `dist/asite-cache.zip`.
3. GitHub-release med tag `vX.Y.Z` og `asite-cache.zip` vedhæftet. Sitene viser opdateringen i wp-admin inden for 6 timer.
Privat repo: sæt `define('ASITE_CACHE_GITHUB_TOKEN', '…');` i wp-config.php på hvert site (fine-grained token med læseadgang til repoet).
