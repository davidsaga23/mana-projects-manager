<?php
/**
 * A minimal .xlsx writer — a real Excel file, with no library.
 *
 * WHY THIS EXISTS AT ALL, given the standing rule that nothing external gets
 * added to this codebase: an .xlsx is a ZIP of XML files, and PHP ships both.
 * The alternative was CSV, which export.php already emits and which David
 * explicitly did not ask for — he asked for a file like his own, and his own has
 * live formulas, department bands, subtotals and a colour scheme.
 *
 * THE STYLE TABLE AND THE PALETTE ARE READ FROM HIS FILE, not invented.
 * RTC_ExpenseProjection_v01.xlsx was opened and its styles.xml parsed: the
 * oranges below are its oranges, the number formats are its formats (negatives
 * in brackets, a zero shown as a dash), and the formulas are the ones it
 * actually carries.
 *
 * WHAT IT DELIBERATELY DOES NOT DO. No charts, no merged cells, no shared
 * strings, no shared formulas. Values are written as INLINE strings and every
 * formula is written out in full on its own row — Excel's shared-formula
 * optimisation (one master with `ref`, the rest pointing at it by index) is
 * where a hand-rolled writer becomes unopenable, and the file is a few kilobytes
 * larger without it.
 *
 * ⚠ THE ORDER OF cellXfs IS THE MEANING OF THE XLSX_* CONSTANTS. Inserting one
 * in the middle silently restyles the whole document.
 *
 * ⚠ ZipArchive can be absent on a hardened host. Callers must check
 * xlsx_available() and say so rather than emitting a corrupt download — a file
 * that will not open is the worst possible version of "nothing failed visibly".
 */

/* Style slots — indices into cellXfs in xlsx_styles_xml(). */
const XLSX_DEFAULT     = 0;
const XLSX_TITLE       = 1;   // white on dark orange, the top bar
const XLSX_HEAD        = 2;   // white on mid orange, the column headers
const XLSX_BAND        = 3;   // white on orange, a department band
const XLSX_TEXT        = 4;
const XLSX_TEXT_ALT    = 5;   // the same on a shaded row
const XLSX_MONEY       = 6;
const XLSX_MONEY_ALT   = 7;
const XLSX_NUM         = 8;   // 2 decimals
const XLSX_NUM_ALT     = 9;
const XLSX_INT         = 10;
const XLSX_INT_ALT     = 11;
const XLSX_MONEY_CALC  = 12;  // blue: this cell is a formula
const XLSX_MONEY_CALC_ALT = 13;
const XLSX_NUM_CALC    = 14;
const XLSX_NUM_CALC_ALT = 15;
const XLSX_SUB_LABEL   = 16;  // subtotal row
const XLSX_SUB_MONEY   = 17;
const XLSX_SUB_NUM     = 18;
const XLSX_GRAND_LABEL = 19;  // grand total row
const XLSX_GRAND_MONEY = 20;
const XLSX_NOTE        = 21;  // small grey
const XLSX_NOTE_ALT    = 22;
const XLSX_WARN        = 23;  // bold red, for anything unpriced

/* ---- the freelancer sheet's own look ------------------------------------
 * Taken from Mana_Freelancer_DataBase.xlsx by opening it and reading its
 * styles.xml, not by eye: bold Arial 16 on FFE06666 for the title, bold Arial
 * for the headers, plain Arial for the body, blue underlined for a reel link.
 * Nothing to do with the orange expense-projection palette above, which is why
 * it gets its own slots rather than borrowing approximate ones.
 *
 * APPENDED, deliberately. Every index above is referenced by
 * export_projection.php, so inserting anything in the middle would silently
 * repaint that entire sheet. */
const XLSX_FL_TITLE = 24;   // bold 16 on red
const XLSX_FL_HEAD  = 25;   // bold
const XLSX_FL_TEXT  = 26;
const XLSX_FL_LINK  = 27;   // blue, underlined
const XLSX_FL_NUM   = 28;   // 2 decimals, for a rate

/** Pick the shaded variant of a style on alternate rows. */
function xlsx_alt(int $style, bool $shaded): int {
    if (!$shaded) return $style;
    static $pairs = [
        XLSX_TEXT       => XLSX_TEXT_ALT,
        XLSX_MONEY      => XLSX_MONEY_ALT,
        XLSX_NUM        => XLSX_NUM_ALT,
        XLSX_INT        => XLSX_INT_ALT,
        XLSX_MONEY_CALC => XLSX_MONEY_CALC_ALT,
        XLSX_NUM_CALC   => XLSX_NUM_CALC_ALT,
        XLSX_NOTE       => XLSX_NOTE_ALT,
    ];
    return $pairs[$style] ?? $style;
}

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
 * A cell is a scalar, or an array:
 *   ['v' => mixed, 's' => style]                a value
 *   ['f' => 'SUM(H4:H9)', 'v' => 123, 's' => n] a FORMULA, with the value this
 *                                               app computed cached beside it
 *
 * The cached value matters: Excel recalculates on open, but Google Sheets
 * previews, Quick Look and anything reading the file without a formula engine
 * show the cache. Writing a formula with no cache produces a file that looks
 * empty everywhere except Excel.
 *
 * @throws RuntimeException when ZipArchive is missing or the archive fails
 */
function xlsx_build(string $sheetName, array $rows, array $widths = []): string {
    if (!xlsx_available()) {
        throw new RuntimeException(
            'This server has no ZipArchive extension, so it cannot write an Excel file. '
            . 'Ask the host to enable php-zip; the CSV export works meanwhile.'
        );
    }

    $sheet = xlsx_sheet_xml($rows, $widths);

    $tmp = tempnam(sys_get_temp_dir(), 'anima_xlsx_');
    if ($tmp === false) throw new RuntimeException('Could not create a temporary file for the export.');

    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        @unlink($tmp);
        throw new RuntimeException('Could not open a temporary archive for the export.');
    }

    // Excel caps a sheet name at 31 characters and forbids : \ / ? * [ ] — a
    // longer or odder one opens to a repair prompt rather than a spreadsheet.
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

    // fullCalcOnLoad makes Excel recompute every formula when the file opens,
    // rather than trusting the cached values written below. The cache is for
    // readers that have no formula engine; Excel should do the arithmetic
    // itself, and then editing a rate updates the totals as it would in any
    // spreadsheet.
    $zip->addFromString('xl/workbook.xml',
        '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"'
        . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets><sheet name="' . xlsx_text($safeName) . '" sheetId="1" r:id="rId1"/></sheets>'
        . '<calcPr calcId="171027" fullCalcOnLoad="1"/>'
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

/** A number as Excel wants it: a plain decimal, no thousands separator. */
function xlsx_number(float $v): string {
    $s = number_format($v, 6, '.', '');
    // Only trim the fractional part — rtrim on "380" would leave "38".
    return strpos($s, '.') !== false ? rtrim(rtrim($s, '0'), '.') : $s;
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
            $style   = XLSX_DEFAULT;
            $value   = $cell;
            $formula = null;
            if (is_array($cell)) {
                $value   = $cell['v'] ?? '';
                $style   = (int)($cell['s'] ?? XLSX_DEFAULT);
                $formula = $cell['f'] ?? null;
            }
            if ($formula === null && ($value === null || $value === '') && $style === XLSX_DEFAULT) {
                continue;   // truly nothing here
            }

            $ref = xlsx_col((int)$c) . $r;

            if ($formula !== null) {
                $out .= '<c r="' . $ref . '" s="' . $style . '"><f>' . xlsx_text($formula) . '</f>';
                if ($value !== null && $value !== '') {
                    $out .= '<v>' . xlsx_number((float)$value) . '</v>';
                }
                $out .= '</c>';
            } elseif (is_int($value) || is_float($value)) {
                $out .= '<c r="' . $ref . '" s="' . $style . '"><v>' . xlsx_number((float)$value) . '</v></c>';
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
 * The style table, mirroring RTC_ExpenseProjection_v01.xlsx.
 *
 * Its palette, read from its own styles.xml:
 *   B85C00  the title bar and the grand total
 *   EA8B14  the column headers
 *   D97706  a department band
 *   FDEBD3  every other data row
 *   FFF2CC  a subtotal row
 *
 * Its number formats too — negatives in brackets and a zero shown as a dash,
 * which is how a budget sheet reads rather than how a database prints.
 */
function xlsx_styles_xml(): string {
    return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="3">'
        .   '<numFmt numFmtId="164" formatCode="$#,##0.00;($#,##0.00);-"/>'
        .   '<numFmt numFmtId="165" formatCode="0.00;(0.00);-"/>'
        .   '<numFmt numFmtId="166" formatCode="0;(0);-"/>'
        . '</numFmts>'
        . '<fonts count="13">'
        .   '<font><sz val="11"/><name val="Calibri"/></font>'                                        // 0
        .   '<font><b/><sz val="14"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>'               // 1 title
        .   '<font><b/><sz val="10"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>'               // 2 header
        .   '<font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>'               // 3 band
        .   '<font><sz val="10"/><name val="Arial"/></font>'                                          // 4 data
        .   '<font><sz val="10"/><color rgb="FF0000FF"/><name val="Arial"/></font>'                   // 5 computed
        .   '<font><b/><sz val="11"/><name val="Arial"/></font>'                                      // 6 subtotal
        .   '<font><b/><sz val="12"/><color rgb="FFFFFFFF"/><name val="Arial"/></font>'               // 7 grand
        .   '<font><sz val="9"/><color rgb="FF595959"/><name val="Arial"/></font>'                    // 8 note
        .   '<font><b/><sz val="16"/><name val="Arial"/></font>'                                      // 9  freelancer title
        .   '<font><b/><sz val="11"/><name val="Arial"/></font>'                                      // 10 freelancer header
        .   '<font><sz val="11"/><name val="Arial"/></font>'                                          // 11 freelancer body
        .   '<font><u/><sz val="11"/><color rgb="FF0000FF"/><name val="Arial"/></font>'                // 12 reel link
        . '</fonts>'
        . '<fills count="8">'
        .   '<fill><patternFill patternType="none"/></fill>'
        .   '<fill><patternFill patternType="gray125"/></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FFB85C00"/><bgColor indexed="64"/></patternFill></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FFEA8B14"/><bgColor indexed="64"/></patternFill></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FFD97706"/><bgColor indexed="64"/></patternFill></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FFFDEBD3"/><bgColor indexed="64"/></patternFill></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FFFFF2CC"/><bgColor indexed="64"/></patternFill></fill>'
        .   '<fill><patternFill patternType="solid"><fgColor rgb="FFE06666"/><bgColor indexed="64"/></patternFill></fill>'  // 7 freelancer title
        . '</fills>'
        . '<borders count="2">'
        .   '<border><left/><right/><top/><bottom/><diagonal/></border>'
        .   '<border><left/><right/><top style="thin"><color rgb="FFB85C00"/></top>'
        .     '<bottom style="thin"><color rgb="FFB85C00"/></bottom><diagonal/></border>'
        . '</borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="29">'
        // 0 default
        .   '<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
        // 1 title  2 header  3 band
        .   '<xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        .   '<xf numFmtId="0" fontId="2" fillId="3" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        .   '<xf numFmtId="0" fontId="3" fillId="4" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        // 4 text  5 text_alt
        .   '<xf numFmtId="0" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        .   '<xf numFmtId="0" fontId="4" fillId="5" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        // 6 money  7 money_alt
        .   '<xf numFmtId="164" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1" applyNumberFormat="1"/>'
        .   '<xf numFmtId="164" fontId="4" fillId="5" borderId="0" xfId="0" applyFont="1" applyFill="1" applyNumberFormat="1"/>'
        // 8 num  9 num_alt
        .   '<xf numFmtId="165" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1" applyNumberFormat="1"/>'
        .   '<xf numFmtId="165" fontId="4" fillId="5" borderId="0" xfId="0" applyFont="1" applyFill="1" applyNumberFormat="1"/>'
        // 10 int  11 int_alt
        .   '<xf numFmtId="166" fontId="4" fillId="0" borderId="0" xfId="0" applyFont="1" applyNumberFormat="1"/>'
        .   '<xf numFmtId="166" fontId="4" fillId="5" borderId="0" xfId="0" applyFont="1" applyFill="1" applyNumberFormat="1"/>'
        // 12 money_calc  13 money_calc_alt   (blue = this cell is a formula)
        .   '<xf numFmtId="164" fontId="5" fillId="0" borderId="0" xfId="0" applyFont="1" applyNumberFormat="1"/>'
        .   '<xf numFmtId="164" fontId="5" fillId="5" borderId="0" xfId="0" applyFont="1" applyFill="1" applyNumberFormat="1"/>'
        // 14 num_calc  15 num_calc_alt
        .   '<xf numFmtId="165" fontId="5" fillId="0" borderId="0" xfId="0" applyFont="1" applyNumberFormat="1"/>'
        .   '<xf numFmtId="165" fontId="5" fillId="5" borderId="0" xfId="0" applyFont="1" applyFill="1" applyNumberFormat="1"/>'
        // 16 sub_label  17 sub_money  18 sub_num
        .   '<xf numFmtId="0" fontId="6" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1"/>'
        .   '<xf numFmtId="164" fontId="6" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyNumberFormat="1"/>'
        .   '<xf numFmtId="165" fontId="6" fillId="6" borderId="1" xfId="0" applyFont="1" applyFill="1" applyBorder="1" applyNumberFormat="1"/>'
        // 19 grand_label  20 grand_money
        .   '<xf numFmtId="0" fontId="7" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        .   '<xf numFmtId="164" fontId="7" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1" applyNumberFormat="1"/>'
        // 21 note  22 note_alt
        .   '<xf numFmtId="0" fontId="8" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        .   '<xf numFmtId="0" fontId="8" fillId="5" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        // 23 warn
        .   '<xf numFmtId="0" fontId="6" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        // 24-28: the freelancer sheet
        .   '<xf numFmtId="0" fontId="9"  fillId="7" borderId="0" xfId="0" applyFont="1" applyFill="1"/>'
        .   '<xf numFmtId="0" fontId="10" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        .   '<xf numFmtId="0" fontId="11" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        .   '<xf numFmtId="0" fontId="12" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
        .   '<xf numFmtId="165" fontId="11" fillId="0" borderId="0" xfId="0" applyFont="1" applyNumberFormat="1"/>'
        . '</cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles>'
        . '</styleSheet>';
}

/** Send a built file to the browser as a download. */
function xlsx_send(string $bytes, string $filename): void {
    $filename = preg_replace('/[^A-Za-z0-9 ._-]/', '_', $filename);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($bytes));
    header('Cache-Control: no-store');
    echo $bytes;
}

/* ---------------------------------------------------------------------------
 * READING one back.
 *
 * The writer above has had no counterpart: an .xlsx could be produced and never
 * consumed. Needed so the studio's existing freelancer spreadsheet — a thousand
 * people already typed once — can be imported rather than retyped.
 *
 * Minimal on purpose, matching the writer: a sheet is a ZIP of XML, and the two
 * parts that matter are the shared-string table and the first worksheet. It
 * reads VALUES, not formatting, and it does not evaluate formulas — a cached
 * value is used where one exists, which is what Excel leaves behind.
 *
 * Regexes rather than a DOM parse for the same reason the writer emits strings:
 * no library, and these documents are machine-generated and regular. Written
 * without a single backslash so that no amount of shell or editor mangling can
 * quietly turn a character class into something else.
 * ------------------------------------------------------------------------- */

/** Column letters to a 0-based index: A->0, Z->25, AA->26. */
function xlsx_col_index(string $ref): int {
    $n = 0;
    for ($i = 0; $i < strlen($ref); $i++) {
        $c = ord($ref[$i]);
        if ($c < 65 || $c > 90) break;
        $n = $n * 26 + ($c - 64);
    }
    return $n - 1;
}

/**
 * Every row of the first worksheet as a flat array of strings.
 *
 * Blank cells are filled in, so a row is positional and column D is always
 * index 3 — a sheet that skips an empty cell would otherwise shift every value
 * after it one place left, which is the failure that silently puts an email in
 * the seniority column.
 *
 * @throws RuntimeException when the file is not a readable workbook
 */
function xlsx_read_rows(string $path, int $maxRows = 5000): array {
    if (!xlsx_available()) {
        throw new RuntimeException('This server has no ZipArchive extension, so it cannot read '
                                 . 'an Excel file. Ask the host to enable php-zip.');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('That file could not be opened as a spreadsheet. If it is an '
                                 . 'older .xls, open it in Excel and save as .xlsx first.');
    }

    $shared = [];
    $sx = $zip->getFromName('xl/sharedStrings.xml');
    if ($sx !== false) {
        if (preg_match_all('~<si>(.*?)</si>~s', $sx, $m)) {
            foreach ($m[1] as $si) {
                // A single <si> can hold several <t> runs when part of the text
                // is styled differently; joined, or a bolded first name would
                // arrive without its surname.
                $t = '';
                if (preg_match_all('~<t[^>]*>(.*?)</t>~s', $si, $tm)) $t = implode('', $tm[1]);
                $shared[] = html_entity_decode($t, ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
        }
    }

    $sheet = $zip->getFromName('xl/worksheets/sheet1.xml');
    $zip->close();
    if ($sheet === false) throw new RuntimeException('That workbook has no first worksheet.');

    $rows = [];
    if (preg_match_all('~<row[^>]*>(.*?)</row>~s', $sheet, $rm)) {
        foreach ($rm[1] as $body) {
            if (count($rows) >= $maxRows) break;
            $cells = [];
            if (preg_match_all('~<c ([^>]*?)(?:/>|>(.*?)</c>)~s', $body, $cm, PREG_SET_ORDER)) {
                foreach ($cm as $c) {
                    $attr = $c[1];
                    $inner = $c[2] ?? '';
                    $idx = 0;
                    if (preg_match('~r="([A-Z]+)~', $attr, $r)) $idx = xlsx_col_index($r[1]);

                    $type = '';
                    if (preg_match('~t="([a-zA-Z]+)"~', $attr, $t)) $type = $t[1];

                    $val = '';
                    if ($type === 'inlineStr') {
                        if (preg_match('~<t[^>]*>(.*?)</t>~s', $inner, $v)) $val = $v[1];
                    } elseif (preg_match('~<v>(.*?)</v>~s', $inner, $v)) {
                        $val = $v[1];
                        if ($type === 's') {
                            $val = $shared[(int)$val] ?? '';
                        }
                    }
                    if ($type !== 's') $val = html_entity_decode($val, ENT_QUOTES | ENT_XML1, 'UTF-8');
                    $cells[$idx] = trim($val);
                }
            }
            if (!$cells) { $rows[] = []; continue; }
            // Filled to the widest index so the row stays positional.
            $wide = max(array_keys($cells));
            $flat = [];
            for ($i = 0; $i <= $wide; $i++) $flat[] = $cells[$i] ?? '';
            $rows[] = $flat;
        }
    }
    return $rows;
}
