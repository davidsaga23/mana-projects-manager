<?php
/**
 * A minimal .xlsx writer — a real Excel file, with no library.
 *
 * WHY THIS EXISTS AT ALL, given the standing rule that nothing external gets
 * added to this codebase: an .xlsx is a ZIP of XML files, and PHP ships both.
 * The alternative was CSV, which is what export.php already emits and which
 * David explicitly did not ask for — he asked for a file "like the one
 * attached", and the attached file has bold headers, department blocks,
 * subtotals and money formatting. CSV has none of those.
 *
 * WHAT IT DELIBERATELY DOES NOT DO. No formulas, no charts, no merged cells, no
 * shared-string table. Values are written as INLINE strings, which costs a few
 * bytes per cell and removes an entire second XML part that has to stay in sync
 * with the sheet — the classic way a hand-rolled xlsx ends up unopenable.
 *
 * The style table is fixed and small, because a general style engine is how this
 * file would grow into the library it exists to avoid. Six styles, named by
 * constant, and adding a seventh means editing styles.xml by hand on purpose.
 *
 * ⚠ ZipArchive can be absent on a hardened host. Callers must check
 * xlsx_available() and say so rather than emitting a corrupt download — a file
 * that will not open is the worst possible version of "nothing failed visibly".
 */

/** Style slots. The numbers are indices into cellXfs in xlsx_styles_xml(). */
const XLSX_DEFAULT   = 0;
const XLSX_BOLD      = 1;
const XLSX_TITLE     = 2;
const XLSX_MONEY     = 3;
const XLSX_MONEY_B   = 4;   // bold money, for subtotals
const XLSX_NUM       = 5;   // 2 decimal places, not money
const XLSX_HEAD      = 6;   // bold on a fill, with a rule under it
const XLSX_GROUP     = 7;   // a department band

/** Can this server write one at all? */
function xlsx_available(): bool {
    return class_exists('ZipArchive');
}

/** 0 -> A, 25 -> Z, 26 -> AA. */
function xlsx_col(int $i): string {
    $s = '';
    $i++;
    while ($i > 0) {
        $m = ($i - 1) % 26;
        $s = chr(65 + $m) . $s;
        $i = (int)(($i - $m) / 26);
    }
    return $s;
}

/** XML text escaping, plus the control characters Excel refuses to open. */
function xlsx_text(string $s): string {
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/u', '', $s);
    return htmlspecialchars((string)$s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

/**
 * Build the file and return its bytes.
 *
 * A cell is either a scalar, or ['v' => mixed, 's' => style]. A null or ''
 * value writes no cell at all, which is what keeps a fifteen-column sheet with
 * mostly-empty columns small and fast to open.
 *
 * @param array $rows    list of rows, each a list of cells
 * @param array $widths  column index => character width
 * @throws RuntimeException when ZipArchive is missing or the archive cannot be written
 */
function xlsx_build(string $sheetName, array $rows, array $widths = []): string {
    if (!xlsx_available()) {
        throw new RuntimeException(
            'This server has no ZipArchive extension, so it cannot write an Excel file. '
            . 'Ask the host to enable php-zip; the CSV export works meanwhile.'
        );
    }

    $sheet = xlsx_sheet_xml($rows, $widths);

    // A real temporary file: ZipArchive writes to a path, not to a string.
    $tmp = tempnam(sys_get_temp_dir(), 'anima_xlsx_');
    if ($tmp === false) throw new RuntimeException('Could not create a temporary file for the export.');

    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        throw new RuntimeException('Could not open a temporary archive for the export.');
    }

    // Excel is unforgiving about these three parts. Sheet name is capped at 31
    // characters and cannot contain : \ / ? * [ ] — a longer or odder one makes
    // the file open to a repair prompt rather than a spreadsheet.
    $safeName = preg_replace('/[:\\\\\\/?*\[\]]/', ' ', $sheetName);
    $safeName = mb_substr(trim($safeName) ?: 'Sheet1', 0, 31);

    $zip->addFromString('[Content_Types].xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>');

    $zip->addFromString('_rels/.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');

    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . xlsx_text($safeName) . '" sheetId="1" r:id="rId1"/></sheets>'
        . '</workbook>');

    $zip->addFromString('xl/_rels/workbook.xml.rels',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
        . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
        . '</Relationships>');

    $zip->addFromString('xl/styles.xml', xlsx_styles_xml());
    $zip->addFromString('xl/worksheets/sheet1.xml', $sheet);
    $zip->close();

    $bytes = file_get_contents($tmp);
    @unlink($tmp);
    if ($bytes === false) throw new RuntimeException('The export file could not be read back.');
    return $bytes;
}

/** The sheet part. */
function xlsx_sheet_xml(array $rows, array $widths): string {
    $out = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
         . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

    if ($widths) {
        $out .= '<cols>';
        foreach ($widths as $i => $w) {
            $n = (int)$i + 1;
            $out .= '<col min="' . $n . '" max="' . $n . '" width="' . (float)$w . '" customWidth="1"/>';
        }
        $out .= '</cols>';
    }

    $out .= '<sheetData>';
    $r = 0;
    foreach ($rows as $row) {
        $r++;
        $out .= '<row r="' . $r . '">';
        foreach ((array)$row as $c => $cell) {
            $style = XLSX_DEFAULT;
            $value = $cell;
            if (is_array($cell)) {
                $value = $cell['v'] ?? '';
                $style = (int)($cell['s'] ?? XLSX_DEFAULT);
            }
            // An empty cell is written as nothing at all unless it carries a
            // style — a department band needs its fill to run across the row.
            if (($value === null || $value === '') && $style === XLSX_DEFAULT) continue;

            $ref = xlsx_col((int)$c) . $r;
            if (is_int($value) || is_float($value)) {
                $out .= '<c r="' . $ref . '" s="' . $style . '"><v>'
                      . rtrim(rtrim(number_format((float)$value, 6, '.', ''), '0'), '.')
                      . '</v></c>';
            } elseif ($value === null || $value === '') {
                $out .= '<c r="' . $ref . '" s="' . $style . '"/>';
            } else {
                $out .= '<c r="' . $ref . '" s="' . $style . '" t="inlineStr"><is><t xml:space="preserve">'
                      . xlsx_text((string)$value) . '</t></is></c>';
            }
        }
        $out .= '</row>';
    }
    return $out . '</sheetData></worksheet>';
}

/**
 * The style table. Fixed and small on purpose — see the file header.
 *
 * The order of cellXfs entries IS the meaning of the XLSX_* constants, so
 * inserting one in the middle silently restyles the whole document.
 */
function xlsx_styles_xml(): string {
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="2">'
        .   '<numFmt numFmtId="164" formatCode="#,##0.00"/>'
        .   '<numFmt numFmtId="165" formatCode="0.00"/>'
        . '</numFmts>'
        . '<fonts count="3">'
        .   '<font><sz val="11"/><name val="Calibri"/></font>'
        .   '<font><b/><sz val="11"/><name val="Calibri"/></font>'
        .   '<font><b/><sz val="14"/><name val="Calibri"/></font>'
        . '</fonts>'
        . '<fills count="4">'
        .   '<fill><patternFill patternType="none"/></fill>'
        .   '<fill><patternFill patternType="gray125"/></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FFE4E8EC"/><bgColor indexed="64"/></patternFill></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FFF2F5F7"/><bgColor indexed="64"/></patternFill></fill>'
        . '</fills>'
        . '<borders count="2">'
        .   '<border><left/><right/><top/><bottom/><diagonal/></border>'
        .   '<border><left/><right/><top/><bottom style="thin"><color rgb="FF9FB0BF"/></bottom><diagonal/></border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="8">'
        .   '<xf numFmtId="0"   fontId="0" fillId="0" borderId="0" xfId="0"/>'                        // 0 default
        .   '<xf numFmtId="0"   fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'          // 1 bold
        .   '<xf numFmtId="0"   fontId="2" fillId="0" borderId="0" xfId="0" applyFont="1"/>'          // 2 title
        .   '<xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'  // 3 money
        .   '<xf numFmtId="164" fontId="1" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyFont="1"/>' // 4 bold money
        .   '<xf numFmtId="165" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/>'  // 5 number
        .   '<xf numFmtId="0"   fontId="1" fillId="2" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>' // 6 header
        .   '<xf numFmtId="0"   fontId="1" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"/>' // 7 group band
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
}

/** Send a built file to the browser as a download. */
function xlsx_send(string $bytes, string $filename): void {
    // Strip anything that would let a filename break out of the header.
    $filename = preg_replace('/[^A-Za-z0-9 ._-]/', '_', $filename);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: no-store');
    echo $bytes;
}
