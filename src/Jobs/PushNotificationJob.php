<?php

namespace Ssntpl\Neev\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Notifications\PushNotification;

class PushNotificationJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct(public int $user_id, public string $title, public string $body, public ?string $link = null) {}

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $user = User::model()->find($this->user_id);
        $user->notify(new PushNotification(
            $this->title,
            $this->body,
            $this->link,
        ));
    }
}
