<?php

namespace App\Http\Controllers;

use App\Actions\Records\SaveAcademicVerification;
use App\Http\Requests\AcademicVerificationRequest;
use App\Models\AcademicPeriod;
use App\Models\AcademicRecord;
use App\Models\Document;
use App\Models\EvaluationRun;
use App\Models\Student;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AcademicVerificationController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('viewAny', AcademicRecord::class);
        $filters = $request->validate([
            'student_id' => ['nullable', 'integer', 'exists:students,id'],
            'academic_period_id' => ['nullable', 'integer', 'exists:academic_periods,id'],
            'verification_status' => ['nullable', 'in:pending,verified,rejected'],
        ]);

        return view('academic-verifications.index', [
            'records' => AcademicRecord::with(['student', 'academicPeriod', 'verifiedBy'])
                ->when($filters['student_id'] ?? null, fn ($q, $value) => $q->where('student_id', $value))
                ->when($filters['academic_period_id'] ?? null, fn ($q, $value) => $q->where('academic_period_id', $value))
                ->when($filters['verification_status'] ?? null, fn ($q, $value) => $q->where('verification_status', $value))
                ->orderByDesc('id')->paginate(15)->withQueryString(),
            ...$this->choices(),
        ]);
    }

    public function create(Request $request): View
    {
        Gate::authorize('create', AcademicRecord::class);
        $data = $request->validate(['student_id' => ['nullable', 'integer', 'exists:students,id'], 'academic_period_id' => ['nullable', 'integer', 'exists:academic_periods,id']]);

        return view('academic-verifications.form', ['record' => new AcademicRecord(['student_id' => $data['student_id'] ?? null, 'academic_period_id' => $data['academic_period_id'] ?? null, 'enrollment_status' => 'unverified', 'academic_standing' => 'unverified']), 'correction' => false, ...$this->choices()]);
    }

    public function store(AcademicVerificationRequest $request, SaveAcademicVerification $save): RedirectResponse
    {
        $record = $save->handle($request->user(), $request->validated(), $request->file('certificates', []));

        return to_route('academic-verifications.show', $record)->with('status', 'Academic record saved.');
    }

    public function show(AcademicRecord $verification): View
    {
        Gate::authorize('view', $verification);
        $verification->load(['student', 'academicPeriod', 'verifiedBy', 'documents.uploadedBy', 'supersedes', 'grades']);
        $revisions = $verification->student->academicRecords()->where('academic_period_id', $verification->academic_period_id)->with('verifiedBy')->orderByDesc('revision')->get();

        return view('academic-verifications.show', [
            'record' => $verification,
            'revisions' => $revisions,
            'evaluations' => EvaluationRun::where(fn ($q) => $q->whereIn('grade_record_id', $revisions->modelKeys())->orWhereIn('enrollment_record_id', $revisions->modelKeys()))->with(['program', 'academicPeriod'])->get(),
        ]);
    }

    public function edit(AcademicRecord $verification): View
    {
        Gate::authorize('update', $verification);

        return view('academic-verifications.form', ['record' => $verification->load(['student', 'academicPeriod', 'grades']), 'correction' => false]);
    }

    public function update(AcademicVerificationRequest $request, AcademicRecord $verification, SaveAcademicVerification $save): RedirectResponse
    {
        $record = $save->handle($request->user(), $request->validated(), $request->file('certificates', []), $verification);

        return to_route('academic-verifications.show', $record)->with('status', 'Academic record saved.');
    }

    public function correction(AcademicRecord $verification): View
    {
        Gate::authorize('correct', $verification);

        return view('academic-verifications.form', ['record' => $verification->load(['student', 'academicPeriod', 'grades']), 'correction' => true]);
    }

    public function correct(AcademicVerificationRequest $request, AcademicRecord $verification, SaveAcademicVerification $save): RedirectResponse
    {
        $record = $save->handle($request->user(), $request->validated(), $request->file('certificates', []), $verification, true);

        return to_route('academic-verifications.show', $record)->with('status', 'New academic revision saved. Decisions using an older revision require Coordinator reassessment.');
    }

    public function certificate(AcademicRecord $verification, Document $document): StreamedResponse
    {
        Gate::authorize('view', $verification);
        abort_unless($document->academic_record_id === $verification->id && $document->disk === 'academic' && $document->document_type === 'grade_certificate' && str_starts_with($document->storage_path, 'certificates/'.$verification->id.'/'), 404);
        $disk = Storage::disk('academic');
        abort_unless($document->checksum && $disk->exists($document->storage_path), 404);
        $stream = $disk->readStream($document->storage_path);
        abort_unless(is_resource($stream), 404);
        try {
            $hash = hash_init('sha256');
            hash_update_stream($hash, $stream);
            $matches = hash_equals($document->checksum, hash_final($hash));
        } finally {
            fclose($stream);
        }
        abort_unless($matches, 404);
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', basename(str_replace('\\', '/', $document->original_name))) ?: 'certificate';

        return $disk->download($document->storage_path, $name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function choices(): array
    {
        return [
            'students' => Student::orderBy('last_name')->orderBy('first_name')->get(),
            'periods' => AcademicPeriod::orderByDesc('academic_year')->orderBy('semester_code')->get(),
        ];
    }
}
