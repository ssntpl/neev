<?php

namespace Ssntpl\Neev\Notifications;

use Illuminate\Notifications\Notification;
use NotificationChannels\Fcm\FcmChannel;
use NotificationChannels\Fcm\FcmMessage;
use NotificationChannels\Fcm\Resources\Notification as FcmNotification;

class PushNotification extends Notification
{
    public function __construct(public string $title, public string $body, public ?string $link = null) {}

    public function via($notifiable)
    {
        return [FcmChannel::class];
    }

    public function toFcm($notifiable)
    {
        $message = FcmMessage::create()
            ->notification(
                FcmNotification::create()
                    ->title($this->title)
                    ->body($this->body)
            )
            ->data([
                'type' => 'notification',
                'click_action' => $this->link ?? env('APP_URL'),
            ]);

        return $message;
    }

    public function toArray($notifiable)
    {
        return [
            'title' => $this->title,
            'body' => $this->body,
            'link' => $this->link ?? env('APP_URL'),
        ];
    }
}
