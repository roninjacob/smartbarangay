<?php

namespace App\Services;

/** A disk-backed OS temporary file, automatically unlinked when its handle closes. */
class ReportCsvFile
{
    private mixed $handle;

    public function __construct()
    {
        $this->handle = @tmpfile();
        if ($this->handle === false) {
            throw new ReportExportException('Unable to create the private CSV staging file. No download was created.');
        }
    }

    public function handle(): mixed
    {
        return $this->handle;
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    public function __destruct()
    {
        $this->close();
    }
}
