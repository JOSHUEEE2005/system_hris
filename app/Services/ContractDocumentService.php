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
    private array $wordImages = [];

    private array $wordImageNamesByHash = [];

    private int $wordDrawingId = 0;

    private ?int $wordConvertedPageWidthTwips = null;

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
        'converted-pdf-spacer',
        'converted-pdf-image',
        'converted-pdf-text',
        'converted-pdf-table',
        'converted-pdf-layout',
        'converted-pdf-section-row',
        'converted-pdf-separator-row',
        'converted-pdf-header-row',
        'converted-pdf-section-cell',
        'converted-pdf-heading-cell',
        'converted-pdf-cell',
        'converted-pdf-checkbox-cell',
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
        $scriptPath = tempnam(sys_get_temp_dir(), 'contract-pdf-script-');
        if ($pdfPath === false || $conversionPath === false || $scriptPath === false
            || file_put_contents($pdfPath, $contents) === false) {
            if (is_string($pdfPath)) {
                @unlink($pdfPath);
            }
            if (is_string($conversionPath)) {
                @unlink($conversionPath);
            }
            if (is_string($scriptPath)) {
                @unlink($scriptPath);
            }
            throw new RuntimeException('The uploaded PDF could not be prepared for conversion.');
        }

        $python = $this->resolvePdfPython();
        if (! $this->pdfPythonIsAvailable($python)) {
            @unlink($pdfPath);
            @unlink($conversionPath);
            @unlink($scriptPath);
            throw new RuntimeException(
                'Python 3 was not found by the application server. Install Python 3 and PyMuPDF, '
                .'or set PDF_LAYOUT_PYTHON to the executable path.',
            );
        }

        $script = <<<'PY'
import base64, json, sys
from collections import Counter
from statistics import median
import pymupdf

document = pymupdf.open(sys.argv[1])
pages = []
for page in document:
    found = page.find_tables()
    drawings = page.get_drawings()
    images = page.get_image_info(xrefs=True)
    small_image_counts = Counter(
        image["xref"]
        for image in images
        if image.get("xref")
        and image["bbox"][2] - image["bbox"][0] <= 12
        and image["bbox"][3] - image["bbox"][1] <= 12
    )
    checkbox_images = [
        image for image in images
        if image.get("xref")
        and small_image_counts[image["xref"]] >= 3
        and 0.6 <= (image["bbox"][2] - image["bbox"][0]) / max(1, image["bbox"][3] - image["bbox"][1]) <= 1.4
        and image["bbox"][2] - image["bbox"][0] <= 12
        and image["bbox"][3] - image["bbox"][1] <= 12
    ]
    tables = []
    for table in found.tables:
        bbox = list(table.bbox)
        vertical_segments = []
        horizontal_segments = []
        # Use drawn borders when the table detector omits empty outer columns.
        for drawing in drawings:
            for item in drawing["items"]:
                if item[0] != "re":
                    continue
                rect = item[1]
                if rect.width <= 2 and rect.height >= 2:
                    top = max(bbox[1] - 4, rect.y0)
                    bottom = min(bbox[3] + 4, rect.y1)
                    if bottom > top:
                        vertical_segments.append(((rect.x0 + rect.x1) / 2, top, bottom))
                elif rect.height <= 2 and rect.width >= 2:
                    y = (rect.y0 + rect.y1) / 2
                    if abs(y - bbox[1]) <= 8 or abs(y - bbox[3]) <= 8:
                        horizontal_segments.append((y, rect.x0, rect.x1))

        vertical_clusters = []
        for x, top, bottom in sorted(vertical_segments):
            if not vertical_clusters or x - vertical_clusters[-1][0] > 2:
                vertical_clusters.append([x, [(top, bottom)]])
            else:
                vertical_clusters[-1][1].append((top, bottom))

        columns = []
        for x, segments in vertical_clusters:
            covered = 0
            last_bottom = None
            for top, bottom in sorted(segments):
                if last_bottom is None or top > last_bottom + 2:
                    covered += bottom - top
                elif bottom > last_bottom:
                    covered += bottom - last_bottom
                last_bottom = max(last_bottom or bottom, bottom)
            if covered >= max(6, min(12, (bbox[3] - bbox[1]) * 0.15)):
                columns.append(x)

        horizontal_clusters = []
        for y, left, right in sorted(horizontal_segments):
            if not horizontal_clusters or y - horizontal_clusters[-1][0] > 2:
                horizontal_clusters.append([y, [(left, right)]])
            else:
                horizontal_clusters[-1][1].append((left, right))

        row_boundaries = []
        left_edge = columns[0] if len(columns) >= 2 else bbox[0]
        right_edge = columns[-1] if len(columns) >= 2 else bbox[2]
        for y, segments in horizontal_clusters:
            intervals = sorted((max(left_edge, left), min(right_edge, right)) for left, right in segments)
            covered = 0
            last_right = None
            for left, right in intervals:
                if right <= left:
                    continue
                if last_right is None or left > last_right:
                    covered += right - left
                elif right > last_right:
                    covered += right - last_right
                last_right = max(last_right or right, right)
            if covered >= (right_edge - left_edge) * 0.75:
                row_boundaries.append(y)

        if len(columns) >= 2:
            bbox[0] = columns[0]
            bbox[2] = columns[-1]
        above = [y for y in row_boundaries if 0 <= bbox[1] - y <= 8]
        below = [y for y in row_boundaries if 0 <= y - bbox[3] <= 8]
        if above:
            bbox[1] = max(above)
        if below:
            bbox[3] = min(below)

        blue_segments = []
        for drawing in drawings:
            fill = drawing.get("fill")
            rect = drawing["rect"]
            if fill and all(abs(actual - expected) < 0.01 for actual, expected in zip(fill, (0.122, 0.22, 0.388))) \
                    and 2 <= rect.height <= 8 and rect.y1 >= bbox[1] and rect.y0 <= bbox[3]:
                blue_segments.append(((rect.y0 + rect.y1) / 2, rect.x0, rect.x1))

        blue_clusters = []
        for y, left, right in sorted(blue_segments):
            if not blue_clusters or y - blue_clusters[-1][0] > 1:
                blue_clusters.append([y, [(left, right)]])
            else:
                blue_clusters[-1][1].append((left, right))

        blue_bands = []
        for y, segments in blue_clusters:
            intervals = sorted((max(bbox[0], left), min(bbox[2], right)) for left, right in segments)
            covered = 0
            last_right = None
            for left, right in intervals:
                if right <= left:
                    continue
                if last_right is None or left > last_right:
                    covered += right - left
                elif right > last_right:
                    covered += right - last_right
                last_right = max(last_right or right, right)
            if covered >= (bbox[2] - bbox[0]) * 0.75:
                blue_bands.append((y, y))

        extracted = table.extract()
        rows = []
        blue_separator_rows = []
        for row_index, row in enumerate(table.rows):
            cells = []
            cell_rectangles = []
            for column_index, cell in enumerate(row.cells):
                if cell is None:
                    cells.append(None)
                    continue
                cell_rect = pymupdf.Rect(cell)
                cell_rectangles.append(cell_rect)
                text = page.get_textbox(cell_rect).strip()
                if not text and row_index < len(extracted) and column_index < len(extracted[row_index]):
                    text = extracted[row_index][column_index] or ""
                for image in checkbox_images:
                    image_rect = pymupdf.Rect(image["bbox"])
                    image_center = pymupdf.Point(
                        image_rect.x0 + image_rect.width / 2,
                        image_rect.y0 + image_rect.height / 2,
                    )
                    if cell_rect.contains(image_center):
                        # Repeated small images in table cells are checkbox marks.
                        text = (text + " ☐").strip()
                cells.append({"rect": list(cell), "text": text})
            rows.append(cells)
            if cell_rectangles:
                row_top = median(rect.y0 for rect in cell_rectangles)
                row_height = median(rect.height for rect in cell_rectangles)
                if row_height <= 8 and any(row_top <= band[1] + 1 and row_top + row_height >= band[0] - 1 for band in blue_bands):
                    blue_separator_rows.append(row_index)
        tables.append({"bbox": bbox, "columns": columns, "rows": rows, "blue_separator_rows": blue_separator_rows})

    table_boxes = [pymupdf.Rect(table["bbox"]) for table in tables]
    blocks = []
    for block in page.get_text("dict", sort=True)["blocks"]:
        if "lines" not in block:
            continue
        visible_spans = []
        text_lines = []
        for line in block["lines"]:
            visible_line_spans = [
                span for span in line["spans"]
                if (span.get("text") or "").strip()
            ]
            if not visible_line_spans:
                continue
            visible_spans.extend(visible_line_spans)
            line_text = "".join(span.get("text", "") for span in line["spans"]).strip()
            if line_text:
                text_lines.append(line_text)
        text = "\n".join(text_lines)
        if not text or not visible_spans:
            continue
        span_rectangles = [pymupdf.Rect(span["bbox"]) for span in visible_spans]
        bounds = pymupdf.Rect(
            min(rect.x0 for rect in span_rectangles),
            min(rect.y0 for rect in span_rectangles),
            max(rect.x1 for rect in span_rectangles),
            max(rect.y1 for rect in span_rectangles),
        )
        if any(bounds.intersects(box) and bounds.intersect(box).get_area() > bounds.get_area() * 0.3 for box in table_boxes):
            continue
        blocks.append({
            "y": bounds.y0,
            "x": bounds.x0,
            "width": bounds.width,
            "height": bounds.height,
            "text": text,
        })

    image_items = []
    for image in images:
        image_rect = pymupdf.Rect(image["bbox"]) & page.rect
        image_center = pymupdf.Point(
            image_rect.x0 + image_rect.width / 2,
            image_rect.y0 + image_rect.height / 2,
        )
        if image in checkbox_images or image_rect.is_empty or any(
            table_box.contains(image_center) for table_box in table_boxes
        ):
            continue
        pixmap = page.get_pixmap(
            matrix=pymupdf.Matrix(1.5, 1.5),
            clip=image_rect,
            alpha=False,
        )
        image_items.append({
            "y": image_rect.y0,
            "x": image_rect.x0,
            "width": image_rect.width,
            "height": image_rect.height,
            "image": base64.b64encode(pixmap.tobytes("jpg", jpg_quality=88)).decode("ascii"),
        })

    items = blocks + image_items + [
        {
            "y": table["bbox"][1],
            "x": table["bbox"][0],
            "height": table["bbox"][3] - table["bbox"][1],
            "table": table,
        }
        for table in tables
    ]
    items.sort(key=lambda item: (item["y"], item["x"]))
    pages.append({
        "width": page.rect.width,
        "height": page.rect.height,
        "header": "",
        "items": items,
    })

with open(sys.argv[2], "w", encoding="utf-8") as output:
    json.dump({"pages": pages}, output, ensure_ascii=False)
PY;
        if (file_put_contents($scriptPath, $script) === false) {
            @unlink($pdfPath);
            @unlink($conversionPath);
            @unlink($scriptPath);
            throw new RuntimeException('The PDF conversion script could not be prepared.');
        }

        $process = new Process([$python, $scriptPath, $pdfPath, $conversionPath]);
        $process->setTimeout(270);
        try {
            $process->run();
        } finally {
            @unlink($pdfPath);
            @unlink($scriptPath);
        }
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
                $html .= '<img class="converted-pdf-header" src="data:image/jpeg;base64,'.$page['header'].'" alt="Original PDF page header" style="width:'.$pageWidth.'pt">';
            }
            $previousBottom = 0.0;
            $headerGroups = $this->overlappingPdfHeaderGroups($page['items'], $pageWidth, $pageHeight);
            $groupedItems = [];
            foreach ($page['items'] as $itemIndex => $item) {
                if (isset($headerGroups[$itemIndex])) {
                    $group = $headerGroups[$itemIndex];
                    $itemY = max(0, (float) ($item['y'] ?? 0));
                    $gap = max(0, $itemY - $previousBottom);
                    if ($gap >= 1) {
                        $html .= '<p class="converted-pdf-spacer" style="margin-top:'.number_format($gap, 2, '.', '').'pt"></p>';
                    }
                    $html .= $group['html'];
                    $previousBottom = max($previousBottom, $group['bottom']);
                    foreach ($group['indices'] as $groupedIndex) {
                        $groupedItems[$groupedIndex] = true;
                    }

                    continue;
                }
                if (isset($groupedItems[$itemIndex])) {
                    continue;
                }

                $itemY = max(0, (float) ($item['y'] ?? 0));
                $gap = max(0, $itemY - $previousBottom);
                if ($gap >= 1) {
                    $html .= '<p class="converted-pdf-spacer" style="margin-top:'.number_format($gap, 2, '.', '').'pt"></p>';
                }

                if (isset($item['table'])) {
                    $html .= $this->renderExtractedPdfTable($item['table'], (float) $page['width']);
                } elseif (isset($item['image'])) {
                    $left = max(0, min(100, (float) $item['x'] / $pageWidth * 100));
                    $width = max(1, min(100 - $left, (float) $item['width'] / $pageWidth * 100));
                    $imageWidth = max(1, (float) $item['width']);
                    $html .= '<p class="converted-pdf-text" style="margin-left:'.$left.'%;width:'.$width.'%">'
                        .'<img class="converted-pdf-image" src="data:image/jpeg;base64,'.$item['image'].'" '
                        .'alt="Image from original PDF" style="width:'.number_format($imageWidth, 2, '.', '').'pt">'
                        .'</p>';
                } else {
                    $text = nl2br(htmlspecialchars((string) $item['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
                    $left = max(0, min(100, (float) $item['x'] / $pageWidth * 100));
                    $width = max(1, min(100 - $left, (float) ($item['width'] ?? $pageWidth) / $pageWidth * 100));
                    $html .= '<p class="converted-pdf-text" style="margin-left:'.$left.'%;width:'.$width.'%">'.$text.'</p>';
                }

                $previousBottom = max(
                    $previousBottom,
                    $itemY + max(0, (float) ($item['height'] ?? 0)),
                );
            }
            $html .= '</div>';
            if ($pageIndex < count($pages) - 1) {
                $html .= '<div class="converted-pdf-page-break"></div>';
            }
        }

        return $html;
    }

    private function overlappingPdfHeaderGroups(array $items, float $pageWidth, float $pageHeight): array
    {
        $groups = [];
        foreach ($items as $imageIndex => $image) {
            if (! isset($image['image'])) {
                continue;
            }

            $imageY = max(0, (float) ($image['y'] ?? 0));
            $imageX = max(0, (float) ($image['x'] ?? 0));
            $imageWidth = max(0, (float) ($image['width'] ?? 0));
            $imageHeight = max(0, (float) ($image['height'] ?? 0));
            $imageRight = $imageX + $imageWidth;
            $imageBottom = $imageY + $imageHeight;
            if ($imageWidth <= 0 || $imageHeight <= 0 || $imageY > $pageHeight * 0.2
                || $imageRight >= $pageWidth) {
                continue;
            }

            $textIndices = [];
            foreach ($items as $itemIndex => $item) {
                if (! isset($item['text'])) {
                    continue;
                }

                $itemY = max(0, (float) ($item['y'] ?? 0));
                $itemX = max(0, (float) ($item['x'] ?? 0));
                $itemHeight = max(0, (float) ($item['height'] ?? 0));
                if ($itemY < $imageY || $itemY >= $imageBottom || $itemY + $itemHeight <= $imageY
                    || $itemY > $pageHeight * 0.25 || $itemX < $imageRight) {
                    continue;
                }

                $textIndices[] = $itemIndex;
            }

            if ($textIndices === []) {
                continue;
            }

            sort($textIndices);
            $textItems = array_map(static fn (int $index): array => $items[$index], $textIndices);
            $groups[$imageIndex] = [
                'html' => $this->renderOverlappingPdfHeader($image, $textItems, $imageX, $imageWidth, $pageWidth),
                'indices' => $textIndices,
                'bottom' => max($imageBottom, ...array_map(
                    static fn (array $item): float => max(0, (float) $item['y']) + max(0, (float) ($item['height'] ?? 0)),
                    $textItems,
                )),
            ];
        }

        return $groups;
    }

    private function renderOverlappingPdfHeader(
        array $image,
        array $textItems,
        float $imageX,
        float $imageWidth,
        float $pageWidth,
    ): string {
        $imageRight = $imageX + $imageWidth;
        $textGroups = [];
        foreach ($textItems as $item) {
            $itemX = max(0, (float) ($item['x'] ?? 0));
            $groupIndex = null;
            foreach ($textGroups as $index => $group) {
                if (abs($group['x'] - $itemX) < 0.01) {
                    $groupIndex = $index;
                    break;
                }
            }
            if ($groupIndex === null) {
                $textGroups[] = ['x' => $itemX, 'items' => [$item]];
            } else {
                $textGroups[$groupIndex]['items'][] = $item;
            }
        }

        $boundaries = array_merge(
            [0, $imageX, $imageRight, $pageWidth],
            array_column($textGroups, 'x'),
        );
        sort($boundaries, SORT_NUMERIC);
        $boundaries = array_values(array_reduce($boundaries, static function (array $unique, float $boundary): array {
            if ($unique === [] || abs(end($unique) - $boundary) >= 0.01) {
                $unique[] = $boundary;
            }

            return $unique;
        }, []));
        $columnWidths = [];
        for ($index = 0; $index < count($boundaries) - 1; $index++) {
            $columnWidths[] = max(0, ($boundaries[$index + 1] - $boundaries[$index]) / $pageWidth * 100);
        }
        $html = '<table class="converted-pdf-layout" style="width:100%;margin-left:0%"><colgroup>';
        foreach ($columnWidths as $columnWidth) {
            $html .= '<col style="width:'.number_format($columnWidth, 6, '.', '').'%">';
        }
        $html .= '</colgroup><tbody><tr>';
        foreach ($boundaries as $index => $boundary) {
            if ($index >= count($boundaries) - 1) {
                break;
            }

            $html .= '<td>';
            if (abs($boundary - $imageX) < 0.01) {
                $html .= '<p class="converted-pdf-text"><img class="converted-pdf-image" '
                    .'src="data:image/jpeg;base64,'.$image['image'].'" alt="Image from original PDF" '
                    .'style="width:'.number_format($imageWidth, 2, '.', '').'pt"></p>';
            }
            foreach ($textGroups as $group) {
                if (abs($boundary - $group['x']) < 0.01) {
                    $html .= $this->renderOverlappingPdfHeaderText($group['items'], max(0, (float) ($image['y'] ?? 0)));
                }
            }
            $html .= '</td>';
        }

        return $html.'</tr></tbody></table>';
    }

    private function renderOverlappingPdfHeaderText(array $items, float $imageY): string
    {
        $html = '';
        $previousBottom = $imageY;
        foreach ($items as $item) {
            $itemY = max(0, (float) ($item['y'] ?? 0));
            $gap = max(0, $itemY - $previousBottom);
            if ($gap >= 1) {
                $html .= '<p class="converted-pdf-spacer" style="margin-top:'.number_format($gap, 2, '.', '').'pt"></p>';
            }
            $text = nl2br(htmlspecialchars((string) $item['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
            $html .= '<p class="converted-pdf-text">'.$text.'</p>';
            $previousBottom = max($previousBottom, $itemY + max(0, (float) ($item['height'] ?? 0)));
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

        $xBoundaries = array_values(array_filter($table['columns'] ?? [], 'is_numeric'));
        if (count($xBoundaries) < 2) {
            $xBoundaries = [(float) $box[0], (float) $box[2]];
            foreach ($rows as $row) {
                foreach ($row as $cell) {
                    if (is_array($cell) && isset($cell['rect'])) {
                        $xBoundaries[] = (float) $cell['rect'][0];
                        $xBoundaries[] = (float) $cell['rect'][2];
                    }
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
        $rowHeights = [];
        $rowTexts = [];
        foreach ($rows as $row) {
            $tops = [];
            $heights = [];
            $texts = [];
            foreach ($row as $cell) {
                if (is_array($cell) && isset($cell['rect'])) {
                    $tops[] = (float) $cell['rect'][1];
                    $heights[] = max(0, (float) $cell['rect'][3] - (float) $cell['rect'][1]);
                    $texts[] = trim((string) ($cell['text'] ?? ''));
                }
            }
            $rowTops[] = $tops === [] ? null : min($tops);
            sort($heights, SORT_NUMERIC);
            $heightCount = count($heights);
            $middle = intdiv($heightCount, 2);
            $rowHeights[] = $heightCount === 0
                ? 0
                : ($heightCount % 2 === 1
                    ? $heights[$middle]
                    : ($heights[$middle - 1] + $heights[$middle]) / 2);
            $rowTexts[] = trim(implode('', $texts));
        }

        $skipRows = [];
        for ($rowIndex = 1; $rowIndex < count($rows) - 1; $rowIndex++) {
            if ($rowTexts[$rowIndex] !== ''
                || $rowTops[$rowIndex] === null
                || $rowTops[$rowIndex - 1] === null
                || $rowTops[$rowIndex + 1] === null) {
                continue;
            }

            $previousBottom = $rowTops[$rowIndex - 1] + $rowHeights[$rowIndex - 1];
            $currentBottom = $rowTops[$rowIndex] + $rowHeights[$rowIndex];
            $nextBottom = $rowTops[$rowIndex + 1] + $rowHeights[$rowIndex + 1];
            if ($rowTops[$rowIndex] < $previousBottom - 1
                && $currentBottom >= $nextBottom - 1) {
                $skipRows[$rowIndex] = true;
            }
        }

        foreach ($rows as $rowIndex => $row) {
            if (isset($skipRows[$rowIndex])) {
                continue;
            }
            $texts = array_map(fn ($cell): string => is_array($cell) ? trim((string) ($cell['text'] ?? '')) : '', $row);
            $rowCells = array_values(array_filter($row, 'is_array'));
            $isSection = count($rowCells) === 1
                && ((float) $rowCells[0]['rect'][2] - (float) $rowCells[0]['rect'][0]) >= $tableWidth * 0.8;
            $isHeader = count(array_filter($texts, fn (string $text): bool => in_array(strtoupper(trim($text)), [
                'STATUS', 'DESCRIPTION', 'ORGANIZATION', 'RESPONSIBILITY', 'REMARKS',
            ], true))) >= 3;
            $isBlueSeparator = in_array($rowIndex, $table['blue_separator_rows'] ?? [], true);
            $rowClass = $isSection
                ? ' class="converted-pdf-section-row"'
                : ($isHeader
                    ? ' class="converted-pdf-header-row"'
                    : ($isBlueSeparator ? ' class="converted-pdf-separator-row"' : ''));
            $rowHeight = number_format($rowHeights[$rowIndex], 2, '.', '');
            // Account for cell padding and the shared collapsed border added by Dompdf.
            $cellHeight = number_format(max(0.1, $rowHeights[$rowIndex] - 2.75), 2, '.', '');
            $html .= '<tr'.$rowClass.' style="height:'.$rowHeight.'pt">';
            $column = 0;

            foreach ($row as $cell) {
                if (! is_array($cell) || ! isset($cell['rect'])) {
                    continue;
                }

                $rect = $cell['rect'];
                $start = $this->nearestPdfColumnBoundary($xBoundaries, (float) $rect[0]);
                $end = $this->nearestPdfColumnBoundary($xBoundaries, (float) $rect[2]);
                if ($end <= $start) {
                    $end = min($columnCount, $start + 1);
                }
                $colspan = max(1, $end - $start);
                while ($column < $start) {
                    if (! isset($occupied[$rowIndex][$column])) {
                        $html .= '<td class="converted-pdf-cell" style="height:'.$cellHeight.'pt"></td>';
                    }
                    $column++;
                }
                while (isset($occupied[$rowIndex][$column])) {
                    $column++;
                }
                if ($column > $start) {
                    $start = $column;
                    $colspan = max(1, min($colspan, $columnCount - $start));
                }
                $rowspan = 1;
                $rowBottom = (float) $rect[3];
                $spanRows = [];
                for ($nextRow = $rowIndex + 1; $nextRow < count($rows); $nextRow++) {
                    if (isset($skipRows[$nextRow])) {
                        continue;
                    }
                    $nextTop = $rowTops[$nextRow] ?? null;
                    if ($nextTop === null || $nextTop >= $rowBottom - 1.0) {
                        break;
                    }
                    $rowspan++;
                    $spanRows[] = $nextRow;
                }

                $class = $isSection ? 'converted-pdf-section-cell' : ($isHeader ? 'converted-pdf-heading-cell' : 'converted-pdf-cell');
                $tag = $isHeader || $isSection ? 'th' : 'td';
                $spanAttrs = ($colspan > 1 ? ' colspan="'.$colspan.'"' : '')
                    .($rowspan > 1 ? ' rowspan="'.$rowspan.'"' : '');
                $text = trim((string) ($cell['text'] ?? ''));
                $text = str_replace(["\u{e71f}", ''], '☐', $text);
                $hasCheckbox = str_contains($text, "\u{2610}");
                $escapedText = nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false);
                $class = $hasCheckbox ? 'converted-pdf-checkbox-cell' : $class;
                $cellStyle = ' style="height:'.$cellHeight.'pt'.($hasCheckbox ? ';text-align:center' : '').'"';
                $html .= '<'.$tag.' class="'.$class.'"'.$spanAttrs.$cellStyle.'>'.$escapedText.'</'.$tag.'>';

                for ($spanColumn = $column; $spanColumn < $column + $colspan; $spanColumn++) {
                    for ($spanRow = $rowIndex + 1; $spanRow < $rowIndex + $rowspan; $spanRow++) {
                        $occupied[$spanRow][$spanColumn] = true;
                    }
                }
                $column += $colspan;
            }
            while ($column < $columnCount) {
                if (! isset($occupied[$rowIndex][$column])) {
                    $html .= '<td class="converted-pdf-cell" style="height:'.$cellHeight.'pt"></td>';
                }
                $column++;
            }
            $html .= '</tr>';
        }

        return $html.'</tbody></table>';
    }

    private function nearestPdfColumnBoundary(array $boundaries, float $x): int
    {
        $nearest = 0;
        $distance = INF;
        foreach ($boundaries as $index => $boundary) {
            $candidateDistance = abs((float) $boundary - $x);
            if ($candidateDistance < $distance) {
                $nearest = $index;
                $distance = $candidateDistance;
            }
        }

        return $nearest;
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
        if (! str_contains($html, 'converted-pdf-table') && ! str_contains($html, 'converted-pdf-layout')) {
            return $html;
        }

        $document = $this->parseFragment($html);
        $root = $this->fragmentRoot($document);
        $changed = false;

        foreach ($root->getElementsByTagName('table') as $table) {
            if (! $table instanceof DOMElement) {
                continue;
            }
            $classes = preg_split('/\s+/', trim($table->getAttribute('class')));
            if (! in_array('converted-pdf-table', $classes, true)
                && ! in_array('converted-pdf-layout', $classes, true)) {
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
            .'.converted-pdf-spacer{height:0;line-height:0;margin:0;padding:0}'
            .'.converted-pdf-image{display:block;height:auto;max-width:100%}'
            .'.converted-pdf-layout{width:100%;margin:0;border-collapse:collapse;table-layout:fixed}'
            .'.converted-pdf-layout td{border:0;padding:0;vertical-align:top}'
            .'.converted-pdf-layout .converted-pdf-text{margin:0;white-space:nowrap}'
            .'.converted-pdf-table{table-layout:fixed;border-collapse:collapse;font-size:6pt;line-height:1.1;margin-top:0;margin-bottom:12pt}'
            .'.converted-pdf-table td,.converted-pdf-table th{border:1px solid #111;padding:1pt .5pt;line-height:1;vertical-align:middle}'
            .'.converted-pdf-table .converted-pdf-header-row th{background:#d3d3d3;color:#111;text-align:center;font-size:6pt}'
            .'.converted-pdf-table .converted-pdf-section-cell{background:#163666;color:#fff;font-weight:bold;text-align:left}'
            .'.converted-pdf-table .converted-pdf-checkbox-cell{font-family:DejaVu Sans,sans-serif;font-size:9pt;line-height:1;text-align:center}'
            .'.converted-pdf-table .converted-pdf-cell{background:#fff;color:#111}'
            .'.converted-pdf-table .converted-pdf-separator-row td{background:#1f3863;color:#1f3863}'
            .'.converted-pdf-text{margin:0;font-size:10pt;line-height:1.25;white-space:normal}'
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
        $this->wordImages = [];
        $this->wordImageNamesByHash = [];
        $this->wordDrawingId = 0;
        $convertedPdfPageSize = $this->convertedPdfPageSize($html);
        $this->wordConvertedPageWidthTwips = $convertedPdfPageSize === null
            ? null
            : (int) round($convertedPdfPageSize[0] * 20);

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

        $imageContentTypes = [];
        $imageEntries = [];
        foreach ($this->wordImages as $image) {
            $documentRelationships .= '<Relationship Id="'.$image['relationship_id'].'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/image" Target="media/'.$image['name'].'"/>';
            $imageContentTypes[$image['extension']] = '<Default Extension="'.$image['extension'].'" ContentType="'.$image['mime_type'].'"/>';
            $imageEntries['word/media/'.$image['name']] = $image['contents'];
        }

        $entries = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .implode('', $imageContentTypes)
                .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
                .'<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
                .$contentOverrides.'</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
                .'</Relationships>',
            'word/document.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships" xmlns:wp="http://schemas.openxmlformats.org/drawingml/2006/wordprocessingDrawing" xmlns:a="http://schemas.openxmlformats.org/drawingml/2006/main" xmlns:pic="http://schemas.openxmlformats.org/drawingml/2006/picture">'
                .'<w:body>'.$body.'<w:sectPr>'.$sectionReferences
                .($convertedPdfPageSize === null
                    ? '<w:pgSz w:w="11906" w:h="16838"/>'
                        .'<w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="708" w:footer="708" w:gutter="0"/>'
                    : '<w:pgSz w:w="'.(int) round($convertedPdfPageSize[0] * 20).'" w:h="'.(int) round($convertedPdfPageSize[1] * 20).'"/>'
                        .'<w:pgMar w:top="0" w:right="0" w:bottom="0" w:left="0" w:header="0" w:footer="0" w:gutter="0"/>')
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
        ] + $sectionEntries + $imageEntries;

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
            } elseif ($tag === 'img' && $property === 'width'
                && preg_match('/^[0-9]{1,4}(?:\.[0-9]+)?pt$/', $value)) {
                $safe[] = $property.':'.$value;
            } elseif ($tag === 'p' && $property === 'margin-top'
                && preg_match('/^[0-9]{1,4}(?:\.[0-9]+)?pt$/', $value)) {
                $safe[] = $property.':'.$value;
            } elseif ($tag === 'div' && in_array($property, ['width', 'min-height'], true)
                && preg_match('/^[0-9]{1,5}(?:\.[0-9]+)?pt$/', $value)) {
                $safe[] = $property.':'.$value;
            } elseif ($tag === 'tr' && $property === 'height'
                && preg_match('/^[0-9]{1,3}(?:\.[0-9]+)?pt$/', $value)) {
                $safe[] = $property.':'.$value;
            } elseif (in_array($tag, ['td', 'th'], true) && $property === 'height'
                && preg_match('/^[0-9]{1,3}(?:\.[0-9]+)?pt$/', $value)) {
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
        if ($tag === 'img') {
            return $this->wordParagraph($this->renderWordImage($node));
        }
        if ($tag === 'div' && in_array('converted-pdf-page-break', preg_split('/\s+/', trim($node->getAttribute('class'))), true)) {
            return '<w:p><w:r><w:br w:type="page"/></w:r></w:p>';
        }
        if ($tag === 'div' && in_array('converted-pdf-page', preg_split('/\s+/', trim($node->getAttribute('class'))), true)) {
            $page = '';
            foreach ($node->childNodes as $child) {
                $page .= $this->renderWordBlock($child);
            }

            return $page;
        }
        if ($tag === 'p' && in_array('converted-pdf-spacer', preg_split('/\s+/', trim($node->getAttribute('class'))), true)) {
            if (preg_match('/(?:^|;)\s*margin-top\s*:\s*([0-9]+(?:\.[0-9]+)?)pt/i', $node->getAttribute('style'), $match)) {
                $spacing = (int) round((float) $match[1] * 20);

                return '<w:p><w:pPr><w:spacing w:before="'.$spacing.'" w:after="0" w:line="1" w:lineRule="exact"/></w:pPr>'
                    .'<w:r><w:rPr><w:sz w:val="2"/><w:vanish/></w:rPr><w:t xml:space="preserve"> </w:t></w:r></w:p>';
            }

            return '<w:p/>';
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
            $indent = $tag === 'blockquote'
                ? '<w:ind w:left="720"/>'
                : $this->wordParagraphIndent($node);
            $convertedPdfText = $tag === 'p'
                && in_array('converted-pdf-text', preg_split('/\s+/', trim($node->getAttribute('class'))), true);
            $spacing = $convertedPdfText ? '<w:spacing w:after="0"/>' : '';
            $properties = ($style !== '' || $alignment !== '' || $indent !== '' || $spacing !== '')
                ? '<w:pPr>'.$style.$alignment.$indent.$spacing.'</w:pPr>'
                : '';

            return '<w:p>'.$properties.$this->renderWordInline($node).'</w:p>';
        }

        return $this->wordParagraph($this->renderWordInline($node));
    }

    private function renderWordTable(DOMElement $table): string
    {
        $isConvertedPdfLayout = in_array(
            'converted-pdf-layout',
            preg_split('/\s+/', trim($table->getAttribute('class'))),
            true,
        );
        $rows = [];
        foreach ($table->childNodes as $section) {
            if (! $section instanceof DOMElement) {
                continue;
            }
            if (strtolower($section->tagName) === 'tr') {
                $rows[] = $section;

                continue;
            }
            foreach ($section->childNodes as $row) {
                if ($row instanceof DOMElement && strtolower($row->tagName) === 'tr') {
                    $rows[] = $row;
                }
            }
        }

        $columnCount = 1;
        foreach ($rows as $row) {
            $rowColumnCount = 0;
            foreach ($row->childNodes as $cell) {
                if ($cell instanceof DOMElement && in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                    $rowColumnCount += max(1, (int) $cell->getAttribute('colspan'));
                }
            }
            $columnCount = max($columnCount, $rowColumnCount);
        }

        $tableWidthPercent = 100.0;
        $marginLeftPercent = 0.0;
        if (preg_match('/(?:^|;)\s*width\s*:\s*([0-9]+(?:\.[0-9]+)?)%/i', $table->getAttribute('style'), $widthMatch)) {
            $tableWidthPercent = min(100, max(0.1, (float) $widthMatch[1]));
        }
        if (preg_match('/(?:^|;)\s*margin-left\s*:\s*([0-9]+(?:\.[0-9]+)?)%/i', $table->getAttribute('style'), $marginMatch)) {
            $marginLeftPercent = min(100, max(0, (float) $marginMatch[1]));
        }
        $tableWidthBase = $this->wordConvertedPageWidthTwips ?? 9000;
        $tableWidth = max(1, (int) round($tableWidthBase * $tableWidthPercent / 100));
        $tableIndent = $this->wordConvertedPageWidthTwips === null
            ? max(0, (int) round(11906 * $marginLeftPercent / 100) - 1440)
            : max(0, (int) round($this->wordConvertedPageWidthTwips * $marginLeftPercent / 100));

        $gridColumns = '';
        $columnWidths = [];
        foreach ($table->getElementsByTagName('col') as $col) {
            if (! $col instanceof DOMElement) {
                continue;
            }
            if (preg_match('/(?:^|;)\s*width\s*:\s*([0-9]+(?:\.[0-9]+)?)%/i', $col->getAttribute('style'), $widthMatch)) {
                $columnWidths[] = (float) $widthMatch[1];
            }
        }
        $widthTotal = array_sum($columnWidths);
        $resolvedColumnWidths = [];
        $allocatedWidth = 0;
        for ($column = 0; $column < $columnCount; $column++) {
            if ($column === $columnCount - 1) {
                $columnWidth = $tableWidth - $allocatedWidth;
            } elseif (count($columnWidths) === $columnCount && $widthTotal > 0) {
                $columnWidth = (int) floor(($columnWidths[$column] / $widthTotal) * $tableWidth);
            } else {
                $columnWidth = (int) floor($tableWidth / $columnCount);
            }
            $columnWidth = max(1, $columnWidth);
            $allocatedWidth += $columnWidth;
            $resolvedColumnWidths[$column] = $columnWidth;
            $gridColumns .= '<w:gridCol w:w="'.$columnWidth.'"/>';
        }

        $wordRows = '';
        $occupied = [];
        foreach ($rows as $rowIndex => $row) {
            $wordRows .= $this->renderWordRow($row, $rowIndex, $columnCount, $occupied, $resolvedColumnWidths);
        }

        $tableBorders = $isConvertedPdfLayout
            ? '<w:tblBorders><w:top w:val="nil"/><w:left w:val="nil"/><w:bottom w:val="nil"/>'
                .'<w:right w:val="nil"/><w:insideH w:val="nil"/><w:insideV w:val="nil"/></w:tblBorders>'
                .'<w:tblCellMar><w:top w:w="0" w:type="dxa"/><w:left w:w="0" w:type="dxa"/>'
                .'<w:bottom w:w="0" w:type="dxa"/><w:right w:w="0" w:type="dxa"/></w:tblCellMar>'
            : '<w:tblBorders><w:top w:val="single" w:sz="4" w:color="B7C8D5"/>'
                .'<w:left w:val="single" w:sz="4" w:color="B7C8D5"/>'
                .'<w:bottom w:val="single" w:sz="4" w:color="B7C8D5"/>'
                .'<w:right w:val="single" w:sz="4" w:color="B7C8D5"/>'
                .'<w:insideH w:val="single" w:sz="4" w:color="B7C8D5"/>'
                .'<w:insideV w:val="single" w:sz="4" w:color="B7C8D5"/></w:tblBorders>';

        return '<w:tbl><w:tblPr><w:tblW w:w="'.$tableWidth.'" w:type="dxa"/>'
            .($tableIndent > 0 ? '<w:tblInd w:w="'.$tableIndent.'" w:type="dxa"/>' : '')
            .'<w:tblLayout w:type="fixed"/>'.$tableBorders.'</w:tblPr><w:tblGrid>'.$gridColumns.'</w:tblGrid>'.$wordRows.'</w:tbl>';
    }

    private function renderWordRow(DOMElement $row, int $rowIndex, int $columnCount, array &$occupied, array $columnWidths): string
    {
        $sourceCells = [];
        foreach ($row->childNodes as $cell) {
            if ($cell instanceof DOMElement && in_array(strtolower($cell->tagName), ['td', 'th'], true)) {
                $sourceCells[] = $cell;
            }
        }

        $cells = '';
        $sourceIndex = 0;
        $column = 0;
        while ($column < $columnCount) {
            if (isset($occupied[$rowIndex][$column])) {
                $cells .= '<w:tc><w:tcPr><w:tcW w:w="'.$columnWidths[$column].'" w:type="dxa"/><w:vMerge/></w:tcPr><w:p/></w:tc>';
                $column++;
                continue;
            }
            if (! isset($sourceCells[$sourceIndex])) {
                $cells .= '<w:tc><w:tcPr><w:tcW w:w="'.$columnWidths[$column].'" w:type="dxa"/></w:tcPr><w:p/></w:tc>';
                $column++;
                continue;
            }

            $cell = $sourceCells[$sourceIndex++];
            $content = '';
            if (preg_match('/^\s*\x{2610}\s*$/u', $cell->textContent) === 1) {
                $content = '<w:p><w:pPr><w:jc w:val="center"/></w:pPr>'
                    .$this->renderWordInline($cell).'</w:p>';
            } else {
                foreach ($cell->childNodes as $child) {
                    $content .= $this->renderWordBlock($child);
                }
            }
            if ($content === '') {
                $content = '<w:p/>';
            }

            $colspan = max(1, (int) $cell->getAttribute('colspan'));
            $rowspan = max(1, (int) $cell->getAttribute('rowspan'));
            $cellWidth = 0;
            for ($widthColumn = $column; $widthColumn < $column + $colspan; $widthColumn++) {
                $cellWidth += $columnWidths[$widthColumn] ?? 0;
            }
            $cellProperties = '<w:tcW w:w="'.$cellWidth.'" w:type="dxa"/>'
                .($colspan > 1 ? '<w:gridSpan w:val="'.$colspan.'"/>' : '')
                .($rowspan > 1 ? '<w:vMerge w:val="restart"/>' : '');

            $cells .= '<w:tc><w:tcPr>'.$cellProperties.'</w:tcPr>'.$content.'</w:tc>';

            for ($spanRow = $rowIndex + 1; $spanRow < $rowIndex + $rowspan; $spanRow++) {
                for ($spanColumn = $column; $spanColumn < $column + $colspan; $spanColumn++) {
                    $occupied[$spanRow][$spanColumn] = true;
                }
            }
            $column += $colspan;
        }

        $rowProperties = '';
        if (preg_match('/(?:^|;)\s*height\s*:\s*([0-9]+(?:\.[0-9]+)?)pt/i', $row->getAttribute('style'), $heightMatch)) {
            $rowHeight = max(1, (int) round((float) $heightMatch[1] * 20));
            $rowProperties = '<w:trPr><w:trHeight w:val="'.$rowHeight.'" w:hRule="atLeast"/></w:trPr>';
        }

        return '<w:tr>'.$rowProperties.$cells.'</w:tr>';
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
            if ($tag === 'img') {
                $output .= $this->renderWordImage($child);

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

    private function renderWordImage(DOMElement $image): string
    {
        $source = $image->getAttribute('src');
        if (! preg_match('/^data:image\/(png|jpeg);base64,([A-Za-z0-9+\/=\r\n]+)$/i', $source, $matches)) {
            return '';
        }

        $contents = base64_decode(preg_replace('/\s+/', '', $matches[2]), true);
        if ($contents === false || $contents === '' || strlen($contents) > 5000000) {
            return '';
        }

        $extension = strtolower($matches[1]) === 'png' ? 'png' : 'jpg';
        $mimeType = $extension === 'png' ? 'image/png' : 'image/jpeg';
        $signature = $extension === 'png' ? "\x89PNG\r\n\x1a\n" : "\xFF\xD8\xFF";
        if (! str_starts_with($contents, $signature)) {
            return '';
        }

        $hash = hash('sha256', $contents);
        if (! isset($this->wordImageNamesByHash[$hash])) {
            $index = count($this->wordImages) + 1;
            $name = 'image'.$index.'.'.$extension;
            $relationshipId = 'rIdContractImage'.$index;
            $this->wordImages[] = [
                'relationship_id' => $relationshipId,
                'name' => $name,
                'extension' => $extension,
                'mime_type' => $mimeType,
                'contents' => $contents,
            ];
            $this->wordImageNamesByHash[$hash] = $index - 1;
        }

        $imageDetails = $this->wordImages[$this->wordImageNamesByHash[$hash]];
        $dimensions = function_exists('getimagesizefromstring') ? @getimagesizefromstring($contents) : false;
        $pixelWidth = is_array($dimensions) ? max(1, (int) $dimensions[0]) : 600;
        $pixelHeight = is_array($dimensions) ? max(1, (int) $dimensions[1]) : 200;
        if (preg_match('/(?:^|;)\s*width\s*:\s*([0-9]+(?:\.[0-9]+)?)pt/i', $image->getAttribute('style'), $widthMatch)) {
            $width = max(1, (int) round((float) $widthMatch[1] * 12700));
            $height = max(1, (int) round($width * $pixelHeight / $pixelWidth));
        } else {
            $scale = min(
                1,
                5715000 / ($pixelWidth * 9525),
                8229600 / ($pixelHeight * 9525),
            );
            $width = max(1, (int) round($pixelWidth * 9525 * $scale));
            $height = max(1, (int) round($pixelHeight * 9525 * $scale));
        }
        $drawingId = ++$this->wordDrawingId;
        $description = htmlspecialchars($image->getAttribute('alt'), ENT_XML1 | ENT_QUOTES, 'UTF-8');

        return '<w:r><w:drawing><wp:inline distT="0" distB="0" distL="0" distR="0">'
            .'<wp:extent cx="'.$width.'" cy="'.$height.'"/>'
            .'<wp:docPr id="'.$drawingId.'" name="'.$imageDetails['name'].'" descr="'.$description.'"/>'
            .'<wp:cNvGraphicFramePr><a:graphicFrameLocks noChangeAspect="1"/></wp:cNvGraphicFramePr>'
            .'<a:graphic><a:graphicData uri="http://schemas.openxmlformats.org/drawingml/2006/picture">'
            .'<pic:pic><pic:nvPicPr><pic:cNvPr id="'.$drawingId.'" name="'.$imageDetails['name'].'"/>'
            .'<pic:cNvPicPr/></pic:nvPicPr><pic:blipFill><a:blip r:embed="'.$imageDetails['relationship_id'].'"/>'
            .'<a:stretch><a:fillRect/></a:stretch></pic:blipFill><pic:spPr><a:xfrm><a:off x="0" y="0"/>'
            .'<a:ext cx="'.$width.'" cy="'.$height.'"/></a:xfrm><a:prstGeom prst="rect"><a:avLst/>'
            .'</a:prstGeom></pic:spPr></pic:pic></a:graphicData></a:graphic></wp:inline></w:drawing></w:r>';
    }

    private function wordParagraphIndent(DOMElement $element): string
    {
        $style = $element->getAttribute('style');
        if (! preg_match('/(?:^|;)\s*margin-left\s*:\s*([0-9]+(?:\.[0-9]+)?)%/i', $style, $match)) {
            return '';
        }

        $indentBase = $this->wordConvertedPageWidthTwips ?? 9026;
        $maxIndentPercent = $this->wordConvertedPageWidthTwips === null ? 90 : 100;
        $indent = (int) round($indentBase * min($maxIndentPercent, max(0, (float) $match[1])) / 100);
        $rightIndent = null;
        if ($this->wordConvertedPageWidthTwips !== null
            && preg_match('/(?:^|;)\s*width\s*:\s*([0-9]+(?:\.[0-9]+)?)%/i', $style, $widthMatch)) {
            $contentWidth = (int) round(
                $this->wordConvertedPageWidthTwips * min(100, max(0.1, (float) $widthMatch[1])) / 100,
            );
            $rightIndent = max(0, $this->wordConvertedPageWidthTwips - $indent - $contentWidth);
        }

        if ($indent === 0 && $rightIndent === null) {
            return '';
        }

        return '<w:ind'.($indent > 0 ? ' w:left="'.$indent.'"' : '')
            .($rightIndent !== null ? ' w:right="'.$rightIndent.'"' : '').'/>';
    }

    private function wordRun(string $text, array $format = []): string
    {
        if ($text === '') {
            return '';
        }

        $properties = '';
        if (preg_match('/^\s*\x{2610}\s*$/u', $text) === 1) {
            $properties .= '<w:rFonts w:ascii="Segoe UI Symbol" w:hAnsi="Segoe UI Symbol"/>';
        }
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
            .'.converted-pdf-spacer{height:0;line-height:0;margin:0;padding:0}'
            .'.converted-pdf-image{display:block;height:auto;max-width:100%}'
            .'.converted-pdf-layout{width:100%;margin:0;border-collapse:collapse;table-layout:fixed}'
            .'.converted-pdf-layout td{border:0;padding:0;vertical-align:top}'
            .'.converted-pdf-layout .converted-pdf-text{margin:0;white-space:nowrap}'
            .'.converted-pdf-table{table-layout:fixed;border-collapse:collapse;font-size:7pt;line-height:1;margin-top:3pt;margin-bottom:5pt}'
            .'.converted-pdf-table td,.converted-pdf-table th{border:1px solid #111;padding:2pt 3pt;line-height:1;vertical-align:middle}'
            .'.converted-pdf-table .converted-pdf-header-row th{background:#d3d3d3;color:#111;text-align:center;font-size:7pt}'
            .'.converted-pdf-table .converted-pdf-section-cell{background:#163666;color:#fff;font-weight:bold;text-align:left}'
            .'.converted-pdf-table .converted-pdf-checkbox-cell{font-family:DejaVu Sans,sans-serif;font-size:9pt;line-height:1;text-align:center}'
            .'.converted-pdf-table .converted-pdf-cell{background:#fff;color:#111}'
            .'.converted-pdf-table .converted-pdf-separator-row td{background:#1f3863;color:#1f3863}'
            .'header{position:fixed;top:-1.5cm;left:0;right:0;height:auto;font-size:9pt}'
            .'footer{position:fixed;bottom:-1.5cm;left:0;right:0;height:auto;font-size:9pt}'
            .'blockquote{margin:8pt 0 8pt 20pt}'
            .'ul,ol{margin:0 0 10pt;padding-left:22pt}</style></head><body>'.$html.'</body></html>';
    }
}
