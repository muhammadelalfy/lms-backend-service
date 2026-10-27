<?php

namespace Modules\Exams\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Modules\Core\Http\Concerns\AuthorizesStaff;
use Modules\Exams\Actions\PublishGoogleForm;
use Modules\Exams\Actions\SyncGoogleFormResponses;
use Modules\Exams\Models\ExamTemplate;
use Modules\Exams\Models\GoogleForm;

/** A teacher turns an exam template into a graded Google Form and pulls the submissions back. */
class GoogleFormController extends Controller
{
    use AuthorizesStaff;

    public function show(Request $request, ExamTemplate $template)
    {
        $this->authorizeStaff($request);
        $form = GoogleForm::query()->where('template_id', $template->id)->first();

        return ['data' => $form === null ? null : $this->present($form)];
    }

    public function store(Request $request, ExamTemplate $template, PublishGoogleForm $publish)
    {
        $this->authorizeStaff($request);

        return response()->json(['data' => $this->present($publish->handle($request->user(), $template))], 201);
    }

    public function sync(Request $request, ExamTemplate $template, SyncGoogleFormResponses $sync)
    {
        $this->authorizeStaff($request);
        $form = GoogleForm::query()->where('template_id', $template->id)->firstOrFail();
        $new = $sync->handle($request->user(), $form);

        return ['new' => $new, 'data' => $this->present($form->refresh())];
    }

    public function responses(Request $request, ExamTemplate $template)
    {
        $this->authorizeStaff($request);
        $form = GoogleForm::query()->where('template_id', $template->id)->firstOrFail();

        return ['data' => $form->responses()->with('student:id,name')->orderByDesc('submitted_at')->limit(500)->get()];
    }

    private function present(GoogleForm $form): array
    {
        return [
            'id' => $form->id, 'template_id' => $form->template_id, 'responder_uri' => $form->responder_uri,
            'edit_uri' => "https://docs.google.com/forms/d/{$form->form_id}/edit",
            'synced_at' => $form->synced_at, 'responses_count' => $form->responses()->count(),
        ];
    }
}
