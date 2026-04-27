<?php

namespace App\Providers;

use App\Services\AI\Contracts\ReplyGenerator;
use App\Services\AI\OpenAiReplyGenerator;
use App\Services\Email\Contracts\ImapMailboxFactory;
use App\Services\Email\WebklexImapMailboxFactory;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(ImapMailboxFactory::class, WebklexImapMailboxFactory::class);
        $this->app->bind(ReplyGenerator::class, OpenAiReplyGenerator::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
