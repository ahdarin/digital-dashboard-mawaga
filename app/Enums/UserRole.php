<?php

namespace App\Enums;

enum UserRole: string
{
    case CEO = 'CEO';
    case Manager = 'Manager';
    case ContentCreator = 'Content Creator';
    case GraphicDesigner = 'Graphic Designer';
    case SMO = 'SMO';
    case Copywriter = 'Copywriter';
    case ClientOwner = 'Client Owner';
    case ClientMember = 'Client Member';
}
