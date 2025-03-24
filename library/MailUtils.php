<?php

class MailUtils
{
    private function __construct()
    {
    }

    public static function sendMail($setFrom, $setBCC, $recipients, $body, $signature, $subject, $receipt, $attachments)
    {
        $mail_controller = new \FusionDirectory\Mail\MailLib($setFrom,
            $setBCC,
            $recipients,
            $body,
            $signature,
            $subject,
            $receipt,
            $attachments);

        return $mail_controller->sendMail();
    }
}