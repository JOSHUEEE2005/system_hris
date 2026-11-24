<?php

namespace App\Services;

use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMNode;
use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use ZipArchive;

class ContractDocumentService
{
    private const ALLOWED_TAGS = [
        'a', 'b', 'blockquote', 'br', 'col', 'colgroup', 'div', 'em', 'footer', 'h1', 'h2',
        'h3', 'header', 'i', 'li', 'ol', 'p', 's', 'span', 'strong',
        'table', 'tbody', 'td', 'th', 'thead', 'tr', 'u', 'ul', 'img',
    ];

    private const DROP_TAGS = ['embed', 'iframe', 'object', 'script', 'style', 'svg'];
    private const CONVERTED_PDF_CLASSES = [
        'converted-pdf-page',
        'converted-pdf-header',
        'converted-pdf-page-break',
        'converted-pdf-text',
        'converted-pdf-table',
        'converted-pdf-section-row',
        'converted-pdf-header-row',
        'converted-pdf-section-cell',
        'converted-pdf-heading-cell',
        'converted-pdf-cell',
    ];

    public function sanitizeHtml(string $html): string
    {
        $document = $this->parseFragment($html);
        $root = $this->fragmentRoot($document);
        $this->sanitizeChildren($root);

        $sanitized = '';
        foreach ($root->childNodes as $child) {
            $sanitized .= $document->saveHTML($child);
        }

        return $sanitized;
    }

    public function extractUploadedHtml(string $contents, string $mimeType, string $filename): string
    {
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $isDocx = $mimeType === 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
            || $extension === 'docx';

        if ($isDocx) {
            return $this->extractDocxHtml($contents);
        }

        if ($mimeType === 'application/pdf' || $extension === 'pdf') {
            return $this->extractPdfHtml($contents);
        }

        throw new RuntimeException('Only PDF and Word (.docx) uploads can be converted for editing.');
    }

    private function extractDocxHtml(string $contents): string
    {
        $temporaryPath = tempnam(sys_get_temp_dir(), 'contract-docx-input-');
        if ($temporaryPath === false || file_put_contents($temporaryPath, $contents) === false) {
            throw new RuntimeException('The uploaded Word file could not be read.');
        }

        $archive = new ZipArchive();
        if ($archive->open($temporaryPath) !== true) {
            @unlink($temporaryPath);
            throw new RuntimeException('The uploaded Word file is not a valid .docx document.');
        }

        $documentXml = $archive->getFromName('word/document.xml');
        $archive->close();
        @unlink($temporaryPath);

        if (! is_string($documentXml) || $documentXml === '') {
            throw new RuntimeException('No editable text was found in the uploaded Word file.');
        }

        $document = new DOMDocument();
        if (! @$document->loadXML($documentXml)) {
            throw new RuntimeException('The uploaded Word file could not be parsed.');
        }

        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $html = '';
        $body = $xpath->query('//w:body')->item(0);

        if ($body !== null) {
            foreach ($body->childNodes as $block) {
                if (! $block instanceof DOMElement) {
                    continue;
                }

                if ($block->tagName === 'w:p') {
                    $text = '';
                    foreach ($xpath->query('.//w:t', $block) as $textNode) {
                        $text .= $textNode->textContent;
                    }
                    $text = trim(preg_replace('/\s+/u', ' ', $text) ?? '');
                    if ($text !== '') {
                        $html .= '<p>'.htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</p>';
                    }
                } elseif ($block->tagName === 'w:tbl') {
                    $html .= '<table><tbody>';
                    foreach ($xpath->query('./w:tr', $block) as $row) {
                        $html .= '<tr>';
                        foreach ($xpath->query('./w:tc', $row) as $cell) {
                            $cellText = '';
                            foreach ($xpath->query('.//w:t', $cell) as $textNode) {
                                $cellText .= $textNode->textContent;
                            }
                            $html .= '<td>'.htmlspecialchars(trim(preg_replace('/\s+/u', ' ', $cellText) ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8').'</td>';
                        }
                        $html .= '</tr>';
                    }
                    $html .= '</tbody></table>';
                }
            }
        }

        if (trim(strip_tags($html)) === '') {
            throw new RuntimeException('No editable text was found in the uploaded Word file.');
        }

        return $this->sanitizeHtml($html);
    }

    private function extractPdfHtml(string $contents): string
    {
        $pdfPath = tempnam(sys_get_temp_dir(), 'contract-pdf-');
        $conversionPath = tempnam(sys_get_temp_dir(), 'contract-pdf-json-');
        if ($pdfPath === false || $conversionPath === false
            || file_put_contents($pdfPath, $contents) === false) {
            throw new RuntimeException('The uploaded PDF could not be prepared for conversion.');
        }

        $python = $this->resolvePdfPython();
        if (! $this->pdfPythonIsAvailable($python)) {
            @unlink($pdfPath);
            @unlink($conversionPath);
            throw new RuntimeException(
                'Python 3 was not found by the application server. Install Python 3 and PyMuPDF, '
                .'or set PDF_LAYOUT_PYTHON to the executable path.',
            );
        }

        $script = <<<'PY'
import base64, json, sys
import pymupdf

document = pymupdf.open(sys.argv[1])
pages = []
for page in document:
    found = page.find_tables()
    tables = []
    for table in found.tables:
        extracted = table.extract()
        rows = []
        for row_index, row in enumerate(table.rows):
            cells = []
            for column_index, cell in enumerate(row.cells):
                if cell is None:
                    cells.append(None)
                    continue
                text = page.get_textbox(pymupdf.Rect(cell)).strip()
                if not text and row_index < len(extracted) and column_index < len(extracted[row_index]):
                    text = extracted[row_index][column_index] or ""
                cells.append({"rect": list(cell), "text": text})
            rows.append(cells)
        tables.append({"bbox": list(table.bbox), "rows": rows})

    table_boxes = [pymupdf.Rect(table["bbox"]) for table in tables]
    blocks = []
    for block in page.get_text("blocks", sort=True):
        bounds = pymupdf.Rect(block[:4])
        text = (block[4] or "").strip()
        if not text:
            continue
        if any(bounds.intersects(box) and bounds.intersect(box).get_area() > bounds.get_area() * 0.3 for box in table_boxes):
            continue
        blocks.append({"y": bounds.y0, "x": bounds.x0, "width": bounds.width, "text": text})

    header = ""
    if tables:
        first_table_y = min(table["bbox"][1] for table in tables)
        has_header_image = any(
            image["bbox"][1] < first_table_y and image["bbox"][3] > 0
            for image in page.get_image_info(xrefs=True)
        )
        if has_header_image and first_table_y > 20:
            clip = pymupdf.Rect(0, 0, page.rect.width, first_table_y)
            pixmap = page.get_pixmap(matrix=pymupdf.Matrix(1.3, 1.3), clip=clip, alpha=False)
            header = base64.b64encode(pixmap.tobytes("jpg", jpg_quality=92)).decode("ascii")
            blocks = [block for block in blocks if block["y"] >= first_table_y]

    items = blocks + [{"y": table["bbox"][1], "x": table["bbox"][0], "table": table} for table in tables]
    items.sort(key=lambda item: (item["y"], item["x"]))
    pages.append({"width": page.rect.width, "height": page.rect.height, "header": header, "items": items})

with open(sys.argv[2], "w", encoding="utf-8") as output:
    json.dump({"pages": pages}, output, ensure_ascii=False)
PY;
        $process = new Process([$python, '-c', $script, $pdfPath, $conversionPath]);
        $process->setTimeout(270);
        $process->run();

        @unlink($pdfPath);
        if (! $process->isSuccessful()) {
            @unlink($conversionPath);
            $errorOutput = trim($process->getErrorOutput());
            throw new RuntimeException(
                'The PDF layout could not be converted. Install Python 3 and PyMuPDF on the application server, '
                .'and set PDF_LAYOUT_PYTHON if needed.'
                .($errorOutput !== '' ? ' Details: '.$errorOutput : ''),
            );
        }

        $conversionJson = file_get_contents($conversionPath);
        @unlink($conversionPath);
        if (! is_string($conversionJson) || trim($conversionJson) === '') {
            throw new RuntimeException('No editable text or tables could be extracted from this PDF.');
        }

        $conversion = json_decode($conversionJson, true);
        if (! is_array($conversion) || empty($conversion['pages'])) {
            throw new RuntimeException('No editable text or tables could be extracted from this PDF.');
        }

        return $this->sanitizeHtml($this->structuredPdfToHtml($conversion['pages']));
    }

    private function resolvePdfPython(): string
    {
        $configuredPython = config('services.pdf_layout.python');
        if (is_string($configuredPython) && trim($configuredPython) !== '') {
            return trim($configuredPython);
        }

        if (PHP_OS_FAMILY === 'Windows') {
            $localAppData = getenv('LOCALAPPDATA');
            if (is_string($localAppData) && $localAppData !== '') {
                foreach (['Python314', 'Python313', 'Python312', 'Python311', 'Python310'] as $version) {
                    $candidate = $localAppData.'\\Programs\\Python\\'.$version.'\\python.exe';
                    if (is_file($candidate)) {
                        return $candidate;
                    }
                }
            }

            return 'python';
        }

        return 'python3';
    }

    private function pdfPythonIsAvailable(string $python): bool
    {
        $isCommand = preg_match('/^[A-Za-z0-9._-]+$/D', $python) === 1;

        return $isCommand
            ? (new ExecutableFinder())->find($python) !== null
            : is_file($python);
    }

    private function structuredPdfToHtml(array $pages): string
    {
        $html = '';
        foreach ($pages as $pageIndex => $page) {
            $pageWidth = max(1, (float) $page['width']);
            $pageHeight = max(1, (float) $page['height']);
            $html .= '<div class="converted-pdf-page" style="width:'.$pageWidth.'pt;min-height:'.$pageHeight.'pt">';
            if (! empty($page['header'])) {
                $html .= '<img class="converted-pdf-header" src="data:image/jpeg;base64,'.$page['header'].'" alt="Original PDF page header">';
            }
            foreach ($page['items'] as $item) {
                if (isset($item['table'])) {
                    $html .= $this->renderExtractedPdfTable($item['table'], (float) $page['width']);
                } else {
                    $text = nl2br(htmlspecialchars((string) $item['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
                    $left = max(0, min(100, (float) $item['x'] / max(1, (float) $page['width']) * 100));
                    $width = max(1, min(100 - $left, (float) ($item['width'] ?? $page['width']) / max(1, (float) $page['width']) * 100));
                    $html .= '<p class="converted-pdf-text" style="margin-left:'.$left.'%;width:'.$width.'%">'.$text.'</p>';
                }
            }
            $html .= '</div>';
            if ($pageIndex < count($pages) - 1) {
                $html .= '<div class="converted-pdf-page-break"></div>';
            }
        }

        return $html;
    }

    private function renderExtractedPdfTable(array $table, float $pageWidth): string
    {
        $rows = $table['rows'] ?? [];
        $box = $table['bbox'] ?? null;
        if ($rows === [] || ! is_array($box) || count($box) < 4 || $pageWidth <= 0) {
            return '';
        }

        $xBoundaries = [(float) $box[0], (float) $box[2]];
        foreach ($rows as $row) {
            foreach ($row as $cell) {
                if (is_array($cell) && isset($cell['rect'])) {
                    $xBoundaries[] = (float) $cell['rect'][0];
                    $xBoundaries[] = (float) $cell['rect'][2];
                }
            }
        }
        sort($xBoundaries);
        $xBoundaries = array_values(array_reduce($xBoundaries, function (array $unique, float $x): array {
            if ($unique === [] || abs(end($unique) - $x) > 1.0) {
                $unique[] = $x;
            }

            return $unique;
        }, []));
        $columnCount = max(1, count($xBoundaries) - 1);
        $tableWidth = max(1.0, (float) $box[2] - (float) $box[0]);
        $width = min(100, $tableWidth / $pageWidth * 100);
        $marginLeft = max(0, (float) $box[0] / $pageWidth * 100);
        $html = '<table class="converted-pdf-table" style="width:'.$width.'%;margin-left:'.$marginLeft.'%"><colgroup>';
        for ($column = 0; $column < $columnCount; $column++) {
            $columnWidth = (($xBoundaries[$column + 1] - $xBoundaries[$column]) / $tableWidth) * 100;
            $html .= '<col width="'.$columnWidth.'%" style="width:'.$columnWidth.'%">';
        }
        $html .= '</colgroup><tbody>';
        $occupied = [];
        $rowTops = [];
        foreach ($rows as $row) {
            $tops = [];
            foreach ($row as $cell) {
                if (is_array($cell) && isset($cell['rect'])) {
                    $tops[] = (float) $cell['rect'][1];
                }
            }
            $rowTops[] = $tops === [] ? null : min($tops);
        }

        foreach ($rows as $rowIndex => $row) {
            $texts = array_map(fn ($cell): string => is_array($cell) ? trim((string) ($cell['text'] ?? '')) : '', $row);
            $rowCells = array_values(array_filter($row, 'is_array'));
            $isSection = count($rowCells) === 1
                && ((float) $rowCells[0]['rect'][2] - (float) $rowCells[0]['rect'][0]) >= $tableWidth * 0.8;
            $isHeader = count(array_filter($texts, fn (string $text): bool => in_array(strtoupper(trim($text)), [
                'STATUS', 'DESCRIPTION', 'ORGANIZATION', 'RESPONSIBILITY', 'REMARKS',
            ], true))) >= 3;
            $rowClass = $isSection ? ' class="converted-pdf-section-row"' : ($isHeader ? ' class="converted-pdf-header-row"' : '');
            $html .= '<tr'.$rowClass.'>';
            $column = 0;

            foreach ($row as $cellIndex => $cell) {
                while (isset($occupied[$rowIndex][$column])) {
                    $column++;
                }
                if (! is_array($cell) || ! isset($cell['rect'])) {
                    $column++;
                    continue;
                }

                $rect = $cell['rect'];
                $start = 0;
                foreach ($xBoundaries as $boundaryIndex => $boundary) {
                    if (abs($boundary - (float) $rect[0]) <= 1.0) {
                        $start = $boundaryIndex;
                        break;
                    }
                }
                $end = $start + 1;
                foreach ($xBoundaries as $boundaryIndex => $boundary) {
                    if ($boundaryIndex > $start && abs($boundary - (float) $rect[2]) <= 1.0) {
                        $end = $boundaryIndex;
                        break;
                    }
                }
                $colspan = max(1, $end - $start);
                while ($column < $start || isset($occupied[$rowIndex][$column])) {
                    $column++;
                }
                $rowspan = 1;
                $rowBottom = (float) $rect[3];
                for ($nextRow = $rowIndex + 1; $nextRow < count($rows); $nextRow++) {
                    $nextTop = $rowTops[$nextRow] ?? null;
                    if ($nextTop === null || $nextTop >= $rowBottom - 1.0) {
                        break;
                    }
                    $rowspan++;
                }

                $class = $isSection ? 'converted-pdf-section-cell' : ($isHeader ? 'converted-pdf-heading-cell' : 'converted-pdf-cell');
                $tag = $isHeader || $isSection ? 'th' : 'td';
                $spanAttrs = ($colspan > 1 ? ' colspan="'.$colspan.'"' : '')
                    .($rowspan > 1 ? ' rowspan="'.$rowspan.'"' : '');
                $text = trim((string) ($cell['text'] ?? ''));
                $text = str_replace(["\u{e71f}", ''], '☐', $text);
                $escapedText = nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
                $html .= '<'.$tag.' class="'.$class.'"'.$spanAttrs.'>'.$escapedText.'</'.$tag.'>';

                for ($spanColumn = $column; $spanColumn < $column + $colspan; $spanColumn++) {
                    for ($spanRow = $rowIndex + 1; $spanRow < $rowIndex + $rowspan; $spanRow++) {
                        $occupied[$spanRow][$spanColumn] = true;
                    }
                }
                $column += $colspan;
            }
            $html .= '</tr>';
        }

        return $html.'</tbody></table>';
    }

    public function personalizeHtml(string $html, ?User $recipient): string
    {
        if ($recipient === null) {
            return $html;
        }

        $recipient->loadMissing(['department', 'employee.department', 'intern.department', 'roles']);
        $employee = $recipient->employee;
        $intern = $recipient->intern;
        $fields = [
            'userfullname' => $recipient->name ?? '',
            'fullname' => $recipient->name ?? '',
            'name' => $recipient->name ?? '',
            'workemail' => $recipient->email,
            'email' => $recipient->email,
            'department' => $recipient->department?->department_name
                ?? $employee?->department?->department_name
                ?? $intern?->department?->department_name
                ?? '',
            'employeeid' => $employee?->employee_no ?? $intern?->intern_no ?? '',
            'employeeno' => $employee?->employee_no ?? $intern?->intern_no ?? '',
            'internno' => $intern?->intern_no ?? '',
            'designation' => $employee?->position ?? $intern?->designation ?? '',
            'position' => $employee?->position ?? $intern?->position ?? '',
            'role' => $recipient->roles->pluck('name')->implode(', '),
            'roles' => $recipient->roles->pluck('name')->implode(', '),
            'status' => $recipient->status ?? '',
            'userstatus' => $recipient->status ?? '',
        ];

        $personalized = preg_replace_callback(
            '/\[([^\[\]]+)\]/u',
            function (array $match) use ($fields): string {
                $key = strtolower((string) preg_replace('/[\s_-]+/u', '', trim($match[1])));
                if (! array_key_exists($key, $fields)) {
                    return $match[0];
                }

                return htmlspecialchars((string) $fields[$key], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            },
            $html,
        );

        if ($personalized === null) {
            throw new RuntimeException('The contract template could not be personalized.');
        }

        return $personalized;
    }

    public function renderPdf(string $html): string
    {
        $html = $this->convertEmbeddedPngImagesForPdf($html);
        $html = $this->applyConvertedPdfTableColumnWidths($html);
        $pageSize = $this->convertedPdfPageSize($html);

        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);

        $renderer = new Dompdf($options);
        if ($pageSize === null) {
            $renderer->loadHtml($this->wrapHtml($html), 'UTF-8');
            $renderer->setPaper('A4');
        } else {
            [$pageWidth, $pageHeight] = $pageSize;
            $renderer->loadHtml($this->wrapConvertedPdfHtml($html, $pageWidth, $pageHeight), 'UTF-8');
            $renderer->setPaper([0, 0, $pageWidth, $pageHeight]);
        }
        $renderer->render();

        return $renderer->output();
    }

    private function applyConvertedPdfTableColumnWidths(string $html): string
    {
        if (! str_contains($html, 'converted-pdf-table')) {
            return $html;
        }

        $document = $this->parseFragment($html);
        $root = $this->fragmentRoot($document);
        $changed = false;

        foreach ($root->getElementsByTagName('table') as $table) {
            if (! $table instanceof DOMElement
                || ! in_array('converted-pdf-table', preg_split('/\s+/', trim($table->getAttribute('class'))), true)) {
                continue;
            }

            $columnWidths = [];
            foreach ($table->getElementsByTagName('col') as $col) {
                if (! $col instanceof DOMElement
                    || ! preg_match('/(?:^|;)\s*width\s*:\s*([0-9]+(?:\.[0-9]+)?)%/i', $col->getAttribute('style'), $widthMatch)) {
                    $columnWidths = [];
                    break;
                }
                $columnWidths[] = (float) $widthMatch[1];
                $col->setAttribute('width', $widthMatch[1].'%');
            }
            if ($columnWidths === []) {
                continue;
            }

            $body = $table->getElementsByTagName('tbody')->item(0);
            if ($body instanceof DOMElement) {
                $widthRow = $document->createElement('tr');
                foreach ($columnWidths as $columnWidth) {
                    $widthCell = $document->createElement('td');
                    $widthCell->setAttribute(
                        'style',
                        'width:'.$columnWidth.'%;height:0;padding:0;border:0;font-size:0;line-height:0',
                    );
                    $widthRow->appendChild($widthCell);
                }
                $body->insertBefore($widthRow, $body->firstChild);
            }

            $occupied = [];
            foreach ($table->getElementsByTagName('tr') as $rowIndex => $row) {
                $column = 0;
                foreach ($row->childNodes as $cell) {
                    if (! $cell instanceof DOMElement || ! in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                        continue;
                    }
                    while (isset($occupied[$rowIndex][$column])) {
                        $column++;
                    }

                    $colspan = max(1, (int) $cell->getAttribute('colspan'));
                    $rowspan = max(1, (int) $cell->getAttribute('rowspan'));
                    $cellWidth = array_sum(array_slice($columnWidths, $column, $colspan));
                    if ($cellWidth > 0) {
                        $styles = array_filter(array_map('trim', explode(';', $cell->getAttribute('style'))));
                        $styles = array_values(array_filter(
                            $styles,
                            static fn (string $style): bool => ! preg_match('/^width\s*:/i', $style),
                        ));
                        $styles[] = 'width:'.rtrim(rtrim(sprintf('%.6F', $cellWidth), '0'), '.').'%';
                        $cell->setAttribute('style', implode(';', $styles));
                        $changed = true;
                    }

                    for ($spanRow = $rowIndex; $spanRow < $rowIndex + $rowspan; $spanRow++) {
                        for ($spanColumn = $column; $spanColumn < $column + $colspan; $spanColumn++) {
                            $occupied[$spanRow][$spanColumn] = true;
                        }
                    }
                    $column += $colspan;
                }
            }
        }

        if (! $changed) {
            return $html;
        }

        $converted = '';
        foreach ($root->childNodes as $child) {
            $converted .= $document->saveHTML($child);
        }

        return $converted;
    }

    private function convertedPdfPageSize(string $html): ?array
    {
        if (! preg_match(
            '/<div\b(?=[^>]*\bclass=["\'][^"\']*\bconverted-pdf-page\b)[^>]*>/i',
            $html,
            $pageTag,
        ) || ! preg_match('/\bstyle=(["\'])(.*?)\1/i', $pageTag[0], $styleMatch)) {
            return null;
        }

        $style = $styleMatch[2];
        if (! preg_match('/(?:^|;)\s*width\s*:\s*([0-9]+(?:\.[0-9]+)?)pt\s*(?:;|$)/i', $style, $widthMatch)
            || ! preg_match('/(?:^|;)\s*min-height\s*:\s*([0-9]+(?:\.[0-9]+)?)pt\s*(?:;|$)/i', $style, $heightMatch)) {
            return null;
        }

        $width = (float) $widthMatch[1];
        $height = (float) $heightMatch[1];
        if ($width <= 0 || $height <= 0 || $width > 5000 || $height > 5000) {
            return null;
        }

        return [$width, $height];
    }

    private function wrapConvertedPdfHtml(string $html, float $pageWidth, float $pageHeight): string
    {
        $width = $pageWidth.'pt';
        $height = $pageHeight.'pt';

        return '<!doctype html><html><head><meta charset="UTF-8"><style>'
            .'@page{margin:0}html,body{width:'.$width.';margin:0;padding:0;background:#fff}'
            .'body{font-family:DejaVu Sans,sans-serif;font-size:11pt;line-height:1.45;color:#1f2937}'
            .'p{margin:0 0 10pt}.converted-pdf-page{box-sizing:border-box;width:'.$width.';min-height:0!important;margin:0;border:0;background:#fff;page-break-inside:auto}'
            .'.converted-pdf-header{display:block;width:100%;height:auto;margin:0}'
            .'.converted-pdf-table{table-layout:fixed;border-collapse:collapse;font-size:6pt;line-height:1.1;margin-top:0;margin-bottom:12pt}'
            .'.converted-pdf-table td,.converted-pdf-table th{border:1px solid #111;padding:1pt .5pt;vertical-align:middle}'
            .'.converted-pdf-table .converted-pdf-header-row th{background:#d3d3d3;color:#111;text-align:center;font-size:6pt}'
            .'.converted-pdf-table .converted-pdf-section-cell{background:#163666;color:#fff;font-weight:bold}'
            .'.converted-pdf-table .converted-pdf-cell{background:#fff;color:#111}'
            .'.converted-pdf-text{margin-top:2pt;margin-bottom:4pt;font-size:10pt;line-height:1.25;white-space:normal}'
            .'.converted-pdf-page-break{height:0;page-break-before:always}'
            .'</style></head><body>'.$html.'</body></html>';
    }

    private function convertEmbeddedPngImagesForPdf(string $html): string
    {
        if (extension_loaded('gd')) {
            return $html;
        }

        $pattern = '~(\bsrc=)(["\'])data:image/png;base64,([A-Za-z0-9+/=]+)\2~i';
        if (! preg_match_all($pattern, $html, $matches, PREG_SET_ORDER)) {
            return $html;
        }

        $pngImages = array_values(array_unique(array_column($matches, 3)));
        $python = $this->resolvePdfPython();
        if (! $this->pdfPythonIsAvailable($python)) {
            throw new RuntimeException(
                'This contract contains PNG images, but PHP GD is unavailable. Install GD or configure '
                .'Python 3 with PyMuPDF using PDF_LAYOUT_PYTHON to export it.',
            );
        }

        $script = <<<'PY'
import base64, json, sys
import pymupdf

converted = []
for encoded in json.load(sys.stdin):
    pixmap = pymupdf.Pixmap(base64.b64decode(encoded, validate=True))
    if pixmap.alpha:
        pixmap = pymupdf.Pixmap(pixmap, 0)
    if pixmap.colorspace != pymupdf.csRGB:
        pixmap = pymupdf.Pixmap(pymupdf.csRGB, pixmap)
    converted.append(base64.b64encode(pixmap.tobytes("jpg", jpg_quality=92)).decode("ascii"))

json.dump(converted, sys.stdout)
PY;
        $process = new Process([$python, '-c', $script]);
        $process->setInput(json_encode($pngImages, JSON_THROW_ON_ERROR));
        $process->setTimeout(120);
        $process->run();

        if (! $process->isSuccessful()) {
            $errorOutput = trim($process->getErrorOutput());
            throw new RuntimeException(
                'PNG images could not be prepared for PDF export.'
                .($errorOutput !== '' ? ' Details: '.$errorOutput : ''),
            );
        }

        $jpegImages = json_decode($process->getOutput(), true);
        if (! is_array($jpegImages) || count($jpegImages) !== count($pngImages)) {
            throw new RuntimeException('PNG images could not be prepared for PDF export.');
        }

        foreach ($jpegImages as $jpegImage) {
            if (! is_string($jpegImage)) {
                throw new RuntimeException('PNG images could not be prepared for PDF export.');
            }
        }

        $jpegByPng = array_combine($pngImages, $jpegImages);
        $convertedHtml = preg_replace_callback(
            $pattern,
            static fn (array $match): string => $match[1].$match[2]
                .'data:image/jpeg;base64,'.$jpegByPng[$match[3]].$match[2],
            $html,
        );

        if ($convertedHtml === null) {
            throw new RuntimeException('PNG images could not be prepared for PDF export.');
        }

        return $convertedHtml;
    }

    public function renderHtmlPreview(string $html): string
    {
        return $this->wrapHtml($html);
    }

    public function renderDocx(string $html): string
    {
        $document = $this->parseFragment($html);
        $root = $this->fragmentRoot($document);
        $headerContent = null;
        $footerContent = null;
        $sections = [];
        foreach ($root->childNodes as $child) {
            if ($child instanceof DOMElement && in_array(strtolower($child->tagName), ['header', 'footer'], true)) {
                $sections[] = $child;
            }
        }
        foreach ($sections as $section) {
            $sectionTag = strtolower($section->tagName);
            $sectionContent = '';
            foreach ($section->childNodes as $child) {
                $sectionContent .= $this->renderWordBlock($child);
            }
            if ($sectionTag === 'header') {
                $headerContent = ($headerContent ?? '').$sectionContent;
            } else {
                $footerContent = ($footerContent ?? '').$sectionContent;
            }
            $root->removeChild($section);
        }

        $body = '';
        foreach ($root->childNodes as $child) {
            $body .= $this->renderWordBlock($child);
        }

        if ($body === '') {
            $body = '<w:p/>';
        }

        $documentRelationships = '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
        $sectionReferences = '';
        $contentOverrides = '';
        $sectionEntries = [];
        if ($headerContent !== null) {
            $documentRelationships .= '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/header" Target="header1.xml"/>';
            $sectionReferences .= '<w:headerReference w:type="default" r:id="rId2"/>';
            $contentOverrides .= '<Override PartName="/word/header1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.header+xml"/>';
            $sectionEntries['word/header1.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<w:hdr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
                .($headerContent !== '' ? $headerContent : '<w:p/>').'</w:hdr>';
        }
        if ($footerContent !== null) {
            $footerRelationshipId = $headerContent !== null ? 'rId3' : 'rId2';
            $documentRelationships .= '<Relationship Id="'.$footerRelationshipId.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/footer" Target="footer1.xml"/>';
            $sectionReferences .= '<w:footerReference w:type="default" r:id="'.$footerRelationshipId.'"/>';
            $contentOverrides .= '<Override PartName="/word/footer1.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.footer+xml"/>';
            $sectionEntries['word/footer1.xml'] = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<w:ftr xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
                .($footerContent !== '' ? $footerContent : '<w:p/>').'</w:ftr>';
        }

        $entries = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
                .'<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
                .$contentOverrides.'</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
                .'</Relationships>',
            'word/document.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
                .'<w:body>'.$body.'<w:sectPr>'.$sectionReferences.'<w:pgSz w:w="11906" w:h="16838"/>'
                .'<w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="708" w:footer="708" w:gutter="0"/>'
                .'</w:sectPr></w:body></w:document>',
            'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .$documentRelationships
                .'</Relationships>',
            'word/styles.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
                .'<w:style w:type="paragraph" w:default="1" w:styleId="Normal"><w:name w:val="Normal"/>'
                .'<w:rPr><w:rFonts w:ascii="Calibri" w:hAnsi="Calibri"/><w:sz w:val="22"/></w:rPr></w:style>'
                .'<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/>'
                .'<w:pPr><w:spacing w:before="240" w:after="120"/></w:pPr><w:rPr><w:b/><w:sz w:val="32"/></w:rPr></w:style>'
                .'<w:style w:type="paragraph" w:styleId="Heading2"><w:name w:val="heading 2"/>'
                .'<w:pPr><w:spacing w:before="200" w:after="100"/></w:pPr><w:rPr><w:b/><w:sz w:val="28"/></w:rPr></w:style>'
                .'<w:style w:type="paragraph" w:styleId="Heading3"><w:name w:val="heading 3"/>'
                .'<w:pPr><w:spacing w:before="160" w:after="80"/></w:pPr><w:rPr><w:b/><w:sz w:val="24"/></w:rPr></w:style>'
                .'</w:styles>',
        ] + $sectionEntries;

        $temporaryPath = tempnam(sys_get_temp_dir(), 'contract-docx-');
        if ($temporaryPath === false) {
            throw new RuntimeException('A temporary document could not be created.');
        }

        $archive = new ZipArchive();
        $opened = false;
        try {
            if ($archive->open($temporaryPath, ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('The Word document could not be created.');
            }
            $opened = true;

            foreach ($entries as $name => $contents) {
                if (! $archive->addFromString($name, $contents)) {
                    throw new RuntimeException('The Word document could not be written.');
                }
            }

            if (! $archive->close()) {
                $opened = false;
                throw new RuntimeException('The Word document could not be finalized.');
            }
            $opened = false;

            $docx = file_get_contents($temporaryPath);
            if ($docx === false) {
                throw new RuntimeException('The Word document could not be read.');
            }

            return $docx;
        } finally {
            if ($opened) {
                $archive->close();
            }
            unlink($temporaryPath);
        }
    }

    private function parseFragment(string $html): DOMDocument
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previousErrorSetting = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML(
                '<?xml encoding="UTF-8"><!DOCTYPE html><html><body><div id="contract-template">'.$html.'</div></body></html>',
                LIBXML_NONET | LIBXML_HTML_NODEFDTD,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrorSetting);
        }

        if (! $loaded) {
            throw new RuntimeException('The contract content could not be parsed.');
        }

        return $document;
    }

    private function fragmentRoot(DOMDocument $document): DOMElement
    {
        $root = (new \DOMXPath($document))->query('//*[@id="contract-template"]')?->item(0);
        if (! $root instanceof DOMElement) {
            throw new RuntimeException('The contract content is missing its document body.');
        }

        return $root;
    }

    private function sanitizeChildren(DOMNode $parent): void
    {
        $children = [];
        foreach ($parent->childNodes as $child) {
            $children[] = $child;
        }

        foreach ($children as $child) {
            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);
            if (in_array($tag, self::DROP_TAGS, true)) {
                $parent->removeChild($child);

                continue;
            }

            $this->sanitizeChildren($child);
            if (! in_array($tag, self::ALLOWED_TAGS, true)) {
                while ($child->firstChild !== null) {
                    $parent->insertBefore($child->firstChild, $child);
                }
                $parent->removeChild($child);

                continue;
            }

            $this->sanitizeAttributes($child, $tag);
        }
    }

    private function sanitizeAttributes(DOMElement $element, string $tag): void
    {
        for ($index = $element->attributes->length - 1; $index >= 0; $index--) {
            $attribute = $element->attributes->item($index);
            if ($attribute === null) {
                continue;
            }

            $name = strtolower($attribute->name);
            $value = trim($attribute->value);
            if ($name === 'class' && in_array($value, self::CONVERTED_PDF_CLASSES, true)) {
                $element->setAttribute('class', $value);
                continue;
            }
            if ($name === 'style' && ($safeStyle = $this->sanitizeConvertedPdfStyle($tag, $value)) !== null) {
                $element->setAttribute('style', $safeStyle);

                continue;
            }
            if ($name === 'href' && $tag === 'a'
                && preg_match('/^(https?:\/\/|mailto:)/i', $value)) {
                $element->setAttribute('href', $value);

                continue;
            }
            if ($name === 'src' && $tag === 'img' && strlen($value) <= 5000000
                && preg_match('#^data:image/(?:png|jpeg);base64,[A-Za-z0-9+/=]+$#', $value)) {
                $element->setAttribute('src', $value);

                continue;
            }
            if ($name === 'width' && $tag === 'col'
                && preg_match('/^(100(?:\.0+)?|[0-9]{1,2}(?:\.[0-9]+)?)%$/', $value)) {
                $element->setAttribute($name, $value);

                continue;
            }
            if ($name === 'alt' && $tag === 'img') {
                $element->setAttribute('alt', $value);

                continue;
            }
            if (in_array($name, ['colspan', 'rowspan'], true) && in_array($tag, ['td', 'th'], true)
                && ctype_digit($value) && (int) $value >= 1 && (int) $value <= 1000) {
                $element->setAttribute($name, $value);

                continue;
            }
            if ($name === 'start' && $tag === 'ol' && ctype_digit($value) && (int) $value >= 1) {
                $element->setAttribute($name, $value);

                continue;
            }

            $element->removeAttributeNode($attribute);
        }
    }

    private function sanitizeConvertedPdfStyle(string $tag, string $style): ?string
    {
        $safe = [];
        foreach (explode(';', $style) as $declaration) {
            if (! str_contains($declaration, ':')) {
                continue;
            }
            [$property, $value] = array_map('trim', explode(':', $declaration, 2));
            $property = strtolower($property);
            $value = strtolower($value);

            if (in_array($property, ['width', 'margin-left'], true)
                && preg_match('/^(100(?:\.0+)?|[0-9]{1,2}(?:\.[0-9]+)?)%$/', $value)
                && ($tag === 'table' && in_array($property, ['width', 'margin-left'], true)
                    || $tag === 'col' && $property === 'width'
                    || $tag === 'p' && in_array($property, ['width', 'margin-left'], true))) {
                $safe[] = $property.':'.$value;
            } elseif ($tag === 'div' && in_array($property, ['width', 'min-height'], true)
                && preg_match('/^[0-9]{1,5}(?:\.[0-9]+)?pt$/', $value)) {
                $safe[] = $property.':'.$value;
            } elseif ($property === 'text-align' && in_array($tag, ['td', 'th'], true)
                && in_array($value, ['left', 'center', 'right'], true)) {
                $safe[] = $property.':'.$value;
            }
        }

        return $safe === [] ? null : implode(';', $safe);
    }

    private function renderWordBlock(DOMNode $node): string
    {
        if (! $node instanceof DOMElement) {
            if ($node->nodeType === XML_TEXT_NODE && trim($node->textContent) !== '') {
                return $this->wordParagraph($this->wordRun($node->textContent));
            }

            return '';
        }

        $tag = strtolower($node->tagName);
        if ($tag === 'table') {
            return $this->renderWordTable($node);
        }
        if ($tag === 'ul' || $tag === 'ol') {
            $list = '';
            $number = (int) ($node->getAttribute('start') ?: 1);
            foreach ($node->childNodes as $item) {
                if (! $item instanceof DOMElement || strtolower($item->tagName) !== 'li') {
                    continue;
                }
                $marker = $tag === 'ul' ? '• ' : ($number++).'. ';
                $list .= $this->wordParagraph($this->wordRun($marker).$this->renderWordInline($item));
            }

            return $list;
        }

        if (in_array($tag, ['p', 'div', 'blockquote', 'h1', 'h2', 'h3'], true)) {
            $style = in_array($tag, ['h1', 'h2', 'h3'], true)
                ? '<w:pStyle w:val="Heading'.substr($tag, 1).'"/>'
                : '';
            $alignment = $this->wordAlignment($node);
            $indent = $tag === 'blockquote' ? '<w:ind w:left="720"/>' : '';
            $properties = ($style !== '' || $alignment !== '' || $indent !== '')
                ? '<w:pPr>'.$style.$alignment.$indent.'</w:pPr>'
                : '';

            return '<w:p>'.$properties.$this->renderWordInline($node).'</w:p>';
        }

        return $this->wordParagraph($this->renderWordInline($node));
    }

    private function renderWordTable(DOMElement $table): string
    {
        $rows = '';
        foreach ($table->childNodes as $section) {
            if (! $section instanceof DOMElement) {
                continue;
            }
            if (strtolower($section->tagName) === 'tr') {
                $rows .= $this->renderWordRow($section);

                continue;
            }
            foreach ($section->childNodes as $row) {
                if ($row instanceof DOMElement && strtolower($row->tagName) === 'tr') {
                    $rows .= $this->renderWordRow($row);
                }
            }
        }

        return '<w:tbl><w:tblPr><w:tblBorders>'
            .'<w:top w:val="single" w:sz="4" w:color="B7C8D5"/>'
            .'<w:left w:val="single" w:sz="4" w:color="B7C8D5"/>'
            .'<w:bottom w:val="single" w:sz="4" w:color="B7C8D5"/>'
            .'<w:right w:val="single" w:sz="4" w:color="B7C8D5"/>'
            .'<w:insideH w:val="single" w:sz="4" w:color="B7C8D5"/>'
            .'<w:insideV w:val="single" w:sz="4" w:color="B7C8D5"/>'
            .'</w:tblBorders></w:tblPr>'.$rows.'</w:tbl>';
    }

    private function renderWordRow(DOMElement $row): string
    {
        $cells = '';
        foreach ($row->childNodes as $cell) {
            if (! $cell instanceof DOMElement || ! in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                continue;
            }
            $content = '';
            foreach ($cell->childNodes as $child) {
                $content .= $this->renderWordBlock($child);
            }
            if ($content === '') {
                $content = '<w:p/>';
            }

            $cells .= '<w:tc><w:tcPr><w:tcW w:w="0" w:type="auto"/>'
                .($cell->hasAttribute('colspan') ? '<w:gridSpan w:val="'.$cell->getAttribute('colspan').'"/>' : '')
                .'</w:tcPr>'.$content.'</w:tc>';
        }

        return '<w:tr>'.$cells.'</w:tr>';
    }

    private function renderWordInline(DOMNode $parent, array $format = []): string
    {
        $output = '';
        foreach ($parent->childNodes as $child) {
            if ($child->nodeType === XML_TEXT_NODE) {
                $output .= $this->wordRun($child->textContent, $format);

                continue;
            }
            if (! $child instanceof DOMElement) {
                continue;
            }

            $tag = strtolower($child->tagName);
            if ($tag === 'br') {
                $output .= '<w:r><w:br/></w:r>';

                continue;
            }

            $childFormat = $format;
            if (in_array($tag, ['b', 'strong'], true)) {
                $childFormat['bold'] = true;
            } elseif (in_array($tag, ['i', 'em'], true)) {
                $childFormat['italic'] = true;
            } elseif ($tag === 'u' || $tag === 'a') {
                $childFormat['underline'] = true;
            } elseif ($tag === 's') {
                $childFormat['strike'] = true;
            }
            $output .= $this->renderWordInline($child, $childFormat);
        }

        return $output;
    }

    private function wordRun(string $text, array $format = []): string
    {
        if ($text === '') {
            return '';
        }

        $properties = '';
        if (! empty($format['bold'])) {
            $properties .= '<w:b/>';
        }
        if (! empty($format['italic'])) {
            $properties .= '<w:i/>';
        }
        if (! empty($format['underline'])) {
            $properties .= '<w:u w:val="single"/>';
        }
        if (! empty($format['strike'])) {
            $properties .= '<w:strike/>';
        }
        $runProperties = $properties !== '' ? '<w:rPr>'.$properties.'</w:rPr>' : '';

        return '<w:r>'.$runProperties.'<w:t xml:space="preserve">'
            .htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8')
            .'</w:t></w:r>';
    }

    private function wordParagraph(string $contents): string
    {
        return '<w:p>'.$contents.'</w:p>';
    }

    private function wordAlignment(DOMElement $element): string
    {
        if (! preg_match('/text-align:\s*(left|center|right|justify)/i', $element->getAttribute('style'), $match)) {
            return '';
        }

        $alignment = strtolower($match[1]);

        return '<w:jc w:val="'.$alignment.'"/>';
    }

    private function wrapHtml(string $html): string
    {
        return '<!doctype html><html><head><meta charset="UTF-8"><style>'
            .'@page{margin:2cm}body{margin:0;padding:24px 12px;background:#e8eef5;font-family:DejaVu Sans,sans-serif;font-size:11pt;line-height:1.45;color:#1f2937}'
            .'p{margin:0 0 10pt}h1{font-size:20pt;margin:0 0 14pt}h2{font-size:16pt;margin:14pt 0 8pt}'
            .'h3{font-size:13pt;margin:12pt 0 6pt}table{width:100%;border-collapse:collapse;margin:10pt 0}'
            .'th,td{border:1px solid #111;padding:3pt;text-align:left}th{background:#d3d3d3;color:#111;font-size:8pt}'
            .'.converted-pdf-page{box-sizing:border-box;margin:0 auto 20pt;border:1px solid #d1d5db;background:#fff;box-shadow:0 2px 8px #173b5912;page-break-after:always}.converted-pdf-header{display:block;width:100%;height:auto;margin-bottom:8pt}'
            .'.converted-pdf-table{table-layout:fixed;border-collapse:collapse;font-size:7pt;line-height:1.15;margin-top:3pt;margin-bottom:5pt}'
            .'.converted-pdf-table td,.converted-pdf-table th{border:1px solid #111;padding:2pt 3pt;vertical-align:middle}'
            .'.converted-pdf-table .converted-pdf-header-row th{background:#d3d3d3;color:#111;text-align:center;font-size:7pt}'
            .'.converted-pdf-table .converted-pdf-section-cell{background:#163666;color:#fff;font-weight:bold}'
            .'.converted-pdf-table .converted-pdf-cell{background:#fff;color:#111}'
            .'header{position:fixed;top:-1.5cm;left:0;right:0;height:auto;font-size:9pt}'
            .'footer{position:fixed;bottom:-1.5cm;left:0;right:0;height:auto;font-size:9pt}'
            .'blockquote{margin:8pt 0 8pt 20pt}'
            .'ul,ol{margin:0 0 10pt;padding-left:22pt}</style></head><body>'.$html.'</body></html>';
    }
}
