<?php

namespace App\Enums;

enum ReservationSource: string
{
    case WebForm = 'web_form';
    case Email = 'email';
}
