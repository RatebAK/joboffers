<?php

namespace App\Services;

/**
 * An immutable description of a document that lives in Cloudinary.
 *
 * `resourceType` must be persisted alongside `publicId`: Cloudinary's delete and
 * rename APIs are scoped per resource type, and a `raw` asset cannot be deleted
 * through the (default) `image` endpoint.
 */
final class StoredDocument
{
    public function __construct(
        public readonly string $url,
        public readonly string $publicId,
        public readonly string $resourceType,
        public readonly ?string $mimeType = null,
        public readonly ?string $originalName = null,
    ) {}

    /**
     * @return array{url: string, public_id: string, resource_type: string, mime_type: ?string, original_name: ?string}
     */
    public function toArray(): array
    {
        return [
            'url'           => $this->url,
            'public_id'     => $this->publicId,
            'resource_type' => $this->resourceType,
            'mime_type'     => $this->mimeType,
            'original_name' => $this->originalName,
        ];
    }
}
