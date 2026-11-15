<?php

namespace App\Support;

/**
 * Pravidla salonu (SPEC 4.3) – čtou se z JSON sloupce `salons.settings`,
 * chybějící hodnoty se doplní výchozími.
 */
final class SalonSettings
{
    public const DEFAULTS = [
        'slot_step' => 15,                 // krok nabídky časů (min)
        'min_notice_hours' => 2,           // nejdřív lze rezervovat (h předem)
        'max_days_ahead' => 60,            // nejdál lze rezervovat (dní)
        'cancel_hours' => 24,              // zrušení zákaznicí nejpozději (h předem)
        'approve_new_customers' => true,   // nové zákaznice čekají na schválení
        'reminder_hours' => 48,            // připomínka (h předem)
        'second_reminder_hours' => null,   // volitelná druhá připomínka (h předem)
    ];

    public readonly int $slotStep;

    public readonly int $minNoticeHours;

    public readonly int $maxDaysAhead;

    public readonly int $cancelHours;

    public readonly bool $approveNewCustomers;

    public readonly int $reminderHours;

    public readonly ?int $secondReminderHours;

    public function __construct(?array $values = null)
    {
        $v = array_merge(self::DEFAULTS, array_filter($values ?? [], fn ($x) => $x !== null && $x !== ''));

        $this->slotStep = max(5, (int) $v['slot_step']);
        $this->minNoticeHours = max(0, (int) $v['min_notice_hours']);
        $this->maxDaysAhead = max(1, (int) $v['max_days_ahead']);
        $this->cancelHours = max(0, (int) $v['cancel_hours']);
        $this->approveNewCustomers = (bool) $v['approve_new_customers'];
        $this->reminderHours = max(1, (int) $v['reminder_hours']);
        $this->secondReminderHours = $v['second_reminder_hours'] ? (int) $v['second_reminder_hours'] : null;
    }

    public function toArray(): array
    {
        return [
            'slot_step' => $this->slotStep,
            'min_notice_hours' => $this->minNoticeHours,
            'max_days_ahead' => $this->maxDaysAhead,
            'cancel_hours' => $this->cancelHours,
            'approve_new_customers' => $this->approveNewCustomers,
            'reminder_hours' => $this->reminderHours,
            'second_reminder_hours' => $this->secondReminderHours,
        ];
    }
}
