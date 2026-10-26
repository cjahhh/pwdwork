<?php

namespace App\Mail\Transport;

use App\Support\PasswordRecoveryDiagnostics;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\MessageConverter;
use Throwable;

class BrevoTransport extends AbstractTransport
{
    public function __construct(#[\SensitiveParameter] private readonly string $apiKey)
    {
        parent::__construct();
    }

    public function __toString(): string
    {
        return 'brevo+https';
    }

    protected function doSend(SentMessage $message): void
    {
        $failureCategory = 'configuration';
        $httpStatus = null;
        try {
            if (trim($this->apiKey) === '') {
                throw new TransportException('Missing provider configuration.');
            }
            $failureCategory = 'message_format';
            $email = MessageConverter::toEmail($message->getOriginalMessage());
            if ($email->getAttachments()) {
                throw new TransportException('Attachments are not supported by this application transport.');
            }
            $address = fn (Address $value) => array_filter(['email' => $value->getAddress(), 'name' => $value->getName()], fn ($value) => $value !== '');
            $payload = [
                'sender' => $address($email->getFrom()[0]),
                'to' => array_map($address, $email->getTo()),
                'subject' => $email->getSubject(),
            ];
            if ($email->getHtmlBody() !== null) {
                $payload['htmlContent'] = $email->getHtmlBody();
            } else {
                $payload['textContent'] = $email->getTextBody();
            }
            foreach (['cc' => $email->getCc(), 'bcc' => $email->getBcc()] as $key => $values) {
                if ($values) {
                    $payload[$key] = array_map($address, $values);
                }
            }
            if ($email->getReplyTo()) {
                $payload['replyTo'] = $address($email->getReplyTo()[0]);
            }
            // Fixed HTTPS endpoint; no redirects, SMTP fallback, request dumps or provider response logging.
            $failureCategory = 'connection';
            PasswordRecoveryDiagnostics::record('provider_started');
            $response = Http::acceptJson()->asJson()->withHeaders(['api-key' => $this->apiKey])
                ->connectTimeout(5)->timeout(10)->withOptions(['allow_redirects' => false, 'verify' => true])
                ->post('https://api.brevo.com/v3/smtp/email', $payload);
            $httpStatus = $response->status();
            $failureCategory = match (true) {
                $httpStatus === 401 => 'provider_authentication',
                $httpStatus === 403 => 'provider_permission',
                $httpStatus === 429 => 'provider_rate_limit',
                $httpStatus >= 500 => 'provider_server',
                $httpStatus === 201 => 'provider_response',
                default => 'provider_rejected',
            };
            $messageId = $response->json('messageId');
            if ($httpStatus !== 201 || ! is_string($messageId) || trim($messageId) === '') {
                throw new TransportException('Provider did not accept the message.');
            }
            PasswordRecoveryDiagnostics::record('provider_accepted', 'none', $httpStatus);
        } catch (Throwable) {
            PasswordRecoveryDiagnostics::record('provider_failed', $failureCategory, $httpStatus);
            // Do not chain provider exceptions: they may contain credentials or the entire email body.
            throw new TransportException('Email delivery failed. Check the provider configuration and delivery dashboard.');
        }
    }
}
