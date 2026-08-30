<?php

namespace App\Enums;

enum UserRole: string
{
    case CEO = 'CEO';
    case Manager = 'Manager';
    case ContentCreator = 'Content Creator';
    case DesainGrafis = 'Graphic Designer';
    case SMO = 'SMO';
    case Copywriter = 'Copywriter';
    case Admin = 'Admin';
}
