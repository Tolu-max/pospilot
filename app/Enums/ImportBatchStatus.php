<?php

namespace App\Enums;

enum ImportBatchStatus: string
{
    case Previewed = 'previewed';
    case Imported = 'imported';
    case Failed = 'failed';
}
