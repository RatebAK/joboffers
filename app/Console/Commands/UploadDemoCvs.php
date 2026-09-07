<?php

namespace App\Console\Commands;

use App\Services\DocumentUploadService;
use Illuminate\Console\Command;
use Illuminate\Http\UploadedFile;
use Throwable;

/**
 * Uploads a folder of sample CV PDFs to Cloudinary using the SAME upload path
 * the real application uses (DocumentUploadService, resource_type = raw), then
 * writes a manifest JSON that the demo seeders consume.
 *
 * This performs NO AI analysis — it only stores the files and records their
 * Cloudinary URLs / public IDs. The manifest is keyed by the PDF filename so
 * the JobSeekerProfileSeeder can attach the right CV to the right seeded seeker.
 *
 * Usage:
 *   php artisan demo:upload-cvs
 *   php artisan demo:upload-cvs --dir=database/seeders/cvs --out=database/seeders/data/cv-manifest.json
 */
class UploadDemoCvs extends Command
{
    protected $signature = 'demo:upload-cvs
        {--dir=database/seeders/cvs : Folder (relative to base path) containing the CV PDFs}
        {--out=database/seeders/data/cv-manifest.json : Where to write the manifest JSON}
        {--folder=job-seeker-resumes : Cloudinary folder to upload into}';

    protected $description = 'Upload sample CV PDFs to Cloudinary (no AI) and write a manifest for the demo seeders.';

    public function handle(DocumentUploadService $uploader): int
    {
        $dir = base_path($this->option('dir'));
        $out = base_path($this->option('out'));
        $folder = (string) $this->option('folder');

        if (! is_dir($dir)) {
            $this->error("CV directory not found: {$dir}");
            $this->line("Create it and drop your PDF files there, e.g.:");
            $this->line("  {$dir}");

            return self::FAILURE;
        }

        $files = collect(glob(rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . '*'))
            ->filter(fn ($p) => is_file($p))
            ->filter(fn ($p) => in_array(strtolower(pathinfo($p, PATHINFO_EXTENSION)), ['pdf', 'doc', 'docx'], true))
            ->values();

        if ($files->isEmpty()) {
            $this->error("No PDF/DOC/DOCX files found in {$dir}");

            return self::FAILURE;
        }

        $this->info("Found {$files->count()} CV file(s). Uploading to Cloudinary folder '{$folder}'...");

        // Reuse an existing manifest so re-runs don't re-upload already-stored CVs.
        $manifest = [];
        if (is_file($out)) {
            $existing = json_decode((string) file_get_contents($out), true);
            if (is_array($existing)) {
                $manifest = $existing;
            }
        }

        $uploaded = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($files as $path) {
            $name = basename($path);

            if (isset($manifest[$name]['url'])) {
                $this->line("  - {$name}: already in manifest, skipping upload");
                $skipped++;
                continue;
            }

            try {
                // Wrap the on-disk file as an UploadedFile so it flows through the
                // exact same code path as a real user upload. 'test: true' lets us
                // construct it without an actual HTTP request.
                $mime = $this->guessMime($path);
                $uploadedFile = new UploadedFile(
                    $path,
                    $name,
                    $mime,
                    null,
                    true // test mode
                );

                $document = $uploader->upload($uploadedFile, $folder);

                $manifest[$name] = [
                    'file'          => $name,
                    'url'           => $document->url,
                    'public_id'     => $document->publicId,
                    'resource_type' => $document->resourceType,
                    'mime_type'     => $document->mimeType,
                    'original_name' => $document->originalName,
                ];

                $this->info("  - {$name}: uploaded -> {$document->url}");
                $uploaded++;
            } catch (Throwable $e) {
                $this->error("  - {$name}: upload FAILED: {$e->getMessage()}");
                $failed++;
            }
        }

        // Ensure the output directory exists, then persist the manifest.
        $outDir = dirname($out);
        if (! is_dir($outDir)) {
            mkdir($outDir, 0755, true);
        }
        file_put_contents($out, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        $this->newLine();
        $this->info("Done. Uploaded: {$uploaded}, skipped: {$skipped}, failed: {$failed}.");
        $this->info("Manifest written to: {$out}");
        $this->line("Manifest now contains " . count($manifest) . " CV entr(y/ies).");

        return $failed > 0 && $uploaded === 0 ? self::FAILURE : self::SUCCESS;
    }

    private function guessMime(string $path): string
    {
        return match (strtolower(pathinfo($path, PATHINFO_EXTENSION))) {
            'pdf'  => 'application/pdf',
            'doc'  => 'application/msword',
            'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            default => 'application/octet-stream',
        };
    }
}
