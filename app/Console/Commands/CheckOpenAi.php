<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;
use Throwable;

class CheckOpenAi extends Command
{
    protected $signature = 'openai:check';

    protected $description = 'Send one tiny request to OpenAI with the configured key and model, and print the result.';

    public function handle(): int
    {
        $key = config('openai.api_key');
        $model = config('openai.model');
        if (! filled($key) || ! filled($model)) {
            $this->error('OPENAI_API_KEY and OPENAI_MODEL must both be set (run php artisan config:clear if you just edited .env).');

            return self::FAILURE;
        }
        $this->line('Model: '.$model.'  Base URL: '.config('openai.base_url'));
        try {
            $r = Http::withToken((string) $key)->acceptJson()->asJson()->baseUrl((string) config('openai.base_url'))->timeout(30)
                ->post('/chat/completions', ['model' => $model, 'messages' => [['role' => 'user', 'content' => 'Reply with the word OK.']]]);
        } catch (Throwable $e) {
            $this->error('Could not reach OpenAI: '.$e->getMessage());

            return self::FAILURE;
        }
        if ($r->failed()) {
            $this->error('OpenAI answered HTTP '.$r->status().': '.mb_substr((string) $r->body(), 0, 600));

            return self::FAILURE;
        }
        $this->info('OK. The model answered: '.trim((string) $r->json('choices.0.message.content')));
        $this->line('The case analysis also needs structured JSON output (json_schema); a model that does not support it will fall back to keyword reading.');

        return self::SUCCESS;
    }
}
