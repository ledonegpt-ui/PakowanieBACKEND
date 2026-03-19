<?php
declare(strict_types=1);

final class PdfToZpl
{
    /**
     * Konwertuje PDF (binarnie) do ZPL (bitmapa ^GFA).
     * Zwraca ZPL gotowe do wysłania na drukarkę (może zawierać wiele ^XA...^XZ jeśli PDF wielostronicowy).
     */
    public static function pdfBinaryToZpl(string $pdfBin, int $dpi = 203): string
    {
        if ($pdfBin === '' || strlen($pdfBin) < 100) {
            throw new \RuntimeException('PDF->ZPL: pusty/za krótki PDF');
        }
        if ($dpi < 100) $dpi = 203;
        if ($dpi > 600) $dpi = 300;

        $tmpDir = __DIR__ . '/../../storage/tmp';
        if (!is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);

        $base = $tmpDir . '/lbl_' . date('Ymd_His') . '_' . bin2hex(random_bytes(6));
        $pdfFile = $base . '.pdf';
        file_put_contents($pdfFile, $pdfBin);

        $outPattern = $base . '_%03d.png';

        // Render PDF -> 1-bit PNG
        $cmd = sprintf(
            "gs -q -dSAFER -dBATCH -dNOPAUSE -dUseCropBox -sDEVICE=pngmono -r%d -sOutputFile=%s %s 2>&1",
            $dpi,
            escapeshellarg($outPattern),
            escapeshellarg($pdfFile)
        );

        $out = [];
        $rc = 0;
        @exec($cmd, $out, $rc);
        if ($rc !== 0) {
            @unlink($pdfFile);
            throw new \RuntimeException("PDF->ZPL: ghostscript error rc={$rc} out=" . substr(implode("\n", $out), 0, 400));
        }

        $pngs = glob($base . '_*.png');
        if (!$pngs) {
            @unlink($pdfFile);
            throw new \RuntimeException("PDF->ZPL: ghostscript nie wygenerował PNG");
        }
        sort($pngs);

        $zplAll = '';
        foreach ($pngs as $png) {
            $zplAll .= self::pngToZplGfa($png);
            @unlink($png);
        }

        @unlink($pdfFile);
        return $zplAll;
    }

    /**
     * PNG (bitmapa) -> ZPL ^GFA (hex, bez kompresji).
     */
    private static function pngToZplGfa(string $pngPath): string
    {
        if (!function_exists('imagecreatefrompng')) {
            throw new \RuntimeException('PDF->ZPL: brak php-gd (imagecreatefrompng)');
        }

        $im = @imagecreatefrompng($pngPath);
        if (!$im) {
            throw new \RuntimeException("PDF->ZPL: nie mogę otworzyć PNG {$pngPath}");
        }

        // pngmono z Ghostscript bywa paletowe (1-bit). imagecolorat() może wtedy zwracać indeks palety,
        // a nie RGB. Konwertujemy do truecolor, żeby odczyt pikseli był stabilny.
        if (function_exists('imagepalettetotruecolor') && function_exists('imageistruecolor')) {
            if (!imageistruecolor($im)) {
                @imagepalettetotruecolor($im);
            }
        }

        $w = imagesx($im);
        $h = imagesy($im);
        if ($w < 10 || $h < 10) {
            imagedestroy($im);
            throw new \RuntimeException("PDF->ZPL: podejrzany rozmiar {$w}x{$h}");
        }

        $bytesPerRow = (int)ceil($w / 8);
        $totalBytes  = $bytesPerRow * $h;

        $hexParts = [];
        $hexPartsLen = 0;

        for ($y = 0; $y < $h; $y++) {
            $row = '';
            for ($bx = 0; $bx < $bytesPerRow; $bx++) {
                $byte = 0;
                for ($bit = 0; $bit < 8; $bit++) {
                    $x = $bx * 8 + $bit;
                    $isBlack = false;

                    if ($x < $w) {
                        $c = imagecolorat($im, $x, $y);

                        $gray = 255.0; // domyślnie biały

                        // Truecolor (najczęściej po konwersji z palety)
                        if (!function_exists('imageistruecolor') || imageistruecolor($im)) {
                            $a = ($c & 0x7F000000) >> 24; // 0=opaque, 127=transparent
                            if ($a >= 120) {
                                $gray = 255.0; // przezroczyste traktuj jako białe
                            } else {
                                $r = ($c >> 16) & 0xFF;
                                $g = ($c >> 8) & 0xFF;
                                $b = $c & 0xFF;
                                $gray = ($r + $g + $b) / 3;
                            }
                        } else {
                            // Fallback dla paletowych PNG
                            $rgba = imagecolorsforindex($im, $c);
                            if (is_array($rgba)) {
                                if (isset($rgba['alpha']) && (int)$rgba['alpha'] >= 120) {
                                    $gray = 255.0;
                                } else {
                                    $r = (int)($rgba['red'] ?? 255);
                                    $g = (int)($rgba['green'] ?? 255);
                                    $b = (int)($rgba['blue'] ?? 255);
                                    $gray = ($r + $g + $b) / 3;
                                }
                            }
                        }

                        $isBlack = ($gray < 128);
                    }

                    if ($isBlack) {
                        $byte |= (1 << (7 - $bit));
                    }
                }
                $row .= sprintf('%02X', $byte);
            }
            $hexParts[] = $row;
            $hexPartsLen += strlen($row);

            // lekka optymalizacja pamięci
            if (count($hexParts) >= 200) {
                // nic – tylko trzymamy w tablicy, ale nie rozdymamy jednej gigantycznej konkatenacji w pętli
            }
        }

        imagedestroy($im);

        $hex = implode('', $hexParts);

        // ^PW i ^LL w "dots" (piksele PNG = dots przy danym DPI)
        $zpl =
            "^XA" .
            "^PW{$w}" .
            "^LL{$h}" .
            "^FO0,0^GFA,{$totalBytes},{$totalBytes},{$bytesPerRow},{$hex}^FS" .
            "^XZ";

        return $zpl;
    }
}
