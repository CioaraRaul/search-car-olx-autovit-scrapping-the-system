<?php

namespace App\Enums;

enum IngestionRunStatus: string
{
    case Running = 'running';
    case Success = 'success';
    case Failed = 'failed';
    case Partial = 'partial';
}
