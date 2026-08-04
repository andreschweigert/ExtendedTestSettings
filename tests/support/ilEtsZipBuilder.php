<?php

declare(strict_types=1);

/**
 * Baut ZIP-Archive für die Installer-Tests.
 *
 * Neben normalen Dateien lassen sich gezielt die Fälle erzeugen, gegen die sich
 * die Grenzen aus C-10 richten: sehr große Einzeldateien, sehr viele Einträge
 * und Archive mit auffälligem Kompressionsverhältnis. Große Inhalte werden über
 * temporäre Dateien erzeugt (ftruncate), nicht im Speicher aufgebaut.
 */
class ilEtsZipBuilder
{
    private string $zip_path;
    private ZipArchive $zip;

    /** @var string[] Temporäre Quelldateien, die erst nach close() gelöscht werden dürfen. */
    private array $temp_files = [];

    public function __construct(string $zip_path)
    {
        $this->zip_path = $zip_path;
        $this->zip      = new ZipArchive();

        if ($this->zip->open($zip_path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException("ZIP konnte nicht angelegt werden: $zip_path");
        }
    }

    public function add(string $name, string $content): self
    {
        $this->zip->addFromString($name, $content);
        return $this;
    }

    public function addDir(string $name): self
    {
        $this->zip->addEmptyDir(rtrim($name, '/'));
        return $this;
    }

    /**
     * Datei mit $size Bytes, davon $random_bytes zufällig, Rest Null-Bytes.
     *
     * Über den Zufallsanteil lässt sich das Kompressionsverhältnis steuern:
     * Null-Bytes komprimieren praktisch vollständig weg, Zufallsdaten gar nicht.
     */
    public function addGenerated(string $name, int $size, int $random_bytes = 0): self
    {
        $tmp = $this->zip_path . '.src-' . count($this->temp_files);
        $fh  = fopen($tmp, 'wb');

        if ($fh === false) {
            throw new RuntimeException("Temporäre Quelldatei konnte nicht angelegt werden: $tmp");
        }

        if ($random_bytes > 0) {
            fwrite($fh, random_bytes(min($random_bytes, $size)));
        }
        ftruncate($fh, $size);
        fclose($fh);

        $this->temp_files[] = $tmp;
        $this->zip->addFile($tmp, $name);

        return $this;
    }

    /** Schließt das Archiv und räumt die temporären Quelldateien ab. */
    public function close(): string
    {
        if (!$this->zip->close()) {
            throw new RuntimeException("ZIP konnte nicht geschrieben werden: {$this->zip_path}");
        }

        foreach ($this->temp_files as $tmp) {
            @unlink($tmp);
        }
        $this->temp_files = [];

        return $this->zip_path;
    }
}
