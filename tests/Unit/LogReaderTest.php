<?php

use Illuminate\Support\Facades\File;
use Opcodes\LogViewer\Exceptions\CannotOpenFileException;
use Opcodes\LogViewer\Facades\Cache as LogViewerCache;
use Opcodes\LogViewer\LogFile;
use Opcodes\LogViewer\Readers\IndexedLogReader;
use Opcodes\LogViewer\Utils\GenerateCacheKey;
use Spatie\TestTime\TestTime;

beforeEach(function () {
    $this->file = generateLogFile();
    File::append($this->file->path, makeLaravelLogEntry());
});

it('can scan a log file', function () {
    $logReader = $this->file->logs();
    expect($logReader->requiresScan())->toBeTrue();

    $logReader->scan();

    $index = $this->file->index();

    expect($logReader->requiresScan())->toBeFalse()
        ->and($index->count())->toBe(1);
});

it('can re-scan the file after a new entry has been added', function () {
    $logReader = $this->file->logs();
    $logReader->scan();

    TestTime::addMinute();

    File::append($this->file->path, PHP_EOL.makeLaravelLogEntry());

    // re-instantiate the log reader to make sure we don't have anything cached
    IndexedLogReader::clearInstance($this->file);
    $logReader = $this->file->logs();
    expect($logReader->requiresScan())->toBeTrue();

    $logReader->scan();
    $index = $this->file->index();

    expect($logReader->requiresScan())->toBeFalse()
        ->and($index->count())->toBe(2)
        ->and($index->getFlatIndex())->toHaveCount(2);
});

it('rebuilds a search index when its cache is evicted but file metadata survives', function () {
    $path = $this->file->path;

    $read = function () use ($path) {
        IndexedLogReader::clearInstances();

        $logReader = (new LogFile($path))->logs()->search('Testing');
        $logReader->scan();

        return [
            'requires_scan' => $logReader->requiresScan(),
            'new_bytes' => $logReader->numberOfNewBytes(),
            'total' => $logReader->total(),
            'count' => count($logReader->reset()->get()),
        ];
    };

    expect($read())->toMatchArray([
        'requires_scan' => false,
        'new_bytes' => 0,
        'total' => 1,
        'count' => 1,
    ]);

    $index = (new LogFile($path))->index('~Testing~iu');

    LogViewerCache::forget(GenerateCacheKey::for($index, 'metadata'));
    LogViewerCache::forget(GenerateCacheKey::for($index, 'chunk:0'));

    expect($read())->toMatchArray([
        'requires_scan' => false,
        'new_bytes' => 0,
        'total' => 1,
        'count' => 1,
    ]);
});

it('throws an exception when file cannot be opened for reading', function () {
    if (PHP_OS_FAMILY === 'Windows') {
        $this->markTestSkipped('File permissions work differently on Windows. The feature tested might still work.');
    }

    chmod($this->file->path, 0333); // prevent reading
    $logReader = $this->file->logs();

    $logReader->scan();
})->expectException(CannotOpenFileException::class);
