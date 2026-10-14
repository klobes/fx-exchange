<?php

namespace Botble\EmironFx\Reports;

use RuntimeException;
use ZipArchive;

/**
 * Plotëson librin "Statistika Monetare Ditore" të BSH-së direkt në XML-në e
 * xlsx-it: ruan stilet, listat (data validation), fletët e tjera dhe lidhjet.
 * Shkruan vetëm qelizat e dhëna; s'prek asgjë tjetër.
 *
 *  - Cover Page!B9: data e raportimit (numër serial Excel, formati i shabllonit).
 *  - Fletët e të dhënave: rreshtat nga 9 e poshtë (rreshti 9 = modeli i stilit).
 */
class BoaWorkbookWriter
{
    public const FIRST_DATA_ROW = 9;

    /**
     * Kolonat që skema e raportit të BSH-së i pret si vlera numerike.
     * FxMoney kthen qëllimisht string për saktësi me bcmath, ndaj tipi i
     * qelizës nuk duhet deduktuar vetëm nga tipi PHP i vlerës.
     *
     * @var array<string, array<int, string>>
     */
    private const NUMERIC_COLUMNS = [
        'F1.1' => ['E', 'F'],
        'F1.2' => ['E', 'F'],
        'F3' => ['B', 'C'],
    ];

    /**
     * @param array<string, array<int, array<string, string|float|int|null>>> $sheets
     *        "F1.1" => [ ['A' => 'Individë', 'B' => 'I agreguar', 'E' => 1234.5, ...], ... ]
     */
    public function write(string $templatePath, string $outPath, \DateTimeInterface $date, array $sheets): void
    {
        if (! is_file($templatePath)) {
            throw new RuntimeException("Shablloni i BSH-së mungon: {$templatePath}");
        }

        if (! copy($templatePath, $outPath)) {
            throw new RuntimeException("S'u krijua dot skedari: {$outPath}");
        }

        $zip = new ZipArchive();

        if ($zip->open($outPath) !== true) {
            throw new RuntimeException("S'u hap dot xlsx: {$outPath}");
        }

        try {
            $paths = $this->sheetPaths($zip);

            if (isset($paths['Cover Page'])) {
                $xml = $zip->getFromName($paths['Cover Page']);
                $xml = $this->setCell($xml, 'B9', $this->excelSerial($date), true);
                $zip->addFromString($paths['Cover Page'], $xml);
            }

            foreach ($sheets as $name => $rows) {
                if (! isset($paths[$name])) {
                    throw new RuntimeException("Fleta {$name} s'ekziston në shabllon.");
                }

                $xml = $zip->getFromName($paths[$name]);

                foreach (array_values($rows) as $i => $cells) {
                    $r = self::FIRST_DATA_ROW + $i;
                    $xml = $this->ensureRow($xml, $r);

                    foreach ($cells as $col => $value) {
                        if ($value === null || $value === '') {
                            continue;
                        }

                        $numeric = $this->isNumericCell($name, $col, $value);

                        $xml = $this->setCell($xml, $col . $r, $value, $numeric);
                    }
                }

                $zip->addFromString($paths[$name], $xml);
            }
        } finally {
            $zip->close();
        }
    }

    /** @return array<string,string> emri i fletës => shtegu në zip */
    private function sheetPaths(ZipArchive $zip): array
    {
        $workbook = (string) $zip->getFromName('xl/workbook.xml');
        $rels = (string) $zip->getFromName('xl/_rels/workbook.xml.rels');

        preg_match_all('/<Relationship\b[^>]*\bId="([^"]+)"[^>]*\bTarget="([^"]+)"/', $rels, $m1, PREG_SET_ORDER);
        preg_match_all('/<Relationship\b[^>]*\bTarget="([^"]+)"[^>]*\bId="([^"]+)"/', $rels, $m2, PREG_SET_ORDER);
        $targets = [];

        foreach ($m1 as $m) {
            $targets[$m[1]] = $m[2];
        }

        foreach ($m2 as $m) {
            $targets[$m[2]] = $m[1];
        }

        preg_match_all('/<sheet\b[^>]*\bname="([^"]+)"[^>]*\br:id="([^"]+)"/', $workbook, $sheets, PREG_SET_ORDER);
        $paths = [];

        foreach ($sheets as $s) {
            $target = $targets[$s[2]] ?? null;

            if ($target) {
                $paths[html_entity_decode($s[1], ENT_QUOTES | ENT_XML1, 'UTF-8')] = str_starts_with($target, '/') ? ltrim($target, '/') : 'xl/' . $target;
            }
        }

        return $paths;
    }

    /** Siguron që rreshti ekziston; përndryshe e klonon nga rreshti 9 (stili). */
    private function ensureRow(string $xml, int $r): string
    {
        if (preg_match('/<row\b[^>]*\br="' . $r . '"[^>]*(\/>|>)/', $xml)) {
            return $xml;
        }

        if (! preg_match('/<row\b[^>]*\br="' . self::FIRST_DATA_ROW . '"[^>]*>.*?<\/row>/s', $xml, $model)) {
            throw new RuntimeException('Rreshti model ' . self::FIRST_DATA_ROW . ' mungon në shabllon.');
        }

        $clone = preg_replace('/\br="' . self::FIRST_DATA_ROW . '"/', 'r="' . $r . '"', $model[0], 1);
        $clone = preg_replace_callback('/<c r="([A-Z]+)' . self::FIRST_DATA_ROW . '"/', fn ($m) => '<c r="' . $m[1] . $r . '"', $clone);
        $clone = preg_replace('/<c r="([A-Z]+\d+)"([^>]*?)(?:\s+t="[^"]*")?([^>]*)>.*?<\/c>/s', '<c r="$1"$2$3/>', $clone);

        // Futet para rreshtit të parë me numër më të madh, që rendi të mbetet i vlefshëm.
        if (preg_match_all('/<row\b[^>]*\br="(\d+)"/', $xml, $rows, PREG_OFFSET_CAPTURE)) {
            foreach ($rows[1] as [$num, $offset]) {
                if ((int) $num > $r) {
                    $start = strrpos(substr($xml, 0, $offset), '<row');

                    return substr($xml, 0, $start) . $clone . substr($xml, $start);
                }
            }
        }

        return str_replace('</sheetData>', $clone . '</sheetData>', $xml);
    }


    private function isNumericCell(string $sheet, string $col, string|int|float $value): bool
    {
        if (is_int($value) || is_float($value)) {
            return true;
        }

        if (! is_string($value) || ! preg_match('/^-?\d+(?:\.\d+)?$/', $value)) {
            return false;
        }

        return in_array($col, self::NUMERIC_COLUMNS[$sheet] ?? [], true);
    }

    private function setCell(string $xml, string $ref, string|int|float $value, bool $numeric): string
    {
        $pattern = '/<c r="' . $ref . '"((?:\s+[a-zA-Z:]+="[^"]*")*)\s*(?:\/>|>.*?<\/c>)/s';

        if (! preg_match($pattern, $xml, $m)) {
            // Qeliza s'ekziston në rresht: shtohet në fund të rreshtit (Excel e pranon; rendi ruhet sepse shkruajmë A→F).
            preg_match('/\d+$/', $ref, $rn);

            return preg_replace(
                '/(<row\b[^>]*\br="' . $rn[0] . '"[^>]*>)(.*?)(<\/row>)/s',
                '$1$2' . $this->cellXml($ref, '', $value, $numeric) . '$3',
                $xml,
                1
            );
        }

        $attrs = preg_replace('/\s+t="[^"]*"/', '', $m[1]);

        return str_replace($m[0], $this->cellXml($ref, $attrs, $value, $numeric), $xml);
    }

    private function cellXml(string $ref, string $attrs, string|int|float $value, bool $numeric): string
    {
        if ($numeric) {
            return '<c r="' . $ref . '"' . $attrs . '><v>' . $this->number($value) . '</v></c>';
        }

        $text = htmlspecialchars((string) $value, ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<c r="' . $ref . '"' . $attrs . ' t="inlineStr"><is><t xml:space="preserve">' . $text . '</t></is></c>';
    }

    private function number(string|int|float $value): string
    {
        $s = is_string($value) ? $value : rtrim(rtrim(sprintf('%.6F', $value), '0'), '.');

        return $s === '' || $s === '-0' ? '0' : $s;
    }

    private function excelSerial(\DateTimeInterface $date): int
    {
        $base = new \DateTimeImmutable('1899-12-30', $date->getTimezone());
        $day = new \DateTimeImmutable($date->format('Y-m-d'), $date->getTimezone());

        return (int) $base->diff($day)->days;
    }
}
