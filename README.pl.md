# System Monitor – dokumentacja

[English](README.md) | **Polski**

Dokumentacja dla wersji wtyczki **1.00** (ResourceSpace 11).
*(od X.YY)* oznacza wersję wtyczki, w której funkcja pojawiła się po raz pierwszy. Funkcje bez
oznaczenia są dostępne od 1.00. Historia zmian: [CHANGELOG.md](CHANGELOG.md).

Wtyczka pokazuje na jednej stronie bieżący stan i historię serwera oraz ResourceSpace: procesor,
pamięć, procesy PHP-FPM / nginx / bazy danych, dysk i sieć (wykresy na żywo i historyczne), a także
aktywnych użytkowników, pobrania, uploady, zasoby według typu i zadania offline. Kontrola
konfiguracji pokazuje, których danych brakuje na serwerze i jak je włączyć.

**Gdzie:** System > Plugins > System Monitor > **Options**. Stronę mogą otworzyć tylko
administratorzy (uprawnienie `a`).

> Przeznaczona dla serwerów Linux (Ubuntu / Debian) z nginx, PHP-FPM i MySQL / MariaDB.
> Na innych systemach statystyki ResourceSpace działają, dane serwera mogą być niedostępne.

---

## Zakładki

### Live

Odświeżana co kilka sekund (ustawienie *Live view refresh*, domyślnie 3 s), gdy zakładka jest otwarta.

| Kafelek / pole | Znaczenie |
|---|---|
| **CPU** | Wykorzystanie wszystkich rdzeni, liczba rdzeni, load average (1 min). |
| **Memory**, **Swap** | Zajęta pamięć (całość minus *available*) i zajętość swapu. |
| **Active users** | Użytkownicy aktywni w ostatnich 5 minutach i w ostatnich *N* minutach (ustawienie). |
| **PHP-FPM workers** | Zajęte / uruchomione workery i kolejka żądań puli. |
| **Requests** | Żądania na sekundę (nginx, a bez niego PHP-FPM) i połączenia nginx. |
| **Database** | Połączenia, zapytania na sekundę, aktywne wątki. |
| **Network**, **Disk I/O** | Bajty na sekundę odebrane / wysłane, odczytane / zapisane. |
| **Filestore disk** | Zajętość dysku z filestore. |
| **Offline jobs** | Zadania czekające w kolejce, zadania z błędem. |
| Wykresy CPU / Memory | Ostatnie 10 minut, z udziałem CPU każdej grupy procesów. |
| **Processes** | Liczba, CPU i pamięć: PHP-FPM, nginx, baza danych, przetwarzanie mediów (ImageMagick, ffmpeg, Ghostscript, ExifTool, LibreOffice), PHP CLI (cron, zadania offline). Pamięć to RSS: workery współdzielą kod, więc suma jest większa niż faktyczne zużycie. |
| **PHP-FPM, database, OPcache, disks** | Szczegóły puli („max children reached”, wolne żądania, kolejka), trafienia i pamięć OPcache, dyski. |
| **Active users** | Kto jest zalogowany: login, nazwa, grupa, ostatnia aktywność. |

### Server history

Wykresy dla 6 h, 24 h, 48 h (próbki minutowe) oraz 7, 30, 90 dni i roku (historia godzinowa): CPU,
pamięć, load, aktywni użytkownicy, CPU / liczba / pamięć każdej grupy procesów, workery PHP-FPM
i „max children reached”, żądania na sekundę, połączenia nginx, baza danych, sieć, operacje dyskowe,
zajętość dysku filestore i zadania offline. Wykres ma najwyżej ok. 800 punktów; linia przerywana to
maksimum punktu, ciągła – średnia. Wartości pokazują się po najechaniu kursorem.

Historia gromadzi się od chwili włączenia wtyczki (zob. *Zbieranie danych*).

### ResourceSpace

Skala czasu: **godziny** (48 h), **dni** (90) lub **miesiące** (24).

- Kafelki: zasoby (liczba, rozmiar), zasoby bez podglądu, użytkownicy (aktywni w 30 dniach),
  kolekcje, aktywne udostępnienia zewnętrzne (i ile z nich nie ma daty ważności), zadania offline.
- Wykresy: unikalni użytkownicy, pobrania (liczba i dane), uploady (nowe zasoby, liczba i rozmiar),
  odsłony zasobów i wyszukiwania, logowania (udane / nieudane, skala dni).
- Tabele: zasoby według typu i stanu workflow, zadania offline (z ostatnimi błędnymi), rozmiar bazy
  z największymi tabelami, top 10 z ostatnich 30 dni: pobierane zasoby, wyszukiwania, użytkownicy
  pobierający i wgrywający.

Źródła: `daily_stat` (statystyki samego ResourceSpace – wykresy dzienne i miesięczne obejmują więc
także okres **sprzed** instalacji wtyczki), `resource_log`, `resource`, `search_log`, `activity_log`
(logowania: najnowsze 1 000 000 wpisów), `job_queue`, `external_access_keys`. W skali godzin
użytkownicy pochodzą z próbek wtyczki, więc ten wykres zaczyna się od startu zbierania. Wyniki są
buforowane przez 2–15 minut (rozmiar bazy: 1 godzinę).

Grupy wybrane w *Settings > Leave these user groups out* (np. konta testów obciążenia) nie są
liczone. Statystyki samego ResourceSpace się nie zmieniają.

### System check

Pokazuje, co wtyczka może mierzyć na tym serwerze, czego brakuje i **jak to dodać** (polecenia
i fragmenty konfiguracji dla Ubuntu / Debian):

| Kontrola | Co sprawdza |
|---|---|
| Server metrics (/proc) | Linux i czytelny `/proc` (także przy `open_basedir`). |
| Processes of other users | Czy `/proc` jest zamontowany z `hidepid` (nginx i baza byłyby niewidoczne); brakujące usługi (np. baza na innym serwerze). |
| PHP-FPM status (web) | `fpm_get_status()` działa w żądaniu WWW – pula, `pm`, `pm.max_children` (z `pool.d/*.conf`, gdy da się odczytać); ostrzeżenie, gdy „max children reached” > 0. |
| PHP-FPM status page | `pm.status_path` i lokalizacja w nginx – potrzebne kolektorowi z crona (PHP CLI). |
| nginx stub_status | Połączenia i żądania na sekundę. |
| Database status | Działa `SHOW GLOBAL STATUS`, wersja, `max_connections` i najwyższe wykorzystanie. |
| Data folder | Zapis do `<filestore>/system_monitor`. |
| Cron collector | Czy próbki przychodzą z crona i jak stara jest ostatnia. |
| OPcache | Włączony, niezapełniony. |
| PHP curl | Potrzebny do odczytu stron statusu. |

Niżej: wersje i limity (ResourceSpace, PHP, baza, InnoDB buffer pool, limity PHP, ścieżki
ImageMagick / ffmpeg / Ghostscript, uptime), zebrana historia i przycisk **Take a sample now**.

Baner na górze strony pokazuje, ile pozycji wymaga uwagi.

### Settings

| Ustawienie | Domyślnie | Znaczenie |
|---|---|---|
| Take samples during page requests | wł. | Bez crona. Najwyżej jedna próbka na interwał, pobierana po wysłaniu strony (jedno sprawdzenie czasu pliku na żądanie). |
| Sample interval | 1 min | 30 s, 1, 2 lub 5 min. |
| Keep minute samples | 2 dni | 1–14 dni, ok. 1 MB dziennie. |
| Keep hourly history | 400 dni | 30–1100 dni. |
| Live view refresh | 3 s | 2–30 s. |
| „Active” users | 15 min | Aktywność w ostatnich N minutach. |
| Leave these user groups out | – | Niewliczane do statystyk ResourceSpace. |
| PHP-FPM status URL, nginx stub_status URL | puste | Tylko adresy na tym serwerze (`127.0.0.1`, `localhost`, `::1`). |

Ponadto: **Delete history** (ustawienia zostają), **Reset plugin** i lista planowanych funkcji.

---

## Przygotowanie serwera (zalecane)

Wszystkie kroki są opcjonalne: bez nich wtyczka działa i pokazuje to, co może.

### 1. Strony statusu PHP-FPM i nginx

Pula PHP-FPM (np. `/etc/php/8.3/fpm/pool.d/www.conf` – pula obsługująca ResourceSpace):

```ini
pm.status_path = /fpm-status
```

nginx – osobny serwer nasłuchujący tylko na 127.0.0.1 (np. `/etc/nginx/conf.d/status-local.conf`):

```nginx
server {
    listen 127.0.0.1:8099;
    server_name localhost;
    access_log off;
    allow 127.0.0.1;
    deny all;

    location = /nginx_status { stub_status; }

    location = /fpm-status {
        include fastcgi_params;
        fastcgi_param SCRIPT_NAME     $uri;
        fastcgi_param SCRIPT_FILENAME $uri;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}
```

```bash
sudo nginx -t && sudo systemctl reload nginx && sudo systemctl restart php8.3-fpm
curl -s 'http://127.0.0.1:8099/fpm-status?json'
curl -s http://127.0.0.1:8099/nginx_status
```

Potem wpisz `http://127.0.0.1:8099/fpm-status` i `http://127.0.0.1:8099/nginx_status` w *Settings*.

### 2. Kolektor z crona (równe próbki, także w nocy)

```bash
# /etc/cron.d/system_monitor
* * * * * www-data php <RS_ROOT>/plugins/system_monitor/cli/collect.php > /dev/null 2>&1
```

Test: `sudo -u www-data php <RS_ROOT>/plugins/system_monitor/cli/collect.php --force --verbose`.
Nie uruchamiaj go jako root (pliki należałyby do roota). `RS_ROOT=/ścieżka` wskazuje katalog
ResourceSpace, gdy wtyczka nie leży w `<RS_ROOT>/plugins`.

### 3. hidepid

Jeśli `/proc` jest zamontowany z `hidepid`, PHP widzi tylko własne procesy. System check pokazuje,
jak pozwolić użytkownikowi serwera WWW je widzieć (grupa `proc`, `gid=proc`).

---

## Dane i prywatność

- Wszystko w `<filestore>/system_monitor/data_<klucz>/` (niedostępne przez URL): `settings.json`,
  `state.json`, `minute/RRRR-MM-DD.jsonl`, `hour/RRRR-MM.jsonl`, `cache/`.
- Wtyczka tylko czyta: `/proc`, `/etc/php/<wersja>/fpm/pool.d/*.conf`, skonfigurowane lokalne strony
  statusu i bazę danych (SELECT / SHOW). Bez `exec()` i poleceń powłoki.
- Stronę i jej dane (`pages/data.php`) widzą tylko administratorzy.
- **Reset plugin** (wpisz `RESET`) usuwa `<filestore>/system_monitor`. Danych ResourceSpace nie zmienia.

## Obciążenie

- Na zwykłym żądaniu: jedno wywołanie `filemtime()`. Raz na interwał jedno żądanie po wysłaniu strony
  pobiera próbkę: odczyt `/proc` (wszystkie procesy), dwa małe zapytania do bazy i strony statusu.
- Widok Live czyta te same dane co kilka sekund tylko wtedy, gdy administrator ma otwartą zakładkę.
- Statystyki ResourceSpace są buforowane.

## Planowane (kolejne wersje)

**Etap 2**
- Alerty progowe: CPU, pamięć, dysk, zadania offline, zajęte workery PHP-FPM, nieudane logowania – baner dla administratorów i e-mail
- Znaczniki zdarzeń na wykresach (test obciążenia, aktualizacja ResourceSpace) – ręcznie lub z narzędzia testów obciążenia
- Eksport CSV / JSON każdego wykresu
- Kafelek na stronę główną ResourceSpace (CPU, pamięć, aktywni użytkownicy)

**Etap 3**
- Czasy żądań (najwolniejsze strony) z logu dostępu nginx lub slow logu PHP-FPM
- Baza danych: trend największych tabel, lista wolnych zapytań
- Porównanie dwóch okresów (np. ten tydzień i poprzedni)
- Uprawnienie do podglądu dla osób spoza administratorów

## Do sprawdzenia w ResourceSpace 11

Wtyczkę napisano na podstawie źródeł ResourceSpace 10.7: tabele `daily_stat`, `resource_log`,
`activity_log`, `job_queue`, `user.last_active`, hook `initialise`. Sprawdź w ResourceSpace 11
(F12 / System check), czy zakładka ResourceSpace pokazuje dane.

## Instalacja

- **W ResourceSpace:** Plugin Manager > Upload a plugin > `system_monitor-<wersja>.zip`, albo
- **Wiersz poleceń:**
  ```bash
  curl -LO https://github.com/s29829/system_monitor/releases/download/v<wersja>/system_monitor-<wersja>.zip
  sudo unzip -o system_monitor-<wersja>.zip -d <RS_ROOT>/plugins/
  sudo chown -R www-data:www-data <RS_ROOT>/plugins/system_monitor
  ```

Potem System > Plugins > włącz **System Monitor** > Options. Katalog danych wtyczka tworzy sama.

## Licencja

PolyForm Noncommercial License 1.0.0 – bezpłatnie do użytku prywatnego i niekomercyjnego. Użycie
komercyjne tylko za pisemną zgodą autora: [LICENSE-COMMERCIAL.md](LICENSE-COMMERCIAL.md). Pełny tekst: [LICENSE](LICENSE).
