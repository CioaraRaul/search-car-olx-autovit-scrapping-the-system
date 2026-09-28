# Car Finder — Auto-scraper pentru OLX/Autovit

## Obiectiv
Aplicație care caută automat mașini second hand pe OLX și Autovit, filtrează anunțurile după criterii tehnice (inclusiv "cunoștințe auto" pe care userul nu le are), le stochează într-o bază de date, și trimite **o singură notificare pe zi** (seara) cu tot ce a găsit nou și relevant — astfel userul nu mai caută manual.

## Stack recomandat
- Laravel (Artisan Command + Scheduler pentru cron)
- MySQL/MariaDB pentru stocare
- Telegram Bot API pentru notificări (simplu, gratuit, fără aprobări)
- Opțional: apel către un LLM (ex. Claude API) pentru analiza textului liber din anunțuri

## Componente

### 1. Scraper (2 module separate: OLX + Autovit)
- Autovit are adesea un API intern JSON — verificat prin Network tab, mai stabil decât HTML parsing.
- OLX poate necesita parsing HTML sau are și el endpoint-uri interne — de verificat.
- Extrage: preț, an, km, motorizare (capacitate cilindrică, CP), transmisie, combustibil, oraș, link, poze, descriere text, id extern (`external_id`) + `source`.
- Rulare frecventă (ex. la 1-2 ore) via Laravel Scheduler — colectarea e frecventă, notificarea rămâne 1x/zi.
- Rate limiting / delay-uri între request-uri, user-agent rezonabil — atenție la protecții anti-bot (Cloudflare, rate limiting). Structura HTML se poate schimba periodic, deci mentenanță continuă a selectors.

### 2. Bază de date
Tabel `listings`:
- `external_id` + `source` (unic, evită duplicate)
- criterii tehnice (preț, an, km, motor, transmisie, combustibil, oraș etc.)
- `notified_at` (nullable) — marchează ce a fost deja trimis userului
- istoric preț (opțional) — util pentru negociere dacă vânzătorul modifică prețul

### 3. Filtrare "hard" (criterii explicite userul)
- SQL simplu pe: preț, an, km, marcă/model, combustibil, transmisie
- **De completat de user**: buget, marcă/model dorite, an minim, km maxim, zonă geografică

### 4. Filtrare "soft" — partea de cunoștințe auto
Combinație de:
- **Reguli hardcodate** pe bază de research: listă de motorizări cu probleme cunoscute (ex. 1.6 TDCI PowerShift, EA189, lanț distribuție N47 etc.), plus reguli generice (km suspect de mici la mașină veche, preț mult sub media pieței)
- **Analiză text liber via LLM**: descrierea anunțului trimisă către un API (ex. Claude) pentru un scor/rezumat de riscuri ("fără accident", "unic proprietar", detalii tehnice scrise de vânzător)
- **Verificare rating vânzător** (adăugat 2026-09-26): dacă site-ul expune un rating/scor al vânzătorului, anunțurile de la vânzători cu rating prost sunt excluse sau marcate ca risc — de verificat în faza de scraper ce date sunt disponibile efectiv (OLX vs Autovit, vânzător privat vs dealer)

### 5. Notificare zilnică
- Cron seara: selectează toate `listings` cu `notified_at IS NULL` care trec de scor
- Trimite mesaj/mesaje via Telegram Bot API
- Marchează `notified_at` după trimitere

## De discutat / decis înainte de implementare
- [ ] Criterii hard: buget, marcă/model, an, km, combustibil, zonă
- [ ] Sursă de reguli pentru motorizări problematice (listă inițială de pornire)
- [ ] Se folosește LLM pentru analiza descrierilor sau doar reguli hardcodate la început?
- [ ] Frecvența scraping-ului (1h? 2h?)
- [ ] Format notificare Telegram (mesaj per mașină vs. listă compactă)

## Riscuri de reținut
- ToS ale site-urilor + posibile blocări IP
- Mentenanță continuă a scraper-ului (structura HTML se schimbă)
- Fără garanție 100% "expert auto" automat — reguli + LLM reduc riscul, nu îl elimină
