<?php

namespace App\Services;

use App\Models\User;
use DOMDocument;
use DOMElement;
use DOMNode;
use Dompdf\Dompdf;
use Dompdf\Options;
use RuntimeException;
use ZipArchive;

class ContractDocumentService
{
    private const ALLOWED_TAGS = [
        'a', 'b', 'blockquote', 'br', 'div', 'em', 'h1', 'h2', 'h3', 'i',
        'li', 'ol', 'p', 's', 'span', 'strong', 'table', 'tbody', 'td',
        'th', 'thead', 'tr', 'u', 'ul',
    ];

    private const DROP_TAGS = ['embed', 'iframe', 'object', 'script', 'style', 'svg'];

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

    public function personalizeHtml(string $html, ?User $recipient): string
    {
        if ($recipient === null) {
            return $html;
        }

        $recipient->loadMissing(['department', 'employee.department', 'roles']);
        $fields = [
            'userfullname' => $recipient->name ?? '',
            'fullname' => $recipient->name ?? '',
            'name' => $recipient->name ?? '',
            'workemail' => $recipient->email,
            'email' => $recipient->email,
            'department' => $recipient->department?->department_name
                ?? $recipient->employee?->department?->department_name
                ?? '',
            'employeeid' => $recipient->employee?->employee_no ?? '',
            'employeeno' => $recipient->employee?->employee_no ?? '',
            'designation' => $recipient->employee?->position ?? '',
            'position' => $recipient->employee?->position ?? '',
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
        $options = new Options();
        $options->set('isRemoteEnabled', false);
        $options->set('isPhpEnabled', false);

        $renderer = new Dompdf($options);
        $renderer->loadHtml($this->wrapHtml($html), 'UTF-8');
        $renderer->setPaper('A4');
        $renderer->render();

        return $renderer->output();
    }

    public function renderDocx(string $html): string
    {
        $document = $this->parseFragment($html);
        $root = $this->fragmentRoot($document);
        $body = '';

        foreach ($root->childNodes as $child) {
            $body .= $this->renderWordBlock($child);
        }

        if ($body === '') {
            $body = '<w:p/>';
        }

        $entries = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
                .'<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
                .'</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
                .'</Relationships>',
            'word/document.xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
                .'<w:body>'.$body.'<w:sectPr><w:pgSz w:w="11906" w:h="16838"/>'
                .'<w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440" w:header="708" w:footer="708" w:gutter="0"/>'
                .'</w:sectPr></w:body></w:document>',
            'word/_rels/document.xml.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
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
        ];

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
            if ($name === 'style' && in_array($tag, ['div', 'p', 'td', 'th'], true)
                && preg_match('/^text-align\s*:\s*(left|center|right|justify)\s*;?$/i', $value, $match)) {
                $element->setAttribute('style', 'text-align: '.strtolower($match[1]));

                continue;
            }
            if ($name === 'href' && $tag === 'a'
                && preg_match('/^(https?:\/\/|mailto:)/i', $value)) {
                $element->setAttribute('href', $value);

                continue;
            }
            if (in_array($name, ['colspan', 'rowspan'], true) && in_array($tag, ['td', 'th'], true)
                && ctype_digit($value) && (int) $value >= 1 && (int) $value <= 10) {
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
            .'@page{margin:2cm}body{font-family:DejaVu Sans,sans-serif;font-size:11pt;line-height:1.45;color:#1f2937}'
            .'p{margin:0 0 10pt}h1{font-size:20pt;margin:0 0 14pt}h2{font-size:16pt;margin:14pt 0 8pt}'
            .'h3{font-size:13pt;margin:12pt 0 6pt}table{width:100%;border-collapse:collapse;margin:10pt 0}'
            .'th,td{border:1px solid #b7c8d5;padding:6pt;text-align:left}blockquote{margin:8pt 0 8pt 20pt}'
            .'ul,ol{margin:0 0 10pt;padding-left:22pt}</style></head><body>'.$html.'</body></html>';
    }
}
