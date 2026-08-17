<?php

namespace App\Services;

use App\Exceptions\DocumentUploadException;
use Cloudinary\Cloudinary;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Stores and removes user documents (CVs, resumes) on Cloudinary.
 *
 * Cloudinary rules this service encodes, so callers never have to think about them:
 *
 * 1. Documents are uploaded with `resource_type: raw`. Raw storage returns the
 *    exact bytes that were uploaded, which is what a text extractor needs. PDFs
 *    uploaded as `image` are treated as rasterisable artwork instead.
 * 2. A raw `public_id` includes the file extension, and Cloudinary derives that
 *    extension from the *uploaded file's name* — not from the `public_id` that
 *    was requested. Laravel hands us a temp file named `phpXXXX.tmp`, so the
 *    upload is streamed from a temp copy carrying the intended extension. Without
 *    this, URLs end in `.tmp` (or gain a doubled extension such as `cv.bin.pdf`).
 * 3. Cloudinary refuses to deliver `.pdf` and `.zip` URLs unless
 *    "Allow delivery of PDF and ZIP files" is enabled for the product
 *    environment. While it is off, every `.pdf` URL answers HTTP 401
 *    "deny or ACL failure" with an empty body. `services.cloudinary.pdf_delivery_enabled`
 *    selects between the real `.pdf` extension and a delivery-safe one.
 * 4. Deletes are scoped per resource type, so `resourceType` travels with the
 *    `publicId` and is passed back on removal.
 */
class DocumentUploadService
{
    /** Cloudinary will not deliver URLs ending in these extensions unless explicitly allowed. */
    private const RESTRICTED_EXTENSIONS = ['pdf', 'zip'];

    private const RESOURCE_TYPE = 'raw';

    public function __construct(private readonly Cloudinary $cloudinary) {}

    /**
     * Upload a document and return everything needed to serve or delete it later.
     *
     * @param  string  $folder  Cloudinary folder, e.g. "job-seeker-resumes"
     *
     * @throws DocumentUploadException
     */
    public function upload(UploadedFile $file, string $folder): StoredDocument
    {
        $originalName  = $file->getClientOriginalName();
        $realExtension = strtolower($file->getClientOriginalExtension());
        $storedName    = $this->buildStoredName($originalName);
        $extension     = $this->deliverableExtension($realExtension);

        // Stream from a temp copy so Cloudinary derives the extension we intend.
        $sourcePath = $this->createTempCopy($file, $storedName, $extension);

        try {
            $result = $this->cloudinary->uploadApi()->upload($sourcePath, [
                'folder'        => $folder,
                'public_id'     => $storedName,
                'resource_type' => self::RESOURCE_TYPE,
                'access_mode'   => 'public',
            ]);
        } catch (Throwable $e) {
            Log::error('Cloudinary document upload failed', [
                'folder'        => $folder,
                'original_name' => $originalName,
                'error'         => $e->getMessage(),
            ]);

            throw DocumentUploadException::uploadFailed($e->getMessage());
        } finally {
            @unlink($sourcePath);
        }

        $document = new StoredDocument(
            url: $result['secure_url'],
            publicId: $result['public_id'],
            resourceType: $result['resource_type'] ?? self::RESOURCE_TYPE,
            mimeType: $file->getMimeType(),
            originalName: $originalName,
        );

        Log::info('Document uploaded to Cloudinary', [
            'folder'         => $folder,
            'original_name'  => $originalName,
            'real_extension' => $realExtension,
            'stored_as'      => $document->publicId,
            'resource_type'  => $document->resourceType,
            'url'            => $document->url,
        ]);

        return $document;
    }

    /**
     * Confirm the stored document is publicly downloadable.
     *
     * Worth calling before handing a URL to an external service: it turns an
     * opaque downstream failure ("could not extract any text") into a precise,
     * actionable storage error.
     *
     * @throws DocumentUploadException
     */
    public function assertDeliverable(StoredDocument $document): void
    {
        try {
            $response = Http::timeout(20)->head($document->url);
        } catch (Throwable $e) {
            Log::warning('Could not verify document delivery', [
                'url'   => $document->url,
                'error' => $e->getMessage(),
            ]);

            return; // Never block an upload because the check itself failed.
        }

        if ($response->successful()) {
            return;
        }

        Log::error('Stored document is not deliverable', [
            'url'           => $document->url,
            'status'        => $response->status(),
            'cloudinary_error' => $response->header('X-Cld-Error'),
        ]);

        throw DocumentUploadException::notDeliverable($document->url, $response->status());
    }

    /**
     * Remove a document. Safe to call with nulls or an already-deleted asset.
     */
    public function delete(?string $publicId, ?string $resourceType = null): void
    {
        if (blank($publicId)) {
            return;
        }

        try {
            $this->cloudinary->uploadApi()->destroy($publicId, [
                'resource_type' => $resourceType ?: self::RESOURCE_TYPE,
                'invalidate'    => true,
            ]);
        } catch (Throwable $e) {
            // A failed cleanup should never break the request that triggered it.
            Log::warning('Could not delete document from Cloudinary', [
                'public_id'     => $publicId,
                'resource_type' => $resourceType,
                'error'         => $e->getMessage(),
            ]);
        }
    }

    /**
     * The extension the document should be served under.
     *
     * PDFs fall back to a non-restricted extension while Cloudinary PDF delivery
     * is disabled, otherwise the URL would answer 401 for every consumer.
     */
    public function deliverableExtension(string $extension): string
    {
        $extension = strtolower(ltrim($extension, '.'));

        if (! in_array($extension, self::RESTRICTED_EXTENSIONS, true)) {
            return $extension;
        }

        if (config('services.cloudinary.pdf_delivery_enabled')) {
            return $extension;
        }

        return strtolower((string) config('services.cloudinary.pdf_fallback_extension', 'bin'));
    }

    /**
     * A collision-free, URL-safe public_id that still hints at the original filename.
     */
    private function buildStoredName(string $originalName): string
    {
        $base = Str::slug(pathinfo($originalName, PATHINFO_FILENAME)) ?: 'document';

        return Str::limit($base, 60, '') . '_' . Str::lower(Str::random(12));
    }

    /**
     * @return string Absolute path to a temp copy named `<storedName>.<extension>`
     *
     * @throws DocumentUploadException
     */
    private function createTempCopy(UploadedFile $file, string $storedName, string $extension): string
    {
        $path = rtrim(sys_get_temp_dir(), '\\/') . DIRECTORY_SEPARATOR . $storedName . '.' . $extension;

        if (! @copy($file->getRealPath(), $path)) {
            throw DocumentUploadException::uploadFailed('could not stage the file for upload');
        }

        return $path;
    }
}
