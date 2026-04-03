<?php
declare(strict_types=1);

final class ZebraPrinter
{
    /**
     * Drukuje plik ZPL na drukarce CUPS przypisanej do stacji.
     * Nazwa drukarki: zebra_st{station_code}_raw
     */
    public static function print(string $stationCode, string $filePath): void
    {
        $printerName = 'zebra_st' . $stationCode . '_' . (strtolower((string)pathinfo($filePath, PATHINFO_EXTENSION)) === 'pdf' ? 'pdf' : 'raw');
        $safeFile    = escapeshellarg($filePath);
        $safePrinter = escapeshellarg($printerName);

        $cmd    = "lp -d {$safePrinter} {$safeFile} 2>&1";
        $output = shell_exec($cmd);

        if (strpos((string)$output, 'request id') === false) {
            throw new RuntimeException("Błąd drukowania na {$printerName}: " . $output);
        }
    }

    public static function printerName(string $stationCode): string
    {
        return 'zebra_st' . $stationCode . '_raw';
    }
}
