<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;

class TransactionalEmailDelivery
{
    public function send(Closure $delivery, string $notificationType): bool
    {
        try {
            $delivery();

            return true;
        } catch (TransportExceptionInterface $exception) {
            preg_match('/got code ["\']?(\d{3})/i', $exception->getMessage(), $responseCode);

            Log::warning('Transactional email delivery failed.', [
                'notification_type' => $notificationType,
                'exception' => $exception::class,
                'smtp_response_code' => $responseCode[1] ?? null,
            ]);

            return false;
        }
    }
}
