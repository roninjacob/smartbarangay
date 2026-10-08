<?php

namespace App\Services;

use App\Models\Reservation;
use App\Models\Service;
use App\Models\User;
use App\Rules\OfficialDocumentTemplate;
use Illuminate\Http\File;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class ReservationDocumentPreparer
{
    public function __construct(private DocxTemplateRenderer $renderer, private DocumentMergeFields $fields) {}

    public function prepare(Reservation $reservation, User $admin): void
    {
        $disk = Storage::disk('local');
        $newPath = null;
        $oldPath = null;
        $work = 'document-generation/'.Str::random(40);
        try {
            DB::transaction(function () use ($reservation, $admin, $disk, $work, &$newPath, &$oldPath) {
                // Use the same Service-first lock order as template replacement and reservation creation.
                $service = Service::lockForUpdate()->findOrFail($reservation->service_id);
                $locked = Reservation::lockForUpdate()->findOrFail($reservation->id);
                abort_unless($locked->service_id === $service->id, 409);
                if (! $locked->status->canPrepareDocument()) {
                    $this->fail('Documents can only be prepared for Approved or Ready for Pickup requests. Completed documents are historical and cannot be regenerated.');
                }
                $template = $service->documentTemplate()->lockForUpdate()->first();
                if (! $template) {
                    $this->fail('No official document template has been uploaded for this Service.');
                }
                if ($template->mime_type !== OfficialDocumentTemplate::DOCX_MIME) {
                    $this->fail('Automatic document preparation is available for DOCX templates only. This PDF remains available as the official static template/reference.');
                }
                if (! preg_match('#^service-document-templates/'.$service->id.'/[A-Za-z0-9]{40}\.docx$#D', $template->stored_path) || ! $disk->exists($template->stored_path)) {
                    $this->fail('The official DOCX template is unavailable. Upload a valid current template before preparing this document.');
                }
                $locked->load(['user', 'schedule']);
                $locked->setRelation('service', $service);
                if (! $disk->makeDirectory($work)) {
                    $this->fail('Private document storage is unavailable. Try again later.');
                }
                $output = $disk->path($work.'/prepared.docx');
                $this->renderer->render($disk->path($template->stored_path), $output, $this->fields->values($locked));
                $newPath = 'reservation-documents/'.$locked->id.'/'.Str::random(40).'.docx';
                if (! $disk->putFileAs(dirname($newPath), new File($output), basename($newPath))) {
                    $this->fail('The prepared document could not be stored. Try again later. The previous prepared document is preserved.');
                }
                $previous = $locked->document()->lockForUpdate()->first();
                $oldPath = $previous?->hasSafePath() ? $previous->stored_path : null;
                $locked->document()->updateOrCreate([], [
                    'service_document_template_id' => $template->id, 'template_revision' => $template->revision(),
                    'template_filename' => $template->original_filename,
                    'original_filename' => (Str::slug(Str::limit($service->name, 120, '')) ?: 'Barangay-Document').'-Request-'.$locked->id.'.docx',
                    'stored_path' => $newPath, 'mime_type' => OfficialDocumentTemplate::DOCX_MIME,
                    'file_size' => $disk->size($newPath), 'generated_by' => $admin->id, 'generated_at' => now(),
                ]);
            });
        } catch (Throwable $exception) {
            if ($newPath !== null) {
                $disk->delete($newPath);
            }
            if ($exception instanceof ValidationException || $exception instanceof HttpExceptionInterface) {
                throw $exception;
            }
            report($exception);
            $this->fail('Document preparation could not be saved. Try again later. The previous prepared document is preserved.');
        } finally {
            $disk->deleteDirectory($work);
        }
        // A successfully committed replacement is now authoritative. Never delete the old file before commit.
        if ($oldPath !== null) {
            $disk->delete($oldPath);
        }
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['document' => $message]);
    }
}
