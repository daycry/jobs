<?php

declare(strict_types=1);

/**
 * This file is part of Daycry Queues.
 *
 * (c) Daycry <daycry9@proton.me>
 *
 * For the full copyright and license information, please view
 * the LICENSE file that was distributed with this source code.
 */

namespace Daycry\Jobs\Loggers;

use CodeIgniter\I18n\Time;
use CodeIgniter\Log\Handlers\BaseHandler;
use Daycry\Jobs\Config\Jobs as JobsConfig;
use Daycry\Jobs\Interfaces\LoggerHandlerInterface;

/**
 * File-based job execution history handler.
 *
 * v1.1+ writes entries in NDJSON (one JSON object per line, newest at the bottom).
 * Reads transparently support both NDJSON and the legacy JSON-array layout
 * (newest first) so older log files keep working without manual migration.
 */
class FileHandler extends BaseHandler implements LoggerHandlerInterface
{
    private ?string $path = null;
    private string $name;

    public function __construct()
    {
        $configuration = config('Jobs');
        $base          = rtrim((string) $configuration->filePath, '/\\');
        $this->path    = $base;
        if ($this->path !== null && ! is_dir($this->path)) {
            mkdir($this->path, 0700, true);
        }
    }

    /**
     * Persist a structured log entry JSON string for the current job name.
     *
     * @param mixed $level
     * @param mixed $message
     */
    public function handle($level, $message): bool
    {
        /** @var JobsConfig $config */
        $config = config('Jobs');
        if (! isset($this->name) || ($this->name === '' || $this->name === '0')) {
            $decoded = json_decode((string) $message, true);
            if (is_array($decoded) && ! empty($decoded['name'])) {
                $this->name = (string) $decoded['name'];
            }
        }
        if (! isset($this->name) || ($this->name === '' || $this->name === '0')) {
            $this->name = 'unnamed';
        }

        $safeName = $this->sanitizeName($this->name);
        $fileName = rtrim($config->filePath, '/\\') . '/' . $safeName . '.json';

        // First-write migration: if a legacy JSON-array file exists, rewrite it as
        // NDJSON in-place before appending so the on-disk format stays consistent.
        $this->migrateLegacyIfNeeded($fileName);

        // NDJSON append: one record per line, atomic via flock(LOCK_EX).
        $line = (string) $message;
        // Defensive normalisation: collapse any embedded newlines so each entry stays on one line.
        $line = str_replace(["\r\n", "\r", "\n"], ' ', $line);
        $line .= "\n";

        $fp = @fopen($fileName, 'ab');
        if ($fp === false) {
            return false;
        }

        try {
            flock($fp, LOCK_EX);
            fwrite($fp, $line);
            flock($fp, LOCK_UN);
        } finally {
            fclose($fp);
        }

        // Deterministic pruning so maxLogsPerJob remains a hard cap (matches legacy semantics).
        // The cost is O(n) line-count + optional rewrite, comparable to the legacy
        // read/decode/slice/write loop while keeping atomic appends in the hot path.
        $max = $config->maxLogsPerJob;
        if ($max > 0) {
            $this->pruneIfNeeded($fileName, $max);
        }

        return true;
    }

    public function setPath(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function lastRun(string $name): string|Time
    {
        $entries = $this->history($name, 1);
        if ($entries === [] || ! isset($entries[0]->start_at)) {
            return '--';
        }

        return Time::parse($entries[0]->start_at);
    }

    /**
     * Returns an array of recent executions for a job, newest first.
     *
     * Reads transparently from either NDJSON (v1.1+ format) or the legacy
     * JSON-array file produced by previous versions.
     *
     * @return array<int, object>
     */
    public function history(string $name, int $limit = 10): array
    {
        $safeName = $this->sanitizeName($name);
        $fileName = ($this->path ?? '') . '/' . $safeName . '.json';
        if (! file_exists($fileName)) {
            return [];
        }

        return $this->readEntries($fileName, $limit);
    }

    /**
     * Detect the on-disk format and dispatch to the appropriate reader.
     *
     * Legacy files start with `[` (single JSON array, newest first).
     * NDJSON files start with `{` (one JSON object per line, newest at the bottom).
     *
     * @return array<int, object>
     */
    private function readEntries(string $fileName, int $limit): array
    {
        $fp = @fopen($fileName, 'rb');
        if ($fp === false) {
            return [];
        }

        flock($fp, LOCK_SH);

        $first = '';

        while (! feof($fp) && ($c = fgetc($fp)) !== false) {
            if (! ctype_space($c)) {
                $first = $c;
                break;
            }
        }
        rewind($fp);

        if ($first === '[') {
            $raw = stream_get_contents($fp);
            flock($fp, LOCK_UN);
            fclose($fp);

            if ($raw === false) {
                return [];
            }
            $logs = json_decode($raw);

            return is_array($logs) ? array_slice($logs, 0, $limit) : [];
        }

        flock($fp, LOCK_UN);
        fclose($fp);

        return $this->tailNdjson($fileName, $limit);
    }

    /**
     * Stream the file keeping only the last $limit lines in memory, then return
     * them decoded with the newest entry at index 0.
     *
     * @return array<int, object>
     */
    private function tailNdjson(string $fileName, int $limit): array
    {
        $fp = @fopen($fileName, 'rb');
        if ($fp === false) {
            return [];
        }

        $buffer = [];

        while (($line = fgets($fp)) !== false) {
            $line = rtrim($line, "\r\n");
            if ($line === '') {
                continue;
            }
            $buffer[] = $line;
            if (count($buffer) > $limit) {
                array_shift($buffer);
            }
        }
        fclose($fp);

        $entries = [];

        foreach (array_reverse($buffer) as $line) {
            $obj = json_decode($line);
            if ($obj !== null) {
                $entries[] = $obj;
            }
        }

        return $entries;
    }

    /**
     * If $fileName is a legacy JSON-array file, rewrite it as NDJSON
     * (oldest→newest) so subsequent appends keep the file consistent.
     */
    private function migrateLegacyIfNeeded(string $fileName): void
    {
        if (! file_exists($fileName)) {
            return;
        }

        $fp = @fopen($fileName, 'rb');
        if ($fp === false) {
            return;
        }

        $first = '';

        while (! feof($fp) && ($c = fgetc($fp)) !== false) {
            if (! ctype_space($c)) {
                $first = $c;
                break;
            }
        }

        if ($first !== '[') {
            fclose($fp);

            return;
        }

        rewind($fp);
        $raw = stream_get_contents($fp);
        fclose($fp);

        $logs = is_string($raw) ? json_decode($raw) : null;
        if (! is_array($logs)) {
            return;
        }

        // Legacy format stores newest-first; on disk we want oldest-first NDJSON
        // so the natural append at the bottom keeps the chronological order.
        $entries = array_reverse($logs);

        $tmp = $fileName . '.tmp';
        $out = @fopen($tmp, 'wb');
        if ($out === false) {
            return;
        }

        foreach ($entries as $entry) {
            $encoded = json_encode($entry);
            if ($encoded === false) {
                continue;
            }
            $encoded = str_replace(["\r\n", "\r", "\n"], ' ', $encoded);
            fwrite($out, $encoded . "\n");
        }
        fclose($out);
        rename($tmp, $fileName);
    }

    /**
     * Trim the file to the last $max entries.
     *
     * Handles both legacy JSON-array layouts (rewriting them as NDJSON in the
     * process so subsequent appends stay consistent) and NDJSON.
     */
    private function pruneIfNeeded(string $fileName, int $max): void
    {
        if ($max <= 0 || ! file_exists($fileName)) {
            return;
        }

        $fp = @fopen($fileName, 'rb');
        if ($fp === false) {
            return;
        }

        $first = '';

        while (! feof($fp) && ($c = fgetc($fp)) !== false) {
            if (! ctype_space($c)) {
                $first = $c;
                break;
            }
        }

        if ($first === '[') {
            // Legacy JSON-array on disk. Decode, slice newest $max (already newest-first
            // in legacy layout), then rewrite as NDJSON (oldest→newest order on disk).
            rewind($fp);
            $raw = stream_get_contents($fp);
            fclose($fp);
            $logs = is_string($raw) ? json_decode($raw) : null;
            if (! is_array($logs)) {
                return;
            }

            $kept = array_reverse(array_slice($logs, 0, $max));

            $tmp = $fileName . '.tmp';
            $out = @fopen($tmp, 'wb');
            if ($out === false) {
                return;
            }

            foreach ($kept as $entry) {
                $encoded = json_encode($entry);
                if ($encoded === false) {
                    continue;
                }
                $encoded = str_replace(["\r\n", "\r", "\n"], ' ', $encoded);
                fwrite($out, $encoded . "\n");
            }
            fclose($out);
            rename($tmp, $fileName);

            return;
        }

        // NDJSON: count cheaply; rewrite only when we exceed the cap.
        rewind($fp);
        $count = 0;

        while (! feof($fp)) {
            $line = fgets($fp);
            if ($line === false) {
                continue;
            }
            if (rtrim($line, "\r\n") === '') {
                continue;
            }
            $count++;
        }

        if ($count <= $max) {
            fclose($fp);

            return;
        }

        rewind($fp);
        $tail = [];

        while (($line = fgets($fp)) !== false) {
            if (rtrim($line, "\r\n") === '') {
                continue;
            }
            $tail[] = $line;
            if (count($tail) > $max) {
                array_shift($tail);
            }
        }
        fclose($fp);

        $tmp = $fileName . '.tmp';
        $out = @fopen($tmp, 'wb');
        if ($out === false) {
            return;
        }

        foreach ($tail as $line) {
            fwrite($out, $line);
        }
        fclose($out);
        rename($tmp, $fileName);
    }

    /**
     * Sanitize job name for safe filesystem usage.
     */
    private function sanitizeName(string $name): string
    {
        return preg_replace('/[^A-Za-z0-9._-]+/', '_', $name) ?? 'unnamed';
    }
}
