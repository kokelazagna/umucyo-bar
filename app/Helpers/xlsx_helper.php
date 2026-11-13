<?php
// ============================================================================
// Builds an Excel (.xlsx) file in memory with plain functions.
// No library and no PHP extension is needed: an .xlsx file is just a ZIP of XML
// files, and zip_build() writes a simple ("stored", not compressed) ZIP.
// ============================================================================

// $files = ['name/inside.zip' => 'file content', ...]  ->  the binary ZIP file
function zip_build(array $files): string
{
    $body    = '';
    $central = '';
    $offset  = 0;
    $count   = 0;

    $now     = getdate();
    $dosTime = ($now['hours'] << 11) | ($now['minutes'] << 5) | intdiv($now['seconds'], 2);
    $dosDate = (($now['year'] - 1980) << 9) | ($now['mon'] << 5) | $now['mday'];

    foreach ($files as $name => $data) {
        $name    = (string)$name;
        $crc     = crc32($data);
        $size    = strlen($data);
        $nameLen = strlen($name);

        // local file header + data
        $local = pack('VvvvvvVVVvv', 0x04034b50, 20, 0, 0, $dosTime, $dosDate, $crc, $size, $size, $nameLen, 0)
               . $name . $data;
        // entry of the central directory (the table of contents at the end of the ZIP)
        $central .= pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0, 0, $dosTime, $dosDate, $crc, $size, $size,
                         $nameLen, 0, 0, 0, 0, 0, $offset) . $name;

        $body   .= $local;
        $offset += strlen($local);
        $count++;
    }
    $end = pack('VvvvvVVv', 0x06054b50, 0, 0, $count, $count, strlen($central), $offset, 0);
    return $body . $central . $end;
}

// A, B, ... Z, AA, AB ...
function xlsx_column_name(int $index): string
{
    $name = '';
    $index++;
    while ($index > 0) {
        $m     = ($index - 1) % 26;
        $name  = chr(65 + $m) . $name;
        $index = intdiv($index - $m - 1, 26);
    }
    return $name;
}

// Text safe to place inside XML.
function xlsx_escape($value): string
{
    $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string)$value);
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

// $rows = list of [cells, style]
//   cells = list of ['v' => value, 't' => 'text' | 'int' | 'money' | 'pct']
//   style = '' | 'bold' | 'header' | 'title'
// Returns the binary .xlsx file.
function xlsx_build(array $rows, string $sheetName = 'Report'): string
{
    // style numbers used in the styles part below:
    // 0 normal, 1 bold, 2 header, 3 number, 4 number bold, 5 percent, 6 title
    $styleOfText = ['bold' => 1, 'header' => 2, 'title' => 6];
    $widths      = [];
    $sheetRows   = '';

    foreach ($rows as $rowIndex => $row) {
        list($cells, $style) = $row;
        $xml = '';
        foreach ($cells as $colIndex => $cell) {
            $value = $cell['v'] ?? '';
            $type  = $cell['t'] ?? 'text';
            $ref   = xlsx_column_name($colIndex) . ($rowIndex + 1);

            if ($style !== 'title') {
                $widths[$colIndex] = max($widths[$colIndex] ?? 8, min(mb_strlen((string)$value) + 2, 50));
            }
            if (($type === 'int' || $type === 'money' || $type === 'pct') && $value !== '' && $value !== null && is_numeric($value)) {
                $s    = ($type === 'pct') ? 5 : (($style === 'bold') ? 4 : 3);
                $xml .= '<c r="' . $ref . '" s="' . $s . '"><v>' . (0 + $value) . '</v></c>';
            } else {
                $s    = $styleOfText[$style] ?? 0;
                $xml .= '<c r="' . $ref . '" s="' . $s . '" t="inlineStr"><is><t xml:space="preserve">' . xlsx_escape($value) . '</t></is></c>';
            }
        }
        $sheetRows .= '<row r="' . ($rowIndex + 1) . '">' . $xml . '</row>';
    }

    $cols = '';
    foreach ($widths as $colIndex => $w) {
        $cols .= '<col min="' . ($colIndex + 1) . '" max="' . ($colIndex + 1) . '" width="' . $w . '" customWidth="1"/>';
    }
    $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . ($cols !== '' ? '<cols>' . $cols . '</cols>' : '')
        . '<sheetData>' . $sheetRows . '</sheetData></worksheet>';

    $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="1"><numFmt numFmtId="164" formatCode="0.0&quot;%&quot;"/></numFmts>'
        . '<fonts count="3"><font><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="11"/><name val="Calibri"/></font>'
        . '<font><b/><sz val="14"/><name val="Calibri"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill>'
        . '<fill><patternFill patternType="solid"><fgColor rgb="FFE5E7EB"/><bgColor indexed="64"/></patternFill></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="7">'
        . '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        . '<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        . '<xf numFmtId="3" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="3" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>'
        . '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'
        . '<xf numFmtId="0" fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';

    $name = preg_replace('/[\[\]\:\*\?\/\\\\]/', ' ', $sheetName);
    $name = xlsx_escape(mb_substr($name, 0, 31));

    return zip_build([
        '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            . '</Types>',
        '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>',
        'xl/workbook.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            . '<sheets><sheet name="' . $name . '" sheetId="1" r:id="rId1"/></sheets></workbook>',
        'xl/_rels/workbook.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            . '</Relationships>',
        'xl/styles.xml'             => $styles,
        'xl/worksheets/sheet1.xml'  => $sheet,
    ]);
}
