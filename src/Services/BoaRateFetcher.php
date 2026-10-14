<?php

namespace Botble\EmironFx\Services;

use Carbon\Carbon;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Lexon kursin zyrtar nga faqja publike e Bankës së Shqipërisë.
 *
 * BSH-ja s'ka API zyrtar; publikon një tabelë HTML çdo ditë pune pas 12:00
 * (kursi llogaritet 11:30–12:00). Parser-i është i kujdesshëm:
 *  - merr vetëm tabelat e kursit zyrtar ("Lek për njësi valute"); tabela e
 *    çmimeve mesatare Bid/Ask (USD/EUR) anashkalohet qëllimisht;
 *  - data merret nga "Last update: dd.mm.yyyy" që i paraprin çdo tabele;
 *  - njësia lexohet nga emri, p.sh. "Japanese Yen (100)" → 100;
 *  - nëse s'gjen asnjë kurs, hedh përjashtim (s'kthen kurrë "bosh = ok").
 *
 * Nëse BSH ndryshon faqen, importi dështon me zë (email "fut manualisht"),
 * jo në heshtje.
 */
class BoaRateFetcher
{
    /**
     * @return array{source_url: string, published_at: ?Carbon, tables: array<int, array{date: ?Carbon, rates: array<string, array{rate: string, unit: int, name: string}>}>}
     */
    public function fetch(): array
    {
        $parsed = $this->parse($this->fetchHtml());
        $parsed['source_url'] = (string) config('plugins.emiron-fx.general.boa_url');

        return $parsed;
    }

    /** HTML-ja e papërpunuar e faqes së BSH-së (edhe për diagnostikim: --save-html). */
    public function fetchHtml(): string
    {
        $url = (string) config('plugins.emiron-fx.general.boa_url');

        $response = Http::timeout(20)
            ->retry(2, 2000)
            ->withHeaders(['User-Agent' => 'EmironFX/0.2 (+rate import; exchange office)', 'Accept-Language' => 'en'])
            ->get($url);

        if (! $response->successful()) {
            throw new RuntimeException("BSH: HTTP {$response->status()} nga {$url}");
        }

        return $response->body();
    }

    /**
     * @return array{published_at: ?Carbon, tables: array<int, array{date: ?Carbon, rates: array<string, array{rate: string, unit: int, name: string}>}>}
     */
    public function parse(string $html): array
    {
        preg_match_all('/<table\b.*?<\/table>/is', $html, $tables, PREG_OFFSET_CAPTURE);

        $result = ['published_at' => null, 'tables' => []];
        $previousEnd = 0;

        foreach ($tables[0] as [$tableHtml, $offset]) {
            $header = mb_strtolower($this->firstRowsText($tableHtml));

            // Tabela e çmimeve mesatare blerje/shitje s'është kurs zyrtar.
            if (preg_match('/\b(bid|ask)\b|blerjes|shitjes/u', $header)) {
                $previousEnd = $offset + strlen($tableHtml);
                continue;
            }

            $rates = $this->parseTable($tableHtml);

            if (! $rates) {
                $previousEnd = $offset + strlen($tableHtml);
                continue;
            }

            // Data: e fundit "dd.mm.yyyy [hh:mm:ss]" në tekstin mes tabelës së
            // mëparshme dhe kësaj (pavarësisht etiketave, &nbsp; apo gjuhës).
            $date = $this->dateBefore(substr($html, $previousEnd, $offset - $previousEnd));
            $previousEnd = $offset + strlen($tableHtml);

            if (! $date) {
                throw new RuntimeException(sprintf(
                    'BSH: u gjetën kurse (%s) por jo data e publikimit para tabelës — faqja mund të ketë ndryshuar strukturë.',
                    implode(', ', array_slice(array_keys($rates), 0, 5))
                ));
            }

            $result['tables'][] = ['date' => $date, 'rates' => $rates];

            if (! $result['published_at'] || $date->gt($result['published_at'])) {
                $result['published_at'] = $date;
            }
        }

        if (! $result['tables']) {
            throw new RuntimeException('BSH: s\'u gjet asnjë tabelë me kurse — faqja mund të ketë ndryshuar strukturë.');
        }

        return $result;
    }

    /** @return array<string, array{rate: string, unit: int, name: string}> */
    private function parseTable(string $tableHtml): array
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML('<?xml encoding="UTF-8"><html><body>' . $tableHtml . '</body></html>');
        libxml_clear_errors();

        $rates = [];

        foreach ((new DOMXPath($dom))->query('//tr') as $tr) {
            $cells = [];

            foreach ($tr->childNodes as $node) {
                if (in_array(strtolower($node->nodeName), ['td', 'th'], true)) {
                    $cells[] = trim(preg_replace('/\s+/u', ' ', $node->textContent));
                }
            }

            // Rreshti: Emri | KODI | kursi | ndryshimi ...
            foreach ($cells as $i => $cell) {
                if (! preg_match('/^[A-Z]{3}$/', $cell) || ! isset($cells[$i + 1])) {
                    continue;
                }

                $value = str_replace([',', ' '], '', $cells[$i + 1]);

                if (! preg_match('/^\d+(\.\d+)?$/', $value) || (float) $value <= 0) {
                    break;
                }

                $name = $cells[$i - 1] ?? '';
                $unit = preg_match('/\((\d+)\)/', $name, $m) ? (int) $m[1] : 1;

                $rates[$cell] = ['rate' => $value, 'unit' => max(1, $unit), 'name' => $name];
                break;
            }
        }

        return $rates;
    }

    /** Data e fundit dd.mm.yyyy (me orë nëse ka) në një copë HTML, si tekst i pastër. */
    private function dateBefore(string $htmlChunk): ?Carbon
    {
        $text = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], ' ', $htmlChunk)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/[\s\x{00A0}]+/u', ' ', $text);

        if (! preg_match_all('/(\d{2})[.\/](\d{2})[.\/](\d{4})(?:\s*,?\s*(\d{1,2}:\d{2}(?::\d{2})?))?/u', (string) $text, $m, PREG_SET_ORDER)) {
            return null;
        }

        $last = end($m);
        $time = $last[4] ?? '';
        $time = $time === '' ? '00:00:00' : (substr_count($time, ':') === 1 ? $time . ':00' : $time);

        $date = Carbon::createFromFormat('d.m.Y H:i:s', sprintf('%s.%s.%s %s', $last[1], $last[2], $last[3], str_pad($time, 8, '0', STR_PAD_LEFT)), config('app.timezone'));

        return $date ?: null;
    }

    private function firstRowsText(string $tableHtml): string
    {
        preg_match_all('/<tr\b.*?<\/tr>/is', $tableHtml, $rows);

        return strip_tags(implode(' ', array_slice($rows[0] ?? [], 0, 2)));
    }
}
