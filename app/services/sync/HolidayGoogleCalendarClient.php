<?php
declare(strict_types=1);

/**
 * REAL (not mocked) Google Calendar API v3 client for Thailand's public holiday calendar
 * (th.th#holiday@group.v.calendar.google.com) -- backs the "Sync from Google Calendar" picker on
 * the Holiday tab (Time & Leave > Setup & Rules, 2026-08-28, explicit request). Unlike
 * OrigamiEmployeeCandidateClient elsewhere in this app, this one is NOT a stub/mock --
 * GOOGLE_CALENDAR_API_KEY (config.php/.env) is a real, working key the user provided directly, so
 * this makes a genuine outbound HTTP call every time and returns Google's own real data.
 *
 * TH-only, per explicit request ("ให้รองรับเฉพาะประเทศไทยก่อน" carried over from the same rollout as
 * Tax & Statutory's own country-scoping) -- the calendar id is hardcoded, not parameterized by
 * country. Extending to other countries later just means adding more calendar ids (Google publishes
 * one per country, e.g. `en.sg#holiday@group.v.calendar.google.com` for Singapore) and a
 * country->calendar-id lookup here; nothing else in this feature would need to change.
 *
 * Google's calendar event summaries are Thai-only (this is the "th.th" locale-specific calendar,
 * not a bilingual feed) -- there is no per-event English translation available from the API
 * itself. THAI_TO_ENGLISH_NAMES below is a small, hand-curated best-effort lookup for Thailand's
 * fixed, well-known set of ~15-18 annual public holidays (this set rarely changes). A holiday
 * whose Thai summary doesn't match anything here (e.g., Google's own "ชดเชยวันหยุด..." in-lieu-day
 * wording, which varies by year) falls back to using the Thai text for BOTH name_th and name_en --
 * `name_translated: false` on that row so the picker UI can show which names are a real
 * translation vs. a fallback, rather than silently guessing.
 *
 * `is_recurring` is ALWAYS false on every returned row, deliberately -- Thailand's public holidays
 * are a mix of fixed-Gregorian-date holidays (New Year's Day, Labour Day, ...) and lunar-calendar
 * Buddhist observances (Makha Bucha, Visakha Bucha, Asarnha Bucha, sometimes Songkran) that move
 * every year. Marking any of them `is_recurring=true` (this app's own "same month/day repeats
 * forever" flag, see SetupRulesModel's holiday resolver) would be wrong for the lunar ones -- since
 * distinguishing "genuinely fixed" from "lunar, looks fixed this year" isn't reliable without its
 * own curated list, every synced holiday is imported as a one-time date for the fetched year. An
 * admin re-syncs each year for that year's real dates, which is always correct.
 *
 * IMPORTANT, confirmed against a real live fetch (28 rows for 2026), not assumed: this calendar is
 * NOT limited to Thailand's official statutory public holidays for private-sector employers -- it
 * also includes government-civil-servant-only days (วันพืชมงคล / Royal Ploughing Day, a holiday
 * for government offices, not private companies) and purely cultural observances with no legal
 * holiday status at all (วันวาเลนไทน์ / Valentine's Day, วันตรุษจีน / Chinese New Year, วัน
 * คริสต์มาส / Christmas). This is exactly why this feature is reviewed-and-selected (see
 * HolidaySyncModel), never auto-applied wholesale -- blindly importing every row here would
 * silently create paid holidays a Thai company has no legal obligation (and often no actual
 * intention) to give. The picker UI must make this distinction visible to whoever is reviewing,
 * not just to whoever reads this docblock.
 */
class HolidayGoogleCalendarClient {
    private const CALENDAR_ID = 'th.th%23holiday@group.v.calendar.google.com';

    /** 2026-08-28, verified against a REAL live fetch (not guessed): Google's own calendar text has
     *  real-world quirks a naive "correct Thai spelling" lookup would miss entirely --
     *  'วันขื้นปีใหม่' (New Year's Day) uses a non-standard vowel (สระอื instead of the
     *  dictionary-correct สระอึ, i.e. ขื้น not ขึ้น) that's been in Google's feed for years, not a
     *  transcription error on this end. Both spellings are listed so a future correction on
     *  Google's side doesn't silently break this map. Matched against NORMALIZED text (see
     *  normalizeThaiText()) so a leading zero-width space Google's feed sometimes prepends (found
     *  live on "วันรัฐธรรมนูญ" / Constitution Day) doesn't cause a miss either. */
    private const THAI_TO_ENGLISH_NAMES = [
        'วันขึ้นปีใหม่' => "New Year's Day",
        'วันขื้นปีใหม่' => "New Year's Day",
        'วันจักรี' => 'Chakri Memorial Day',
        'วันสงกรานต์' => 'Songkran Festival',
        'วันแรงงานแห่งชาติ' => 'National Labour Day',
        'วันฉัตรมงคล' => 'Coronation Day',
        'วันวิสาขบูชา' => 'Visakha Bucha Day',
        'วันอาสาฬหบูชา' => 'Asalha Puja Day',
        'วันเข้าพรรษา' => 'Buddhist Lent Day',
        'วันเฉลิมพระชนมพรรษาสมเด็จพระนางเจ้าฯ พระบรมราชินี' => "HM the Queen's Birthday",
        'วันเฉลิมพระชนมพรรษาสมเด็จพระนางเจ้าสุทิดา' => "HM the Queen's Birthday",
        'วันแม่แห่งชาติ' => "National Mother's Day",
        'วันปิยมหาราช' => 'Chulalongkorn Day',
        'วันเฉลิมพระชนมพรรษาพระบาทสมเด็จพระเจ้าอยู่หัว' => "HM the King's Birthday",
        'วันเฉลิมพระชนมพรรษา สมเด็จพระเจ้าอยู่หัวมหาวชิราลงกรณ บดินทรเทพยวรางกูร' => "HM the King's Birthday",
        'วันชาติ' => 'National Day',
        'วันพ่อแห่งชาติ' => "National Father's Day",
        'วันรัฐธรรมนูญ' => 'Constitution Day',
        'วันสิ้นปี' => "New Year's Eve",
        'วันมาฆบูชา' => 'Makha Bucha Day',
        'วันคล้ายวันสวรรคตของพระบาทสมเด็จพระปรมินทรมหาภูมิพลอดุลยเดชบรมนาถบพิตร' => 'HM King Bhumibol Memorial Day',
        'วันคล้ายวันพระบรมราชสมภพ พระบาทสมเด็จพระบรมชนกาธิเบศรมหาภูมิพลอดุลยเดชมหาราช บรมนาถบพิตร และวันพ่อแห่งชาติ' => "HM King Bhumibol Memorial Day and National Father's Day",
        'วันเฉลิมพระชนมพรรษาสมเด็จพระนางเจ้าสิริกิติ์ พระบรมราชินีนาถ พระบรมราชชนนีพันปีหลวง และวันแม่แห่งชาติ' => "HM Queen Sirikit's Birthday and National Mother's Day",
    ];

    /** Strips a leading zero-width space / BOM-like invisible character (U+200B, U+FEFF) that
     *  Google's own feed sometimes prepends -- found live on "วันรัฐธรรมนูญ" (Constitution Day) --
     *  and trims ordinary whitespace. Applied before matching against THAI_TO_ENGLISH_NAMES so
     *  that quirk doesn't cause a real, correctly-spelled holiday name to miss the lookup. */
    private function normalizeThaiText(string $text): string {
        return trim(preg_replace('/^(?:\x{200B}|\x{FEFF})+/u', '', $text) ?? $text);
    }

    /** @return array<array{name_th:string, name_en:string, name_translated:bool, holiday_date:string, is_recurring:bool, google_event_id:string}> */
    public function fetchThaiHolidays(int $year): array {
        if (GOOGLE_CALENDAR_API_KEY === '') {
            throw new RuntimeException('GOOGLE_CALENDAR_API_KEY is not configured.');
        }
        $timeMin = urlencode("{$year}-01-01T00:00:00Z");
        $timeMax = urlencode("{$year}-12-31T23:59:59Z");
        $url = 'https://www.googleapis.com/calendar/v3/calendars/' . self::CALENDAR_ID . '/events'
            . '?key=' . urlencode(GOOGLE_CALENDAR_API_KEY)
            . '&timeMin=' . $timeMin
            . '&timeMax=' . $timeMax
            . '&maxResults=200&orderBy=startTime&singleEvents=true';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false || $response === '') {
            throw new RuntimeException("Google Calendar API request failed: " . ($curlError ?: 'empty response'));
        }
        if ($httpCode !== 200) {
            throw new RuntimeException("Google Calendar API returned HTTP {$httpCode}.");
        }
        $data = json_decode($response, true);
        if (!is_array($data) || !isset($data['items']) || !is_array($data['items'])) {
            throw new RuntimeException('Google Calendar API returned an unexpected response shape.');
        }

        $results = [];
        foreach ($data['items'] as $event) {
            $summary = $this->normalizeThaiText((string)($event['summary'] ?? ''));
            $dateStr = (string)($event['start']['date'] ?? ($event['start']['dateTime'] ?? ''));
            $date = substr($dateStr, 0, 10);
            if ($summary === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }
            $englishName = self::THAI_TO_ENGLISH_NAMES[$summary] ?? null;
            $results[] = [
                'name_th' => $summary,
                'name_en' => $englishName ?? $summary,
                'name_translated' => $englishName !== null,
                'holiday_date' => $date,
                'is_recurring' => false,
                'google_event_id' => (string)($event['id'] ?? ''),
            ];
        }
        return $results;
    }
}
