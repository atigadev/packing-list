<?php
// Penulis .xlsx minimal (beberapa sheet, header tebal, angka & teks) tanpa library eksternal.

class XlsxWriter
{
    private $sheets = [];

    /** @param array $rows baris pertama = header */
    public function addSheet(string $name, array $rows, array $widths = []): void
    {
        $name = substr(preg_replace('#[\[\]\*\?/\\\\:]#', '', $name), 0, 31) ?: 'Sheet' . (count($this->sheets) + 1);
        $this->sheets[] = compact('name', 'rows', 'widths');
    }

    private static function col(int $i): string
    {
        $s = '';
        for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s;
        return $s;
    }

    private static function esc($v): string
    {
        $v = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$v);
        return htmlspecialchars($v, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function sheetXml(array $sheet): string
    {
        $x = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews>';
        $cols = max(1, count($sheet['rows'][0] ?? []));
        $x .= '<cols>';
        for ($c = 0; $c < $cols; $c++) {
            $w = $sheet['widths'][$c] ?? 16;
            $x .= '<col min="' . ($c + 1) . '" max="' . ($c + 1) . '" width="' . $w . '" customWidth="1"/>';
        }
        $x .= '</cols><sheetData>';
        foreach ($sheet['rows'] as $r => $row) {
            $x .= '<row r="' . ($r + 1) . '">';
            foreach (array_values($row) as $c => $v) {
                $ref = self::col($c) . ($r + 1);
                $style = $r === 0 ? ' s="1"' : '';
                if ($v === null || $v === '') {
                    $x .= '<c r="' . $ref . '"' . $style . '/>';
                } elseif ($r > 0 && (is_int($v) || is_float($v))) {
                    $x .= '<c r="' . $ref . '"' . $style . '><v>' . $v . '</v></c>';
                } else {
                    $x .= '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . self::esc($v) . '</t></is></c>';
                }
            }
            $x .= '</row>';
        }
        $x .= '</sheetData>';
        if (count($sheet['rows']) > 1) $x .= '<autoFilter ref="A1:' . self::col($cols - 1) . count($sheet['rows']) . '"/>';
        return $x . '</worksheet>';
    }

    public function save(string $file): void
    {
        $zip = new ZipArchive();
        $zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        $n = count($this->sheets);
        $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        foreach ($this->sheets as $i => $s) {
            $k = $i + 1;
            $ct .= '<Override PartName="/xl/worksheets/sheet' . $k . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $wb .= '<sheet name="' . self::esc($s['name']) . '" sheetId="' . $k . '" r:id="rId' . $k . '"/>';
            $rels .= '<Relationship Id="rId' . $k . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $k . '.xml"/>';
            $zip->addFromString('xl/worksheets/sheet' . $k . '.xml', $this->sheetXml($s));
        }
        $rels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
        $zip->addFromString('[Content_Types].xml', $ct . '</Types>');
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', $wb . '</sheets></workbook>');
        $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts>'
            . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFDDE6F0"/></patternFill></fill></fills>'
            . '<borders count="1"><border/></borders><cellStyleXfs count="1"><xf/></cellStyleXfs>'
            . '<cellXfs count="2"><xf/><xf fontId="1" fillId="2" applyFont="1" applyFill="1"/></cellXfs></styleSheet>');
        $zip->close();
    }

    public function download(string $filename): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'xlsx');
        $this->save($tmp);
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . filesize($tmp));
        header('Cache-Control: no-store');
        readfile($tmp);
        unlink($tmp);
    }
}
