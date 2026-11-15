<?php

namespace App\Mail;

use App\Models\Customer;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * E-mail s odkazem do rezervací.
 *  - welcome:  osobní odkaz po první rezervaci
 *  - recover:  dočasný odkaz po žádosti na /obnovit (30 min)
 *  - existing: dočasný odkaz, když někdo rezervoval přes veřejný formulář se známým kontaktem
 */
class AccessLinkMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Customer $customer,
        public string $url,
        public string $reason = 'welcome',
        public ?int $validMinutes = null,
    ) {}

    public function envelope(): Envelope
    {
        $salon = $this->customer->salon->name;

        return new Envelope(subject: match ($this->reason) {
            'welcome' => "Váš odkaz do rezervací – {$salon}",
            'existing' => "Vaše rezervace čeká na potvrzení – {$salon}",
            default => "Nový odkaz do rezervací – {$salon}",
        });
    }

    public function content(): Content
    {
        return new Content(markdown: 'mail.access-link', with: [
            'salon' => $this->customer->salon,
            'validLabel' => $this->validMinutes === null ? null
                : ($this->validMinutes >= 120 ? intdiv($this->validMinutes, 60).' hodin' : $this->validMinutes.' minut'),
        ]);
    }
}
