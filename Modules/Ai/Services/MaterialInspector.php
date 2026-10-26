<?php

declare(strict_types=1);

namespace Modules\Ai\Services;

use Illuminate\Http\UploadedFile;
use Modules\Ai\DTOs\InspectedFile;
use Modules\Ai\Enums\MaterialKind;
use Modules\Ai\Exceptions\InvalidMaterialFile;

/**
 * Decides what a file really is from its bytes, not its name: a PDF starts with %PDF-, a Word/Excel
 * file is a zip holding word/document.xml or xl/workbook.xml. Refuses password-protected files,
 * macros, old binary formats, zip bombs and a name that lies about the content.
 */
final class MaterialInspector
{
    private const OLE_MAGIC = "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1";

    public function inspect(UploadedFile $file): InspectedFile
    {
        $path = (string) $file->getRealPath();
        $extension = strtolower($file->getClientOriginalExtension());
        $this->refuseByExtension($extension);

        if (filesize($path) === 0) {
            throw new InvalidMaterialFile('الملف فارغ.');
        }
        $head = (string) file_get_contents($path, false, null, 0, 8);

        $kind = match (true) {
            str_starts_with($head, '%PDF-') => $this->inspectPdf($path),
            str_starts_with($head, self::OLE_MAGIC) => throw new InvalidMaterialFile('الملف محمي بكلمة مرور أو بصيغة قديمة. احفظه بصيغة docx أو xlsx بدون كلمة مرور.'),
            str_starts_with($head, "PK\x03\x04") => $this->inspectOpenXml($path),
            default => throw new InvalidMaterialFile('نوع الملف غير مدعوم. المسموح: PDF أو Word (docx) أو Excel (xlsx).'),
        };

        if ($extension !== $kind->value) {
            throw new InvalidMaterialFile('امتداد الملف لا يطابق محتواه.');
        }

        return new InspectedFile($kind, $kind->mime(), hash_file('sha256', $path));
    }

    private function refuseByExtension(string $extension): void
    {
        if (in_array($extension, ['xlsm', 'docm', 'xlsb', 'dotm', 'xltm'], true)) {
            throw new InvalidMaterialFile('الملفات التي تحتوي على ماكرو غير مسموحة.');
        }
        if (in_array($extension, ['doc', 'xls'], true)) {
            throw new InvalidMaterialFile('الصيغة القديمة غير مدعومة. احفظ الملف بصيغة docx أو xlsx.');
        }
        if (! in_array($extension, ['pdf', 'docx', 'xlsx'], true)) {
            throw new InvalidMaterialFile('نوع الملف غير مدعوم. المسموح: PDF أو Word (docx) أو Excel (xlsx).');
        }
    }

    private function inspectPdf(string $path): MaterialKind
    {
        $size = filesize($path);
        $handle = fopen($path, 'rb');
        // The encryption dictionary is named in the trailer: at the end, or at the start of a linearized file.
        $tail = $size > 4096 ? (fseek($handle, -4096, SEEK_END) === 0 ? (string) fread($handle, 4096) : '') : (string) file_get_contents($path);
        rewind($handle);
        $start = (string) fread($handle, 4096);
        fclose($handle);

        if (str_contains($tail, '/Encrypt') || str_contains($start, '/Encrypt')) {
            throw new InvalidMaterialFile('الملف محمي بكلمة مرور.');
        }
        if (! str_contains($tail, '%%EOF')) {
            throw new InvalidMaterialFile('ملف PDF تالف أو غير مكتمل.');
        }

        return MaterialKind::Pdf;
    }

    private function inspectOpenXml(string $path): MaterialKind
    {
        $zip = new \ZipArchive;
        if ($zip->open($path, \ZipArchive::RDONLY) !== true) {
            throw new InvalidMaterialFile('الملف تالف أو لا يمكن قراءته.');
        }

        try {
            $entries = $zip->numFiles;
            $expanded = 0;
            $sheets = 0;
            $names = [];
            for ($i = 0; $i < $entries; $i++) {
                $stat = $zip->statIndex($i);
                $expanded += (int) ($stat['size'] ?? 0);
                $names[] = (string) ($stat['name'] ?? '');
                if (preg_match('#^xl/worksheets/sheet\d+\.xml$#', (string) $stat['name'])) {
                    $sheets++;
                }
                if ($entries > 5000 || $expanded > (int) config('ai.materials.max_uncompressed_mb') * 1048576) {
                    throw new InvalidMaterialFile('محتوى الملف أكبر من الحد المسموح بعد فكّ الضغط.');
                }
            }
        } finally {
            $zip->close();
        }

        foreach ($names as $name) {
            if (str_ends_with($name, 'vbaProject.bin')) {
                throw new InvalidMaterialFile('الملفات التي تحتوي على ماكرو غير مسموحة.');
            }
        }
        if (in_array('word/document.xml', $names, true)) {
            return MaterialKind::Docx;
        }
        if (in_array('xl/workbook.xml', $names, true)) {
            if ($sheets > (int) config('ai.materials.max_xlsx_sheets')) {
                throw new InvalidMaterialFile('عدد أوراق ملف Excel أكبر من الحد المسموح ('.config('ai.materials.max_xlsx_sheets').').');
            }

            return MaterialKind::Xlsx;
        }

        throw new InvalidMaterialFile('نوع الملف غير مدعوم. المسموح: PDF أو Word (docx) أو Excel (xlsx).');
    }
}
