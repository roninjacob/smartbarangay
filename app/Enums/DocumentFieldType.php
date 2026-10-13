<?php

namespace App\Enums;

enum DocumentFieldType: string
{
    case Text = 'text';
    case Number = 'number';
    case Date = 'date';
    case Dropdown = 'dropdown';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
