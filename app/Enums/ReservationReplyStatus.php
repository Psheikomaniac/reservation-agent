<?php

namespace App\Enums;

enum ReservationReplyStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Sent = 'sent';
    case Failed = 'failed';
}
