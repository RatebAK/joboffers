<?php

// ============================================================
// DO NOT DELETE — Tests for DocumentUploadService.
// Covers: the Cloudinary extension rules that decide whether a
// stored document is actually deliverable, resource-type-scoped
// deletion, and the delivery verification guard.
// ============================================================

use App\Exceptions\DocumentUploadException;
use App\Services\DocumentUploadService;
use App\Services\StoredDocument;
use Cloudinary\Api\Upload\UploadApi;
use Cloudinary\Cloudinary;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;

// ── Helpers ───────────────────────────────────────────────────

/**
 * Build the service with a Cloudinary double whose upload() echoes back the
 * options it was called with, so tests can assert on public_id / resource_type.
 */
function uploaderWithSpy(array &$captured, array $overrides = []): DocumentUploadService
{
    $uploadApi = Mockery::mock(UploadApi::class);
    $uploadApi->shouldReceive('upload')
        ->andReturnUsing(function ($path, $options) use (&$captured, $overrides) {
            $captured = ['path' => $path, 'options' => $options];

            return array_merge([
                'public_id'     => $options['folder'] . '/' . $options['public_id']
                                    . '.' . pathinfo($path, PATHINFO_EXTENSION),
                'resource_type' => $options['resource_type'],
                'secure_url'    => 'https://res.cloudinary.com/demo/raw/upload/v1/'
                                    . $options['folder'] . '/' . $options['public_id']
                                    . '.' . pathinfo($path, PATHINFO_EXTENSION),
            ], $overrides);
        });

    $cloudinary = Mockery::mock(Cloudinary::class);
    $cloudinary->shouldReceive('uploadApi')->andReturn($uploadApi);

    return new DocumentUploadService($cloudinary);
}

// ── Extension rules ───────────────────────────────────────────

test('pdf is stored under a delivery safe extension while cloudinary pdf delivery is off', function () {
    config(['services.cloudinary.pdf_delivery_enabled' => false]);
    config(['services.cloudinary.pdf_fallback_extension' => 'txt']);

    $service = uploaderWithSpy($captured);

    expect($service->deliverableExtension('pdf'))->toBe('txt')
        ->and($service->deliverableExtension('PDF'))->toBe('txt')
        ->and($service->deliverableExtension('zip'))->toBe('txt');
});

test('pdf keeps its real extension once cloudinary pdf delivery is enabled', function () {
    config(['services.cloudinary.pdf_delivery_enabled' => true]);

    $service = uploaderWithSpy($captured);

    expect($service->deliverableExtension('pdf'))->toBe('pdf');
});

test('unrestricted document extensions are never rewritten', function () {
    config(['services.cloudinary.pdf_delivery_enabled' => false]);

    $service = uploaderWithSpy($captured);

    expect($service->deliverableExtension('docx'))->toBe('docx')
        ->and($service->deliverableExtension('doc'))->toBe('doc');
});

// ── Upload ────────────────────────────────────────────────────

test('documents are uploaded as raw so the exact bytes are served back', function () {
    Http::fake(['*' => Http::response('', 200)]);
    $service = uploaderWithSpy($captured);

    $service->upload(
        UploadedFile::fake()->create('Amer Mahfudh CV.docx', 20),
        'job-seeker-resumes'
    );

    expect($captured['options']['resource_type'])->toBe('raw')
        ->and($captured['options']['folder'])->toBe('job-seeker-resumes');
});

test('the public id carries no extension because cloudinary appends the source one', function () {
    Http::fake(['*' => Http::response('', 200)]);
    $service = uploaderWithSpy($captured);

    $service->upload(UploadedFile::fake()->create('resume.docx', 20), 'job-seeker-cvs');

    // Passing an extension here is what produced URLs like "cv.docx.docx".
    expect($captured['options']['public_id'])->not->toContain('.');
});

test('the staged upload file carries the deliverable extension, not the php temp one', function () {
    config(['services.cloudinary.pdf_delivery_enabled' => false]);
    config(['services.cloudinary.pdf_fallback_extension' => 'txt']);
    Http::fake(['*' => Http::response('', 200)]);

    $service = uploaderWithSpy($captured);

    $document = $service->upload(
        UploadedFile::fake()->create('my_cv.pdf', 20, 'application/pdf'),
        'job-seeker-cvs'
    );

    // Laravel hands over "phpXXXX.tmp"; Cloudinary would otherwise store ".tmp".
    expect(pathinfo($captured['path'], PATHINFO_EXTENSION))->toBe('txt')
        ->and($document->url)->toEndWith('.txt')
        ->and($document->resourceType)->toBe('raw')
        ->and($document->originalName)->toBe('my_cv.pdf');
});

test('the original filename is preserved on the stored document', function () {
    Http::fake(['*' => Http::response('', 200)]);
    $service = uploaderWithSpy($captured);

    $document = $service->upload(
        UploadedFile::fake()->create('Tammam Mabroukeh CV.docx', 20),
        'job-seeker-resumes'
    );

    expect($document->originalName)->toBe('Tammam Mabroukeh CV.docx')
        ->and($captured['options']['public_id'])->toContain('tammam-mabroukeh-cv');
});

// ── Delivery verification ─────────────────────────────────────

test('an undeliverable document raises an actionable error', function () {
    Http::fake(['*' => Http::response('', 401)]);
    $service = uploaderWithSpy($captured);

    $document = new StoredDocument(
        url: 'https://res.cloudinary.com/demo/raw/upload/v1/cv.pdf',
        publicId: 'cv.pdf',
        resourceType: 'raw',
    );

    expect(fn () => $service->assertDeliverable($document))
        ->toThrow(DocumentUploadException::class);

    try {
        $service->assertDeliverable($document);
    } catch (DocumentUploadException $e) {
        expect($e->getMessage())->toContain('Allow delivery of PDF and ZIP files')
            ->and($e->getHttpStatusCode())->toBe(502);
    }
});

test('a deliverable document passes verification', function () {
    Http::fake(['*' => Http::response('', 200)]);
    $service = uploaderWithSpy($captured);

    $document = new StoredDocument(
        url: 'https://res.cloudinary.com/demo/raw/upload/v1/cv.txt',
        publicId: 'cv.txt',
        resourceType: 'raw',
    );

    $service->assertDeliverable($document);

    expect(true)->toBeTrue(); // no exception
});

// ── Deletion ──────────────────────────────────────────────────

test('deletion is scoped to the stored resource type', function () {
    $uploadApi = Mockery::mock(UploadApi::class);
    $uploadApi->shouldReceive('destroy')
        ->once()
        ->with('job-seeker-cvs/cv_abc.txt', Mockery::on(
            fn ($options) => $options['resource_type'] === 'raw'
        ))
        ->andReturn(['result' => 'ok']);

    $cloudinary = Mockery::mock(Cloudinary::class);
    $cloudinary->shouldReceive('uploadApi')->andReturn($uploadApi);

    (new DocumentUploadService($cloudinary))->delete('job-seeker-cvs/cv_abc.txt', 'raw');
});

test('deleting nothing is a no-op', function () {
    $uploadApi = Mockery::mock(UploadApi::class);
    $uploadApi->shouldNotReceive('destroy');

    $cloudinary = Mockery::mock(Cloudinary::class);
    $cloudinary->shouldReceive('uploadApi')->andReturn($uploadApi);

    $service = new DocumentUploadService($cloudinary);
    $service->delete(null);
    $service->delete('');

    expect(true)->toBeTrue();
});

test('a failing cleanup never bubbles up to the caller', function () {
    $uploadApi = Mockery::mock(UploadApi::class);
    $uploadApi->shouldReceive('destroy')->andThrow(new RuntimeException('cloudinary down'));

    $cloudinary = Mockery::mock(Cloudinary::class);
    $cloudinary->shouldReceive('uploadApi')->andReturn($uploadApi);

    (new DocumentUploadService($cloudinary))->delete('some/id.txt', 'raw');

    expect(true)->toBeTrue(); // swallowed
});
