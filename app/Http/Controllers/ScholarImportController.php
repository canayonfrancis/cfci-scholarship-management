<?php

namespace App\Http\Controllers;

use App\Http\Requests\ScholarImportRequest;
use App\Models\AcademicProgram;
use App\Services\Records\ScholarImports;
use App\Services\Records\ScholarImportTemplate;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

class ScholarImportController extends Controller
{
    public function index(): View
    {
        return view('scholars.bulk-upload', $this->choices());
    }

    public function template(ScholarImportTemplate $template): StreamedResponse
    {
        $courses = AcademicProgram::where('is_active', true)->orderBy('name')->pluck('name')->all();

        return response()->streamDownload(function () use ($template, $courses) {
            $path = tempnam(sys_get_temp_dir(), 'cfci-scholar-template-');
            if ($path === false) {
                throw new \RuntimeException('Cannot allocate template file.');
            }
            try {
                @chmod($path, 0600);
                $template->write($path, $courses);
                $stream = fopen($path, 'rb');
                if ($stream === false) {
                    throw new \RuntimeException('Cannot read template file.');
                }
                try {
                    fpassthru($stream);
                } finally {
                    fclose($stream);
                }
            } finally {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }, 'cfci-scholars-template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function preview(ScholarImportRequest $request, ScholarImports $imports): RedirectResponse
    {
        try {
            $id = $imports->preview($request->user(), $request->file('file'));

            return to_route('scholars.imports.show', ['import' => $id], 303);
        } catch (ValidationException $error) {
            return to_route('scholars.imports.index', [], 303)->withErrors($error->errors());
        } catch (AuthorizationException|HttpException $error) {
            throw $error;
        } catch (\Throwable) {
            report(new \RuntimeException('Scholar upload preview failed; private file contents were not logged.'));

            return to_route('scholars.imports.index', [], 303)->withErrors(['file' => 'The file could not be previewed. No scholars were saved. Check the template and try again.']);
        }
    }

    public function show(Request $request, string $import, ScholarImports $imports): View|RedirectResponse
    {
        try {
            if ($receipt = $imports->receipt($request->user(), $import)) {
                return $this->completed($receipt);
            }
            $state = $imports->state($request->user(), $import);

            return view('scholars.bulk-upload', [...$this->choices(), 'preview' => $state['result'], 'importId' => $import,
                'expiresAt' => Carbon::createFromTimestamp($state['expires_at'], 'Asia/Manila')->format('M d, Y g:i A').' (Asia/Manila)',
            ]);
        } catch (ValidationException $error) {
            return to_route('scholars.imports.index')->withErrors($error->errors());
        } catch (AuthorizationException|HttpException $error) {
            throw $error;
        } catch (\Throwable) {
            report(new \RuntimeException('Scholar upload preview could not be loaded.'));

            return to_route('scholars.imports.index')->withErrors(['file' => 'This preview is unavailable. Upload the original file again.']);
        }
    }

    public function confirm(Request $request, string $import, ScholarImports $imports): RedirectResponse
    {
        $request->validate(['confirm' => ['required', 'accepted']]);
        try {
            return $this->completed($imports->confirm($request->user(), $import));
        } catch (ValidationException $error) {
            return to_route('scholars.imports.show', ['import' => $import], 303)->withErrors($error->errors());
        } catch (AuthorizationException|HttpException $error) {
            throw $error;
        } catch (\Throwable) {
            report(new \RuntimeException('Scholar bulk import failed; private row data and database errors were not logged.'));

            return to_route('scholars.imports.show', ['import' => $import], 303)
                ->withErrors(['file' => 'The import result could not be confirmed. A partial batch is never allowed. Refresh Scholars to check the result before retrying.']);
        }
    }

    public function cancel(Request $request, string $import, ScholarImports $imports): RedirectResponse
    {
        try {
            if ($receipt = $imports->receipt($request->user(), $import)) {
                return $this->completed($receipt);
            }
            $imports->cancel($request->user(), $import);

            return to_route('scholars.index', [], 303)->with('status', 'Upload canceled. No scholar records were imported.');
        } catch (ValidationException $error) {
            return to_route('scholars.imports.index', [], 303)->withErrors($error->errors());
        } catch (AuthorizationException|HttpException $error) {
            throw $error;
        } catch (\Throwable) {
            report(new \RuntimeException('Scholar upload cancellation failed.'));

            return to_route('scholars.imports.index', [], 303)->withErrors(['file' => 'The pending upload could not be removed. Try again or contact the administrator.']);
        }
    }

    private function choices(): array
    {
        return ['preview' => null, 'courses' => AcademicProgram::where('is_active', true)->orderBy('name')->pluck('name')];
    }

    private function completed(array $receipt): RedirectResponse
    {
        return to_route('scholars.index', [], 303)
            ->with('status', 'Bulk upload completed. '.$receipt['imported'].' scholar records imported.')
            ->with('scholar_import_result', $receipt);
    }
}
