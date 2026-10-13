<?php

namespace App\Http\Controllers\Api\Front;

use App\Http\Controllers\Controller;
use App\Services\Front\CaseAnalyzer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Local/testing only: checks the OpenAI connection with the real case-analysis request. Not routed in production. */
class DevOpenAiController extends Controller
{
    public function __invoke(Request $r, CaseAnalyzer $analyzer): JsonResponse
    {
        abort_unless(app()->environment(['local', 'testing']), 404);
        $story = (string) ($r->input('story') ?: 'أريد تأسيس شركة في دبي وتسجيل الرخصة التجارية');

        return response()->json($analyzer->probe(mb_substr($story, 0, 2000)), 200, [], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    }
}
